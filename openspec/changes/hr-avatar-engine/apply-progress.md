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
