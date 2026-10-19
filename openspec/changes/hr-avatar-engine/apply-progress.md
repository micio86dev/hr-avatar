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
