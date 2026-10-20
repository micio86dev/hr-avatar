# Apply progress: hr-avatar-engine

Mode: Strict TDD (Pest, `cd service && php artisan test`). Delivery: auto-chain, feature-branch-chain
(local commits on `feature/extract-avatar-service`, nothing pushed).

Local environment notes (apply for every slice):

- Host port 55432 is held by another project's container, so the local stack is started with
  `HRA_DB_PORT=55442 docker compose up -d postgres redis` and every Pest run uses `DB_PORT=55442`.
  The committed defaults stay 55432/56379; the compose file reads `HRA_DB_PORT` / `HRA_REDIS_PORT`.
- The phpredis extension (`pecl install redis`, 6.3.0) was installed on the host: Laravel's `redis`
  client needs it, the Dockerfile and CI install the same extension, and no `predis` substitute was used.

## Slice 1: Service scaffold core (tasks 1.1-1.10) - complete

Commit subject: `feat(service): scaffold Laravel 13 API with Problem+JSON errors, request ids and health endpoints`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 1.1 | n/a (preflight) | n/a | PHP 8.5.7, Composer 2.10.1, pcov, pdo_pgsql, Docker Compose v5.1.3, Bun 1.4.2 | redis extension was missing: installed with pecl |
| 1.2 | n/a (generation) | n/a | `composer create-project laravel/laravel service "^13.8"`, web surface removed | skeleton users model, migrations, auth/session config removed (API-only, no tables yet) |
| 1.3 | `tests/Unit/ComposerPinsTest.php` | 3 of 8 failed (scramble, jwt, larastan absent) | 8/8 | Pest itself had to be installed first to run the test |
| 1.4 | `tests/Feature/StrayRequestsTest.php` | real cURL error 6 instead of the guard exception | 2/2 | `Http::preventStrayRequests()` in `tests/Pest.php` |
| 1.5 | `tests/Feature/DatabaseConnectionTest.php` | auth failure against the wrong server on 55432 (stack down for this project) | 2/2 | compose with the `hra-pgdata` volume, init script |
| 1.6 | `tests/Feature/BootGuardTest.php` | 7 of 9 failed | 9/9 | guard in `AppServiceProvider::boot()` |
| 1.7 | `tests/Feature/RequestIdTest.php` | header absent, 3+ failures | 7/7 | middleware is global (prepend) so 404/405/413 carry the id; route name joins the log context on `RouteMatched` |
| 1.8 | `tests/Unit/ProblemTest.php`, `tests/Feature/ProblemRendererTest.php` | classes missing; renderer returned `application/json`; docs/storage/dev-tools routes outside `/v1` | 56/56 and 17/17 | Scramble default routes ignored, dev tools off, storage serving off, unused s3/public disks removed |
| 1.9 | `tests/Feature/HealthTest.php` | 404 on all routes | 8/8 | real broken ports for the dependency-failure cases |
| 1.10 | lint + analyse | `phpstan` had no config; pint reported 2 files | pint passed, phpstan level 8 passed | skeleton `filesystems.php` simplified instead of suppressing a finding |

Triangulation: error mapping, request-id validity and health are datasets with at least two cases each.
Layers: Unit (ComposerPins, Problem), Feature (everything else).

### Work Unit Evidence (slice 1)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test` : 116 passed, 464 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 % total, exit 0 |
| Static analysis / style | `composer run analyse` (larastan level 8) and `composer run lint` (pint): both passed |
| Runtime harness (H-HEALTH) | `php artisan serve --port=8100`: `GET /v1/health` 200 `{"status":"ok"}`; `GET /v1/health/ready` 200 with database and redis ok; `GET /v1/nope` with `Accept: text/html` 404 `application/problem+json`, `X-Request-Id: harness-1` echoed |
| Rollback boundary | revert `service/` (new tree) and the tick marks in `tasks.md` |

### Deviations (slice 1)

- Request id middleware is global (prepended) rather than per route group: required for 404/405/413 responses to carry `X-Request-Id`.
- Authored size is about 1,260 lines (skeleton `config/*` untouched files, `composer.lock`, Scramble published config and `.gitignore` files excluded), well above the ~380 the task forecast. The scaffold is one cohesive unit; no test, comment or blank line was removed to fit. Recommend `size:exception`.
- `ApiProblem` exposes `errorCode` (not `code`) because `Exception::$code` is an int.
- `config/hr-avatar.php` holds only `jwt_secret` in slice 1. Task 1.6 lists the token claims, rate limits, release delay, duration ceiling, Tavus retry and catalogue TTL as well; the Guardian Angel review rejected them as speculative configuration (and a provider name in core config), so each moves to the slice that reads it. `.env.example` still documents the variable names.
- `composer install` / `package:discover` boots the app, so outside `testing` it needs `HRA_JWT_SECRET` set (the Dockerfile uses `--no-scripts`; the CI workflow sets a dummy secret on the job).
- Databases are `hra` and `hra_test` (not `hr_avatar`/`hr_avatar_test`) and compose has no `name:`/explicit volume name: the Guardian Angel review treated the product name in a database or project name as a rule violation. The named volume key stays `hra-pgdata` (`HRA_` prefix family).
- Guardian Angel findings fixed: a client error without a catalogue entry now answers 422 `validation_failed` (not 500); compose binds Postgres and Redis to 127.0.0.1; skeleton config trimmed to what the service uses (pgsql + redis, no mail/services/sqlite/mysql, redis defaults for cache and queue); dead `Controller` removed; `Problem::make` lets core members win over `extra`; the request id pattern ends in `\z` (a trailing newline was accepted); a failed readiness probe logs the dependency name only; the boot guard stays in slice 1 because the spec's configuration-bootstrap requirement (and task 1.6) places it there.
- Skeleton `failed_jobs`/`jobs` migrations were removed with the web surface; the queue worker slices add what they need.

Slice 1 commit: `c8788a2`.

## Slice 2: Scaffold tooling (tasks 2.1-2.7) - complete

Commit subject: `build(service): add OpenAPI export with drift gate, Spectral lint, Dockerfile, compose services, CI workflow and boundary scan`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 2.1 | `tests/Arch/VocabularyScannerTest.php`, `tests/Arch/BoundaryTest.php` | `VocabularyScanner` not found (28 errors) | 28/28 | scanner lives in `tests/Arch` (self-exempt, it must name the terms) and tokenizes identifiers (snake/camel/acronym); British spellings are banned too so `candidate_id` is caught and `projection` is not; the skeleton tree was already clean |
| 2.2 | `tests/Feature/OpenApiTest.php` | no `security` key on health, version `0.0.1`, no 503 response, no schemes | 14/14 | transformers in `App\Http\OpenApi\{SecurityDocumentation,DocumentIdentity}`; no document-wide security requirement |
| 2.3 | `tests/Feature/OpenApiDriftTest.php` | `service/openapi.json` not committed | 2/2 | export is identical under a different `APP_URL`/`APP_NAME` (verified); title and server are pinned by `DocumentIdentity` |
| 2.4 | `cd tools/spectral && bun run lint` | script not found, then 6 warnings (contact, description, operation descriptions, tags) | zero warnings at `--fail-severity=warn` | fixed the document (docblocks, info, tags), not the ruleset |
| 2.5 | `tests/Feature/DockerfileTest.php` | no Dockerfile (9 errors) | 9/9 | the printf-built fpm config tripped the no-frontend scan (`\npm`), so it became `docker/php-fpm.conf` |
| 2.6 | `tests/Feature/ComposeTopologyTest.php` | 8 of 11 failed (services missing) | 11/11 | reads `docker compose config --format json`, no YAML dependency added |
| 2.7 | `tests/Feature/CiWorkflowTest.php` | workflow missing (8 errors) | 8/8 | see deviations |

### Work Unit Evidence (slice 2)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='OpenApi|Arch|Compose|CiWorkflow|Dockerfile'` : passed; full suite 189 passed, 640 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 % |
| Static analysis / style | larastan level 8 and pint: passed |
| Runtime harness | `cd tools/spectral && bun install --frozen-lockfile && bun run lint` : "No results with a severity of 'warn' or higher found!" |
| Rollback boundary | revert `tools/`, `.spectral.yaml`, `.github/`, `service/openapi.json`, `service/Dockerfile`, `service/docker/*` (except `initdb`), compose app services, `service/app/Http/OpenApi`, the tooling tests |

### Deviations (slice 2)

- Actions are pinned to major version tags (`actions/checkout@v4`, `shivammathur/setup-php@v2`, `oven-sh/setup-bun@v2`) per the session brief, not to 40-hex SHAs as task 2.7 wrote; `CiWorkflowTest` asserts the tag form. No SHA lookup and no GitHub contact happened.
- Scramble 0.13 API was checked against the installed vendor source (and BEAI's usage) rather than context7, which is not available to the executor.
- The spec and design list a `providerToken` security scheme (a "provider path token"). It is NOT declared: OpenAPI has no path-located credential, so any scheme would tell generated clients to send the token in a header, and the Guardian Angel review rejected that as a dishonest contract. Three schemes exist (`secretKey`, `clientSecret`, `publishableKey`); slice 30 documents `{token}` as a path parameter of the inbound route. The spec/design wording should be amended (decision for the orchestrator). The schemes attach by the `auth.secret` / `auth.client` middleware aliases (created by slices 4 and 6).
- No YAML parser was added: the compose test shells out to `docker compose config`; the workflow test uses text assertions.
- Docker images were not built (4 GB Docker Desktop); the Dockerfile is covered by structural tests only, and the CI workflow has not been run (no remote contact).
- Authored size about 1,050 lines (excludes `bun.lock`, `openapi.json`, bookkeeping): well above the ~400 forecast. Cohesive tooling unit; recommend `size:exception`.

Slice 2 commit: `a4fcfdc` (the orchestrator's accidental commit 5838515 was amended into it).

## Slice 3: Accounts and keys domain (tasks 3.1-3.5) - complete

Commit subject: `feat(service): add account scoping, key generation and resolution, and bootstrap commands`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 3.1 | `tests/Feature/AccountScopeTest.php`, `tests/Feature/ApiKeySchemaTest.php` | `Account` class missing (20 errors) | 20/20 | the CHECK test caught a real defect: `jsonb_typeof(NULL) = 'array'` is NULL, which a CHECK accepts, so a publishable key with no origins passed until `allowed_origins IS NOT NULL` was added |
| 3.2 | `tests/Unit/KeyGeneratorTest.php` | classes missing (dataset error) | 19/19 | `prefixOf` throws on a malformed key (RED first); `parse` is pure string work |
| 3.3 | `tests/Unit/KeyResolverTest.php` | `KeyResolver` missing (14 errors) | 14/14 | hash first, state second; revoked wins over expired; lookup is unscoped on purpose (the key is how the account becomes known) |
| 3.4 | `tests/Feature/BootstrapCommandsTest.php` | commands missing (12 errors) | 12/12 | `{--scopes=*}` is an array option in Laravel's signature syntax; the default is applied in code. `KeyIssuer` is shared with the key endpoints of slice 5 |
| 3.5 | `tests/Feature/ApiKeySerializationTest.php`, `tests/Feature/SecretExposureTest.php` | `key_hash` was serialized | 3/3 | the exposure test uses a `MessageLogged` listener over the real logger, stronger than `Log::spy` |

Triangulation: key formats and constraint violations are datasets; resolver states each have a positive and a negative case.
The vocabulary scan caught the word "candidate" in the resolver's own comments and variable names (a lookup term); renamed.

### Work Unit Evidence (slice 3)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='AccountScope|KeyGenerator|KeyResolver|Bootstrap|IssueKey'` passed; full suite 259 passed, 793 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 % (Keys and Accounts classes all 100 %) |
| Static analysis / style | larastan level 8 and pint passed |
| Runtime harness | `php artisan migrate --force` then `php artisan hra:bootstrap-account Demo` on the local stack printed `hra_sk_live_...` once; the row holds only prefix `hra_sk_live_0177c108`, the hash and scopes `["*"]` |
| Rollback boundary | revert the accounts/api_keys migrations, `app/Accounts`, `app/Keys`, `app/Models`, the two commands and `ReadsInput`, factories, and their tests |

### Deviations (slice 3)

- Authored size about 1,285 lines against a ~330 forecast (tests are about 650 of them). Cohesive unit; recommend `size:exception`.
- `CurrentAccount` has no `set()` yet (removed as dead code at 87 % coverage); slice 4 adds the setter with the authentication middleware that uses it.
- `rotated_from_id` is added as a plain column and its self foreign key in a second statement (Postgres needs the primary key to exist first).

Slice 3 commit: `83fe7cc`.

## Slice 4: Secret-key auth chain (tasks 4.1-4.5) - complete

Commit subject: `feat(service): add secret-key authentication, scopes, query-key rejection and rate limiting`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 4.1 | `tests/Feature/RejectKeyInQueryTest.php` | middleware missing (45 tests failing together with 4.2-4.4) | 13/13 | the clean-query dataset first contained `key_prefix=hra_sk_live_...`, which the rule (any value starting with a key prefix) correctly rejects; the case was wrong, not the rule |
| 4.2 | `tests/Feature/AuthenticateSecretKeyTest.php` | middleware missing | 18/18 | account is bound with `CurrentAccount::runAs` around `$next`, so nothing leaks to the next request; failed-auth budget lives in `Keys\FailedAuthLimiter` (shared with the client chain of slice 6) |
| 4.3 | `tests/Feature/RequireScopeTest.php` | middleware missing | 6/6 | `scope:conversations:write` (Laravel splits the alias on the first colon only) |
| 4.4 | `tests/Feature/RateLimitTest.php` | default config missing | 5/5 | first attempt shared one bucket across keys: Laravel's priority list ran `throttle` before authentication, so the key id was not yet known. Fixed with `prependToPriorityList(before: ThrottleRequests)` |
| 4.5 | `phpunit.high-integrity.xml` + `CiWorkflowTest` | config file missing; workflow step missing | 309 tests, 100.0 % over Keys, Accounts, Middleware | CI step added after the main coverage step |

Extra findings from the harness, fixed with a RED test each: inside the real `api` route group Laravel's priority sort ran authentication ahead of `RejectKeyInQuery`, so `?api_key=` answered 401 instead of 400; `RejectKeyInQuery` is now ordered ahead of authentication in the priority list. An implicit-binding test proves the account is bound before `SubstituteBindings` (removing the priority entry makes it fail).

### Work Unit Evidence (slice 4)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='RejectKeyInQuery|AuthenticateSecretKey|RequireScope|RateLimit'` : 46 passed; full suite 309 passed, 1008 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % |
| Static analysis / style | larastan level 8 and pint passed; `openapi.json` unchanged |
| Runtime harness (H-KEY, temporary route reverted) | no key 401 `unauthenticated`; key with `conversations:read` 403 `insufficient_scope`; wildcard key 200 with `X-RateLimit-Limit: 600`, `X-RateLimit-Remaining: 597`; live key + `Origin` 403 `secret_key_origin_forbidden`; `?api_key=x` 400 `api_key_in_query` |
| Rollback boundary | revert the three middlewares, `Keys\FailedAuthLimiter`, the `secret` limiter, the alias/priority lines in `bootstrap/app.php`, `phpunit.high-integrity.xml`, the workflow step and their tests |

### Deviations (slice 4)

- No named `RateLimiter::for('failed-auth')`: the failed-auth budget is checked by `FailedAuthLimiter` (manual hit counting from `config('hr-avatar.rate_limits.failed_auth')`), because a named limiter nobody applies as `throttle:` middleware would be dead code.
- Authored size about 565 lines (about 325 are tests), above the 400 budget. Cohesive security unit; recommend `size:exception`.
- Reminder carried from slice 3: `CLAUDE.md` now lists the two credential lookups as the only unscoped reads (the Guardian Angel review required an explicit exception).

## Slice 5: API key endpoints (tasks 5.1-5.4) - complete

Commit subject: `feat(service): add API key management with rotation, publishable keys and cursor pagination`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| (pre) | `tests/Arch/BoundaryTest.php` | merging the SDK made the scan see 5 roots (`sdk/src` now exists): "actual size 5 matches expected size 4" | `toHaveCount(5)` | the scan really covers `sdk/src` and finds nothing |
| 5.1 | `tests/Feature/PaginationTest.php` | `PaginationRequest` missing (all cases errored) | 22/22 | `PaginationRequest::page()` + `PagedCollection`; the cursor is validated to exactly `{created_at, id, _pointsToNextItems}` with a strict timestamp (a crafted cursor is bound into SQL, and `2026-02-30` or `yesterday` would reach Postgres) |
| 5.2 | `tests/Unit/OriginNormalizerTest.php` | class missing (22 failures) | 31/31 | default ports are dropped (`:443`), duplicates collapse, 1-20 enforced after normalization |
| 5.3 | `ApiKeyEndpointTest.php`, `ApiKeyScopeTest.php` | 404 on every route (38 failures) | 40/40 | `KeyIssuer::issue` now takes an account id plus origins, expiry and lineage; `IssuedApiKeyResource` is the only class that can carry `key` |
| 5.4 | `tests/Feature/ApiKeyRotationTest.php` | 404 / test-helper fixes (21 failures) | 26/26 | rotate runs in a transaction on a `lockForUpdate` row; revoke is a conditional `UPDATE ... WHERE revoked_at IS NULL` |
| exposure | `SecretExposureTest.php` | n/a (added after the endpoints) | passes | create, rotate, revoke, list and a refused create never leak a key or hash into a response or a log |

Triangulation: scopes, modes, overlaps, malformed origins and cursors are datasets; every refusal asserts that nothing was created or changed.

### Work Unit Evidence (slice 5)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ApiKey|OriginNormalizer|Pagination'` : 119 passed; full suite 430 passed, 1342 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 % total; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % |
| Static analysis / style | `composer run lint` (pint) and `composer run analyse` (larastan level 8) passed |
| OpenAPI | `php artisan scramble:export` regenerated `openapi.json` (4 operations); drift test green; `cd tools/spectral && bun run lint` : no results at `warn` or higher |
| Runtime harness (H-KEY) | `php artisan serve --port=8100`, `hra:bootstrap-account`, then curl: create publishable key (origin normalized to `https://app.example.com`), list `limit=1` (`next_cursor` set), rotate with overlap 60 (`rotated_from_id` set), revoke twice (same `revoked_at`), rotate revoked 409, `?cursor=garbage` 422 `validation_failed` |
| Rollback boundary | `ApiKeyController`, `CreateApiKeyRequest`, `RotateApiKeyRequest`, `PaginationRequest`, `ApiResource`, `ApiKeyResource`, `IssuedApiKeyResource`, `PagedCollection`, `OriginNormalizer`, the routes, the `KeyIssuer` signature change, their tests, `openapi.json` |

### Deviations and decisions (slice 5)

- Authored size about 1,250 lines (about 705 are tests; `openapi.json` and bookkeeping excluded), above the 400 budget. Four cohesive tasks; recommend `size:exception`.
- Route parameter is `{apiKey}` (implicit binding, so the account scope applies before the controller), not `{id}` as the spec writes informally.
- `mode` is required in the create body (the spec lists it without "optional"); `name` is not accepted (not in the spec body or resource).
- Rotation never extends a key: when the old key already expires before `now + overlap`, its own expiry is kept (the spec only says "set to now + overlap"). The successor does not inherit `expires_at`.
- Rotation and create check mode first (`403 forbidden`), then scopes (`403 insufficient_scope`); a malformed body is reported (`422`) before either.
- Default ports are dropped when normalizing an origin (the browser never sends `:443`, so an entry with it could never match).
- Error responses other than the ones listed above (401/404/429) are not yet documented in `openapi.json`; only the success shape, 422 and the per-operation 403/409 are. A shared Problem documentation transformer is a candidate for slice 31.
- `SecretExposureTest` gained the management-API case instead of a new file.
- Guardian Angel advisories: the rotate and revoke docblocks (and so the OpenAPI descriptions) now state that the successor inherits no expiry and that revoke needs no scope comparison (it only removes access); `hra:issue-key` de-duplicates `--scopes` like the endpoint (RED test first: 1 failed). Full suite after the amend: 431 passed, 1343 assertions.

## Slice 6: Client authentication and CORS (tasks 6.1-6.4) - complete

Commit subject: `feat(service): add client_secret JWT, client authentication chain and per-key CORS`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 6.1 | `tests/Unit/ClientSecretTest.php` | `App\Keys\ClientSecret` not found (23 errors) | 23/23 | ported from BEAI `SessionTokenMinterTest` (wrong secret/issuer/audience, missing claim, alg none, expired still parses); added HS512 with the same secret, tampered payload, exact claim set (no `mode`), the expiry instant, a redacting `__debugInfo` |
| 6.2 | `AuthenticateClientTest.php`, `ClientSecretBindingTest.php` | `Target class [auth.client] does not exist` (35 errors); later "authenticates before a route binding" got 404, not 401 | 36/36 | the priority-list RED: `appendToPriorityList` runs before the prepends, so `AuthenticateClient` landed after `SubstituteBindings`; it is now `prependToPriorityList(before: ThrottleRequests)` like the secret chain |
| 6.3 | `tests/Feature/ClientCorsTest.php` | preflight answered 404, no allow headers (11 failures) | 22/22 | `HandleCors` is replaced in place by `ClientCors` (so it stays behind `AssignRequestId` and a preflight carries a request id) |
| 6.4 | extends `RateLimitTest.php` | `Rate limiter [publishable] is not defined`, config null (4 failures) | 9/9 | one `foreach` registers both limiters, keyed by the key id the authentication middleware records |

Triangulation: origins, bearer shapes, key states and forged tokens are datasets; each refusal also asserts the status class it must have (a 401 feeds the failed-auth budget, a 403 does not).

### Work Unit Evidence (slice 6)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ClientSecret|AuthenticateClient|ClientCors|RateLimit'` : 90 passed; full suite 516 passed, 1595 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % (AuthenticateClient, ClientCors, ClientSecret, VerifiedClientSecret, MintedClientSecret all 100 %) |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8) passed; `openapi.json` unchanged (no real client route until slice 26) |
| Runtime harness | temporary `POST /v1/client/conversations/{conversation}/probe` under `auth.client` + `throttle:publishable` (reverted): preflight 204 with `Access-Control-Allow-Origin`, `-Methods: POST`, `-Headers`, `-Max-Age: 600`, `Vary: Origin`, `X-Request-Id`; real call 200 with `X-RateLimit-Limit: 300`, allow + expose headers; other origin 403 `origin_not_allowed` with no allow header; other conversation 403 `forbidden`; no bearer 401 `unauthenticated` |
| Rollback boundary | `ClientSecret`, `VerifiedClientSecret`, `MintedClientSecret`, `AuthenticateClient`, `ClientCors`, the `auth.client` alias and priority entry, the `HandleCors` replacement in `bootstrap/app.php`, the limiter loop and `publishable` limit, and their tests |

### Deviations and decisions (slice 6)

- Authored size about 965 lines (about 616 are tests), above the 400 budget. Four cohesive tasks over one security chain; recommend `size:exception`.
- `iss` and `aud` are class constants of `ClientSecret`, not `config('hr-avatar.jwt_issuer'|'jwt_audience')` as the design lists: nothing varies them, and the Guardian Angel review rejects configuration with no consumer. `config/hr-avatar.php` gains only `rate_limits.publishable`.
- `mint()` returns a small `MintedClientSecret` (token, jti, expiry) so slice 20 can put `client_secret_expires_at` in the response without recomputing the expiry; it is an extra class beside the two the task names.
- `aud` must be exactly `["hr-avatar-client"]` (a token with an extra audience is refused).
- Origin comparison is exact on the raw header (the stored list is canonical and browsers send canonical origins); the preflight echoes only a canonical origin, so `null` or a value with a path gets a 204 with no allow header.
- A request that fails before the allowlist (unknown publishable key, rate limit) carries no `Access-Control-Allow-Origin`, so a browser cannot read that 401/429; this follows the spec ("only after the allowlist check").
- `Vary: Origin` is set on every `v1/client/*` response, allowed or not.

## Slice 7: Credentials and LLM (tasks 7.1-7.5) - complete

Commit subject: `feat(service): add provider and LLM credentials, resolvers and the LLM model catalogue`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 7.1 | `tests/Feature/CredentialSchemaTest.php` | models/tables missing (8 errors, 1 failure) | 10/10 | a unique violation aborts the surrounding Postgres transaction, so the duplicate-insert assertions run inside `DB::transaction` (a savepoint) |
| 7.2 | `ProviderAuthTest`, `CredentialResolverTest`, `LlmCredentialResolverTest` | classes missing (17 errors) | 18/18 | `ProviderAuth` refuses `serialize()` (a job cannot carry a key), redacts in dumps and omits the key from JSON |
| 7.3 | `tests/Feature/ProviderCredentialEndpointTest.php` | 404 on every route (23 failures) | 29/29 | `updateOrCreate` answered 201 for a new row: the controller forces 200 |
| 7.4 | `tests/Feature/LlmEndpointsTest.php` | 404 on every route (20 failures) | 26/26 | `POST/PUT/PATCH/DELETE /v1/llm-models` answer 405 with `Allow: GET` for free (a GET-only route) |
| 7.5 | `tests/Feature/CredentialExposureTest.php` | one failure: the queue wraps the serialization refusal in a `RuntimeException` (test corrected, behaviour was right) | 8/8 | covers model serialization, queued job payload, every credential response and log line, a write that blows up, and the OpenAPI resource schemas |

BEAI tests for these classes (`LlmBindingResolverTest` etc.) belong to slice 8; the BEAI credential model is platform-wide and not reusable here.

### Work Unit Evidence (slice 7)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ProviderCredential|LlmCredential|LlmModel|LlmEndpoints|CredentialResolver|ProviderAuth|CredentialExposure|CredentialSchema'` : 103 passed; full suite 607 passed, 1936 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8) passed |
| OpenAPI | regenerated (`DB_PORT=55442 php artisan scramble:export`, needs the migrated dev database for model docs); drift test green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | `db:seed --class=LlmModelSeeder`, `serve --port=8100`: `PUT /v1/provider-credentials/cartesia` -> `{"provider":"cartesia","source":"account","last_four":"89ab",...}`; `GET` lists the four providers; raw column starts `eyJpdiI6...` (ciphertext) with `last_four` 89ab; unknown provider 404; `GET /v1/llm-models` lists `gemini-flash-lite`, `gemini-pro`; `PUT /v1/llm-credentials/gemini` + `GET`; `DELETE` twice -> 204 204 |
| Rollback boundary | three migrations, `app/Credentials`, three models and factories, `LlmModelSeeder`, three controllers, `PutCredentialRequest`, the four credential resources, the routes, the `credential_providers`/`platform_keys` config blocks, the `captureLogs` move into `tests/Pest.php`, and their tests |

### Deviations and decisions (slice 7)

- Authored size about 1,570 lines (about 767 are tests; migrations and `openapi.json` excluded from the count only where noted by tooling), above the 400 budget: five cohesive tasks (schema, resolvers, 2 endpoint families, sweep). Recommend `size:exception`.
- `CredentialResolver::for()` and `LlmCredentialResolver::for()` take an account id string (not an `Account` model) so connect and jobs, which hold only `account_id`, need no extra query; they bind the account with `runAs` themselves.
- `LlmModelSeeder` seeds two models, `gemini-flash-lite` (`gemini-3.1-flash-lite-preview`) and `gemini-pro` (`gemini-3.1-pro-preview`), provider `gemini`. BEAI's `gemini-3-flash-preview` is deliberately not seeded: its own seeder comment records that this thinking model stalls HeyGen's turn-based custom-LLM path. The catalogue file wins on every seed (a re-run restores `enabled`).
- `PUT /v1/llm-credentials/{llm_provider}` accepts any provider present in `llm_models`, enabled or not; `DELETE` is a 204 whatever the catalogue holds, so a credential cannot get stuck when a provider leaves the catalogue.
- Route parameters are `{provider}` and `{llmProvider}`: Scramble names the OpenAPI path parameter after the controller argument, and the existing "every route is documented" test compares the two.
- Unknown credential providers 404 through a `whereIn` route constraint, so an unauthenticated caller also gets 404 (not 401) for them; the provider list is public configuration.
- `ProviderAuth` refuses `serialize()`: a `dispatch()` of a job holding it fails with the queue's `RuntimeException` wrapping the refusal, and nothing is pushed.
- An account key that cannot be decrypted (rotated `APP_KEY`) makes the resolver throw rather than fall back to the platform key, which would bill the platform for the account's call.
- `last_four` is `null` for keys under 12 characters (`LastFour::of`, multibyte-safe).
- The list entries for provider credentials are plain arrays with a `CredentialSource` enum (documented in OpenAPI), not a resource: they describe providers, not rows.
- Common 401/404/429 Problem responses remain undocumented in `openapi.json` (see slice 5).

## Slice 8: Avatar config and ledger persistence, LLM binding (tasks 8.1-8.3) - complete

Commit subject: `feat(service): add avatar config and provider resource persistence with LLM binding resolution`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 8.1 | `tests/Unit/HasPrefixedIdTest.php` | trait not found; then `isValidUniqueId` undefined (8 errors) | 11/11 | the second RED is a real defect found while writing the test: `HasUlids` only accepts a bare ULID during route binding, which would make every `avc_` id a 404, so the trait overrides `isValidUniqueId` (prefix + ULID) |
| 8.2 | `AvatarConfigModelTest.php`, `ProviderResourceTest.php` | dataset could not load (`ResourceKind` missing), then 1 failure | 33/33 | jsonb does not keep key order (assertions compare order-insensitively); `JsonObject` cast writes `{}` for an empty object and refuses a list or scalar; DB CHECKs keep `config`, `persona`, `voice`, `provider_state` objects |
| 8.3 | `tests/Unit/LlmBindingResolverTest.php` | class not found (10 errors) | 10/10 | ported from BEAI `LlmBindingResolverTest` (unbound -> null, bound -> full binding, unresolvable -> null and never throws, undecryptable key -> null); the BEAI billing tri-state and `heygenConfigurationId` are not carried |

### Work Unit Evidence (slice 8)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='HasPrefixedId|AvatarConfigModel|ProviderResource|LlmBinding'` : 57 passed; full suite 664 passed, 2052 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8) passed; `openapi.json` unchanged (no route) |
| Runtime harness | N/A by task: persistence and value objects only. `migrate --force` applied both migrations to the local `hra` database, and a tinker create under a bound account produced `avc_01m5a523sxxc9exeb44yqzrdzy` (30 characters) with raw `config` `{}` (the row was deleted again) |
| Rollback boundary | migrations 100005-100006, `AvatarConfig`, `ProviderResource`, `App\AvatarProviders\{SyncStatus,ResourceKind}`, `Casts\JsonObject`, `HasPrefixedId`, `App\Llm`, two factories, their tests |

### Deviations and decisions (slice 8)

- Authored size about 880 lines (about 442 are tests), above the 400 budget. Three cohesive persistence tasks; recommend `size:exception`.
- Added beyond the task: a `JsonObject` cast and DB CHECKs (`jsonb_typeof = 'object'`) on the four structured columns. A plain `array` cast stores `{}` as `[]`, the defect BEAI's public API shipped and later fixed (AD13 names it for `metadata`); the config, persona, voice and provider state have the same exposure. Whoever renders these columns (slice 16) must turn an empty array back into an object.
- `avatar_configs.id` is `string(31)`, ids are lowercase (as `HasUlids` produces), 30 characters for `avc_`.
- `avatar_configs` has an `(account_id, external_ref)` index for the `external_ref` list filter (reconciliation item 38), added now because it is a table concern; the filter itself belongs to slice 16.
- `LlmBindingResolver::resolve()` returns null for both "no model bound" and "no usable key"; connect (slice 26) checks `llm_model_id` first to answer `422 llm_credential_missing` only for a bound config.
- `LlmBinding` keeps its key private (read through `apiKey()`), omits it from JSON and `get_object_vars`, and refuses `serialize()`, like `ProviderAuth` (BEAI only redacted `__debugInfo`). The Guardian Angel review caught the first version, whose public `apiKey` property was written out by `json_encode`: RED test first (2 failures), then the property went private. A cast to array still exposes private properties and `var_export` ignores `__debugInfo`; the architecture test banning `var_export`/`print_r` is slice 31's.
- The enums live in `App\AvatarProviders` (`SyncStatus`, `ResourceKind`), next to the contract they serve.

## Slice 9: Provider contract, registry, Mock (tasks 9.1-9.4) - complete

Commit subject: `feat(service): add the provider contract, registry and Mock adapter`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 9.1 | `tests/Unit/AvatarProviders/ProviderSessionTest.php` (ported from BEAI `ProviderTokenTest`), `ProviderExceptionTest.php`, `ProblemRendererTest.php` additions | datasets could not load (value objects missing); the renderer cases answered 500 instead of 502/429 | 99/99 with 9.2-9.4 | the BEAI `fromRef` provider guard became a constructor guard on `JoinMaterial`; the renderer maps `ProviderException` to 502 `provider_error` or, for a throttle, 429 `provider_concurrency_limited` |
| 9.2 | `ProviderRegistryTest.php` (+ `tests/Support/AvatarProviders/{StubProvider,CallbackStubProvider,PollStubProvider}`) | `ProviderRegistry` / `AvatarProvider` missing | green | ingestion modes per adapter come from stub adapters; registry data is `config('hr-avatar.providers')` only |
| 9.3 | `MockProviderTest.php` | `MockProvider` missing | green | state (ended flag, opening text) lives in the shared cache; proven against the real Redis in the harness below |
| 9.4 | `tests/Feature/AvatarProviders/StrayRequestsContractTest.php` | n/a (the guard is `Pest.php`'s `Http::preventStrayRequests()`); the test pins it for the adapter namespace | green | also asserts the shipped adapters make no request without a fake |

Extra contract test: `JoinMaterial::toArray()` keys must equal the members of the SDK's `JoinMaterial` interface (read from `sdk/src/api.ts`), so service and SDK cannot drift on `provider`, `transport`, `conversation_url`, `session_token`, `expires_at` (see `apply-progress-sdk.md`).

### Work Unit Evidence (slice 9)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='AvatarProviders|ProblemRenderer'` : 99 passed; full suite 741 passed, 2302 assertions, exit 0 |
| Coverage | `php artisan test --coverage --min=90` : 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` : 100.0 % |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8) passed; `openapi.json` unchanged; Spectral clean |
| Runtime harness | N/A by task (internal contract). Exercised against the local Redis through the container: `ProviderRegistry` lists `mock` (no credential), `start` returned `{"provider":"mock","transport":"mock","conversation_url":"mock://conversation/conv_harness","session_token":"mock_token_conv_harness","expires_at":"..."}`, `fetchSession` ended false then true after `teardown`, transcript line `Hi!` |
| Rollback boundary | `app/AvatarProviders` (everything in this slice, not `SyncStatus`/`ResourceKind`), the `providers` block in `config/hr-avatar.php`, the `ProviderException` branch in `ProblemRenderer`, `tests/Support/AvatarProviders`, and their tests |

### Deviations and decisions (slice 9)

- Authored size about 1,090 lines (about 545 are tests), above the 400 budget. Four cohesive tasks (value objects, registry, Mock, guard); recommend `size:exception`.
- **Contract change needing owner sign-off:** `start` takes the conversation id as its first argument, `start(string $conversationId, ConversationSpec, AvatarConfig, ProviderAuth, ?string $callbackUrl)`. The spec and design give `start` no conversation id, yet the Mock must return `mock_{I}` / `mock://conversation/{I}` and the LiveAvatar context is named `hra-{conversationId}-{ulid}`. `ConversationSpec` is "exactly" five members, so the id could not live there. Slices 10-14 and 26 must pass it; Tavus ignores it.
- `ProviderException` has a private constructor and three named constructors (`fromStatus`, `throttled`, `noResponse`) and no `previous` parameter: the exception that a failed HTTP call raised holds the request headers (the API key) and the response body, so it can never be attached. It implements `context()` so Laravel logs the status class only. A failure with no HTTP status reads `Provider request failed (no response)`, not the spec's `(status N)` form.
- `JoinMaterial.transport` is a `Transport` enum (`daily`, `liveavatar`, `mock`) as the spec fixes; it lives in `App\AvatarProviders`, not in any core namespace. `sessionToken` stays a public member (it is what the browser receives) and is redacted in dumps.
- Every adapter method takes a `ProviderAuth`, including the Mock, which has no credential. Slice 26 must hand a placeholder to a provider whose registry entry says `credential: false` (a `ProviderAuth::none()` or a nullable parameter; left open so that the real adapters keep a non-null type).
- The interface still has only the six runtime operations; `describe`, `validateConfig`, `syncRequired` (slice 15), `sync` (17) and `catalogue` (18) are added with their implementations, as the task notes require.
- The interface docblock says ProviderAuth comes last except that `start` is followed by its optional callback URL (the design's own signature; the review flagged the docblock, not the order). The config header comment now lists only what is still to come.
- Mock opening text is stored in the shared cache for a day (the transcript operation has no other way to see it).
- `LlmBindingResolver` docblock narrowed ("never throws" became "null instead of an exception, infrastructure failures still propagate") after the slice 8 review note.

## Slice 12: LiveAvatar registrars (tasks 12.1-12.2) - complete (LiveAvatar lane)

Commit subject: `feat(service): add LiveAvatar LLM and voice registrars over the provider resource ledger`

Lane note: slices 12-14 ran in the LiveAvatar lane, in parallel with the Tavus lane (slices 10-11, own
worktree and database `hra_test_tavus`). This lane used `DB_PORT=55442` with `hra_test`. The slice 10/11
boxes in `tasks.md` are untouched.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| (support) `LiveAvatarApi` | `tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarApiTest.php` | `LiveAvatarApi` not found (18 errors) | 18/18 | the one place that knows the host and the `X-API-KEY` header, and maps a transport failure / non-2xx to `ProviderException`; also reused by slices 13-14 |
| (support) `LiveAvatarLedger` | `LiveAvatarLedgerTest.php` | `LiveAvatarLedger` not found (12 errors) | 13/13 | find / record / forget over `provider_resources`, and `secret()` (find-or-create a LiveAvatar secret, dedupe a lost race); binds the account itself with `CurrentAccount::runAs` |
| 12.1 | `LiveAvatarLlmRegistrarTest.php` | class not found (25 errors) | 25/25 (3 failures on the first run were test defects: an unfaked PATCH path and the `Http::fake` stacking gotcha when one pattern is faked twice; fixed with sequences, no assertion weakened) | ported from BEAI `HeygenLlmRegistrarTest` and the `ProviderContractFixtureTest` secret/configuration cases |
| 12.2 | `LiveAvatarVoiceRegistrarTest.php` | class not found (27 errors) | 28/28 | ported from BEAI `HeygenVoiceRegistrarTest`; one extra test (lock timeout path) added after the coverage run showed the branch unexercised |

Triangulation: failure modes, verbs, base URL shapes and key switches are datasets; every refusal asserts the ledger and
`provider_state` are left clean.

### Work Unit Evidence (slice 12)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='LiveAvatar'`: 83 passed, 213 assertions, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --coverage --min=90`: 824 passed, 2515 assertions, total 100.0 %, exit 0 (baseline before the slice: 741 passed) |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 824 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` (pint) and `composer run analyse` (larastan level 8): passed; `OpenApiDrift` tests green (no route, `openapi.json` unchanged) |
| Runtime harness | N/A-WIRE by task: no route or process boundary yet; every LiveAvatar request is pinned with `Http::fake` (verb, URL, header, JSON body) and `Http::preventStrayRequests()` |
| Rollback boundary | `app/AvatarProviders/LiveAvatar/{LiveAvatarApi,LiveAvatarLedger,LiveAvatarLlmRegistrar,LiveAvatarVoiceRegistrar}.php` and `tests/Unit/AvatarProviders/LiveAvatar/*` |

### Deviations and decisions (slice 12)

- Authored size about 1,371 lines (about 431 app, 940 tests), above the 400 budget. Two registrars plus the two support classes
  both need; recommend `size:exception`.
- **Shape of the registrars (decision for slice 17):** `ensureConfiguration(AvatarConfig, LlmBinding, ProviderAuth): string` and
  `forget(AvatarConfig, ProviderAuth): void`; `ensureVoice(accountId, ProviderAuth, engine, vendorVoiceId): string`. The binding is
  a required argument, not resolved inside: a bound model whose key is missing must fail the sync, and treating a null binding as
  "unbound" would delete a working configuration. The sync job decides (`llm_model_id` null -> `forget`; bound -> resolve or fail).
- Failures raise `ProviderException` (generic message, no `previous`, status class in logs) instead of BEAI's `warning` arrays: the sync
  job retries and records a short `sync_error`. `forget` is the only method that never throws. Preconditions that send nothing (an
  unsupported engine, no vendor key, a bind lock that stayed busy) are raised as `ProviderException::noResponse()`, because
  `ProviderException` has no constructor for "not attempted" and the Tavus lane may touch that file. A reason code is a candidate for
  slice 17 / 26 if the operator needs to tell them apart; connect pre-checks credentials (reconciliation 17).
- LLM secret natural key = first 16 hex of the sha256 of the **model key** (the task text: "per (account, key fingerprint)"); the ledger's
  own `key_fingerprint` column is the LiveAvatar key. Vendor secret key = `{engine}:{vendorKeyFingerprint}`, voice key =
  `{engine}:{vendorKeyFingerprint}:{vendorVoiceId}`: a voice bound through a secret that holds a key the account has since replaced
  is never reused (LiveAvatar secrets are immutable). Consequence, not handled here: the old secret/voice stay in the provider as
  clutter; a cleanup sweep belongs to slice 17 (sync) if wanted.
- A PATCH of an existing configuration that answers 2xx without `data.id` keeps the stored id (the object is known to exist); BEAI
  required the id in the answer.
- The bind lock wait is a constructor argument (`lockWaitSeconds`, default 10), not configuration: nothing varies it in production, and
  the tests pass 0. Locks use the application cache (`Cache::lock`), shared by api and worker.
- The LLM configuration `display_name` is `hra-{avatarConfigId}`, the secret name `hra-llm-{fingerprint}`, the vendor secret name
  `hra-{engine}-{vendorFingerprint}`, the voice name `hra-{engine}-{vendorVoiceId}` cut to 64 characters. Names are labels for a
  human reading the LiveAvatar dashboard; LiveAvatar does not require them to be unique and the service never looks anything up by name.
- The vocabulary scan caught "BEAI" in a docblock (a ported comment); reworded.

Slice 12 commit: `c5cd8c7`.

## Slice 13: LiveAvatar adapter - start (tasks 13.1-13.2) - complete (LiveAvatar lane)

Commit subject: `feat(service): add LiveAvatar adapter start with context, token and cleanup`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 13.1 | `tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarPayloadTest.php` | `LiveAvatarPayload` not found (27 errors) | 27/27 | pure bodies; the "env widening path is gone" scenario is a source scan: the payload reads neither `config(` nor `env(` nor `extra_token_fields` |
| 13.2 | `LiveAvatarProviderStartTest.php` | `LiveAvatarProvider` does not exist (31 errors) | 31/31 (2 failures on the first run were test defects: a throwing fake is not recorded by `Http::recorded()`, and a second `Http::fake` for the same pattern does not replace the first; assertions rewritten to the observable behaviour) | ported from BEAI `HeygenProviderTest` and `ProviderContractFixtureTest`: context then token, token failure deletes the context, voice before anything billable, golden bodies pinned with `toBe`, key never leaks, registry entry |

Triangulation: every failure mode (500/429/422/timeout/missing id) is a dataset for both the context call and the token call;
cleanup is shown for the delete succeeding, 404, 5xx and timing out.

### Work Unit Evidence (slice 13)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='LiveAvatar'`: 141 passed, 323 assertions, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --coverage --min=90`: 882 passed, 2626 assertions, total 99.5 %, exit 0 (`LiveAvatarProvider` 90.0 %: the four slice 14 placeholders are uncovered by design; every other LiveAvatar class 100 %) |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 882 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed; `openapi.json` unchanged |
| Runtime harness | N/A-WIRE by task: no route consumes the adapter yet (connect is slice 26); the HTTP boundary is pinned with `Http::fake` + `Http::preventStrayRequests()` |
| Rollback boundary | `LiveAvatarPayload.php`, `LiveAvatarProvider.php`, the two `use`/`heygen` lines in `config/hr-avatar.php`, and `LiveAvatarPayloadTest` / `LiveAvatarProviderStartTest` |

### Deviations and decisions (slice 13)

- Authored size about 798 lines (about 297 app and config, 501 tests), above the 400 budget: one adapter `start` with its pure payload, the
  failure matrix and the registry entry. Recommend `size:exception`.
- **Config keys of a `heygen` avatar config** (decision for slice 15, `LiveAvatarFieldSpecs`): camelCase, as BEAI's field specs kept them:
  `avatarId`, `interactivityType` (`CONVERSATIONAL`/`PUSH_TO_TALK`), `videoQuality`, and for an external voice `voiceSpeed`,
  `voiceStability`, `voiceSimilarityBoost`, `voiceStyle`, `voiceUseSpeakerBoost`, `ttsModelName`. The voice itself is the config's
  top-level `voice {engine, voice_id}` (so BEAI's `voiceId`, `ttsEngine` and `ttsExternalVoiceId` keys do not exist). BEAI's
  `maxSessionDurationSec`, `videoEncoding` and the native voice knobs were never proven against a live call and are **not** sent;
  slice 15 should not declare them as settings that have an effect (or the allowlist must grow with a proof).
- The proven constants `interactivity_type: CONVERSATIONAL` and `video_settings.quality: low` stay as floors (they were in BEAI's
  demonstrated-working call, and its hotfix 0.22.1 existed because they were missing); a config value overrides them. BEAI's platform
  `avatar_id`/`voice_id`/`language` environment floor is gone: `avatarId` comes from the config only (slice 15 makes it required), and the
  language is the conversation's.
- A token answer without `data.session_id` is a failure (and the context is deleted); BEAI tolerated a null session id because its
  `provider_session_ref` was nullable. Here a session with no ref could never be torn down or polled.
- `JoinMaterial.expiresAt` is now + 3600 s: LiveAvatar documents no token lifetime and the answer carries no expiry. Open item:
  confirm the real lifetime; the SDK does not read `expires_at` yet.
- A config bound to a model whose configuration was never synced starts without `llm_configuration_id` (as BEAI did); the
  `config_not_synced` gate of slice 20 is what keeps that from happening.
- Until slice 14, `teardown`, `fetchTranscript`, `fetchSession` and `parseCallback` exist only to satisfy the contract and throw
  `LogicException`; they are untested placeholders that slice 14's RED tests replace. The adapter is registered as `heygen` in the shipped
  registry now, but nothing calls it before slice 26.
- Guardian Angel notes from slice 12 handled here: `isUnknownSecret` now documents the observed message it matches. The voice
  registrar's `noResponse()` for a precondition failure and the LLM secret name carrying a truncated hash of the model key
  (the vendor already holds the key itself; the label is not a credential) are kept and recorded above.

Slice 13 commit: `863028e`.

## Slice 14: LiveAvatar adapter - remaining operations (tasks 14.1-14.3) - complete (LiveAvatar lane)

Commit subject: `feat(service): add LiveAvatar teardown, transcript and session status`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 14.1 | `tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarTeardownTest.php` | the slice 13 placeholder threw `LogicException` in every case | 21/21 | ported from BEAI `HeygenProviderTest` teardown cases; the context delete reuses the slice 13 private method (now null-safe) |
| 14.2 | `LiveAvatarTranscriptTest.php` | `LogicException` instead of `TranscriptNotReady` / `TranscriptShapeError` / lines | 28/28 | ported from BEAI `reconcileTranscript` cases, rewritten for the `data.transcript_data` / `absolute_timestamp` shape the research found |
| 14.3 | `LiveAvatarSessionStatusTest.php` | `LogicException` | 21/21 (RED for the three files together: 62 tests, 0 passed) | `parseCallback` returns `[]` |

Triangulation: roles, timestamp shapes, malformed rows and answers, end reasons and failure statuses are datasets.

### Work Unit Evidence (slice 14)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='LiveAvatar'`: 204 passed, 436 assertions, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --coverage --min=90`: 944 passed, 2736 assertions, total 100.0 %, every LiveAvatar class 100.0 %, exit 0 |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 944 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed; Scramble drift tests (`--filter=OpenApiDrift`) 2 passed; `openapi.json` unchanged |
| Runtime harness | N/A-WIRE by task: no route or process consumes these operations until slices 23-29 (reconciler, release job, poller); each request is pinned with `Http::fake` (verb, URL, header, body) under `Http::preventStrayRequests()` |
| Rollback boundary | the `teardown`, `fetchTranscript`, `fetchSession`, `parseCallback` bodies and the timestamp helpers in `LiveAvatarProvider.php` (back to the slice 13 placeholders), and the three test files |

### Deviations and decisions (slice 14)

- Authored size 476 changed lines (119 added and 7 removed in the adapter, 350 in tests): slightly above the 400 budget for three cohesive
  operations of one class; recommend `size:exception`.
- **Roles:** `user` and `avatar` pass through as the spec says; `assistant` and `agent` are also read as the avatar, because BEAI's
  fixtures used `assistant` and the vocabulary of the transcript endpoint was never confirmed. BEAI's `candidate` role is dropped
  (host vocabulary) and is now a shape error, like any other unknown role. If a live check shows only `user`/`avatar`, the two
  synonyms can go.
- **Timestamps (assumptions, flagged `ponytail:` in the code):** `absolute_timestamp` may be epoch milliseconds, epoch seconds
  (below 1e11), a numeric string or an ISO 8601 string; `relative_timestamp` is read as milliseconds. A value that is none of these
  is null (the contract allows null), never invented. BEAI's `time_ms` field was wrong per the research and is not read.
- `session_active` is read from `data.session_active` (same envelope as `transcript_data`); only the first transcript page is read,
  since `next_timestamp` pagination is undocumented (research open item). Both are recorded for slice 23 (reconciler) to revisit
  with a live session.
- `fetchSession` returns `endReason` `unknown` when LiveAvatar reports `end_at` without a reason (or a reason that is not text), so
  an ended session always carries a reason as the spec requires. A `200` whose body has no `data` object is a `ProviderException`, not an end.
- A failed transcript fetch raises `ProviderException` (BEAI returned `[]` for a non-2xx): the contract says an error is never an
  empty transcript, and an empty one would let the reconciler replace stored lines with nothing.
- A transcript shape error message never quotes the row (no text, no role).
- Nothing in the adapter calls `LiveAvatarApi::call` without catching `ProviderException` where the contract says "never throws"
  (`teardown`, the context delete); `fetchTranscript` and `fetchSession` let it propagate as the contract says.

## Slice 15: Field specs, config validation, voice binding (tasks 15.1-15.2) - complete (slices 15-19 batch)

Commit subject: `feat(service): add provider field specs, config validation, describe and syncRequired`

Batch note: slices 15-19 ran on the merged tracker branch (Tavus and LiveAvatar lanes already merged), single writer,
`DB_PORT=55442` with `hra_test`. Baseline before the batch: 1098 tests passing.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 15.1 | `tests/Unit/AvatarProviders/FieldSpecValidatorTest.php` | the suite could not load: `DatasetMissing` (the dataset builds `new VoiceBinding`, class not found) | green with 15.2 | ported from BEAI `ProviderFieldSpecTest` / `ProviderFieldSpecsEngineKnobsTest` (unknown, required, type, range, enum, null = absent, int accepted for a number, all errors at once) |
| 15.2 | `Tavus/TavusFieldSpecsTest`, `LiveAvatar/LiveAvatarFieldSpecsTest`, `MockValidateConfigTest`, `SyncRequiredTest` | `validateConfig` / `describe` / `syncRequired` undefined on all three adapters (140 tests, 0 passed) | 140/140 | two test defects fixed on the way: a message assertion that tripped on the word "external", and `toBe` on arrays of objects (identity) became `toEqual` |

### Work Unit Evidence (slice 15)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='FieldSpecValidatorTest\|TavusFieldSpecsTest\|LiveAvatarFieldSpecsTest\|MockValidateConfigTest\|SyncRequiredTest'`: 141 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1241 passed, 3228 assertions; `--coverage --min=90`: total 100.0 %, exit 0 |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 1241 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed; `openapi.json` unchanged (no route) |
| Runtime harness | N/A by task: pure validation, exercised through the config endpoints of slice 16 |
| Rollback boundary | `FieldType`, `FieldSpec`, `FieldError`, `FieldSpecValidator`, `VoiceBinding`, `ProviderDescription`, `Tavus/TavusFieldSpecs`, `LiveAvatar/LiveAvatarFieldSpecs`, the `describe`/`validateConfig`/`syncRequired` methods on the interface and the three adapters, the `StubProvider` additions, and their tests |

### Deviations and decisions (slice 15)

- Authored size about 1,176 lines (about 540 app, 636 tests), above the 400 budget: the framework, three adapters and the
  per-provider specs are one cohesive unit; recommend `size:exception`.
- **`validateConfig` takes a third, optional `persona` argument** (`validateConfig(array $config, ?VoiceBinding $voice, array $persona = [])`).
  The design signature has two. The `persona` object is stored and its Tavus knobs are pushed to the provider, so it needs the same
  allow-list as `config`; without it a client could store arbitrary keys that slice 17 would copy into a third-party request.
  Persona errors are named `persona.<key>`, config errors `config.<key>`, the voice engine `voice.engine`.
- **Tavus keys** (decision for slices 16-17): `config` = `replica_id`, `persona_id` (both required, as BEAI's `faceId`/`palId`),
  `participant_left_timeout` (integer 0-3600) and `tts_model_name` (only with a Cartesia or ElevenLabs voice, models per engine).
  `persona` = `llm_temperature`, `llm_speculative_inference`, `turn_taking_patience`, `interruptibility`, `voice_isolation`,
  `idle_engagement` (snake_case like the other Tavus wire keys). BEAI's recording, captions, audio-only, call duration, `ttsEngine`
  and `ttsExternalVoiceId` are not carried: nothing sends the first four, and the voice engine/id come from the config's `voice`.
  The LLM model is deliberately not a knob (the `llm_model_id` binding writes that persona path, as BEAI decided).
- **LiveAvatar keys**: exactly the camelCase keys `LiveAvatarPayload` reads (`avatarId` required). `maxSessionDurationSec`,
  `videoEncoding`, `voiceId`, `ttsEngine` are unknown keys. The voice settings are refused unless a matching external voice is bound
  (`requires_voice_engine`): `voiceSpeed` and `ttsModelName` for Cartesia or ElevenLabs, the four ElevenLabs knobs for ElevenLabs only
  (LiveAvatar's Cartesia object has `speed` and `model`). This replaces BEAI's `tts_setting_unsupported` / `superseded_by_*` codes.
- New field type `integer` (BEAI had none): `TavusPayload` drops a non-integer left-timeout, so a float would have been accepted and
  never sent.
- Error codes: `required`, `type`, `range`, `enum`, `unknown`, `requires_voice_engine`, and `unsupported` for a voice engine the
  provider does not speak. The sentences are fixed English and never quote the value.
- `LiveAvatarProvider::syncRequired` is also true when `provider_state.llm_configuration_id` is stored without a bound model: the
  sync job of slice 17 is what deletes that stale LiveAvatar configuration (slice 12 left "forget" to it), and a job is only
  dispatched for a config that needs one.
- Guardian Angel finding fixed (RED test first): when the provider refuses the voice engine, a voice-gated setting is no longer also
  reported as `requires_voice_engine`; the `voice.engine` error stands alone for it.
- Mock accepts the `provider` voice engine only; Tavus and LiveAvatar accept `provider|cartesia|elevenlabs`. The catalogue's
  `resources` are `replica`, `pal` (Tavus) and `avatar`, `voice` (LiveAvatar); Mock lists none.

## Slice 16: Avatar config endpoints (tasks 16.1-16.2) - complete

Commit subject: `feat(service): add avatar config CRUD endpoints with soft delete`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 16.1 | `tests/Feature/AvatarConfigEndpointTest.php` (create, list, show, scopes, isolation) | 81 of 85 failed: 404 on every route | 85/85 with 16.2 | includes reconciliation item 38: exact-match `external_ref` filter applied before the cursor page is cut (three pages over 3 matching of 9 rows) |
| 16.2 | same file (patch, delete) | same run | green | the provider-immutable and sync-reset scenarios; soft delete keeps the row |

Test defects fixed on the way (no assertion weakened): `jsonb` reorders object keys, so the stored-vs-sent comparison became
`toEqual`; and the same reordering made `isDirty()` report a change for an identical PATCH, which was a real defect (a no-op
PATCH of a synced config reset it to `pending`): the controller now compares the members as data (`canonical()`), RED first.

### Work Unit Evidence (slice 16)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter=AvatarConfigEndpointTest`: 85 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1326 passed, 3484 assertions; `--coverage --min=90`: total 100.0 %, exit 0 |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 1326 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed |
| OpenAPI | `DB_PORT=55442 php artisan scramble:export` regenerated `openapi.json` (5 new operations, tag `Avatar configs`); `OpenApiDriftTest` green; `cd tools/spectral && bun run lint`: no results at `warn` or higher |
| Runtime harness (H-KEY) | `php artisan serve --port=8100`, `hra:bootstrap-account`, curl: `POST /v1/avatar-configs` mock -> 201 `{"id":"avc_01m5ah4e...","config":{},"sync_status":"not_required",...}`; a Tavus body with `faceId` -> 422 `errors` `config.faceId`, `config.replica_id`, `config.persona_id`; `GET ?external_ref=tenant-1&limit=1` -> the one match, `next_cursor` null (rows removed afterwards) |
| Rollback boundary | `AvatarConfigController`, `AvatarConfigRequest`, `CreateAvatarConfigRequest`, `UpdateAvatarConfigRequest`, `ListAvatarConfigsRequest`, `AvatarConfigResource`, the route block in `routes/api.php`, `openapi.json`, the test |

### Deviations and decisions (slice 16)

- Authored size about 950 lines (about 500 tests, 450 app), above the 400 budget: five routes, three requests and a resource with the full
  validation matrix; recommend `size:exception`.
- Route parameter is `{avatarConfig}` (implicit binding applies the account scope and hides soft-deleted rows), not `{id}`.
- PATCH replaces each member it sends as a whole (`config` is not merged); a member left out is kept. The validation checks the state
  that would result (stored members with the sent ones laid over), so changing the voice engine cannot leave a stored setting the new
  engine does not take, and removing the voice is refused while `tts_model_name` is stored.
- Sync state on PATCH: only a real change (data comparison, key order ignored) of `config`, `persona`, `llm_model_id` or `voice`
  re-evaluates `syncRequired` (`pending` or `not_required`) and clears `sync_error`; `synced_at` is kept. The dispatch lands in slice 17.
- `config`, `persona` and `voice` are written back as objects even when empty (`{}`), as the slice 8 note required.
- Validation errors are keyed `config.<key>`, `persona.<key>`, `voice.engine`/`voice.voice_id` with a fixed sentence per code.
  `voice` must carry exactly `engine` and `voice_id` (others are dropped by `validated()`).
- The conversation-based in-use guard (`config_in_use`) and "a deleted config cannot start a conversation" need the `conversations`
  table and stay in slice 20, as the task says.
- `external_ref` filter: exact and case-sensitive, 1-255 characters (an empty value is a 422, not "no filter").
- Common 401/403/429 Problem responses remain undocumented in `openapi.json` (slice 31).

## Slice 17: Config sync (tasks 17.1-17.3) - complete

Commit subject: `feat(service): add queued avatar config sync with Tavus persona and LiveAvatar registration`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 17.1 | `tests/Unit/AvatarProviders/Tavus/TavusPersonaSyncTest.php` | `TavusPersonaSync` / `SyncResult` / `SyncBlocked` not found (41 tests with 17.2: 0 passed) | 28/28 | ported from BEAI `TavusPalSyncTest` (one `add /layers` operation, 304 is synced, empty layers send nothing, binding merged next to the temperature, key re-sent every sync, status-only failures) |
| 17.2 | `tests/Unit/AvatarProviders/SyncTest.php` | `sync` undefined on all three adapters | 15/15 | Tavus delegates; LiveAvatar calls both registrars and the references land in `provider_state` / the ledger; Mock is `SyncResult::notRequired()`; a blocked sync sends nothing |
| 17.3 | `tests/Feature/SyncAvatarConfigTest.php`, additions to `AvatarConfigEndpointTest.php` | job and `AvatarConfigSync` missing, no `/sync` route, no dispatch on create or patch | 118 across both files | real runs use `dispatchSync` with `Http::fake`; the endpoint tests run under `Queue::fake()` |

A real defect surfaced by the LiveAvatar test (RED): a model built by `create()` holds `provider_state` as null until it is read back,
and the registrar spread it. `AvatarConfig` now declares the column defaults (`config`, `provider_state` = `{}`), with a model test.

### Work Unit Evidence (slice 17)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='TavusPersonaSync\|SyncTest\|SyncAvatarConfigTest\|AvatarConfigEndpointTest'`: 161 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1404 passed, 3659 assertions; `--coverage --min=90`: total 100.0 %, exit 0 |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 1404 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed |
| OpenAPI | regenerated (`POST /avatar-configs/{avatarConfig}/sync`: 202, 404, 409); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | `serve --port=8100`, bootstrap account: create a Tavus config -> `pending`; `POST .../sync` -> 202 with `sync_status` `pending`; the same call on a Mock config -> 409 `invalid_state`. Three jobs landed on the local Redis queue (`queue:clear` reported 3) and were cleared; the harness rows were deleted |
| Rollback boundary | `SyncAvatarConfig`, `AvatarConfigSync`, `SyncResult`, `SyncBlocked`, `TavusPersonaSync`, the `sync` interface method and adapter methods, the LiveAvatar constructor dependencies, the `sync` controller action and route, the `AvatarConfig` defaults, the scan-test allowance, their tests, `openapi.json` |

### Deviations and decisions (slice 17)

- Authored size about 1,158 lines (about 750 tests), above the 400 budget: three tasks (persona sync, adapter sync, job plus
  endpoint) with the failure matrix; recommend `size:exception`.
- **No `SecretRedactor`/`Redact` helper:** `sync_error` is written from a closed set by construction (a `ProviderException`'s generic
  sentence, a `SyncBlocked` fixed sentence, or `Synchronization failed.`), so there is nothing to scrub and no second redactor to keep
  in step with slice 21's. Slice 21 does not need to reuse anything from here.
- `SyncBlocked` (new, `App\AvatarProviders`): a precondition failure with fixed sentences (provider, language-model or voice-vendor
  credential missing; config incomplete). Slice 12 had raised these as `ProviderException::noResponse()`, which reads as a network
  fault. The job fails such a sync at once (`$this->fail()`), without the retries.
- The Tavus update targets `/v2/pals/{id}` as BEAI's proven call did; the persona must already exist (`persona_id` is required),
  so the spec scenario "exactly one persona was created" does not apply: the sync updates, it never creates a persona. Creating one
  on demand would need the persona id stored in `provider_state`; not in the task, left as an open decision.
- A persona with nothing to push (no knob, no external voice, no model) is not touched and counts as synced; removing the last knob
  leaves the old value on the persona (documented in the class). The persona's LLM binding is likewise not removed when unbound.
- `POST /v1/avatar-configs/{id}/sync` answers 202 with the config (`pending`), and 409 `invalid_state` for a config whose provider
  needs no objects (the spec says "re-dispatch and 202" and also "a mock config is never synchronized"; refusing is the honest
  reading of both). Decision for the orchestrator if 202 with no job is preferred.
- A PATCH dispatches only when `config`, `persona`, `llm_model_id` or `voice` really changed and the new state is `pending`; a config
  that settles as `not_required` queues nothing. Known race (documented on the job): a run that reads the config before a PATCH and
  finishes after the PATCH's own job can mark the config synced for the older state.
- `Tavus` sends the model as `layers.tts.tts_model_name` always for an external voice (default per engine), because the update replaces
  the whole `layers` node.
- The Tavus file scan (`TavusConcurrencyGuardScanTest`) allows exactly one new call: a PATCH addressed with `rawurlencode($personaId)`,
  and a new test asserts that only `TavusPersonaSync.php` patches. The ban on listing or ending conversations is unchanged.
- `TavusProvider` takes `TavusPersonaSync` as a second constructor argument with a default (`new TavusPersonaSync`), so
  `new TavusProvider` still works in every existing test.
- `AvatarConfigSync` lives in `App\Conversations` as the task names it, although it is about configs.
- Guardian Angel findings fixed: the sync state (`sync_status`, `sync_error`, `synced_at`) and `provider_state` are no longer mass-assignable
  on `AvatarConfig` (written with `forceFill`; a test helper that relied on it was corrected), the controller docs no longer name providers,
  and two stray comments (`ponytail:` marker, an adapter named in the contract docblock) were reworded.

## Slice 18: Catalogue (tasks 18.1-18.2) - complete

Commit subject: `feat(service): add provider catalogue endpoints with cache`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 18.1 | `tests/Unit/AvatarProviders/Tavus/TavusCatalogueTest.php`, `LiveAvatar/LiveAvatarCatalogueTest.php`, `CatalogueEntryTest.php`, `MockCatalogueTest.php` | classes missing (`LiveAvatarCatalogue` not in the container, 31 failures of 71 on the first run, rest errors) | 56/56 | ported from BEAI `AvatarProviderCatalogueTest` and `TavusPalEditableCatalogueTest`: faces with thumbnails and untrained ones skipped, pals list id and name only (no prompt, no layers), pagination past ten and the page bound, LiveAvatar public plus own lists merged, expired and inactive avatars dropped, dedupe, private voices tolerated, language verbatim, failures carry the status only. Italian detection, the `editable` flag, `label`, `locale`, `accent` and the HR-Avatar-as-a-provider branch are dropped |
| 18.2 | `tests/Feature/CatalogueEndpointTest.php` | 404 on both routes (all failures) | 22/22 | describe without any call; 404 for an unknown pair before any credential lookup; `422 provider_credential_missing`; `502 provider_error` for every provider failure including a provider 429; 300 s cache keyed by owner, key fingerprint, provider, resource; `fresh=1`; account A never served from B's entry |

### Work Unit Evidence (slice 18)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='CatalogueEntryTest\|TavusCatalogueTest\|LiveAvatarCatalogueTest\|MockCatalogueTest\|CatalogueEndpointTest\|Scan'`: 122 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1480 passed, 3855 assertions; `--coverage --min=90`: total 100.0 %, exit 0 |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 1480 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed |
| OpenAPI | regenerated (`GET /catalogue`, `GET /catalogue/{provider}/{resource}`: 200, 404, 422, 502); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | `serve --port=8100`, bootstrap account: `GET /v1/catalogue` lists `mock` (0 fields), `heygen` (avatar, voice; 9 fields), `tavus` (replica, pal; 4 fields); `GET /v1/catalogue/tavus/replica` with no key stored -> 422 `provider_credential_missing`; `.../tavus/voice` -> 404; the harness account was deleted |
| Rollback boundary | `CatalogueEntry`, `TavusCatalogue`, `LiveAvatarCatalogue`, the `catalogue` interface method and adapter methods, the two constructor dependencies, `CatalogueController`, `CatalogueRequest`, the two routes, the scan-test allowance, `openapi.json`, their tests |

### Deviations and decisions (slice 18)

- Authored size about 1,152 lines (about 660 tests), above the 400 budget: two adapters' catalogues with their paging and failure
  matrices, plus the endpoint and the cache; recommend `size:exception`.
- `GET /v1/catalogue` answers `{data: [{provider, fields, persona_fields, resources, voice_engines}]}` for every registry provider
  (Mock included, with nothing to list). Slice 19 appends the voice vendors (`cartesia`, `elevenlabs`) to the same list.
- `GET /v1/catalogue/{provider}/{resource}` answers `{data: [entries]}` (not paginated: the adapter bounds the walk at 20 pages of 100).
- A provider failure is always `502 provider_error`, including a provider 429: the renderer's mapping of a throttle to
  `429 provider_concurrency_limited` is about starting conversations, not about reading an inventory.
- Cache key `hra:catalogue:{owner}:{fingerprint}:{provider}:{resource}`, 300 s, failures never cached, an empty answer is cached like any
  other (BEAI did not cache empties; with a five-minute lifetime and `fresh=1` that is not worth a branch). Accounts on the platform key
  share one entry on purpose: it is the same inventory.
- **Tavus personas: one list call, no `editable` flag.** BEAI made three calls per persona listing to mark which personas the account may
  PATCH. The normalized entry has exactly the spec's members, so the flag cannot be returned; open item for the owner: a stock Tavus
  persona is listed and a sync that has persona knobs to push to it fails with `Provider request failed (status 400)`.
- `preview_audio_via_api` is true for LiveAvatar voices (the sample exists only behind `POST /v1/voice-preview`, slice 19); BEAI left it
  false because its sample route was a different endpoint. Tavus voices are not a resource here (BEAI listed them; a Tavus voice is
  picked through the external vendors).
- The Tavus file scan allows exactly one more call: a GET built from `self::BASE_URL.$path` (the face and persona lists), and a new test
  asserts that only `TavusCatalogue.php` does it and that it never mentions a conversation.
- Guardian Angel finding fixed (RED first): the private-voice list of the **platform key** is not read. LiveAvatar holds every account's
  bound third-party voices in the platform's one space, so listing them would have shown one account's voice ids to another (a
  cross-account read). An account's own key still lists its private voices. The status filters stay fail-open for a row that states
  no status (as BEAI had it), now stated in the class docs. The duplicated Tavus HTTP setup (a second `x-api-key` call next to
  `TavusProvider::http()`) was left: the catalogue has its own base path and timeout, and sharing would couple the two classes.

## Slice 19: Voice vendors and preview (tasks 19.1-19.3) - complete

Commit subject: `feat(service): add voice vendors and voice preview with cached audio`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 19.1 | `tests/Unit/Voices/{CartesiaVoices,ElevenLabsVoices}Test.php`, `tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarVoicesTest.php`, `tests/Unit/Voices/VoiceVendorRegistryTest.php` | classes missing (182 tests with 19.2 and 19.3, 30 passing: the slice 18 ones) | all green | ported from BEAI `VoiceProviderCatalogueTest` (Cartesia and ElevenLabs lists, pagination, headers, failures) and the vendor calls of `VoicePreviewService`; Italian ranking and detection dropped |
| 19.2 | `tests/Unit/Voices/VoicePreviewTest.php` | classes missing | green | ported from BEAI `AvatarVoicePreviewTest` (phrase from config, cache key separation, failure never cached, oversize/empty refused). `CatalogueSample` was not built, see below |
| 19.3 | `tests/Feature/VoicePreviewEndpointTest.php`, additions to `CatalogueEndpointTest.php` | 404 on the route | 182/182 across the slice | `text` (or any extra key) -> 422; Tavus with null engine -> 422 `tts_engine` with no call; missing credential 422; failure 502; vendor 404 -> 404 |

### Work Unit Evidence (slice 19)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='CartesiaVoices\|ElevenLabsVoices\|LiveAvatarVoices\|VoiceVendorRegistry\|VoicePreview\|CatalogueEndpoint'`: all passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1611 passed, 4151 assertions; `--coverage --min=90`: total 100.0 %, exit 0 |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 1611 passed, 100.0 %, exit 0 |
| Static analysis / style | `composer run lint` and `composer run analyse` (level 8): passed |
| OpenAPI | regenerated (`POST /voice-preview`: 200, 404, 422, 502; `GET /catalogue` now lists the two vendors); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness | N/A-WIRE by task (every vendor call is pinned with `Http::fake` under `Http::preventStrayRequests()`). Over the local API only the edges were driven: `text` in the body -> 422 `{text: This field is not accepted.}`; `tavus` with no engine -> 422 `tts_engine`; `cartesia` with no key -> 422 `provider_credential_missing`; `GET /v1/catalogue` lists `mock, heygen, tavus, cartesia, elevenlabs` (harness account deleted) |
| Rollback boundary | `app/Voices/*` `LiveAvatarVoices`, `VoicePreviewController`, `VoicePreviewRequest`, the vendor branch of `CatalogueController`, the `voice_vendors` and `voice_preview` blocks of `config/hr-avatar.php`, the route, `openapi.json`, their tests |

### Deviations and decisions (slice 19)

- Authored size about 1,634 lines (about 925 tests), above the 400 budget: three vendors, the preview and catalogue-sample services, the
  endpoint and the catalogue extension; recommend `size:exception`.
- **`CatalogueSample` was not built.** Task 19.2 names it (a port of BEAI's catalogue-sample proxy for Cartesia's free clip, with its
  SSRF guard), but no spec requirement or route consumes the clip and `POST /v1/voice-preview` synthesizes the configured phrase for
  Cartesia. The Guardian Angel review rejected it twice: first as Cartesia-specific code behind a generic class name, then, once folded
  into the vendor, as unreachable and speculative code with speculative configuration. The ported class and its tests (28 cases:
  host allowlist dataset, redirect not followed, size cap, audio only, mid-body failure, per-credential cache) are kept out of the tree at
  `/private/tmp/claude-501/-Users-alessandromicelli-Desktop-beai/1704da6a-e9c8-4671-9564-13765d8dbdb1/scratchpad/catalogue-sample/`
  (`CatalogueSampleTest.php.txt`, `CartesiaVoices-clip-methods.php.txt`) and can be restored when a route needs it. Owner decision: add
  the route, or drop the task. Consequence: a Cartesia entry says `preview_audio_via_api: true` for every voice (the preview endpoint can
  synthesize any), and the list no longer asks for the file url.
- **Cache key includes the credential** (owner and key fingerprint), beyond the spec's `(vendor, voice, model, phrase version, language)`:
  a voice id that only one account's key can use (a cloned voice) must not be played to another account. Accounts on the platform key
  still share entries. The repeat-request scenario holds within an account.
- Audio is cached in the application cache (Redis in production, 7 days, `voice_preview.cache_seconds`), not on a disk: the service has no
  durable disk and a container disk is per instance. It is stored as the raw bytes under a key that is a hash.
- Sentences live in `config('hr-avatar.voice_preview.phrases')` for `en` and `it` and are neutral (a test scans for host vocabulary);
  `language` is required and must be one of those keys (a language with no sentence is a 422, not a silent default as BEAI's `it`).
- The preview vendor is `tts_engine ?? provider`. `provider` must be one of the four credential providers; `tts_engine` one of
  `cartesia|elevenlabs` (the stock engine `provider` is not an engine to preview). A provider that is not a vendor and has no engine
  (Tavus, Mock) answers 422 on `tts_engine`, without naming a provider in the core.
- `voice_id` is restricted to `[A-Za-z0-9_-]{1,128}` for a preview (it is placed in a vendor url and escaped with `rawurlencode`
  besides), narrower than the 1-128 any-character voice binding; BEAI restricted to 80.
- A vendor 404 answers `404 not_found` (a voice the vendor does not know is the caller's mistake); every other vendor failure is
  `502 provider_error`, a vendor 429 included. No dedicated throttle for the preview beyond the per-key limit: BEAI had 10 per
  minute per user because its keys were the platform's; here the account's own key pays unless it has none. Open item if the platform
  key should be protected.
- LiveAvatar is a `VoiceVendor` registered as `heygen`; its catalogue listing stays under the adapter (`GET /v1/catalogue/heygen/voice`).
  Its sample is generic (not the phrase, not language dependent), so one cache entry serves every language.
- Dropped from BEAI: Tavus persona preview (`previewPersona`), the `voice_preview_*` error vocabulary (Problem codes instead), the
  browser cache header, the throttle, Italian ranking.
- Config comment: `voice_preview.phrases` holds Italian text; it is language data, not interface copy.
- Guardian Angel findings fixed (RED first where behaviour changed): `voice_id` is anchored with `\z` (a trailing newline matched
  `$`); the synthesized audio of Cartesia and ElevenLabs is read as a stream and never buffered beyond `max_bytes` (a mid-body transfer
  failure is a clean provider error with nothing leaked); the `CatalogueSample` findings above. A finding asking for `T-EXPOSE-001`
  catalogue entries was not applicable: that rule belongs to the BEAI wrapper and does not exist in this repository's `CLAUDE.md`.
  LiveAvatar's sample is a base64 JSON envelope, so it cannot be streamed; it is checked against the cap after decoding.
- The preview fakes in the tests are closures (`fn () => Http::response(...)`): a streamed body can be read once, so a fake that answers
  more than one call needs a fresh response each time.

## Unit 0: Reconciliation follow-ups 40-44 (slices 15-19 review) - complete

Commit subject: `fix(service): guard the sync race with a revision and throttle voice previews per key`

Batch note: baseline 1611 tests passing (serial `php artisan test`, `DB_PORT=55442`). `php artisan test --parallel` runs but
`DatabaseConnectionTest` fails under it by design of the parallel runner (per-process database names `hra_test_test_N`, the test
asserts `hra_test`); the configured serial runner is the verification of record.

| Item | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 40 | existing `AvatarConfigEndpointTest` "refuses a config whose provider needs no objects" | n/a: the behaviour exists since slice 17 | already green | recorded in `reconciliation.md`; no code change, no new test |
| 41 | `tests/Feature/SyncRevisionTest.php` (13 tests) | 11 of 18 failed together with 42: no `sync_revision` column, third job argument missing | 11/11 | race tests: a PATCH lands inside the provider call (success path and failure path), a config already past the run's revision (no call), the current revision still settles, a change that settles `not_required` overtakes a run; endpoint tests: create carries 0, PATCH bumps by one per real change, `not_required` outcome bumps without a job, no-op PATCH and rename do not bump, manual sync bumps, the resource does not expose it |
| 42 | `tests/Feature/VoicePreviewThrottleTest.php` (7 tests) | `rate_limits.preview` null, no 429 | 7/7 | default 10, 429 body (`rate_limited`, problem media type, `Retry-After`, `X-RateLimit-Limit/Remaining`), per key, a refused (422) request counts, independent from the secret budget in both directions |
| 43 | n/a (documentation) | n/a | n/a | `tasks.md` 19.2 and the unit table updated; no code existed |
| 44 | existing `VoicePreviewTest` credential-keyed cache cases | n/a: already implemented in slice 19 | already green | recorded in `reconciliation.md` |

Mutation check (not committed): removing the `WHERE sync_revision = ?` clause from the job's single write made 3 of the 11
`SyncRevisionTest` cases fail, so the tests do depend on the compare-and-set.

### Work Unit Evidence (unit 0)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='SyncRevisionTest\|VoicePreviewThrottleTest'`: 20 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1629 passed, 4217 assertions, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (larastan level 8) passed (0 errors) |
| OpenAPI | `php artisan scramble:export` (only the voice-preview description changed); `OpenApiDriftTest` green; `cd tools/spectral && bun run lint`: no results at `warn` or higher |
| Runtime harness | N/A-WIRE: the race is a queue interleaving, proven by an in-flight write inside the faked provider call; the throttle is proven through the real route and middleware chain |
| Rollback boundary | migration `..._100007_add_sync_revision_to_avatar_configs`, the `sync_revision` lines in `AvatarConfig`, `SyncAvatarConfig`, `AvatarConfigSync` and `AvatarConfigController`, the `preview` limiter (config, `AppServiceProvider`, route, `.env.example`), the two test files, `reconciliation.md` items 40-44 |

### Deviations and decisions (unit 0)

- `SyncAvatarConfig` takes the revision as an optional third argument (default 0, the revision of a never-changed config) so the
  slice 17 tests that construct the job with two arguments are untouched. No existing test was edited.
- Guardian Angel observation fixed (RED first, 2 failures): the controller no longer increments in PHP. The revision is set to
  `sync_revision + 1` by the database in the same UPDATE and the model is read again before the job is queued, so two concurrent
  changes cannot share a revision and a job always carries the revision that was really stored. `sync_revision` has no Eloquent
  cast (a cast would reject the SQL expression); Postgres returns it as an integer.
- A new migration adds the column rather than editing the slice 8 migration, so a database that already ran it migrates forward.
- The revision moves up on every real change to `config`, `persona`, `llm_model_id` or `voice` even when the new state needs no
  sync (`not_required`), so that a run still in flight for the older state cannot settle it.
- Residual limit, documented on the job: a run already past the start check can still send the older state to the provider while a
  newer run does the same. The config stays truthful (the older run does not settle it); the provider-side objects hold whichever
  call landed last until the next sync. Serializing runs per config (`WithoutOverlapping` with a no-attempt-cost release) would close
  it; left open.
- The preview budget default (10 a minute per key) follows BEAI's per-user preview limit; the env variable is `HRA_RATE_LIMIT_PREVIEW`.
- Authored size about 330 lines (about 215 tests): within the budget.

Unit 0 commit: `37d4e5f`.

## Slice 20: Conversations (model, create, show, config in-use guard) (tasks 20.1-20.4) - complete

Slice 20 commit: `3b0507c`.

Commit subject: `feat(service): add conversations and events schema with create and read endpoints`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 20.1 | `tests/Feature/ConversationSchemaTest.php` (32 tests) | 2 failed and 23 more would not have passed: `conversations` / `conversation_events` tables, enums, models and factories missing (the 7 that "passed" are refusal checks that pass while the table is absent) | 32/32 | columns in design order, `metadata` is `json`, unique `callback_token_hash` (any number of nulls), the three indexes, CHECKs for status / ended_reason / transcript_status / metadata object / event source, type, role, name, data object, both event uniques, FK cascade, restrict on the config, scope by account, typed reads, hidden internals, mass assignment |
| 20.2 | `tests/Feature/ConversationCreateTest.php` (53 cases) | 65 of 74 failed across 20.2-20.4 (404 on the route, 204 where 409 was due) | green | scenarios: happy path, default duration, above ceiling, oversize metadata, config not synced, config of another account, opaque round-trip; plus client secret claims and expiry, never stored or logged, null as not given, whitespace kept, `account_id`/state in the body ignored, scope and key |
| 20.3 | `tests/Feature/ConversationShowTest.php` (7 cases) | same run | green | members, finished conversation, no internals (refs, callback hash, prompt, secret), cross-account 404, deleted config still readable, scope |
| 20.4 | `tests/Feature/AvatarConfigInUseTest.php` (11 cases) | same run | green | 409 `config_in_use` for created/connecting/live, ended/failed do not block, other configs do not block, 404 for a foreign config, deleted config 404 on create, existing conversations keep the reference |

Test defects fixed on the way (no assertion weakened): the first version expected `"en\n"` to be refused, but Laravel's `TrimStrings`
turns it into `en`, which is valid (case replaced by `"e n"`); Pest spreads an array dataset entry into arguments, so the list
`[60]` ran as the integer 60 (wrapped as `[[60]]`); a violation aborts the surrounding Postgres transaction, so the schema test's
repeated refusals run in a savepoint (`refused()`), as in slice 7.

### Work Unit Evidence (slice 20)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ConversationCreateTest\|ConversationShowTest\|AvatarConfigInUseTest\|ConversationSchemaTest'`: 107 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1738 passed, 4583 assertions, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `DB_PORT=55442 php artisan scramble:export` (the dev database needs the new migrations, else a model-schema warning); `OpenApiDriftTest` green; `cd tools/spectral && bun run lint`: no results at `warn` or higher |
| Runtime harness (H-KEY) | `serve --port=8100`, `hra:bootstrap-account`, curl: `POST /v1/avatar-configs` mock -> `avc_...`; `POST /v1/conversations` with `external_ref` and `metadata {"a":{"b":{}}}` -> 201 `status created`, `max_duration_seconds 1800`, `metadata` returned byte-for-byte, `client_secret` and `client_secret_expires_at` present; `GET /v1/conversations/{id}` the same body without the secret; `DELETE` of the config -> 409 `config_in_use`. Harness rows deleted |
| Rollback boundary | migrations 100008-100009, `Conversation`, `ConversationEvent`, the three enums, two factories, `ConversationController`, `CreateConversationRequest`, the two resources, the two routes, the guard in `AvatarConfigController::destroy`, `max_duration_ceiling` config, the `trimStrings` line in `bootstrap/app.php`, their tests, `openapi.json` |

### Deviations and decisions (slice 20)

- Authored size about 1,500 lines (about 800 tests), above the 400 budget: schema with two models, three endpoints' worth of rules
  and the in-use guard. Recommend `size:exception`.
- `conversation_events` carries `account_id` (the design's table lists it among "every table carries account_id"; the events
  model is account-scoped like all others, so ingestion and the transcript read stay scoped without a join).
- `$middleware->trimStrings(except: ['external_ref', 'system_prompt', 'opening_text'])` in `bootstrap/app.php`: Laravel trims every
  string in the request, which would change a host's opaque reference or a prompt. The exception also applies to `external_ref` on the
  avatar-config endpoints (an improvement there). `ConvertEmptyStringsToNull` is left alone: an empty `external_ref` or
  `opening_text` becomes null (none), an empty `system_prompt` is refused.
- Optional members accept `null` as "not given" (`opening_text`, `external_ref`, `max_duration_seconds`, `metadata`); an absent
  `metadata` is stored and returned as `{}` (the design's webhook example shows `"metadata":{}`), not null.
- `metadata` is read from the raw JSON body (AD13) and validated in `after()`: a list, an empty list, a scalar or a form-encoded
  value is a 422 on `metadata`; the size is the compact UTF-8 encoding (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`) and the limit
  is inclusive at 8192 bytes. The column is `json` with an `object` cast and a `json_typeof = 'object'` CHECK.
- `language` is `string(2)` with the anchored `^[a-z]{2}\z` rule; `system_prompt` has no minimum beyond `required` (an empty
  string is null after the middleware).
- Default duration is `min(1800, HRA_MAX_DURATION_CEILING)` so a ceiling below 1800 does not make the default invalid. The ceiling
  is `hr-avatar.max_duration_ceiling` (`HRA_MAX_DURATION_CEILING`, 3600), added to `.env.example`.
- Order of checks: body validation (422), then the config (404), then its sync state (409). A malformed `avatar_config_id` is a 404,
  not a 422, as the spec says "a config of another account is a 404".
- `provider` is copied from the config at create time (not accepted from the body, which a test proves).
- The config-in-use guard and the create take the config row lock (`SELECT ... FOR UPDATE`) inside one transaction each, so a delete
  and a create of the same config are serialized: whichever locks first decides (the other sees a 404 or a 409 `config_in_use`). One
  connection cannot interleave two requests, so the tests pin the mechanism (the lock query precedes the conversation lookup and
  the insert; removing the lock fails both). Found by the Guardian Angel review (it first passed this as an acceptable limitation).
- `avatar_config_id` has no `exists` rule (it would answer 422, the spec wants 404) and the lookup is the account-scoped, soft-delete
  aware `find`, so another account's config and a deleted one are indistinguishable from an unknown id.
- `Conversation::avatarConfig()` includes soft-deleted configs (`withTrashed`), since a conversation outlives its config.
- Common 401/403/429 Problem responses remain undocumented in `openapi.json` (slice 31).

## Slice 21: Consumer webhooks - endpoints, signing, destination safety (tasks 21.1-21.3) - complete

Slice 21 commit: `8d7d203`.

Commit subject: `feat(service): add webhook endpoint management, HMAC signing and verification, and destination guard`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 21.1 | `tests/Unit/Webhooks/WebhookSignerTest.php` (24 cases) | 0 of 151 passed with 21.2-21.3: `WebhookSigner` not found | green | ported from BEAI `WebhookSignerTest`; the vectors were computed outside the code with `openssl dgst -sha256 -hmac`; verify window boundaries (300 accepted, 301 refused, both directions), tampered body / timestamp / secret, 9 malformed headers, retry re-signing, `encode()` unescaped slashes and unicode and `{}`, `JsonException` on bad UTF-8 |
| 21.2 | `tests/Unit/Webhooks/OutboundHostGuardTest.php` (about 90 cases) | same run | green | ported from BEAI `OutboundHostGuardTest` and extended: IPv4/IPv6 literal datasets (shared address space, benchmarking, multicast, NAT64, IPv4-mapped), numeric host spellings, injected resolver (every resolved address checked, unresolvable allowed), real resolver for `localhost`, not-enforced mode |
| 21.3 | `tests/Feature/WebhookEndpointTest.php` (about 45 cases) | same run | green | every spec scenario plus patch, delete, pagination, scope, isolation, limit (and its account row lock), secret never in any response after create / never logged / encrypted at rest |

Test defects fixed on the way (no assertion weakened): none beyond the dataset labels.

### Work Unit Evidence (slice 21)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='WebhookSignerTest\|OutboundHostGuardTest\|WebhookEndpointTest'`: all passed, exit 0 |
| Full suite | see the batch totals at the end of the slice 24 section |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: `Webhooks/Outbound` classes 100.0 % each, total 100.0 % (the file now includes `app/Webhooks/Outbound`) |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `scramble:export` regenerated (5 operations, tag `Webhook endpoints`, `EventType` schema); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | `serve --port=8100`, bootstrap account, curl: `POST /v1/webhook-endpoints` -> 201 with `secret` (`hra_whsec_` + 64 hex); `GET /v1/webhook-endpoints/{id}` the same body without it; a URL with credentials -> 422 `errors.url`; `DELETE` -> 204. Harness rows deleted |
| Rollback boundary | migration 100010, `WebhookEndpoint` + factory, `app/Webhooks/Outbound`, `WebhookEndpointController`, the three requests, the two resources, the route block, the `OutboundHostGuard` binding in `AppServiceProvider`, the `phpunit.high-integrity.xml` line, their tests, `openapi.json` |

### Deviations and decisions (slice 21)

- **`RetryClassifier` and `SecretRedactor` are not built** (task 21.2 names them). The spec has no permanent-failure class: every non-2xx,
  timeout, connection error and blocked destination is a failed attempt that is retried on the same schedule, so a classifier reduces to
  "2xx is success" and lives in `DeliverWebhook` (slice 22) with the closed error codes. A `last_error` is one of `timeout`,
  `connection_failed`, `destination_not_allowed`, `http_<status>`: it can never hold a secret or a body, so there is nothing to redact (the
  same reasoning as slice 17's `sync_error`). `DeliveryStatus` is created with its first use in slice 22.
- **Signer API** follows the design: `sign($secret, $timestamp, $body)` returns the header value (`v1=<hex>`), `verify($secret, $header,
  $timestamp, $body, $now)` checks the window then compares with `hash_equals` against a freshly computed signature (so an uppercase,
  truncated or multi-valued header never verifies), and `encode()` stays the single place a payload becomes bytes (BEAI's hard rule).
- **`OutboundHostGuard`** takes `enforced` (default true, fail closed) and an optional resolver closure. The container binding sets
  `enforced = ! environment('local', 'testing')`, which is how both the https rule and the address vetting follow "outside local and
  testing". `vettedAddresses()` returns the addresses the delivery must pin its connection to (empty when destinations are not enforced
  or the host does not resolve) and throws `DestinationNotAllowed` (fixed message, never the URL or the address) otherwise.
- Beyond PHP's private / reserved filter flags the guard also blocks 100.64.0.0/10 (shared address space, holds a cloud metadata
  address), 192.0.0.0/24, 198.18.0.0/15, 224.0.0.0/4, 64:ff9b::/96 and ff00::/8, and every host written as a bare number (a resolver
  reads `2130706433` or `0x7f.1` as an address). Hostname literals in these forms are refused whether or not the system resolver would
  turn them into a private address. The numeric-host regex only matches names made entirely of numbers, so real names are unaffected.
- A host that does not resolve is allowed at create / update (as BEAI), so an endpoint can be registered before its DNS exists; the
  delivery pins to the addresses it vets and finds none (slice 22: `connection_failed`, no request sent).
- The URL rule is a reusable `DestinationUrl` validation rule: https (or http where not enforced), a host, no control characters or
  spaces, no user info, nothing the guard refuses. Messages are fixed sentences and never repeat the URL.
- The 20-endpoint limit is checked inside a transaction holding the account row lock (`SELECT ... FOR UPDATE` on `accounts`), so two
  concurrent creates cannot both take the last slot. The tests pin the mechanism (one connection cannot interleave two requests). The
  answer is 422 `validation_failed` with a `detail` and no field error, since no single field is at fault.
- `events` must be a non-empty list of distinct known names (an endpoint subscribed to nothing is useless and a DB CHECK keeps it that
  way); `description` is limited to 255 characters (the spec gives no limit).
- Route parameter is `{webhookEndpoint}` (implicit binding applies the account scope), not `{id}`.
- `PATCH` ignores `secret`, `id` and `account_id` in the body; there is no secret rotation endpoint (not in the spec). Deleting an
  endpoint cascades to its deliveries once slice 22 adds that table with `ON DELETE CASCADE`.
- Authored size 1,265 lines (592 tests), above the 400 budget: signer, guard, the validation rule and the endpoint family
  with the full matrices. Recommend `size:exception`.

## Slice 22: Consumer webhooks - dispatch and delivery (tasks 22.1-22.3) - complete

Slice 22 commit: `863d32d`.

Commit subject: `feat(service): add webhook dispatcher, signed delivery with retries and the delivery log`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 22.1 | `tests/Feature/WebhookDispatcherTest.php` (about 25 cases), `WebhookDispatchTransactionTest.php` (3) | the suite could not load (`DeliveryStatus` / dispatcher missing, dataset error) | green | not subscribed, disabled, other accounts, exact body (compared with an independently built envelope; no provider refs, prompt, token hash or secret), `{}` stays `{}`, `failed` status, `transcript.ready` member for `ready` and `partial` only, one `evt_` per emission and a new one for the next, re-record is a no-op, DB unique, payload hidden, cascade, scoping, CHECKs |
| 22.2 | `tests/Feature/DeliverWebhookTest.php` (about 40 cases) | same | green | ported from BEAI `DeliverWebhookJobTest` with the HR-Avatar semantics: headers and exact bytes, receiver can verify, 10 s timeout and no redirects (asserted on the real client options), re-signing, every non-2xx retried, the `[10,60,300,1800,7200]` schedule through the sixth failure, recovery, `timeout` / `connection_failed`, terminal rows and missing rows ignored, endpoint disabled during retries, lost-race write is a no-op, DNS rebinding (`destination_not_allowed`, no request), connection pinned with `CURLOPT_RESOLVE` (port, IPv6, no pin for a literal / unresolved / not enforced), job carries the account and delivery ids only, a job of another account does nothing, `failed()` safety net |
| 22.3 | `tests/Feature/WebhookDeliveryLogTest.php` (about 20), `WebhookExposureTest.php` (4) | same | green | log content, no payload / body / secret / URL, status filter (and across pages), paging, other endpoint's rows, 404 for a foreign endpoint, scope; the secret is in no log line across a failing cycle whatever the receiver or transport says |

The after-commit tests use the real synchronous queue: `Queue::fake()` records a push the moment it is made, so it cannot show
that a job waits for the commit. With the sync driver "queued after the commit" is observable as "the request is made after the
commit" (and a rolled-back transaction makes no request). Removing `->afterCommit()` fails three of them (mutation check, not committed).

### Work Unit Evidence (slice 22)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='WebhookDispatcherTest\|WebhookDispatchTransactionTest\|DeliverWebhookTest\|WebhookDeliveryLogTest\|WebhookExposureTest'`: 91 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 1985 passed, 5337 assertions, exit 0; `--coverage`: every new class 100.0 % |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `scramble:export` regenerated (`GET /webhook-endpoints/{webhookEndpoint}/deliveries`, `DeliveryStatus` schema); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | `serve --port=8100`, bootstrap account, endpoint + mock config + conversation created through the API, one delivery row inserted (no outbound request is made from the harness): `GET /v1/webhook-endpoints/{id}/deliveries` -> `{"data":[{"id":...,"event_id":"evt_harness","event_type":"conversation.ended","status":"pending","attempts":1,"last_response_status":500,"last_error":"http_500","next_attempt_at":...,"completed_at":null}],"next_cursor":null}` with no payload; `?status=failed` -> empty. Harness rows deleted |
| Rollback boundary | migration 100011, `WebhookDelivery`, `DeliveryStatus`, `WebhookDispatcher`, `DeliverWebhook`, `WebhookDeliveryController`, `ListWebhookDeliveriesRequest`, `WebhookDeliveryResource`, the route, their tests, `openapi.json` |

### Deviations and decisions (slice 22)

- Authored size 1,542 lines (1,047 tests), above the 400 budget: dispatcher, job and log with the failure matrices. Recommend `size:exception`.
- **Retry semantics follow the spec, not BEAI's:** every non-2xx (including a 4xx) is a failed attempt and is retried; there is no permanent-failure class. `last_error` codes: `timeout`, `connection_failed`, `destination_not_allowed`, `http_<status>`, plus two from the design that the spec's list ("a short safe code or message") allows: `endpoint_disabled` (the endpoint was disabled or is gone when the attempt was due) and `internal_error` (the `failed()` safety net).
- `DeliverWebhook` does not use the queue's retries (`tries` 1): the row's `attempts` is the count, and a failed attempt queues the job again with the next delay. A duplicated job cannot count an attempt twice because every write is `UPDATE ... WHERE status = 'pending' AND attempts = <read>`; the retry is queued only when that write landed.
- A delivery whose endpoint is disabled (or deleted) at attempt time becomes `failed` without a request; `attempts` stays at what was made. Deleting an endpoint cascades to its deliveries, so a retry queued for it finds no row.
- **Pinning:** the job connects to the addresses `OutboundHostGuard::vettedAddresses()` returned, through `CURLOPT_RESOLVE` (`host:port:addr1,[ipv6]`, port 443 when the URL names none). It is skipped for an address literal, a host that did not resolve (the attempt then fails as `connection_failed`) and where destinations are not enforced. Verified against the options handed to the HTTP client; no real connection was made.
- Timeout detection reads the transport exception message for cURL error 28 / "timed out"; every other transport error is `connection_failed`. The message itself is never stored or logged.
- **The job carries the account id with the delivery id** (like `SyncAvatarConfig`), and loads the delivery under that account: the first version read the row unscoped by id and bound the account from it, which the Guardian Angel review rejected (a ULID is not a credential, and the boundary rule allows only the two credential lookups to read unscoped). A job for the wrong account finds nothing and does nothing (tested, RED first: 35 failures after the signature change). The account id is not a secret, so the queue still holds no URL, secret or body.
- `failed()` logs the delivery id and the exception class only (an exception message may hold a URL).
- `webhook_deliveries` rows are written with `insertOrIgnore` (the unique key decides whether this is a repeat) and so carry no Eloquent events; the model is `$guarded = ['*']` and is read, and updated by query, only.
- The dispatcher binds the conversation's account itself (`CurrentAccount::runAs`), so callers (lifecycle in slice 25, jobs, the sweeper) need not.
- The dispatcher takes the utterance count as an argument for `transcript.ready`; slice 23 passes it. `transcript.status` in the payload is the conversation's `transcript_status` at the time, so the caller must have set it first.
- `DeliveryStatus` lives in `App\Webhooks\Outbound` next to `EventType`; the delivery id is a plain lowercase ULID (design: only conversations, configs and endpoints have prefixed ids).
- Retry delays are not configuration (nothing varies them); they are a constant of the job.

## Slice 23: Transcript reconciler (tasks 23.1-23.2) - complete

Slice 23 commit: `f81aa8d`.

Commit subject: `feat(service): add merge-based transcript reconciliation job`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| (support) auth | `tests/Feature/ConversationProviderAuthTest.php` (5) | 0 of 60 passed with 23.1-23.2: classes missing | green | `ProviderAuth::none()` (the placeholder slice 9 left open) and `ConversationProviderAuth::for(Conversation)`: none for a provider that takes no credential, else the account's key, else the platform's, else null |
| 23.1 | `tests/Feature/TranscriptReconcilerTest.php` (35) | same run | green | merge adds only missing, normalized comparison (trim, whitespace, case, unicode case), other role / other text are different lines, repeated lines are not lost, sequence continues after the highest stored (lifecycle events included), lifecycle events never match, stored text and time as given, empty ready, partial, non-terminal is a no-op, idempotent, ready and partial never override each other, stale model, `transcript.ready` once with status and count and no text, endpoints scoping, one transaction (rollback takes everything), lock order |
| 23.2 | `tests/Feature/ReconcileTranscriptTest.php` (20) | same run | green | pending to ready, finished is a no-op, no provider session finishes empty, not-ready / provider failure propagate for the queue to retry, `tries` 5 and `backoff` `[30,60,120,300]`, `failed()` finishes as partial with the client lines and emits once, shape error fails the job at once (queue job told, nothing thrown), credential resolution (account key; missing key is a retryable failure without a request), account isolation, the job holds ids only |

A support class for the tests, `tests/Support/AvatarProviders/ScriptedProvider.php`, lets a test script `fetchTranscript` / `teardown`
answers and see what was asked (slice 24 uses it too). Mutation check (not committed): removing the `transcript_status = 'pending'`
condition from the finishing UPDATE failed 3 of the 35 reconciler cases.

### Work Unit Evidence (slice 23)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ConversationProviderAuthTest\|TranscriptReconcilerTest\|ReconcileTranscriptTest'`: 60 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 2047 passed, 5477 assertions, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors; `openapi.json` unchanged (no route) |
| Runtime harness | N/A by task: a queue job driven end to end in the slice 26 harness; here the adapter is scripted and the Tavus path runs against `Http::fake` |
| Rollback boundary | `TranscriptReconciler`, `ReconcileTranscript`, `ConversationProviderAuth`, `ProviderAuth::none()` and `NONE`, `ScriptedProvider`, their tests |

### Deviations and decisions (slice 23)

- Authored size 930 lines (687 tests): within about 2.3x the budget for two cohesive classes and the shared auth helper; recommend `size:exception`.
- **Multiset matching instead of set matching.** The spec says a provider line is added when "no existing utterance of the same role and normalized text" exists. Read literally, a provider that repeats a line ("Yes", "Okay.") would have all but the first dropped when the browser forwarded nothing, which is silent data loss. Here each stored line accounts for **one** provider line: provider `Yes, Yes` with client `Yes` adds one. Every spec scenario holds (merge adds only missing, normalized comparison, idempotent).
- Provider lines get the idempotency key `provider:{position}` (the unique key the events table needs); the position is the line's index in the provider's list.
- `reconcile()` takes the line list and the outcome (`ready` or `partial`); the empty-list call is the "finalize" of the task text (no provider session, partial). A conversation that has not reached a terminal state is a no-op (so a stray job can never finish a live conversation's transcript).
- The finishing write is the first statement (a conditional UPDATE that takes the conversation row lock), then lines are counted and numbered, then `transcript.ready` is recorded; slice 27's event ingestion locks the same row, so a late browser event cannot take a sequence number mid-merge.
- The count in `transcript.ready` is the number of utterance events (browser and provider), lifecycle events excluded.
- **Open decision recorded:** "receipt rows used when passed" (task 23.2) is not built. The receipts table is slice 29, and rows passed through the job would put transcript text on the queue and in `failed_jobs`. Slice 29's `ProcessProviderEvent` should pass the receipt id (the job then reads the receipt's `payload`, which is hidden). Smallest safe option; no test pins a rows argument.
- A provider that needs a credential and has none for the account is `ProviderException::noResponse()` (retry, then partial), not an immediate partial: the host may store the key while retries run.
- `failed()` logs the conversation id and the exception class, never the message.
- `ProviderAuth::none()` is the answer to the slice 9 open point (`credential: false` providers): a real object with the owner `none` and no meaningful key; it still refuses to serialize.

## Slice 24: Provider release job (task 24.1) - complete

Commit subject: `feat(service): add delayed provider session release job`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 24.1 | `tests/Feature/ReleaseProviderSessionTest.php` (26 cases) | 26 errors: `ReleaseProviderSession` not found | 26/26 (one test expectation corrected: with a frozen clock `updated_at` does not change, so only `released_at` differs) | release after end (references passed, `released_at` stamped), no-op when released / no session / missing, failed conversations release too, only `released_at` changes; teardown false releases back with 10/30/60/120 s on attempts 1-4, gives up silently on the fifth (state untouched, log holds the conversation id and no reference or credential), false twice then true is three calls, no key is a failed teardown; reconciliation queued on the first attempt only, whatever the teardown says, delayed to `ended_at + 60 s` (0 when already past); an ended Tavus conversation with no webhook is torn down and reconciled; account isolation |

### Work Unit Evidence (slice 24)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter=ReleaseProviderSessionTest`: 26 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test`: 2073 passed, 5535 assertions, exit 0 |
| Coverage (batch gate) | `DB_PORT=55442 php artisan test --coverage --min=90`: total 100.0 %, exit 0; `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2073 passed, total 100.0 % (keys, accounts, middleware, webhook signer / guard / dispatcher), exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors; `openapi.json` unchanged (no route); Spectral unchanged |
| Runtime harness | N/A by task: a queue job, driven end to end in the slice 26 harness; here the mocked queue job reports `attempts()` and `release()`, and the Tavus path runs against `Http::fake` |
| Rollback boundary | `ReleaseProviderSession` and its test |

### Deviations and decisions (slice 24)

- Authored size 353 lines (250 tests): within the budget.
- The job takes the account id with the conversation id (the design says "scalar conversation id ... loads with `withoutGlobalScope`"). The same boundary finding as slice 22 applies: an unscoped read by id is not one of the two permitted ones, so the job loads the conversation under the account it carries.
- "Logs the conversation id and the status class only": a teardown returns a boolean, so there is no status class to log; the log holds the conversation id and the attempt count. No reference, credential or exception message is ever logged (`failed()` logs the exception class).
- A provider that needs a credential and has none for the account is a teardown that failed (retried, then logged), not an error.
- The release delay (`HRA_RELEASE_DELAY_SECONDS`, 20) is configuration read by whoever queues this job, which is slice 25's lifecycle; nothing in the job reads it, so the key is added there (`config/hr-avatar.php` still lists it as to come).
- A conversation with no provider session (never connected, or failed at connect) is a no-op: its transcript is finished immediately by the lifecycle (slice 25), not by this job.

Slice 24 commit: `c064a9f`.

## Batch summary (unit 0 and slices 20-24)

| Unit | Commit | Full suite after |
|---|---|---|
| 0 reconciliation 40-44 | `37d4e5f` | 1631 |
| 20 conversations | `3b0507c` | 1741 |
| 21 webhook endpoints, signer, guard | `8d7d203` | 1894 |
| 22 dispatch, delivery, log | `863d32d` | 1987 |
| 23 transcript reconciler | `f81aa8d` | 2047 |
| 24 release job | `c064a9f` | 2073 |

### Open decisions (for the owner / next batches)

1. **Receipt rows into `ReconcileTranscript`** (task 23.2): not built. Slice 29's `ProcessProviderEvent` should pass the receipt id so the job reads the (hidden) payload; passing rows would put transcript text on the queue and in `failed_jobs`.
2. **Multiset transcript matching** (slice 23): a stored line accounts for one provider line only, instead of the spec's literal "any existing line blocks". Confirm, or switch to set semantics (one line change in `TranscriptReconciler::addMissing`).
3. **Residual provider-state race** (unit 0): a sync run already past its revision check can send older state to the provider while a newer run does the same; the config stays truthful. Closing it needs per-config serialization of runs (`WithoutOverlapping` with a release that does not spend attempts).
4. **`RetryClassifier` / `SecretRedactor` not built** (slice 21): the spec retries every failed attempt and `last_error` is a closed set of codes. Confirm they can be dropped from the task list for good.
5. **Voice-preview default budget** (unit 0): 10 a minute per key (`HRA_RATE_LIMIT_PREVIEW`), chosen from BEAI's per-user limit.
6. **`trimStrings` exception** (slice 20): `external_ref`, `system_prompt` and `opening_text` are no longer trimmed on any endpoint (also changes `external_ref` on avatar configs).
7. **Endpoint limit answer** (slice 21): the 21st endpoint is a 422 with a `detail` and no field error.
8. **Contract notes still pending from earlier batches** (not touched here): `liveavatar` provider-token security scheme wording, LiveAvatar session-token lifetime, Tavus persona `editable` flag.

---

# Batch 2: unit 0b and slices 25-29

Rulings 45-49 of the slices 20-24 review are recorded in `reconciliation.md` (items 45-49) in the unit 0b commit. Baseline before
this batch: 2073 tests passing serially (`DB_PORT=55442 php artisan test`). From unit 0b on the full suite also runs under
`php artisan test --parallel` (about 60 s instead of 130 s), which the fix below makes possible; both runners are cited where used.

## Unit 0b: Rulings 45-49 (tasks 0b.1-0b.3) - complete

Commit subject: `fix(service): serialize config sync runs per config and make the database check parallel-safe`

Commit: `7c37563`.

| Item | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 45 | n/a (documentation) | n/a | n/a | the slice 23 behaviour stays; `specs/conversations/spec.md` and design AD9 amended in the same commit |
| 46 | n/a (documentation) | n/a | n/a | recorded in `reconciliation.md` and the 21.2 task line |
| 47 | n/a (documentation) | n/a | n/a | recorded; built in slice 29 |
| 48 | n/a (documentation) | n/a | n/a | recorded; no code change |
| 49 | `tests/Feature/SyncSerializationTest.php` (9 tests) | 9 of 9 failed: `SerializeConfigSync` not found, `SyncAvatarConfig::middleware()` undefined | 9/9 | the middleware as a unit (lock free, freed after a run and after an exception, busy -> fresh copy with the same ids, revision and a delay, original job never released, other configs and accounts not held up) and two runs through the real pipeline making one provider call |
| parallel | `tests/Feature/DatabaseConnectionTest.php` | under `--parallel --processes=2`: expected `hra_test`, got `hra_test_test_1` | 2/2 in both runners | see deviations |

Mutation check (not committed): making the lock always succeed failed the "second run of the same config" case.

### Work Unit Evidence (unit 0b)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='SyncSerializationTest\|SyncRevisionTest\|SyncAvatarConfigTest'`: 40 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2082 passed, 5557 assertions, exit 0 (serial: see the batch totals) |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors; `openapi.json` unchanged (no route) |
| Runtime harness | N/A: a queue job middleware and test code; the race is proven through the real job pipeline with a provider call in flight |
| Rollback boundary | `app/Jobs/Middleware/SerializeConfigSync.php`, `SyncAvatarConfig::middleware()` and its docblock, `SyncSerializationTest`, the `DatabaseConnectionTest` and `tests/Pest.php` changes, the reconciliation / spec / design / tasks edits |

### Deviations and decisions (unit 0b)

- **`DatabaseConnectionTest` (asked to fix, not weaken).** The parallel runner gives each worker a database named `<database>_test_<token>`, so the
  fixed `hra_test` assertion could never hold there. The expected name is now derived from `ParallelTesting::token()` and is still compared
  exactly (`hra_test` serially, `hra_test_test_<token>` in a worker), so a run against any other database still fails. Nothing was loosened.
- **A second parallel-only failure was fixed with it.** `ConversationShowTest` used `CONVERSATION_MEMBERS`, a constant defined at file level in
  `ConversationCreateTest`; under `--parallel` the two files can land on different workers, so the constant was undefined. The constant moved to
  `tests/Pest.php` (loaded by every worker) and its definition was removed from `ConversationCreateTest`. This edits two existing tests mechanically
  (no assertion changed) and is the only edit to existing tests in this batch; it is not a reconciliation item.
- **Ruling 49 is not Laravel's `WithoutOverlapping`.** That middleware releases the job, and a release spends one of the job's attempts (the job has
  three, and the existing test pins `tries` 3), so a config that was merely busy could end up marked failed by a run that never started. The
  middleware instead queues a fresh copy a few seconds later and lets the waiting job finish normally, so it spends no attempt. The lock is
  the cache lock `hra:sync-config:{account_id}:{config_id}` (300 s expiry; the array store in tests, Redis in production).
- Authored size 227 lines (154 tests): within the budget.

## Slice 25: Conversation lifecycle and end (tasks 25.1-25.3) - complete

Commit subject: `feat(service): add the conversation state machine, webhook emission and the secret-key end endpoint`

Commit: `86eff30`.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 25.1 | `tests/Unit/ConversationLifecycleTest.php` (about 45 cases), `tests/Feature/ConversationLifecycleRaceTest.php` (9) | 79 of 81 failed with 25.2-25.3 (`ConversationLifecycle` / `IllegalTransition` not found; 2 refusal checks of the end route pass while the route is absent) | green | every edge of the table (7 legal, 13 illegal incl. `created -> failed`, out of a terminal state, `-> created`), terminal immutable and the second writer, idempotent same-state (no second `conversation.live`), argument guard (reason must fit the target), `end()` on open / terminal / failed, timestamps per state, provider reason kept beside the stable reason, the model passed in is refreshed, `conversation.live` / `conversation.ended` rows, `failed` reported as `conversation.ended`, immediate empty `ready` transcript + `transcript.ready` when there is no session (created -> ended, expired, failed at connect), delayed release at the configured / default delay otherwise; race: two stale copies end one conversation (first reason wins, loser's reason and time untouched, one `conversation.ended`, one release), failure vs end both orders, the UPDATE is one conditional statement naming the source states, release only after commit (real sync queue: the teardown does not run inside the transaction), nothing on rollback, reconciliation runs with no provider callback |
| 25.2 | `tests/Feature/ConversationWebhookEmissionTest.php` (7) | same run | green | live once, failure payload (`status failed`, reason, `ended_at`), ended once whoever asks, `transcript.ready` payload (`{status, utterance_count}` and no text, `data` keys exactly `conversation`, `transcript`), the count of utterances, subscribed-only / enabled-only / own-account-only fan-out, one `evt_` per emission shared across endpoints |
| 25.3 | `tests/Feature/ConversationEndTest.php` (11) | same run | green | end live (`api`, release queued), end connecting, end twice (200, identical body, one event, no second release), already ended by another cause (reason kept), failed stays failed, end before connect (no release, transcript `ready`, `transcript.ready` once), foreign 404, unknown 404, `conversations:write` scope (403 `insufficient_scope`), no key 401, body cannot choose the reason |

Mutation checks (not committed): removing the `status IN (...)` source condition from the conditional UPDATE failed 26 cases; removing
`afterCommit()` from the release job dispatch failed the commit-ordering cases.

### Work Unit Evidence (slice 25)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ConversationLifecycleTest\|ConversationLifecycleRaceTest\|ConversationWebhookEmissionTest\|ConversationEndTest'`: 81 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2163 passed, 5847 assertions, exit 0; line coverage 100.0 % |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2163 passed, `ConversationLifecycle` and `IllegalTransition` 100.0 %, exit 0 (the file now includes both) |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `scramble:export` regenerated (`POST /conversations/{conversation}/end`); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-KEY) | the end route is exercised through the full HTTP stack by `ConversationEndTest`; the full curl harness of the whole flow is slice 27's `MockConversationFlowTest` (the connect route does not exist yet, so no live conversation can be made over HTTP) |
| Rollback boundary | `ConversationLifecycle`, `IllegalTransition`, the `end` action and route, the `release_delay_seconds` config key, the high-integrity file entries, their four test files, `openapi.json` |

### Deviations and decisions (slice 25)

- Authored size 1,002 lines (782 tests), above the 400 budget: the state machine's table, the race and ordering proofs and the two surfaces it feeds (webhooks, end route) are one cohesive unit and sit in the 95 % zone. Recommend `size:exception`.
- `transition()` returns `bool` (changed or not) and refreshes the model it was given; the task text only said "transition". A same-target call is the idempotent no-op, a different terminal state is `IllegalTransition`. `end()` is an addition: every ending cause (api, client, sweeper, provider) shares "end if open, otherwise leave it", including a conversation that failed in between.
- `IllegalTransition` extends `ApiProblem` (409 `invalid_state`) so a request-driven illegal change answers per the spec without a mapping layer; event-driven callers catch it.
- A conversation that becomes terminal while `start()` is still running (state `connecting`, no session ref yet) is finalized at once as if it had no session, because nothing is known to release. Slice 26 must release a session whose refs arrive after the conversation is already terminal (recorded as a slice 26 requirement).
- The release job is dispatched `afterCommit()` with a delay of `hr-avatar.release_delay_seconds` (default 20, `HRA_RELEASE_DELAY_SECONDS`, already in `.env.example`).
- `transcript.ready` for a conversation finalized at once is recorded by `TranscriptReconciler::reconcile($conversation, [])` inside the lifecycle's transaction, after `conversation.ended`.

## Slice 26: Client connect (tasks 26.1-26.4) - complete

Commit subject: `feat(service): add client connect with credential pre-checks, single-use CAS and callback token`

Commit: `386b7a4`.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 26.1 | `tests/Feature/ClientConnectTest.php` (about 30 cases) | 39 of 45 failed with 26.2-26.4 (404 on the route, `ConnectConversation` missing; 2 refusal checks pass while the route is absent, 4 arch cases error on the missing files) | green | Mock happy path (`JoinMaterial` shape, `connecting`, `connecting_at`, ref kept), CORS echo and preflight, no provider material in the answer and nothing taken from the body, second connect (`connecting` / `live` -> 409 `already_connected`, no start), concurrent connects (two stale copies: one winner, `start` once), over (`ended` / `failed` -> 409 `invalid_state`), ended between the read and the CAS, credential pre-checks (Tavus without key 422 and the row byte-identical with no request, the platform key accepted, LiveAvatar model without key 422 `llm_credential_missing`, provider checked first, Mock skips both even with a model bound), who may connect (foreign conversation 404, other conversation's secret 403, no secret 401, unlisted origin 403, key in query 400, publishable budget 429) |
| 26.2 | same file | same run | green | start arguments (conversation id, spec from the conversation, metadata object kept, the auth owner), outside any transaction (transaction level equal to the baseline), refs persisted and not returned, no webhook or job for a conversation that is only connecting; failures: provider error -> `failed` / `provider_error` / 502 / `conversation.ended` (status failed) + `transcript.ready`, no refs, no release; 429 and a capacity limit under another status -> `provider_capacity` / 429; provider message not repeated; an unexpected exception fails the conversation (not stuck in `connecting`) and answers 500 without its message; ended in the meantime stays ended while the provider failure is still reported; ended while the session starts -> 409 `invalid_state` and the release is queued for the session that now exists |
| 26.3 | `tests/Feature/CallbackTokenTest.php` (10 cases) | same run | green | target `https://.../v1/webhooks/providers/{provider}/{64 hex}`, only the sha256 stored and in place before `start`, tokens differ, base url trailing slash, plain token absent from the response, the logs and every column of every table (each row of each table cast to text), the real Tavus adapter sends it as `callback_url`, none for `poll` / `none` ingestion or Mock |
| 26.4 | `tests/Arch/ConnectProviderAgnosticTest.php` (2) | same run | green | `ConnectConversation` and `ClientConversationController` name no provider id literal (registry ids, `liveavatar`, `cartesia`, `elevenlabs`) |

Mutation checks (not committed): removing the `! $won` branch, the claim itself, the token hash write and the late release each failed 1 to 8 cases.

### Work Unit Evidence (slice 26)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ClientConnectTest\|CallbackTokenTest\|ConnectProviderAgnosticTest'`: 45 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2208 passed, 6011 assertions, exit 0; line coverage 100.0 % |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2208 passed; `ConnectConversation`, `ConversationLifecycle`, `IllegalTransition` 100.0 %, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `scramble:export` regenerated (`POST /client/conversations/{conversation}/connect`, tag `Client`, `JoinMaterialResource`, security `clientSecret` + `publishableKey`); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-MOCK up to connect) | through the full HTTP stack in `ClientConnectTest` (real key resolution, origin allowlist, client secret, throttle, CORS, route binding, the Mock adapter); the curl harness of the whole flow is `MockConversationFlowTest` in slice 27 |
| Rollback boundary | `ConnectConversation`, `ClientConversationController`, `JoinMaterialResource`, the client route group, the `public_base_url` config key, the high-integrity entry, `ScriptedProvider` start support and the `clientSecretFor` / `browserHeaders` helpers in `tests/Pest.php`, the three test files, `openapi.json` |

### Deviations and decisions (slice 26)

- Authored size 926 lines (675 tests), above the 400 budget: the connect class carries the credential pre-checks, the CAS, the token, the start and both failure paths, and the proofs of each. Recommend `size:exception`.
- The claim goes through `ConversationLifecycle::transition(Connecting)` instead of a second hand-written UPDATE: one state-machine code path. Losing it is read from the `transition()` outcome (`false` = already connecting, `IllegalTransition` from `live` = connected, from a terminal state = `invalid_state`), which gives the reconciliation 16 answers.
- A status that is not `created` is refused before the credential checks (409 beats 422), so a second connect never reports a credential problem.
- **A conversation that ends while `start()` is running** (reported by slice 25 as a requirement): the lifecycle saw no session ref, so it finalized the transcript and queued no release. After `start` returns, the refs are stored, and if the conversation is already over `ReleaseProviderSession` is queued for the session that now exists and the answer is 409 `invalid_state`. If the start itself failed in that window the conversation stays ended and the provider failure is still what the caller gets.
- Any `Throwable` from `start` fails the conversation (`provider_capacity` only for a throttle `ProviderException`, otherwise `provider_error`) and is re-raised; an unexpected non-provider error therefore answers 500 and cannot leave a conversation in `connecting` until the sweeper.
- `HRA_PUBLIC_BASE_URL` was already in `.env.example`; `hr-avatar.public_base_url` reads it. It is only read for a provider that calls back; if it is unset the start of such a provider fails the conversation (`config()->string` throws) instead of sending the provider a broken target. The Railway documentation of the variable is slice 32.
- `ScriptedProvider` (test support) gained `start` (scripted by `$starts`), a settable `$ingestion` and `$lastSpec`; defaults unchanged, so slices 23-24 tests are untouched.
- Cross-account on the browser surface: a secret for the key's own account naming another account's conversation id is a 404 (only the signing secret could mint it); a secret for another conversation is the middleware's 403 `forbidden`, per reconciliation 12.

## Slice 27: Client events, client end, transcript endpoint (tasks 27.1-27.4) - complete

Commit subject: `feat(service): add idempotent client event ingestion, client end and the transcript endpoint`

Commit: `e5db7e5`.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 27.1 | `tests/Feature/ClientEventsTest.php` (53 cases) | 70 of 76 failed with 27.2-27.4 (404 on the routes; 5 refusal checks pass while the routes are absent) | green | batch stored in order with server-assigned sequence, numbering continues after provider and lifecycle rows, body cannot set `sequence` / `source` / `account_id`, time kept in UTC to the millisecond and none when none was given, `data` kept as an object (`{}` stays `{}`), same key in another conversation is independent, the conversation row is locked (`for update`), replay (0 accepted, N duplicates, count unchanged), partial overlap, a key repeated inside one batch, atomic validation (one bad event: 422 naming `events.N.field`, nothing stored; 21 malformed shapes), non-list bodies, 100 accepted / 101 refused, 8000 characters and 2048 bytes of data accepted, accepted while connecting, grace window (30 s and exactly 60 s accepted, 61 s `conversation_closed`, ended and failed), utterance before connect `invalid_state` for the whole batch, `live` in `created` stored without effect, `live` makes a connecting conversation live with one `conversation.live` (replays and later signals do nothing), `error` stored with data and no effect, `live` after the end stored without effect, form-encoded bodies (accepted without data, `data` refused), foreign conversation 404, other conversation's secret 403, no secret 401 |
| 27.2 | `tests/Feature/ClientEndTest.php` (8) | same run | green | client end (`client`, release queued, one `conversation.ended`), already ended / failed (200, attributes byte-identical, nothing emitted), end before connect (no release, `ready` transcript), last events still accepted after the end, reason not taken from the body, foreign 404, 403 / 401, preflight |
| 27.3 | `tests/Feature/TranscriptEndpointTest.php` (14) | same run | green | members exactly `conversation_id`, `status`, `utterances[{sequence, role, text, occurred_at, source}]`, ordering by time then sequence with timeless lines last, lifecycle events excluded, `pending` / `ready` / `partial`, in-flight conversations are `pending` with what was sent, `created` 409 `invalid_state`, empty list, no mixing of conversations, foreign 404 (no text leaked), unknown 404, `conversations:read` scope / key |
| 27.4 | `tests/Feature/MockConversationFlowTest.php` (2) | same run | green | the whole path over HTTP with the real sync queue: bootstrap key, publishable key, Mock config, webhook endpoint, create, connect, live + utterances, client end, release, reconciliation (the Mock opening line merged as a `provider` utterance), transcript `ready`, three webhooks delivered and succeeded in order with the `transcript.ready` payload, a second end changes nothing; and end-before-connect |
| (support) | `tests/Feature/EventTimePrecisionTest.php` (1) | failed with the reconciler change reverted (times cut to whole seconds) | green | provider times are kept to the microsecond, in UTC |

Mutation checks (not committed): removing the row lock, the grace window, the duplicate check and the `connecting` guard on the live signal each failed 1 to 4 cases; making a non-JSON body read no `data` failed the form-encoded refusal.

### Work Unit Evidence (slice 27)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='ClientEventsTest\|ClientEndTest\|TranscriptEndpointTest\|MockConversationFlowTest\|EventTimePrecisionTest'`: all passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2287 passed, 6306 assertions, exit 0; line coverage 100.0 % |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2287 passed, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors |
| OpenAPI | `scramble:export` regenerated (`POST /client/conversations/{conversation}/events`, `.../end`, `GET /conversations/{conversation}/transcript`); `OpenApiDriftTest` green; Spectral: no results at `warn` or higher |
| Runtime harness (H-MOCK full flow) | `MockConversationFlowTest` is the harness: HTTP end to end with the real stack and the synchronous queue (a curl run needs a queue worker for the delayed jobs; the test removes that difference) |
| Rollback boundary | `EventIngestor`, `ClientEventsRequest`, `TranscriptController`, `TranscriptResource`, the `events` / `end` actions and the three routes, migration `100012`, the microsecond write in `TranscriptReconciler`, the five test files, `openapi.json` |

### Deviations and decisions (slice 27)

- Authored size 1217 lines (814 tests), above the 400 budget: ingestion with its validation matrix, the client end, the transcript endpoint and the end-to-end flow. Recommend `size:exception`.
- **`occurred_at` precision.** The column was created with whole-second precision. A transcript is ordered by it and the browser writes milliseconds, so a new migration widens it to microseconds, and `TranscriptReconciler` (slice 23) now writes provider times as microsecond strings in UTC (the query builder would otherwise cut them). The transcript shows times to the millisecond (`...T12:00:05.250Z`), unlike the whole-second timestamps of the other resources, because here the sub-second part decides the order.
- `data` of an event is validated from the raw JSON body (an object stays an object, strings are not trimmed or nulled by the middleware); a form-encoded body can carry no object, so `data` in one is a 422. `events` must be a list (`list` rule), each event an object.
- `role` and `text` are refused on a lifecycle event and `name` on an utterance (the spec lists them as type-specific; accepting and ignoring them would hide a client bug).
- A terminal conversation with `ended_at` exactly 60 s ago still accepts events; 61 s is `conversation_closed` (`ended_at + 60 s` in the past).
- `live` is acted on only for the first stored copy of that key and only when the conversation is `connecting`, under the row lock; a `live` after `live`, in `created`, or after the end is stored without effect.
- The client end returns the full conversation resource to the browser (the spec says "the conversation resource"), including the host's `external_ref` and `metadata`, which the host already chose to put on a conversation the browser holds.

## Slice 28: Sweeper (task 28.1) - complete

Commit subject: `feat(service): add the conversation sweeper and schedule`

Commit: `6d4596f`.

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 28.1 | `tests/Feature/SweepConversationsTest.php` (24 cases) | see the note below: 0 of 24 passed with the command and the schedule absent | 24/24 | overrun live and connecting conversations -> `max_duration`, release queued, one `conversation.ended`; boundary (1829 and exactly 1830 s left alone, 1831 ended), the maximum is read per conversation, within limits byte-identical, no session -> ended with an immediate `ready` transcript and no release; never connected -> `expired` at 901 s with `transcript.ready` and no release, 899 and exactly 900 s left alone, a connecting conversation is not "never connected"; ended / failed conversations untouched; two sweepers (one after the other, and one that was already reading a conversation another process ended first: the first reason stands, one event); each account swept under itself with webhooks to its own endpoints only and a quiet account untouched; no conversation is read without its account (every statement carries the account predicate); 103 conversations in chunks; one conversation that cannot be ended (a trigger refuses its update) does not stop the others, is logged by id and exception class (not message) and the command exits 1; output; the schedule is every minute and without overlapping |

**RED honesty note.** The command and the schedule entry were written in the same step as the test file, and the first run of the tests was already green (after two test-side corrections: the shape of the account statement and the log helper, which hides a string-valued `exception` key). RED was then shown by removing the command and the schedule entry: 0 of 24 passed (23 failed on the missing command, one on the missing schedule entry). Mutation checks (not committed): `<=` instead of `<` on either boundary, and a zero margin, each failed 1 to 2 cases.

### Work Unit Evidence (slice 28)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter=SweepConversationsTest`: 24 passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2311 passed, 6372 assertions, exit 0; line coverage 100.0 % |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2311 passed, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors; `openapi.json` unchanged (no route); Spectral unchanged |
| Runtime harness | `php artisan hra:conversations:sweep` against a seeded overrun row: the feature test drives the command through `Artisan::call` against the real database; the scheduler wiring is asserted on the `Schedule` |
| Rollback boundary | `SweepConversations`, `routes/console.php`, `Conversation::reload()` and its two call sites, `ClientSecret::CONNECT_WINDOW_SECONDS` made public, `SweepConversationsTest` |

### Deviations and decisions (slice 28)

- Authored size 434 lines (315 tests): slightly above the 400 budget (the command is about 100 lines; the rest is the test matrix).
- **No unscoped reads (Guardian Angel finding).** A first version found the accounts with work through one statement that read `conversations` across accounts for their account ids only. The review rejected it: the boundary rule allows exactly two unscoped reads (the key resolver and the inbound token lookup), and a scheduler is no exception. The command now walks the `accounts` table (the tenant root, exempt from scoping; ids only, lazily in chunks of 100) and runs its two scoped queries inside `CurrentAccount::runAs` for each account. The price is two indexed queries per account per run each minute: small for a modest number of accounts, and the point at which it stops being small (a platform-level index of due conversations would need a documented exception to the rule) is recorded under the open decisions.
- **`Conversation::reload()`** replaces `refresh()` in `ConversationLifecycle` and `ConnectConversation`: `refresh()` reads by id alone, a reload keeps the account scope. Behaviour is otherwise the same.
- `ClientSecret::CONNECT_WINDOW_SECONDS` (900) is now public so the sweeper and the secret's expiry share one definition of the connect window.
- A failure while ending one conversation is caught per conversation, logged with the id and the exception class only, and turns the exit code into a failure after the rest have been processed.
- The polling schedule entry is added by slice 29 in the same file.

## Slice 29: Provider event ingestion and polling (tasks 29.1-29.3) - complete

Commit subject: `feat(service): add idempotent provider event ingestion, event processing and the LiveAvatar poller`

Commit: `b33c6ce`.

| Task | Test file | RED (observed, tests written first) | GREEN | Notes |
|---|---|---|---|---|
| (contract) | `tests/Unit/AvatarProviders/Tavus/TavusCallbackTranscriptTest.php` (9) | `callbackTranscript` undefined | green | the rows of a `transcription_ready` callback are read as a fetched transcript is (roles mapped, system row left out, order kept, times and offsets), none carried -> `[]`, shape errors as for a fetch, Mock and LiveAvatar -> `[]` |
| 29.1 | `tests/Feature/ProviderEventIngestorTest.php` (21) | 24 of 29 failed together with the contract file (the 5 that passed are refusal checks while the table is absent) | green | receipt for the authenticated conversation and account (all members, UTC microsecond time, payload), processing queued with the account and receipt id, provider reason kept, a list in order, empty payload stored as `{}`, `ignored` stored processed and nothing queued, the same event twice (once stored and queued; a repeat inside one batch; an `ON CONFLICT` insert, not a read; another provider or key is a different event), schema (unique `(account, provider, key)`: another account storing the same key neither blocks nor is blocked, one receipt per key within an account, CHECKs on kind and payload, NOT NULL account and conversation, FK, cascade, payload hidden, not mass assignable, account scoped), queued only after the commit (real sync queue) and nothing on rollback |
| 29.2 | `tests/Feature/ProcessProviderEventTest.php` (28) | 24 of 24 errored (`ProcessProviderEvent` missing) | green | joined makes live with `conversation.live` for both Tavus spellings and once for both together, no effect in created / live / ended / failed, shutdown through the real Tavus parser and ingestor (primary key, alternate key, both, neither -> `unknown`), connecting ended too, first writer keeps the reason (no release, no second event), failed stays failed, a receipt without a reason -> `unknown`; transcription_ready queues the reconciliation with the receipt id and the delay (50 s when the end was 10 s ago, 0 when over a minute ago, 60 s after ending a live conversation), ends a live conversation first, merges the callback's rows once (end to end with the sync queue), no job when the transcript is finished; processed once however often the job runs (also for the reconciliation), other account / missing receipt, an ignored receipt left unprocessed, a conversation ended while the event is applied; the job holds the ids only, 3 tries, backoff `[10, 60]`, failure logged by receipt id and kind |
| 29.2 (receipt rows) | `tests/Feature/ReconcileTranscriptReceiptTest.php` (13) | 11 of 13 errored (`receiptId` argument missing) | green | merges the rows with no provider call and no credential, a finished transcript is left alone; falls back to the provider's copy when the receipt has no rows, none is given, it is missing, or it is another conversation's, another account's or another kind; unreadable rows fail the job at once; the job holds ids only and keeps its two-argument form |
| 29.3 | `tests/Feature/PollProviderSessionsTest.php` (26) | 23 of 24 failed with the command absent (before the last additions) | green | end stored as `liveavatar:{session}:ended` and processed (provider reason, release queued), asked with the conversation's credential, repeated runs store one receipt (before and after processing), explicit end first wins, no reason -> `unknown`, still active, a provider failure is not an end (status class logged, no credential or session ref), others still asked, the next run finds the end, a missing credential is skipped and named, a conversation whose end cannot be stored does not stop the others (exit 1), only open conversations with a session (created, connecting without session, ended, failed excluded), providers that call back or send nothing are never asked even with a key and an ended Mock session, every account under itself (no statement without the account predicate), 103 conversations in chunks, the real Heygen adapter end to end, a silent Tavus conversation ended by the sweeper then released and reconciled over (faked) HTTP, the schedule |

Mutation checks (not committed; two surviving mutants led to stronger tests): replacing `insertOrIgnore` failed 4 cases; removing the processed guard now fails the "queued once" case; removing the receipt's conversation scope failed 1 case; removing the provider filter of the poll now fails the "never those of a provider that calls back" case.

### Work Unit Evidence (slice 29)

| Evidence | Value |
|---|---|
| Focused test command | `cd service && DB_PORT=55442 php artisan test --filter='TavusCallbackTranscriptTest\|ProviderEventIngestorTest\|ProcessProviderEventTest\|ReconcileTranscriptReceiptTest\|PollProviderSessionsTest'`: all passed, exit 0 |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2407 passed, 6619 assertions, exit 0; line coverage 100.0 % |
| High integrity | `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: 2407 passed, exit 0 |
| Static analysis / style | `vendor/bin/pint --test` passed; `composer run analyse` (level 8): 0 errors; `openapi.json` unchanged (no route); Spectral: no results at `warn` or higher |
| Runtime harness | `php artisan hra:conversations:poll` with a faked provider: `PollProviderSessionsTest` drives the command through `Artisan::call` against the real database and, for LiveAvatar, the real adapter over faked HTTP |
| Rollback boundary | migration `100013`, `ProviderWebhookReceipt`, `ProviderEventIngestor`, `ProcessProviderEvent`, `PollProviderSessions`, the poll schedule entry, `AvatarProvider::callbackTranscript` and its four implementations, the receipt branch of `ReconcileTranscript`, `ScriptedProvider` session scripting, the five test files |

### Deviations and decisions (slice 29)

- Authored size 1729 lines (1251 tests), above the 400 budget: the receipts store, the processing job, the receipt branch of the reconciliation and the poller, each with its matrix. Recommend `size:exception`.
- **Contract grows by one method.** Ruling 47 has the job read the receipt's payload where it runs, which needs the provider's own reading of a transcript in a callback. `AvatarProvider::callbackTranscript(array $payload): list<TranscriptLine>` is added (Tavus implements it by sharing its row reader with `fetchTranscript`; Mock and LiveAvatar return `[]`; the test `StubProvider` base class gained the same one-line method). Reconciliation item 22's list of contract operations is therefore one longer. No provider name is branched on outside the adapter.
- **The uniqueness is per account (Guardian Angel finding).** The spec and task 29.1 say unique on `(provider, idempotency_key)`. The review rejected a key that is global across tenants: an account whose event happened to carry a key another account had stored would be silently dropped, and the stored count would tell it so. The unique key is `(account_id, provider, idempotency_key)`. Idempotency is unchanged where it matters (an event always belongs to one conversation, hence one account; Tavus keys embed the provider's conversation id and polled keys the session id), and the concurrent-duplicates scenario still holds. This is a deliberate, more secure deviation from the spec wording; if the owner prefers the literal text, it is a one-line migration change.
- `ProcessProviderEvent` does not need the adapter at all; only the reconciliation does. A receipt without usable rows falls back to `fetchTranscript`, so a `transcription_ready` event that carried nothing still finishes the transcript.
- A `transcript_ready` that ends a still-open conversation stores no provider reason (the event has none); a `shutdown` without a reason stores `unknown`.
- The reconciliation delay after a `transcript_ready` is `ended_at + 60 s` (the browser's grace window, `EventIngestor::GRACE_SECONDS`), the same as the release job's, and no job is queued when the transcript is already finished.
- **The poller walks the accounts**, as the sweeper does after the Guardian Angel finding on slice 28: every conversation is read under its own account, never across accounts. Tradeoff recorded under the open decisions.
- A conversation whose credential cannot be resolved is skipped and named in the log (`no_credential`) rather than treated as a provider error; polled events are keyed `{event_key_prefix}:{session_ref}:ended` (`liveavatar:...` for Heygen) and their payload is `{source: poll, end_reason}`.
- Polled and callback receipts share the table and the pipeline; the `ignored` kind exists only for callbacks the service takes no action on (stored as processed). The receipts table has no retention (the conversations spec's retention note covers it: nothing is purged in this change).
- RED was observed before GREEN for every file in this slice (tests written first). Two test-side mistakes found in the first GREEN run were corrected (the fake queue ignores `afterCommit`, so commit ordering is proven with the real synchronous queue, as in slices 22 and 25).

## Batch 2 summary (unit 0b and slices 25-29)

| Unit | Commit | Full suite after | Authored lines (tests) |
|---|---|---|---|
| 0b rulings 45-49, sync serialization, parallel-safe database check | `7c37563` | 2082 | 227 (154) |
| 25 conversation lifecycle, webhook emission, secret-key end | `86eff30` | 2163 | 1,002 (782) |
| 26 client connect, callback token | `386b7a4` | 2208 | 926 (675) |
| 27 client events, client end, transcript endpoint, Mock flow | `e5db7e5` | 2287 | 1,217 (814) |
| 28 sweeper | `6d4596f` | 2311 | 434 (315) |
| 29 provider event ingestion, processing, poller | `b33c6ce` | 2407 | 1,729 (1,251) |
| CI: memory for the covered run (follow-up) | `cce6cfa` | 2408 | 12 |
| chore: duplicated variable in `.env.example` | `ab3d5cb` | 2408 | 1 |

Batch gates at the final state (`ab3d5cb`): `DB_PORT=55442 php -d memory_limit=1G artisan test --coverage --min=90` exit 0, 2407 passed at the time of the run, total 100.0 %; `DB_PORT=55442 php artisan test --parallel --processes=4` exit 0, 2408 passed, 6620 assertions; `DB_PORT=55442 vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` exit 0, 2408 passed, `ConnectConversation`, `ConversationLifecycle`, `IllegalTransition` and every other zone class 100.0 %; `vendor/bin/pint --test` passed; `composer run analyse` (level 8) 0 errors; `scramble:export` clean against the committed `openapi.json` (the drift test is in the suite); Spectral: no results at `warn` or higher.

### Existing tests edited in this batch

Only mechanical moves, no assertion changed: `ConversationCreateTest` lost the definition of `CONVERSATION_MEMBERS`, which moved to `tests/Pest.php` so `ConversationShowTest` finds it on any parallel worker (unit 0b). `DatabaseConnectionTest` was changed on request (parallel-safe database name, still compared exactly). Test support (`ScriptedProvider`, `StubProvider`) gained scripting and the `callbackTranscript` method; no behaviour the older tests rely on changed. No reconciliation ruling changed an existing assertion (ruling 45 keeps slice 23's behaviour; the spec wording was amended instead).

### Open decisions (for the owner / next batches)

1. **Contract: `AvatarProvider::callbackTranscript`** (slice 29). Needed by ruling 47 (the job reads the receipt's payload); makes the contract one method longer than reconciliation item 22 lists. Confirm, or fall back to always fetching the provider's copy and drop the receipt rows.
2. **Receipt uniqueness is per account** (slice 29, Guardian Angel finding): `(account_id, provider, idempotency_key)` instead of the spec's `(provider, idempotency_key)`. More secure, same idempotency in practice; the spec text and task 29.1 still say the shorter key.
3. **Sweeper and poller walk every account** (slices 28-29, Guardian Angel finding): two scoped queries per account per run each minute. Fine for a modest number of accounts; a platform-level index of due conversations would need the boundary rule extended first (a documented third exception to the two permitted unscoped reads).
4. **`Conversation::reload()` instead of `refresh()`** (slice 28): keeps the account scope on every re-read of a conversation.
5. **`occurred_at` precision** (slice 27): widened to microseconds, and the transcript shows milliseconds, unlike the whole-second timestamps of the other resources, because the sub-second part decides the order.
6. **A conversation that ends while its session is starting** (slice 26): the connect answers 409 `invalid_state` and the session is released through the delayed job.
7. **`TavusProvider::fetchSession` reads any status other than `active` as ended** (slice 11, raised again by the review): an allow-list of Tavus's ended statuses would be safer; the status vocabulary is not in `research.md`. Owner to confirm.
8. **A missing credential in `ReconcileTranscript` is a retryable provider failure** (slice 23, raised again by the review): it burns the five attempts and ends as `partial`. Could be a distinct, immediate `partial`; left as decided.
9. **`HRA_PUBLIC_BASE_URL` unset**: connecting a provider that calls back fails the conversation (provider_error) instead of sending a broken target; Railway documentation is slice 32.
10. **CI memory**: the covered test run needs more than PHP's default 128 MB at this suite size; the workflow now sets `memory_limit=1G` in `setup-php` (follow-up commit `cce6cfa`).
11. **Client end returns the full conversation resource to the browser**, including the host's `external_ref` and `metadata`, as the spec says.
12. **Contract notes still pending from earlier batches** (not touched here): `liveavatar` provider-token security scheme wording, LiveAvatar session-token lifetime, Tavus persona `editable` flag; the earlier open decisions 6 and 7 of batch 1 (`trimStrings` exception, endpoint limit answer).

---

# Batch 3: unit 0c and slices 30, 31, 32, 40

Rulings 50-56 of the slices 25-29 review are recorded in `reconciliation.md` (items 50-56) in the unit 0c commit. Baseline before this batch:
2408 tests. Final: 2666 tests, 8402 assertions, line coverage 100.0 %. Authored lines exclude the generated `openapi.json`.

| Unit | Commit | Full suite after | Authored lines (tests) |
|---|---|---|---|
| 0c rulings 50-56 | `520770d` | 2433 | 327 (173) |
| 30 inbound provider webhook endpoint | `83bdda7` | 2479 | 678 (468) |
| 31 final service hardening | `4563ccc` | 2609 | 1,111 (930) |
| (flake fix: the request id in a disclosure check) | `59ef8d4` | 2609 | 5 (5) |
| 32 Railway configuration as code | `4dd6ea3` | 2650 | 401 (257) |
| 40 documentation | `4917b13` | 2666 | 1,239 (216; the rest is prose) |

Slices 31 and 40 and slice 30 are above the 400 budget (cohesive: an arch and isolation suite, a documentation set, one endpoint with its
matrix): recommend `size:exception` for 30, 31 and 40.

## Unit 0c: Rulings 50-56 (tasks 0c.1-0c.6) - complete

Commit subject: `fix(service): read only documented Tavus end statuses, fail a missing-credential transcript job at once and gate readiness on the public base url`

| Item | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 50, 51, 56 | n/a (documentation) | n/a | n/a | recorded in `reconciliation.md`; the provider-webhooks spec, design and task 29.1 amended for 51; README "Known assumptions" for 56 (slice 40) |
| 52 | `tests/Arch/WalkedAccountsCeilingTest.php` (2) | 2 of 2 failed | 2/2 | `ponytail:` comments at both account loops naming the ceiling and the way past it |
| 53 | `TavusSessionStatusTest` | 5 failed (4 statuses read as ended; the log case) | 24/24 | allow-list: only `ended`; an unrecognised status is logged by value cut to 32 characters |
| 54 | `ReconcileTranscriptTest` | 2 failed (the job threw) | 21/21 | `CredentialMissing`; the job fails itself like an unreadable transcript; the failure hook finishes `partial` |
| 55 | `HealthTest`, `CallbackTokenTest` | 17 of 37 failed | 69/69 | `PublicBaseUrl`; readiness check `public_base_url` while a registered adapter calls back; connect answers 503 `service_unavailable` before any change |

### Work Unit Evidence (unit 0c)

| Evidence | Value |
|---|---|
| Focused test command | `DB_PORT=55442 php artisan test --filter='TavusSessionStatusTest\|ReconcileTranscriptTest\|HealthTest\|CallbackTokenTest\|WalkedAccountsCeilingTest'`: passed, exit 0 |
| Full suite | `php artisan test --parallel --processes=4`: 2433 passed, exit 0, 100.0 % |
| Static analysis / style / OpenAPI | pint, phpstan level 8 (0 errors), `scramble:export` + drift test, Spectral: all exit 0 |
| Rollback boundary | `PublicBaseUrl`, `CredentialMissing`, the health check, the connect pre-check, the Tavus allow-list, the comments, their tests, spec/design/reconciliation edits |

### Deviations and decisions (unit 0c)

- **Existing assertions changed by a ruling (as asked, said here):** `TavusSessionStatusTest` no longer expects `completed` and `failed` to be ended (53);
  `ReconcileTranscriptTest` no longer expects `ProviderException` for a missing credential (54); the three exact `checks` bodies of `HealthTest` gain
  `public_base_url` (55).
- **Ruling 55, connect.** The earlier behaviour failed the conversation as `provider_error` and answered 500. It now answers 503 `service_unavailable`
  with a clear detail, before the claim, so the conversation stays `created` and can be connected once the address is set. The log names the setting.
- The Guardian Angel review of this commit reported `$this->fail()` as undefined on `ReconcileTranscript`: false, the framework's `Queueable` trait includes
  `InteractsWithQueue` (the tests with a mocked queue job prove the call). Commit passed.

## Slice 30: Inbound provider webhook endpoint (tasks 30.1-30.3) - complete

Commit subject: `feat(service): add the token-authenticated inbound provider webhook endpoint`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 30.1 | `LimitWebhookBodyTest` (5) | part of 34 of 46 failed with the route absent (11 refusal checks passed) | green | exactly 256 KB accepted, one byte over 413, declared Content-Length alone, a body bigger than declared, before the token is judged |
| 30.2 | `ProviderWebhookTest` (24), `ProviderWebhookIdempotencyTest` (3) | same run | green | valid callback (receipt for the conversation and account, processing queued, ignored events stored and not queued), 11 refusals with one identical 404, a bad token beats a malformed body, malformed body after auth is 422 with nothing stored, form-encoded body malformed, the plain token in no column of any table, taken out of an echoed payload, duplicates (one receipt, one transition, one `conversation.ended`), documented as unauthenticated with a path parameter |
| 30.2 (limiter) | `ProviderWebhookThrottleTest` (4) | same run | green | 120 a minute per address, 429 `rate_limited` with `Retry-After`, per-address budget, counts valid callbacks too, draws on no key's budget |
| 30.3 | `ProviderWebhookConfidentialityTest` (10) | same run | green | the token in no log line for six failure modes (including a 500), route name and not path in the log context, not in any exception frame of the application, not echoed, the receipt payload returned by no endpoint |

Mutation checks (not committed): removing the token redaction, the conversation-provider match, and the session-id match each failed 1 to 2 cases.

### Work Unit Evidence (slice 30)

| Evidence | Value |
|---|---|
| Focused test command | `--filter='ProviderWebhook\|LimitWebhookBodyTest'`: 41 passed, exit 0 |
| Full suite / high integrity | 2479 passed, 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` exit 0 (the controller joins the zone; the middleware directory was already in it) |
| Static analysis / style / OpenAPI | pint, phpstan 0 errors, export + drift, Spectral all exit 0 |
| Runtime harness | the HTTP stack end to end in the tests; the docs run (slice 40) also had the service receive nothing from a provider (mock) |
| Rollback boundary | `AuthenticateProviderCallback`, `LimitWebhookBody`, `ProviderCallbackRequest`, `ProviderWebhookController`, the route and limiter, the inbound helpers in `tests/Pest.php`, five test files, `openapi.json` |

### Deviations and decisions (slice 30)

- The first Guardian Angel review rejected an inline `Validator` in the controller (rule: validate at the boundary with Form Requests). The authentication moved to a
  route middleware (`auth.provider`, like `auth.secret` and `auth.client`) and the validation to `ProviderCallbackRequest`. The middleware order keeps
  the 404 before the 422.
- The `{provider}` segment must be a registered adapter whose `ingestion()` is `Callback`: `heygen`, `mock` and unknown ids are the same 404, with no provider name in the code.
- The controller reads the token from the route instead of taking it as an argument, so it appears in no stack trace.
- The plain token is replaced by `[redacted]` in the raw body before it is decoded: a provider may echo the address it was given (the fixture does).
- Shared test helpers (`callbackConversation`, `inboundCallback`, `postInbound`, `inboundReceiptCount`) live in `tests/Pest.php` so any parallel worker has them.
- Signature: none, as Tavus documents none (research.md); the token is the credential.

## Slice 31: Final service hardening (tasks 31.1-31.5) - complete

Commit subject: `test(service): enforce provider-agnostic core, all-routes account isolation, secret non-disclosure and document the common error answers`

| Task | Test file | RED / evidence | GREEN | Notes |
|---|---|---|---|---|
| 31.1 | `Arch/ProviderBranchingTest` (+ `SourceScanner`), `Arch/TransportValuesTest` | the first real-tree run found stray literals (database factories: test support, exempted); fixture cases prove detection | green | token-based scanner: comments and docblocks are not findings; transports are exactly `daily`, `liveavatar`, `mock`; a non-literal transport is reported |
| 31.2 | `Arch/ModelRulesTest` | mutation: emptying `WebhookEndpoint::$hidden` failed 2 cases | green | every model scoped except `Account` and `LlmModel`, secrets hidden, encrypted attributes hidden, no mass-assigned `account_id`, no `var_export`/`print_r`/`var_dump`/`dd`/`dump` (also `\dd(`) in `app/` |
| 31.3 | `AccountIsolationTest` (43) | mutation: an account scope that no longer filters failed 25 cases | green | every authenticated route (35) with a dataset, writes land in B, lists hold none of A's rows, A's rows byte-identical, a meta-test that fails when a route is missing |
| 31.4 | `SecretExposureTest` (+3) | passed at once (no leak found); mutation: logging a provider error body failed the sweep | green | provider, LLM, key, webhook secret, callback token and the caller's key across every answer, log, queued job; a failing provider that echoes the keys; exception messages and traces |
| 31.5 | `OpenApiTest` (+9), `OpenApiDriftTest` (+2), `CiWorkflowTest` (+1) | 5 failed (no `Problem`, no 401/429/404 everywhere); `git rm --cached` made the tracking test fail | green | see decisions |
| open item | `TrustedProxiesTest` (7) | only the env-wiring test failed first (the framework reads `trustedproxy.proxies` already) | green | `config/trustedproxy.php`, `HRA_TRUSTED_PROXIES` |

### Work Unit Evidence (slice 31)

| Evidence | Value |
|---|---|
| Focused test command | `--filter='ProviderBranchingTest\|ModelRulesTest\|TransportValuesTest\|AccountIsolationTest\|SecretExposureTest\|OpenApi\|TrustedProxiesTest\|CiWorkflowTest'`: passed, exit 0 |
| Full suite / high integrity / coverage | 2609 passed, 100.0 %; high integrity exit 0; `php -d memory_limit=1G artisan test --coverage --min=90` exit 0 |
| Static analysis / style / OpenAPI | pint, phpstan 0 errors, export + drift, Spectral all exit 0 |
| Rollback boundary | the Arch files, `AccountIsolationTest`, the `SecretExposureTest` sweep, `ProblemDocumentation`, the `OpenApi*` test additions, `config/trustedproxy.php`, the CI step and `.env.example` line, `openapi.json` |

### Deviations and decisions (slice 31)

- **A real defect found in the OpenAPI document.** Every `application/problem+json` response was documented with `{"type": "string"}`, and the 422 and 404 answers
  inferred by the generator used the framework's `{message}` and `{message, errors}` shapes, which this service never returns. New `ProblemDocumentation`
  registers the `Problem` schema once and the common `Unauthenticated`, `NotFound`, `ValidationFailed` and `RateLimited` responses, points every error response at
  `Problem`, adds 401 and 429 to every operation that takes a credential and 404 to every operation with a path parameter, and keeps (and extends) an operation's
  own answer. The component responses of the framework are removed. The committed `openapi.json` changed accordingly.
- **Drift gate.** `OpenApiDriftTest` fails when `openapi.json` is not tracked by git (skipped outside a git checkout), and the CI step runs
  `git add --intent-to-add` before `git diff --exit-code`, because `git diff` ignores an untracked file.
- **Trusted proxies.** The framework's `TrustProxies` already reads `config('trustedproxy.proxies')`; the new config file feeds it from `HRA_TRUSTED_PROXIES`.
  Default: none trusted (a forwarded address is ignored). On Railway set `*`; without it the proxy is one address and one caller's failures use everyone's budget.
- **Idempotent deletes.** Deleting a credential B does not have is a quiet 204 (A's stays); a catalogue read or a voice preview for B without B's credential is a 422, never served with A's.
- The Guardian Angel review failed the first commit three times over real gaps (`\dd(` not matched, a non-literal transport invisible, and a duplicate 404 in the
  documentation when a framework response was replaced by a common one); each got a failing test first. Its non-blocking notes: the limiter's 120 is now a named constant; the
  `trustedproxy` file-text test stays because the behavioural tests beside it prove the effect.
- The sweep found no leak; the isolation dataset found none either (the earlier slices held).

## Unit (flake): the request id in a disclosure check

Commit `59ef8d4`. `ProblemRendererTest` "renders a provider failure as 502" asserted that the whole body does not contain the provider status (`401`); the body holds the random
request id, which spells it by chance. Red: with a fixed request id `req-401` the old comparison fails 5 of 5; the id is now the one member left out. Found when the high-integrity
run failed once with no defect behind it.

## Slice 32: Railway configuration as code (tasks 32.1-32.2) - complete

Commit subject: `build(service): add Railway config files and deployment topology doc (never applied)`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 32.1 | `RailwayFilesTest` (18) | 15 failed (files absent) | green | three files, `$schema`, the Dockerfile build, pre-deploy `migrate` then `LlmModelSeeder`, healthcheck `/v1/health`, the two start commands matching the compose file, the scheduler on one replica, every key within the Railway schema |
| 32.2 | `Arch/DocsBoundaryTest` | 20 errors (document absent) | green | the variable table parsed and compared with every setting the configuration reads; required/optional marks; host vocabulary; nothing in the repository calls Railway |

### Work Unit Evidence (slice 32)

| Evidence | Value |
|---|---|
| Focused test command | `--filter='RailwayFilesTest\|DocsBoundaryTest'`: passed, exit 0 |
| Full suite / high integrity | 2650 passed, 100.0 %; high integrity exit 0 (a first run failed on the flake fixed in `59ef8d4`) |
| Rollback boundary | the three `railway*.json`, `docs/railway.md`, `RailwayFilesTest`, `DocsBoundaryTest` (railway parts) |

### Deviations and decisions (slice 32)

- **Config as code is deprecated by Railway.** Its reference says files like these keep working for existing services until 2026-12-01 and recommends Infrastructure
  as Code afterwards. The files follow the brief; `docs/railway.md` says so. Migrating is a later change and the owner's call.
- No Railway CLI or API was used or called, and nothing was deployed. The schema keys and the config-file-path rule (absolute from the repository root) were read from
  Railway's public documentation.
- The healthcheck is liveness (`/v1/health`), as the task says. Readiness also depends on the database, Redis and the public base url; the guide recommends monitoring it instead.
- The first commit attempt failed the review because the change touched `tests/Arch/VocabularyScanner.php` (a guard that must name the terms it bans): see the open decisions.
  The change was reverted and the documents are copied into a directory for the unchanged scanner instead.

## Slice 40: Documentation (tasks 40.1-40.3) - complete

Commit subject: `docs: add README, architecture, API and SDK usage guides and record the retention open item`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 40.1 | `Arch/DocsBoundaryTest` (README / architecture / service and SDK READMEs) | 14 of 39 failed (documents absent) | green | retention open item in both documents, local ports, test commands, ratification pointer, known assumptions, the contract, the lazy start, the state machine and the key model |
| 40.2 | same | same run | green | the curl sequence in order, keys and scopes, webhook verification, every curl call exists in `openapi.json` with its method, no real key |
| 40.3 | same | same run | green | options, events, controls, error codes, `./mock`, the script-tag recipe, the pinned peer libraries |
| links | same | a probe document with a missing file and a bad anchor | green | every relative link and heading anchor resolves |

### Work Unit Evidence (slice 40)

| Evidence | Value |
|---|---|
| Focused test command | `--filter=DocsBoundaryTest`: 39 passed, exit 0 |
| Runtime harness | the curl sequence of `docs/api-usage.md` run against a local service (`php artisan serve` on a scratch database in the repository's own PostgreSQL, the mock provider, Redis database 9, a loopback receiver); the answers pasted are the real ones. The PHP and Node verification snippets were run against the three real signed deliveries: valid, tampered body and expired timestamp each judged correctly. Scratch database and Redis keys removed afterwards. |
| Gates | service: full suite 2666 passed 100.0 %; `php -d memory_limit=1G artisan test --coverage --min=90` exit 0 (100.0 %); high integrity `--min=95` exit 0; pint, phpstan, export + drift, Spectral exit 0. SDK: `bunx tsc --noEmit`, `bun run test:coverage` (358 passed, 100 % lines), `bun run build`, `bun run size` (5,511 B and 5,880 B gzipped) all exit 0 |
| Rollback boundary | `README.md`, `service/README.md`, `sdk/README.md`, `docs/architecture.md`, `docs/api-usage.md`, `docs/sdk-usage.md`, the `DocsBoundaryTest` additions |

### Deviations and decisions (slice 40)

- The task names `docs/sdk-usage.md` (40.3); the brief also asked for `sdk/README.md`. Both exist: the README is short and points at the guide.
- The mermaid diagrams (state machine, components, event flow) were not rendered here (no renderer offline); their syntax is standard but unverified. The sequence diagram avoids the
  `participant` keyword because the documents are scanned for host vocabulary.
- The CDN `loaders` in the SDK guide are examples (esm.sh); the SDK's tests use stand-in libraries, so the guide tells readers to try theirs once.
- The README states the Tavus and LiveAvatar assumptions of ruling 56 under "Known assumptions".

## Batch 3 summary and open decisions

Existing tests edited: the three rulings above (53, 54, 55); `ProblemRendererTest` (flake); `tests/Pest.php` gained shared helpers; `DocsBoundaryTest`, `OpenApiTest`, `OpenApiDriftTest`,
`CiWorkflowTest`, `SecretExposureTest` only gained tests.

Open decisions for the owner:

1. **Guard tooling and the Guardian Angel rule.** `service/tests/Arch/VocabularyScanner.php` has to name the host terms it bans, and the review gate reads that as a violation of the
   boundary rule whenever the file is part of a commit (it passed so far only because it was unchanged and cached). Decide whether `CLAUDE.md` should say that guard tooling under
   `service/tests/Arch/` is exempt, as `BoundaryTest` already does in code.
2. **Railway config as code** is deprecated (works for existing services until 2026-12-01); decide when to move to Infrastructure as Code.
3. **`HRA_TRUSTED_PROXIES`** defaults to trusting none. On Railway it must be `*` (documented); confirm that is the production setting.
4. **Railway healthcheck** is liveness; confirm that readiness should not gate a rollout.
5. **Retention** (unchanged): no purge; owner decision pending, recorded in the README.
6. Slices 30, 31 and 40 exceed the 400-line budget: recommend `size:exception`.

## Security fix unit (post-review of slices 3-30)

Branch `feature/extract-avatar-service`, base `9f662dd`, no push. Mode: Strict TDD. Rulings 57-67 in `reconciliation.md`; tasks SF-1 to SF-12 in `tasks.md`.

### TDD cycle evidence

| Item | RED observed (before the change) | GREEN | Commit |
|---|---|---|---|
| SF-1 guard, IPv6 spellings | 8 of the new cases failed: translated and compatible forms (5), 6to4 (1), local-use NAT64 (1), a mapped public address (over-blocked) (1). The mapped-private rows already passed (finding not reproducible at HEAD; PHP's reserved-range filter) | normalisation step | `2bbf676` |
| SF-2 delivery fail closed | 2 failed: no request may be sent for an enforced host with no vetted address (and a second lookup must not happen) | `DeliverWebhook::send` | `d9e92d8` |
| SF-3 relay of deliveries | 14 of 14 failed (command did not exist); the two end-to-end loss tests added after | `RelayLostJobs` | `7251fe2` |
| SF-4 receipts | 2 failed: a redelivered stored receipt was not queued after the lease (single and per-lease) | `ProviderEventIngestor::requeue`, `requeueStored` | `7251fe2` |
| SF-5 connect | 3 failed: conversation left `connecting` (callback target, keeping references, teardown failure); unknown provider already left `created` | `ConnectConversation` | `81d3146` |
| SF-6 limits, scopes, origin | 17 failed: limits accepted 0/negative/below-minimum and in `testing` (13), unknown scope 500 (3 incl. rotation), Origin refusal not counted (1) | `AppServiceProvider::guardIntegerSettings`, `Scope::grantsNamed`, `AuthenticateSecretKey` | `0bd6340` |
| SF-7 keys | 5 failed: successor expiry (2), `iat` from the wall clock (1), prefix bucket loaded (4 rows instead of 1) (1), plus one harness error fixed in the test; interleaved-rotation, tie-break, CORS-scope and live/test tests passed at HEAD | `ApiKeyController`, `ClientSecret`, `KeyResolver` | `762a582` |
| SF-8 credentials | race tests were first written wrong (the competing insert inside the savepoint was rolled back with it) and failed with 500; rewritten with the insert between the read and the insert, they passed: the framework already handles it. `captureLogs`, resolver and ciphertext tests added | tests only (+ helper) | `60fdbea` |
| SF-9 endpoints | 1 failed: unknown stored event name 500 on show, list and update; delete-stops-retries passed at HEAD (FK cascade) | `WebhookEndpointResource` | `3afea49` |
| SF-10 release | none failed: the single release after the references are kept already held | test only | `66216a9` |
| SF-11 ingestion | 9 failed: replay after grace 409; stored encoding 4,200 bytes for a 2 KB check; chunked body read 1 MiB for a 256 KiB cap; unreadable time 500 (1); malformed adapter error class missing (1); two cap tests undefined constant; client time year < 1 and year 10000 UTC (2, found by the review gate) | `EventIngestor`, `LimitWebhookBody`, `ProviderEvent`, `ProviderWebhookController`, `ClientEventsRequest` | `6b735d3` |

REFACTOR: the redundant checks, the named helpers and the docblock corrections of the findings (rulings 57, 65). No assertion was weakened; the existing assertions that were changed or removed are listed in the rulings (57: two live-DNS assertions removed; 58: two `DeliverWebhookTest` assertions reversed; 62: the Origin-refusal test reversed; 64: a probabilistic assertion removed).

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | each item's `--filter` run; last: `cd service && DB_PORT=55442 php artisan test --filter='ClientEventsTest\|OpenApi'`: 96 passed, exit 0 |
| Runtime harness | N/A for a live provider (no live call by rule). The loss and race scenarios run through the real command, queue fake, HTTP kernel and PostgreSQL, with the competing writes interleaved at the SQL level (`DB::listen`) because `RefreshDatabase` gives a single connection |
| Full suite | `DB_PORT=55442 php artisan test --parallel --processes=4`: 2781 passed, 8720 assertions, exit 0 (baseline at `9f662dd`: 2666) |
| Coverage | `php -d memory_limit=4G artisan test --coverage --min=90`: exit 0, total 100.0 %; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`: exit 0, total 100.0 % |
| Static analysis and style | `vendor/bin/pint --test` exit 0; `vendor/bin/phpstan analyse --memory-limit=1G` (level 8) 0 errors |
| OpenAPI | `php artisan scramble:export --path=openapi.json` then `git diff --exit-code service/openapi.json`: exit 0 (regenerated in the commits that changed documentation); `cd tools/spectral && bun run lint`: exit 0, nothing at `warn` or higher |
| SDK | not touched |
| Rollback boundary | per commit: the 12 commits listed in the final report revert independently in reverse order; the relay is `RelayLostJobs`, its schedule line and `ProviderEventIngestor::requeue`; the only persistent change is none (no migration; a comment in an existing one) |

### Disposition of the findings

(`hXXXXXXX` is the reviewed commit; the ids are the review's.)

**Fixed, failing test first:** `h8d7d203` R1-001 (explicit IPv6 normalisation), R3-002, R3-003; `h863d32d` R4-003 (fail closed), R4-001 and R4-002 (relay); slice 29 `b33c6ce` R4-001 (receipts re-queued); `386b7a4` connect tail; `hb3abddd` R3-001 and R4-003 (boot validation), R3-003 (unknown scope), R4-001 (Origin refusal counted); `h450eb50` R3-001 (successor expiry); `hbac90c4` R3-002 (`iat`); `h83fe7cc` R3-002 and R3-003 (key lookup by hash); `h8d7d203` R3-004 and R4-003 (unknown event name); `83bdda7` R3-001 and R3-002; `e5db7e5` grace replay, size boundary, unbounded transcript; `h86eff30` R3-002 (release delay bound); plus one defect the review gate found (browser event time outside the years 1 to 9999).
**Fixed as documentation or readability, with a check where one is possible:** `h8d7d203` R2-001, R2-004, R2-005, R2-006, R2-007, R2-008; `hb3abddd` R2-middleware-priority-comment (comment plus an order test), R3-002 and R4-002 (shared-address caveat in the docs); `e5db7e5` R3-migration-lock.
**Already correct at HEAD, now proved by a test (or a flaky test made deterministic):** `h8d7d203` R4-004 (FK cascade) and R3-006 (live-DNS assertions removed); `hd8c577a` R3-001 (the framework's `createOrFirst`), R3-002 (`captureLogs`), R3-003 (resolver throws), R3-004 (probabilistic assertion removed); `h86eff30` R3-001 (single release); `h450eb50` R3-002 (interleaved rotation) and R3-003 (explicit ids); `h83fe7cc` R3-006 (live/test parity); `hbac90c4` R3-001 (CORS only on client routes); `83bdda7` R3-003 (second copy during the first insert); `hb3abddd` R3-004 (not reproducible: PHPUnit `<env>` does not override the process); `386b7a4` unknown provider leaves the conversation `created`.
**Accepted, with a ruling:** `h83fe7cc` R3-001, R3-004, R3-005; `hb3abddd` R1-001, R2-failed-auth-duplicate-check, R2-magic-numbers-config, R3-005; `h8d7d203` R2-002, R2-003, R3-001 and R4-001 (DNS in the request), R3-005 (no parallel connection under `RefreshDatabase`), R3-007, R4-002 (create idempotency); `e5db7e5` R3-lock-test-coupling.

### Open items for the owner

1. The destination check at create time still resolves DNS in the request (ruling 65); a short resolver timeout is the lever.
2. Webhook endpoint creation is not idempotent (ruling 65, README).
3. The relay re-queues a receipt for 24 hours at most (ruling 60) and a delivery for as long as it is `pending` (its own six attempts end it); confirm both are the wanted policy.
4. A conversation takes at most 10,000 events (ruling 67); confirm the number.
