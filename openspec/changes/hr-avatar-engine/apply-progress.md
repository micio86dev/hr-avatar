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
