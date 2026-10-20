# Service Foundation Specification

## Purpose

Cross-cutting contract of the HR-Avatar HTTP service (`service/`): `/v1` routing, the RFC 9457
error contract, request identification, health endpoints, account scoping, OpenAPI publication,
boundary rules, runtime topology and project documentation. Every other capability builds on it.

Auth legend used across all specs: **SK** = secret key (`Authorization: Bearer hra_sk_...`);
**PK+CS** = publishable key (`X-HRA-Publishable-Key`) plus per-conversation `client_secret`
(`Authorization: Bearer <jwt>`); **PT** = per-conversation provider token in the URL path;
**none** = unauthenticated.

## ADDED Requirements

### Requirement: Versioned API routing

All HTTP endpoints of the service MUST live under the `/v1` prefix. The service MUST be API-only
(no HTML UI). A request to an unknown path or an unsupported method MUST be answered with a
Problem+JSON response, never an HTML page.

#### Scenario: Unknown path

- GIVEN the service is running
- WHEN a client sends `GET /v1/does-not-exist`
- THEN the response status is 404, content type `application/problem+json`, and `code` is `not_found`

#### Scenario: Unsupported method

- GIVEN `GET /v1/health` exists
- WHEN a client sends `DELETE /v1/health`
- THEN the response status is 405, `code` is `method_not_allowed`, and an `Allow` header lists the supported methods

#### Scenario: No unversioned API surface

- GIVEN the route table
- WHEN all registered routes are listed
- THEN every route path starts with `/v1/`

### Requirement: RFC 9457 Problem+JSON errors with stable codes

Every non-2xx response produced by the service MUST be `application/problem+json` carrying the
members `type`, `title`, `status`, `detail`, `code` and `request_id`. `code` MUST be a stable,
machine-readable, non-localized snake_case string drawn from the catalogue below; it MUST NOT vary
with `Accept-Language` or any locale setting. Validation failures MUST add an `errors` object
mapping field names to arrays of messages. Responses MUST NOT contain stack traces, SQL, file
paths, or secret material in any environment, including with `APP_DEBUG=true`.

Code catalogue (status -> code): 400 `api_key_in_query` (a key passed in the query string);
401 `unauthenticated`, `key_expired`, `key_revoked`,
`client_secret_invalid`, `client_secret_expired`; 403 `insufficient_scope`, `origin_not_allowed`,
`secret_key_origin_forbidden`, `forbidden`; 404 `not_found`; 405 `method_not_allowed`; 409
`invalid_state`, `already_connected`, `conversation_closed`, `config_in_use`, `config_not_synced`;
413 `payload_too_large`; 422 `validation_failed`, `provider_credential_missing`,
`llm_credential_missing`; 429 `rate_limited`, `provider_concurrency_limited`; 500
`internal_error`; 502 `provider_error`; 503 `service_unavailable`.

The catalogue is closed. An HTTP error whose status has no code of its own is answered with the closest
code: 408 as 503 `service_unavailable`, 502 and 504 as 502 `provider_error`, any other 4xx (415
included) as 422 `validation_failed`, any other 5xx as 500 `internal_error`.

#### Scenario: Validation error shape

- GIVEN an authenticated SK request creating a resource with an invalid body
- WHEN the service rejects it
- THEN status is 422, `code` is `validation_failed`, and `errors` maps each invalid field to a non-empty array of messages

#### Scenario: Unhandled exception is opaque

- GIVEN `APP_DEBUG=true` and a handler that throws a `RuntimeException` containing a secret string
- WHEN the endpoint is called
- THEN status is 500, `code` is `internal_error`, and the body contains neither the exception message, a stack trace, nor the secret string

#### Scenario: Codes are not localized

- GIVEN a request with `Accept-Language: it`
- WHEN an error is returned
- THEN `code` equals the same English snake_case value as without the header

### Requirement: Request identification

Every response MUST carry an `X-Request-Id` header. If the request supplies `X-Request-Id`
matching `^[A-Za-z0-9._-]{1,64}$` the service MUST echo it; otherwise it MUST generate a new
unique value. The same value MUST appear as `request_id` in every Problem+JSON body and in the log
context of the request.

#### Scenario: Supplied id is echoed

- GIVEN a request with header `X-Request-Id: abc-123`
- WHEN the response is returned
- THEN the response header `X-Request-Id` is `abc-123`

#### Scenario: Invalid id is replaced

- GIVEN a request with `X-Request-Id` containing spaces or longer than 64 characters
- WHEN the response is returned
- THEN the response header is a newly generated value that differs from the supplied one

#### Scenario: Error body matches header

- GIVEN any request that results in an error
- WHEN the response is returned
- THEN `request_id` in the body equals the `X-Request-Id` response header

### Requirement: Health endpoints

Auth: none. `GET /v1/health` (liveness) MUST return 200 with exactly `{"status":"ok"}` without
touching the database or Redis. `GET /v1/health/ready` (readiness) MUST verify PostgreSQL and
Redis connectivity and return 200 `{"status":"ok","checks":{"database":"ok","redis":"ok"}}`; when
any registered provider's adapter ingests events by callback, `checks` MUST also carry `public_base_url`
(`ok` when `HRA_PUBLIC_BASE_URL` is an http(s) address with a host, `failed` otherwise); when
any dependency fails it MUST return 503 with Problem+JSON `code` `service_unavailable` and a
`checks` object naming each dependency as `ok` or `failed`. Health values are machine-facing and
MUST NOT be localized. Responses MUST NOT expose versions, hostnames or connection strings.

#### Scenario: Liveness

- GIVEN the service is running
- WHEN `GET /v1/health` is called without credentials
- THEN status is 200 and the body is exactly `{"status":"ok"}`

#### Scenario: Readiness ok

- GIVEN PostgreSQL and Redis are reachable
- WHEN `GET /v1/health/ready` is called
- THEN status is 200 and `checks.database` and `checks.redis` are `ok`

#### Scenario: Readiness with Redis down

- GIVEN Redis is unreachable
- WHEN `GET /v1/health/ready` is called
- THEN status is 503, `code` is `service_unavailable`, `checks.redis` is `failed` and `checks.database` is `ok`

#### Scenario: Liveness survives dependency failure

- GIVEN PostgreSQL is unreachable
- WHEN `GET /v1/health` is called
- THEN status is 200

### Requirement: Account scoping of all data

Account is the only tenant key. Every table except platform catalogues (`llm_models`) MUST carry
`account_id`, and every query on an account-owned model MUST be scoped by the authenticated
account through a global scope. A query on an account-owned model with no authenticated account
context MUST fail closed (return nothing or throw), never return unscoped rows. A request for a
resource owned by another account MUST be answered exactly as a nonexistent resource (404
`not_found`), never 403. `account_id` MUST NOT be mass-assignable and MUST NOT be accepted from
request input.

#### Scenario: Cross-account read

- GIVEN account A owns avatar config `X` and account B has a valid SK
- WHEN B calls `GET /v1/avatar-configs/X`
- THEN status is 404 and `code` is `not_found`

#### Scenario: Cross-account write

- GIVEN account A owns conversation `C`
- WHEN account B sends `POST /v1/conversations/C/end`
- THEN status is 404 and conversation `C` is unchanged

#### Scenario: Fail closed without context

- GIVEN rows exist for several accounts and no account is bound to the request context
- WHEN an account-owned model is queried
- THEN no rows are returned (or an exception is thrown) and no row from any account is exposed

#### Scenario: account_id in input is ignored

- GIVEN an authenticated SK request body that includes `"account_id": "<other account>"`
- WHEN the resource is created
- THEN it is owned by the authenticated account and the supplied value is ignored or rejected with 422

### Requirement: Collection pagination convention

Every list endpoint over a growing collection MUST accept `limit` (integer 1-100, default 25) and
`cursor` (opaque string), MUST return `{"data":[...],"next_cursor":<string|null>}`, and MUST order
results deterministically (`created_at` descending, id as tiebreaker). An out-of-range `limit` or a
malformed `cursor` MUST yield 422 `validation_failed`. Fixed-size lists (`/v1/provider-credentials`,
`/v1/llm-models`, `/v1/llm-credentials`) are exempt: they return every entry as `{"data":[...]}`.

#### Scenario: Fixed-size list is not paginated

- GIVEN the four provider credential entries
- WHEN `GET /v1/provider-credentials` is called
- THEN `data` holds all four entries and no `next_cursor` member is present

#### Scenario: Default page

- GIVEN 30 resources exist
- WHEN a list endpoint is called with no parameters
- THEN `data` has 25 items and `next_cursor` is a non-null string

#### Scenario: Following the cursor

- GIVEN a first page with a non-null `next_cursor`
- WHEN the list is requested with that cursor
- THEN the remaining items are returned without duplicates and `next_cursor` is null

#### Scenario: Invalid limit

- GIVEN a list endpoint
- WHEN `limit=500` is sent
- THEN status is 422 and `code` is `validation_failed`

### Requirement: OpenAPI publication and drift gate

The service MUST publish its OpenAPI 3.1 document, generated by Scramble, committed at
`service/openapi.json`. The document MUST cover every `/v1` endpoint, declare the security schemes
(secret key bearer, publishable key header, client secret bearer, provider path token) and attach
the correct scheme to each operation. CI MUST regenerate the document and fail when it differs from
the committed file, and MUST run Spectral against it and fail on any error or warning.

#### Scenario: Every route is documented

- GIVEN the route table and the committed `openapi.json`
- WHEN each registered `/v1` route is compared with the document paths
- THEN every route and method appears in the document

#### Scenario: Auth is declared per operation

- GIVEN the committed document
- WHEN the operations of `POST /v1/conversations`, `POST /v1/client/conversations/{id}/connect` and `GET /v1/health` are inspected
- THEN the first requires the secret key scheme, the second requires publishable key plus client secret, and the third declares no security

#### Scenario: Drift fails the build

- GIVEN a route is added without regenerating `openapi.json`
- WHEN the CI drift step runs `scramble:export` and diffs the result
- THEN the step exits non-zero

#### Scenario: Lint is clean

- GIVEN the committed document
- WHEN Spectral runs with the repository ruleset
- THEN it reports zero errors and zero warnings

### Requirement: Boundary rules enforced by architecture tests

The test suite MUST include architecture tests that fail when (a) source under `service/app`,
`service/config`, `service/database`, `service/routes` or `sdk/src` contains any host-product
term (the superset of the `CLAUDE.md` list and this one: `beai`, `participant`, `candidate`,
`competency`, `bars`, `interview`, `project`, `organization`, `assessment`, `score`,
`tenant-of-beai`; case-insensitive, matched on word boundaries, plurals included) or a reference
to a BEAI namespace, and (b) any
class outside provider adapter classes and the single provider registry branches on a provider
name. The arch test file itself is exempt from (a).

#### Scenario: Host vocabulary is rejected

- GIVEN a source file under `service/app` containing the word `candidate`
- WHEN the architecture suite runs
- THEN it fails and names the file and the term

#### Scenario: Provider branching outside adapters is rejected

- GIVEN a service class outside the adapters containing `if ($provider === 'tavus')`
- WHEN the architecture suite runs
- THEN it fails and names the class

#### Scenario: Clean tree passes

- GIVEN the shipped source tree
- WHEN the architecture suite runs
- THEN it passes

### Requirement: Configuration and secrets bootstrap

Configuration MUST be read through `config/hr-avatar.php` with the `HRA_` env prefix. In any
environment other than `testing`, the application MUST refuse to boot when `HRA_JWT_SECRET` is
missing or shorter than 32 bytes. Secrets MUST NOT appear in logs, health output, error bodies or
the OpenAPI document.

#### Scenario: Missing JWT secret

- GIVEN `APP_ENV=production` and `HRA_JWT_SECRET` unset
- WHEN the application boots
- THEN boot fails with an error naming `HRA_JWT_SECRET` but not any secret value

#### Scenario: Short JWT secret

- GIVEN `HRA_JWT_SECRET` of 16 bytes outside the testing environment
- WHEN the application boots
- THEN boot fails

### Requirement: Local runtime topology and CI gates

The repository MUST ship its own `docker-compose.yml` for local development with these services:
PostgreSQL 17, Redis 8, `api`, `worker` (`queue:work`) and `scheduler` (`schedule:work`). CI MUST
run the Pest suite with `--coverage --min=90`, the OpenAPI drift step, Spectral lint and the
architecture tests, and MUST fail if any step fails. Authentication/keys, webhook signing and the
conversation state machine MUST be held to at least 95% line coverage by a dedicated CI check.

#### Scenario: Compose services

- GIVEN `docker-compose.yml`
- WHEN its service names are listed
- THEN they include postgres, redis, api, worker and scheduler

#### Scenario: Coverage gate

- GIVEN total service coverage of 89%
- WHEN CI runs the test step
- THEN it fails

#### Scenario: High-integrity zones

- GIVEN coverage of the auth/keys, signature and conversation state machine classes is 94%
- WHEN the high-integrity coverage check runs
- THEN it fails

### Requirement: Documentation and deployment config as code

The repository MUST contain an English `README.md`, architecture notes, and API usage examples
for the service and the SDK. Railway configuration MUST be three files, one per service:
`service/railway.json` (API service: Dockerfile build, pre-deploy `php artisan migrate --force`
followed by `php artisan db:seed --class=LlmModelSeeder --force`), `service/railway.worker.json`
(`queue:work`) and `service/railway.scheduler.json` (`schedule:work`); `docs/railway.md` MUST
describe the topology including PostgreSQL and Redis. `docs/railway.md` MUST list the required
environment variables (`APP_KEY`, `DB_URL`, `REDIS_URL`, `HRA_JWT_SECRET`, `HRA_PUBLIC_BASE_URL`,
and the optional platform provider keys `TAVUS_API_KEY`, `HEYGEN_API_KEY`, `CARTESIA_API_KEY`,
`ELEVENLABS_API_KEY`). No Railway command is ever executed by this change. Documentation MUST also
state that conversation data retention/purge is out of scope (see `conversations`).

#### Scenario: Railway files are valid

- GIVEN `service/railway.json`, `service/railway.worker.json` and `service/railway.scheduler.json`
- WHEN they are parsed
- THEN each is valid JSON; the API file's pre-deploy command runs `php artisan migrate --force` and then the `LlmModelSeeder` seed; the worker file starts `queue:work`; the scheduler file starts `schedule:work`

#### Scenario: Env documentation completeness

- GIVEN `docs/railway.md`
- WHEN the environment variable table is read
- THEN it names every variable listed above and marks the four provider keys optional

#### Scenario: Docs are boundary-clean

- GIVEN the README and docs files
- WHEN scanned for host-product terms
- THEN none are found
