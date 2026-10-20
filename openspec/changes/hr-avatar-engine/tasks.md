# Tasks: HR-Avatar engine (service + SDK)

Change: `hr-avatar-engine` | Store: hybrid | Pace: auto | Strict TDD: enabled
Inputs (precedence): `reconciliation.md` (binding) > `specs/*/spec.md` > `design.md`.
Repo root for every path below is `/Users/alessandromicelli/Desktop/beai/hr-avatar` (service code in `service/`, SDK in `sdk/`).

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~12,000-15,000 authored additions + deletions across 40 slices (excludes the untouched Laravel skeleton, `composer.lock`, `bun.lock`, `service/openapi.json` and golden fixtures) |
| 400-line budget risk | High overall (Low-Medium per slice; slices 1, 10, 13, 17, 22, 25, 36 are the ones most likely to touch 400) |
| Chained PRs recommended | Yes |
| Suggested split | 40 work-unit slices, one local Conventional Commit each, in the order below |
| Delivery strategy | auto-chain |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

Chain mechanics (from the session preflight): the tracker branch is `feature/extract-avatar-service`. Every slice is a local work-unit commit on it, in order (base of slice N = the commit of slice N-1; base of slice 1 = the current tracker HEAD). No push and no PR is opened. If a slice measures above 400 authored lines after one honest pass, record the real count in the commit body and report it; never delete tests, docs, comments or blank lines to fit the budget.

### Conventions used by every task

- **TDD:** each task opens with a `RED` line (a test that fails for the stated reason), then `GREEN` (the smallest production change), then `Verify` (the exact command). A REFACTOR step is part of GREEN and must keep the suite green. Never weaken an assertion, skip a test or lower a threshold.
- **Verify commands** run from the repo root; `service` is `/Users/alessandromicelli/Desktop/beai/hr-avatar/service`. The Postgres + Redis stack (`cd service && docker compose up -d postgres redis`, host ports 55432/56379) must be up for any service test from slice 1 on. Never run two Pest runs at once against the same test database.
- **BEAI sources are read-only references.** The BEAI wrapper is the parent directory. Locate BEAI tests by class name with `rg -l '<ClassName>' ../api/tests` or `rg -l '<name>' ../frontend/tests` (read-only); the three paths named in `design.md` are `../api/tests/Unit/C7a/TavusProviderTest.php`, `../api/tests/Unit/C7a/HeygenProviderTest.php` and `../api/tests/Unit/C7a/TavusConcurrencyGuardTest.php` (all read-only). A ported test is copied into HR-Avatar, stripped of host vocabulary, and run RED against the new namespace before any production code is written.
- **No host vocabulary** (`beai`, participant, candidate, competency, bars, interview, project, organization, assessment, score, tenant-of-beai) in `service/app`, `service/config`, `service/database`, `service/routes`, `sdk/src`, `README.md` or `docs/`. The arch scan lands in slice 2; from then on every slice stays clean, including generated skeleton text.
- **Cross-account test per endpoint:** every slice that adds a route also adds its own "resource of account A under account B's key answers 404" test; slice 31 consolidates them into the all-routes dataset.
- **Secret leakage:** every slice that stores or returns a credential adds its `SecretExposureTest` case (response scan plus `Log::spy`).
- **Dependency Resolution Policy:** if a pinned dependency or required tool cannot be resolved or installed (PHP 8.5, Composer 2.4+, pcov, pdo_pgsql, Docker, Bun 1.4.0, a pinned package or image tag), STOP at that step and report the exact package, version and error. Never downgrade, substitute, loosen a constraint or remove a version.
- **Remote operations:** apply never contacts GitHub or any remote without explicit user authorization. Task 2.7 needs commit SHAs for pinned actions; if they cannot be taken from an authorized source, STOP and ask.

### Contract notes carried from reconciliation and design (apply must honour)

1. Provider contract operation is `start` (reconciliation item 22 in this change's final wording), with `teardown(sessionRef, contextRef, auth)`, `fetchTranscript`, `fetchSession -> SessionStatus {ended, endReason}`, `parseCallback`, `validateConfig`, `describe`, `syncRequired`, `sync`, `catalogue` and `ingestion(): Callback|Poll|None`. The interface is **grown slice by slice** so that it never contains an unimplemented method: slice 9 introduces the runtime methods (`ingestion`, `start`, `teardown`, `fetchTranscript`, `fetchSession`, `parseCallback`), slice 15 adds `describe`, `validateConfig`, `syncRequired`, slice 17 adds `sync`, slice 18 adds `catalogue`. The Mock adapter returns neutral values for each addition. The final interface is exactly the design contract.
2. Credentials and LLM bindings are resolved at connect (slice 26), never at create.
3. Provider id is `heygen` everywhere; `liveavatar` is only a transport value and the class directory.
4. `conv_`, `avc_`, `we_` prefixed ULID ids; `evt_` is an event id, not a table key.
5. Consumer-webhook plumbing (slices 21-22) and the transcript reconciler/release job (slices 23-24) land **before** `ConversationLifecycle` (slice 25) so the lifecycle can call them for real.
6. `LlmBindingResolver` and the `AvatarConfig`/`provider_resources` models land before the adapters (slice 8) because the LiveAvatar adapter reads them.
7. Deviation from the design file list: the CI file is `.github/workflows/service.yml` (service + openapi jobs) and the SDK job is `.github/workflows/sdk.yml`, per the session brief. Design slice S2a/S2b became slices 1/2 and the design's S8a became slices 33-36 (four SDK slices after splitting for the budget).

### Suggested Work Units

Base boundary for slice N = commit of slice N-1 on `feature/extract-avatar-service` (slice 1 = current tracker HEAD). "Likely PR" is a local slice commit (no PR is opened).

Harness legend. H-HEALTH: `cd service && php artisan serve --port=8100` then `curl -si localhost:8100/v1/health`. H-KEY: `php artisan hra:bootstrap-account Demo` then `curl -si -H "Authorization: Bearer $KEY" localhost:8100/v1/<route>`. H-MOCK: bootstrap account, create a `mock` avatar config, create a conversation, connect with the publishable key plus client secret, post events, end, read the transcript (curl sequence). N/A-WIRE: provider adapters make no live calls by rule (`Http::preventStrayRequests()`); the contract fixtures with `Http::fake` are the proof.

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Service scaffold core: Laravel 13 API-only app, pinned deps, Pest + pcov, compose (Postgres/Redis), `config/hr-avatar.php`, boot guard, request id, Problem+JSON, health | Slice 1 | `cd service && php artisan test` | H-HEALTH | revert `service/` (new tree, nothing else depends on it) |
| 2 | Tooling: Scramble export + drift check, Spectral, arch vocabulary scan, Dockerfile + api/worker/scheduler compose services, `.github/workflows/service.yml` | Slice 2 | `cd service && php artisan test --filter='OpenApi|Arch|Compose|CiWorkflow'` | `cd tools/spectral && bun run lint` | revert `tools/`, `.spectral.yaml`, `.github/`, `service/openapi.json`, `service/Dockerfile`, tooling tests |
| 3 | Accounts + keys domain: `CurrentAccount`/`AccountScope`, `KeyGenerator`, `KeyResolver`, `hra:bootstrap-account`, `hra:issue-key` | Slice 3 | `cd service && php artisan test --filter='AccountScope|KeyGenerator|KeyResolver|Bootstrap|IssueKey'` | `php artisan hra:bootstrap-account Demo` prints a key once | revert accounts/api_keys migrations, `app/Accounts`, `app/Keys`, the two commands |
| 4 | Secret-key auth chain: `RejectKeyInQuery`, `AuthenticateSecretKey`, `RequireScope`, rate limiters, high-integrity coverage file | Slice 4 | `cd service && php artisan test --filter='RejectKeyInQuery|AuthenticateSecretKey|RequireScope|RateLimit'` | H-KEY against a temporary scoped route | revert the three middlewares, limiters, `phpunit.high-integrity.xml` |
| 5 | API key endpoints: create, list (cursor pagination), rotate, revoke; publishable keys + origin allowlist | Slice 5 | `cd service && php artisan test --filter='ApiKey|OriginNormalizer|Pagination'` | H-KEY `POST /v1/api-keys` | revert `ApiKeyController`, requests, resource, `OriginNormalizer`, `PaginationRequest`, routes |
| 6 | Client auth: `ClientSecret` JWT, `AuthenticateClient`, `ClientCors`, publishable limiter | Slice 6 | `cd service && php artisan test --filter='ClientSecret|AuthenticateClient|ClientCors'` | `curl -si -X OPTIONS` preflight against a test-registered client route | revert `ClientSecret`, two middlewares, limiter, `HandleCors` removal |
| 7 | Credentials + LLM catalogue/credentials and resolvers | Slice 7 | `cd service && php artisan test --filter='ProviderCredential|LlmCredential|LlmModel|CredentialResolver'` | H-KEY `PUT /v1/provider-credentials/cartesia` then `GET` | revert three migrations, models, resolvers, controllers, `LlmModelSeeder` |
| 8 | `AvatarConfig` + `provider_resources` persistence, `LlmBinding` + resolver | Slice 8 | `cd service && php artisan test --filter='AvatarConfigModel|ProviderResource|LlmBinding'` | N/A: persistence and value objects only, no route or process | revert two migrations, two models, `app/Llm` |
| 9 | Provider contract (runtime methods), value objects, registry, `ProviderException`, Mock adapter | Slice 9 | `cd service && php artisan test --filter='ProviderRegistry|ProviderException|MockProvider|ProviderSession'` | N/A: internal contract, exercised through the Mock provider in H-MOCK from slice 26 | revert `app/AvatarProviders` core files and the registry config block |
| 10 | Tavus adapter: `start`, payload, language mapping, concurrency guard | Slice 10 | `cd service && php artisan test --filter='Tavus'` | N/A-WIRE | revert `app/AvatarProviders/Tavus` start files and the `tavus` registry entry |
| 11 | Tavus adapter: teardown, transcript, session status, callback mapper | Slice 11 | `cd service && php artisan test --filter='Tavus'` | N/A-WIRE | revert the Tavus teardown/transcript/event-mapper methods and tests |
| 12 | LiveAvatar LLM + voice registrars over the `provider_resources` ledger | Slice 12 | `cd service && php artisan test --filter='LiveAvatarLlmRegistrar|LiveAvatarVoiceRegistrar'` | N/A-WIRE | revert the two registrars and their tests |
| 13 | LiveAvatar adapter: `start` (context, then token, cleanup), payload allowlist | Slice 13 | `cd service && php artisan test --filter='LiveAvatarProvider|LiveAvatarPayload'` | N/A-WIRE | revert `LiveAvatarProvider` start, payload, the `heygen` registry entry |
| 14 | LiveAvatar adapter: teardown, transcript, session status | Slice 14 | `cd service && php artisan test --filter='LiveAvatar'` | N/A-WIRE | revert the LiveAvatar teardown/transcript/session methods and tests |
| 15 | Field specs, `FieldSpecValidator`, `describe`, `validateConfig`, `syncRequired`, voice binding | Slice 15 | `cd service && php artisan test --filter='FieldSpec|ValidateConfig|VoiceBinding|SyncRequired'` | N/A: pure validation, exercised by the config endpoints in slice 16 | revert `FieldSpec*` classes, per-provider field specs and interface additions |
| 16 | Avatar config CRUD endpoints, resource, soft delete | Slice 16 | `cd service && php artisan test --filter='AvatarConfigEndpoint'` | H-KEY `POST /v1/avatar-configs` with `provider: mock` | revert `AvatarConfigController`, requests, resource, routes |
| 17 | Config sync: `SyncAvatarConfig`, `TavusPersonaSync`, LiveAvatar sync, `POST .../sync` | Slice 17 | `cd service && php artisan test --filter='SyncAvatarConfig|TavusPersonaSync|AvatarConfigSync'` | H-KEY `POST /v1/avatar-configs/{id}/sync` returns 202 | revert the job, `TavusPersonaSync`, the `sync` interface addition and route |
| 18 | Catalogue (`GET /v1/catalogue`, per-resource listing, cache) | Slice 18 | `cd service && php artisan test --filter='Catalogue'` | H-KEY `GET /v1/catalogue` | revert catalogue classes, controller, `catalogue` interface addition |
| 19 | Voice vendors (Cartesia, ElevenLabs, HeyGen sample) + `POST /v1/voice-preview` | Slice 19 | `cd service && php artisan test --filter='VoiceVendor|VoicePreview'` | N/A-WIRE | revert `app/Voices`, controller, route |
| 20 | Conversations + events models, create/show endpoints, config in-use guard | Slice 20 | `cd service && php artisan test --filter='ConversationCreate|ConversationShow|AvatarConfigInUse'` | H-KEY `POST /v1/conversations` returns 201 with `client_secret` | revert two migrations, models, enums, controller, requests, resource |
| 21 | Consumer webhooks A: endpoint CRUD, signer + verifier, host guard, retry classifier, redactor | Slice 21 | `cd service && php artisan test --filter='WebhookEndpoint|WebhookSigner|OutboundHostGuard|RetryClassifier|SecretRedactor'` | H-KEY `POST /v1/webhook-endpoints` returns the secret once | revert `app/Webhooks/Outbound`, endpoint migration, controller, routes |
| 22 | Consumer webhooks B: deliveries, `WebhookDispatcher`, `DeliverWebhook`, delivery log | Slice 22 | `cd service && php artisan test --filter='WebhookDispatcher|DeliverWebhook|WebhookDelivery'` | H-KEY `GET /v1/webhook-endpoints/{id}/deliveries` | revert deliveries migration, dispatcher, job, controller |
| 23 | Transcript reconciler (merge, finalize) + `ReconcileTranscript` job | Slice 23 | `cd service && php artisan test --filter='TranscriptReconciler|ReconcileTranscript'` | N/A: queue job, driven end-to-end in slice 26 harness | revert reconciler and job |
| 24 | `ReleaseProviderSession` job | Slice 24 | `cd service && php artisan test --filter='ReleaseProviderSession'` | N/A: queue job, driven end-to-end in slice 26 harness | revert the job |
| 25 | `ConversationLifecycle` state machine, secret-key end, webhook emission, immediate finalize | Slice 25 | `cd service && php artisan test --filter='ConversationLifecycle|ConversationEnd'` | H-KEY `POST /v1/conversations/{id}/end` | revert lifecycle, end route and request |
| 26 | Client connect: pre-checks, CAS, callback token, `JoinMaterial` | Slice 26 | `cd service && php artisan test --filter='ClientConnect|CallbackToken'` | H-MOCK up to connect | revert `ConnectConversation`, client controller connect route |
| 27 | Client events (idempotent batches), client end, transcript endpoint | Slice 27 | `cd service && php artisan test --filter='ClientEvents|ClientEnd|TranscriptEndpoint'` | H-MOCK full flow (events, end, transcript) | revert `EventIngestor`, routes, `TranscriptController` |
| 28 | Sweeper command + schedule | Slice 28 | `cd service && php artisan test --filter='SweepConversations'` | `php artisan hra:conversations:sweep` on a seeded overrun row | revert command and `routes/console.php` entry |
| 29 | Provider event ingestion: `ProviderEventIngestor`, `ProcessProviderEvent`, poller | Slice 29 | `cd service && php artisan test --filter='ProviderEventIngestor|ProcessProviderEvent|PollProviderSessions'` | `php artisan hra:conversations:poll` with `Http::fake`d provider in test | revert receipts migration, ingestor, job, poll command |
| 30 | Inbound provider webhook endpoint (token auth, no oracle, 413/422, log redaction) | Slice 30 | `cd service && php artisan test --filter='ProviderWebhook'` | `curl -si -X POST localhost:8100/v1/webhooks/providers/tavus/<token>` against a seeded conversation | revert controller, `LimitWebhookBody`, route |
| 31 | Final hardening: provider-branching arch test, all-routes isolation dataset, exposure sweep, coverage gates | Slice 31 | `cd service && php artisan test --coverage --min=90 && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` | H-MOCK complete run | revert tests only (no production change expected) |
| 32 | Railway config as code + `docs/railway.md` + docs boundary scan | Slice 32 | `cd service && php artisan test --filter='RailwayFiles|DocsBoundary'` | N/A: config is never applied from this repo | revert three `railway*.json`, `docs/railway.md`, scan test |
| 33 | SDK scaffold: Bun/TS/tsup/Vitest config, `HrAvatarError`, API client, anonymity scan | Slice 33 | `cd sdk && bun run test` | `cd sdk && bun run build` emits ESM/CJS | revert `sdk/` (new tree) |
| 34 | SDK outbox + completion-phrase matcher (ported specs first) | Slice 34 | `cd sdk && bun run test -- outbox completion` | N/A: pure logic, covered by the slice 38 smoke | revert `src/outbox.ts`, `src/completion.ts` and specs |
| 35 | SDK transport infrastructure: Transport interface, `loadTransport`, mock transport, `./mock` export, `media.ts` | Slice 35 | `cd sdk && bun run test -- load-transport mock-transport media` | `cd sdk && bun run build` emits `dist/mock.*` | revert transports index/types/mock, `src/mock.ts`, `src/media.ts` |
| 36 | SDK `connect` + `Conversation` (events, controls, end, ended reasons) | Slice 36 | `cd sdk && bun run test -- connect conversation` | covered by slice 38 smoke | revert `connect.ts`, `conversation.ts`, `index.ts` exports |
| 37 | SDK Daily transport (ported `tavus.ts`) | Slice 37 | `cd sdk && bun run test -- daily-transport` | N/A-WIRE (injected fake `daily-js`) | revert `transports/daily.ts` and specs |
| 38 | SDK LiveAvatar transport (ported `heygen.ts`) | Slice 38 | `cd sdk && bun run test -- liveavatar-transport` | N/A-WIRE (injected fake SDK) | revert `transports/liveavatar.ts` and specs |
| 39 | IIFE build, size gate, Playwright smoke (Chromium + WebKit), `sdk.yml` CI | Slice 39 | `cd sdk && bun run build && bun run size && bunx playwright test` | the Playwright smoke is the runtime harness | revert IIFE config, `scripts/size.ts`, `e2e/`, `.github/workflows/sdk.yml` |
| 40 | Docs: README (service + SDK + retention open item), architecture, API usage, SDK usage | Slice 40 | `cd service && php artisan test --filter=DocsBoundary` | follow `docs/api-usage.md` curl sequence against the local stack | revert README and `docs/` (except `docs/railway.md`) |

### Dependency and parallelism notes

- All slices are executed **sequentially by one writer** on the tracker branch (auto-chain). Hard dependencies: 1 -> 2 -> 3 -> 4 -> 5 -> 6; 7 -> 8 -> 9; 9 -> 10 -> 11; 8 -> 12 -> 13 -> 14 (needs 9); 11 and 14 -> 15 -> 16 -> 17 -> 18 -> 19; 16 -> 20; 20 -> 21 -> 22 -> 23 -> 24 -> 25 -> 26 -> 27 -> 28 -> 29 -> 30 -> 31 -> 32; SDK 33 -> 34 -> 35 -> 36 -> 37, 38 -> 39 -> 40.
- Logically parallel-capable if a second worktree is ever approved: the Tavus track (10-11) and the LiveAvatar track (12-14); the SDK track (33-39), which depends only on the client wire contract in the specs and slice 2's tooling. Not recommended with a single writer.
- Bottleneck slices: 9 (contract), 25 (lifecycle, most callers), 26 (connect, couples credentials + adapters + lifecycle).

---

## Slice 1: Service scaffold core

Commit: `feat(service): scaffold Laravel 13 API with Problem+JSON errors, request ids and health endpoints`
Specs: service-foundation (versioned routing, Problem+JSON, request identification, health, config bootstrap).
Budget note: the untouched `composer create-project` skeleton and `composer.lock` are generated and excluded from the authored count; the authored part (config, providers, middleware, Problem classes, tests) is the ~380 lines. If a reviewer counts the skeleton, this one slice needs a `size:exception`.

- [x] 1.1 Preflight the toolchain (STOP gate).
  - RED: run `php -v && composer --version && php -m | rg -i 'pcov|pdo_pgsql' && docker compose version`; any missing tool is a failing precondition.
  - GREEN: nothing to write; on failure STOP and report (Dependency Resolution Policy). Required: PHP ^8.5, Composer 2.4+, pcov, pdo_pgsql, Docker + Compose v2.
  - Verify: the command above exits 0 and shows PHP 8.5.x.
- [x] 1.2 Create the Laravel app in `service/` (API-only) and strip the web surface.
  - RED: a pin test comes in 1.3; this task is the generation step. Run `composer create-project laravel/laravel service "^13.8"`; if it does not resolve, STOP.
  - GREEN: delete `service/resources/views`, `service/resources/js`, `service/resources/css`, `service/routes/web.php`, `service/package.json`, `service/vite.config.js`; create `service/routes/api.php`; write `service/.env.example` with `APP_NAME`, `DB_*` (host port 55432, database `hr_avatar`), `REDIS_*` (56379), `HRA_JWT_SECRET`, `HRA_PUBLIC_BASE_URL`, `HRA_RATE_LIMIT_SECRET`, `HRA_RATE_LIMIT_PUBLISHABLE`, `HRA_RELEASE_DELAY_SECONDS`, `HRA_MAX_DURATION_CEILING`, platform provider keys; update `service/.gitignore`.
  - Verify: `cd service && php artisan about --only=environment` runs; `git status --short service/` shows only the intended tree.
- [x] 1.3 Pin the dependency set (RED first).
  - RED: `service/tests/Unit/ComposerPinsTest.php` reads `service/composer.json` and asserts `php ^8.5`, `laravel/framework ^13.8`, `dedoc/scramble ^0.13`, `lcobucci/jwt ^5.6`, `pestphp/pest ^4.0`, `pestphp/pest-plugin-laravel ^4.0`, `larastan/larastan ^3`, `laravel/pint ^1.27`; fails because most are absent.
  - GREEN: `cd service && composer require dedoc/scramble:^0.13 lcobucci/jwt:^5.6 && composer require --dev pestphp/pest:^4.0 pestphp/pest-plugin-laravel:^4.0 larastan/larastan:^3 laravel/pint:^1.27`; any unresolved package STOPs the work.
  - Verify: `cd service && php artisan test --filter=ComposerPinsTest`.
- [x] 1.4 Pest config, pcov coverage, stray-request guard.
  - RED: `service/tests/Feature/StrayRequestsTest.php` asserts `Http::get('https://example.test')` throws (fails until the global guard exists).
  - GREEN: `service/tests/Pest.php` (Feature/Unit/Arch bindings, `Http::preventStrayRequests()` in `beforeEach`), `service/phpunit.xml` (pgsql test connection on `hr_avatar_test`, `APP_ENV=testing`, coverage source `app/`), composer scripts `test`, `lint`, `analyse`.
  - Verify: `cd service && php artisan test --filter=StrayRequestsTest`.
- [x] 1.5 Local Postgres + Redis compose (api/worker/scheduler services arrive in slice 2).
  - RED: `service/tests/Feature/DatabaseConnectionTest.php` runs `DB::select('select 1')` and `Redis::ping()` on the test connections; fails with the stack down.
  - GREEN: `service/docker-compose.yml` with `postgres` (`pgvector/pgvector:0.8.0-pg17`, host 55432, volume `hra-pgdata`) and `redis` (`redis:8.0-alpine`, host 56379); `service/docker/initdb/01-create-test-db.sql` creating `hr_avatar_test`.
  - Verify: `cd service && docker compose up -d postgres redis && php artisan test --filter=DatabaseConnectionTest`.
- [x] 1.6 `config/hr-avatar.php` and the boot guard.
  - RED: `service/tests/Feature/BootGuardTest.php` scenarios "Missing JWT secret" and "Short JWT secret": with env `production` and `HRA_JWT_SECRET` unset, or 16 bytes, `AppServiceProvider::boot()` throws an error naming `HRA_JWT_SECRET` and containing no value; in `testing` it does not throw.
  - GREEN: `service/config/hr-avatar.php` (`jwt_secret`, `jwt_issuer` `hr-avatar`, `jwt_audience` `hr-avatar-client`, `public_base_url`, `rate_limits`, `release_delay_seconds` 20, `max_duration_ceiling` 3600, `tavus.concurrency_retries` 3, `tavus.concurrency_backoff_ms` 2000, `catalogue_ttl_seconds` 300; later slices add their own keys), guard in `service/app/Providers/AppServiceProvider.php`.
  - Verify: `cd service && php artisan test --filter=BootGuardTest`.
- [x] 1.7 `AssignRequestId` middleware.
  - RED: `service/tests/Feature/RequestIdTest.php` scenarios "Supplied id is echoed", "Invalid id is replaced" (regex `^[A-Za-z0-9._-]{1,64}$`, otherwise a ULID is minted), "Error body matches header".
  - GREEN: `service/app/Http/Middleware/AssignRequestId.php`; set the log context to route name only (never the path).
  - Verify: `cd service && php artisan test --filter=RequestIdTest`.
- [x] 1.8 Problem+JSON: `ErrorCode`, `Problem`, `ApiProblem`, `ProblemRenderer`, bootstrap wiring.
  - RED: `service/tests/Feature/ProblemRendererTest.php` and `service/tests/Unit/ProblemTest.php`: scenarios "Validation error shape" (`errors` is `{field: [messages]}`, `detail` always present), "Unhandled exception is opaque" (also with `APP_DEBUG=true`), "Codes are not localized" (same `code` under `Accept-Language: it`), unknown path `404 not_found` as `application/problem+json` (never HTML), unsupported method `405 method_not_allowed` with `Allow`, `PostTooLargeException` -> 413 `payload_too_large`, throttle -> 429 `rate_limited` with `Retry-After`, 503 `service_unavailable`, "No unversioned API surface" (`/health` and `/api/health` are 404).
  - GREEN: `service/app/Http/ErrorCode.php` (full catalogue incl. `api_key_in_query`), `Problem.php`, `ApiProblem.php`, `ProblemRenderer.php`, `service/bootstrap/app.php` (`apiPrefix 'v1'`, no web routes, `withExceptions` renderer for `v1/*` and unmatched paths, global `HandleCors` removed).
  - Verify: `cd service && php artisan test --filter='ProblemRenderer|ProblemTest'`.
- [x] 1.9 Health endpoints.
  - RED: `service/tests/Feature/HealthTest.php` scenarios "Liveness" (`200 {"status":"ok"}`, no DB/Redis touch), "Readiness ok" (checks `database`, `redis`), "Readiness with Redis down" (503 Problem+JSON `service_unavailable` with `checks`, no versions/hosts), "Liveness survives dependency failure".
  - GREEN: `service/app/Http/Controllers/V1/HealthController.php`, `service/routes/api.php` (`AssignRequestId` only).
  - Verify: `cd service && php artisan test --filter=HealthTest`.
- [x] 1.10 Static analysis and style baseline.
  - RED: `cd service && composer run analyse && composer run lint` fails (no `phpstan.neon`/`pint.json`, skeleton findings).
  - GREEN: `service/phpstan.neon` (larastan level 8 over `app`, `config`, `routes`, `database`), `service/pint.json`; fix findings in authored code only.
  - Verify: `cd service && composer run analyse && composer run lint && php artisan test --coverage --min=90`.

## Slice 2: Scaffold tooling

Commit: `build(service): add OpenAPI export with drift gate, Spectral lint, Dockerfile, compose services, CI workflow and boundary scan`
Specs: service-foundation (OpenAPI publication and drift gate, boundary rules, local runtime topology and CI gates).

- [x] 2.1 Architecture vocabulary scanner (test the scanner first).
  - RED: `service/tests/Arch/VocabularyScannerTest.php` scenarios "Host vocabulary is rejected" (a temp tree with `candidate` in `app/` fails and names file and term), "Clean tree passes", word-boundary and plural matching, `tenant-of-beai`, exemption of the arch test files; plus a BEAI-namespace reference check.
  - GREEN: `service/tests/Support/VocabularyScanner.php`, `service/tests/Arch/BoundaryTest.php` running it over `app`, `config`, `database`, `routes` (and `../sdk/src` when it exists). Rewrite any skeleton comment that trips the scan; never weaken the term list.
  - Verify: `cd service && php artisan test --filter='VocabularyScanner|BoundaryTest'`.
- [x] 2.2 Scramble configuration and security transformers (check the 0.13 API with context7 first).
  - RED: `service/tests/Feature/OpenApiTest.php` scenarios "Every route is documented" (each registered `/v1` route+method appears in the generated document) and "Auth is declared per operation" (health has no security; later slices extend the dataset), `info.version` equals `service/VERSION`, the four security schemes exist.
  - GREEN: `service/VERSION` (`0.1.0`), `service/config/scramble.php` (`api_path` `v1`), transformers in `service/app/Providers/AppServiceProvider.php` (`secretKey`, `clientSecret`, `publishableKey`, `providerToken`; per-operation security from `auth.secret` / `auth.client` middleware aliases), Problem response documented on the ready endpoint.
  - Verify: `cd service && php artisan test --filter=OpenApiTest`.
- [x] 2.3 Committed export and drift check.
  - RED: `service/tests/Feature/OpenApiDriftTest.php` regenerates the document and compares it with `service/openapi.json`; fails because the file does not exist.
  - GREEN: composer script `openapi:export` (`php artisan scramble:export --path=openapi.json`, against Postgres), commit `service/openapi.json`.
  - Verify: `cd service && php artisan scramble:export --path=openapi.json && git diff --exit-code service/openapi.json`.
- [x] 2.4 Spectral lint, pinned.
  - RED: `cd tools/spectral && bun run lint` fails (no package, no ruleset).
  - GREEN: `tools/spectral/package.json` (Spectral exactly `6.16.3`), `tools/spectral/bun.lock`, root `.spectral.yaml` (zero errors and zero warnings required: `--fail-severity=warn`); fix the document, not the ruleset, when it reports.
  - Verify: `cd tools/spectral && bun install --frozen-lockfile && bun run lint`.
- [x] 2.5 Dockerfile.
  - RED: `service/tests/Feature/DockerfileTest.php` asserts pinned `php:8.5.8-fpm-alpine`, extensions `pdo_pgsql redis pcntl`, a multi-stage build, no frontend steps; fails (no file).
  - GREEN: `service/Dockerfile` mirroring BEAI `api/Dockerfile` (read-only reference `../api/Dockerfile`), minus frontend stages. If an image tag does not resolve when built, STOP.
  - Verify: `cd service && php artisan test --filter=DockerfileTest`.
- [x] 2.6 Compose api, worker and scheduler services.
  - RED: `service/tests/Feature/ComposeTopologyTest.php` scenario "Compose services": parsed YAML lists `postgres`, `redis`, `api` (host 8100 -> 8000), `worker` (`queue:work`), `scheduler` (`schedule:work`); ports 55432/56379/8100; volume `hra-pgdata`; init script mounted.
  - GREEN: extend `service/docker-compose.yml`.
  - Verify: `cd service && php artisan test --filter=ComposeTopologyTest`.
- [x] 2.7 CI workflow `.github/workflows/service.yml`.
  - RED: `service/tests/Feature/CiWorkflowTest.php` parses the workflow: every `uses:` is pinned to a 40-hex commit SHA; jobs `service` (postgres 17 + redis 8 services; `pint --test`; `phpstan analyse`; `migrate`; `php artisan test --coverage --min=90`) and `openapi` (export, `git diff --exit-code service/openapi.json`, Spectral via `bun run lint`; `HRA_JWT_SECRET` 32-byte dummy for the non-test export step); the Bun setup step precedes any step that needs Bun. The high-integrity step is added in slice 4.
  - GREEN: `.github/workflows/service.yml`. SHAs for pinned actions must come from an authorized source (user-provided or an already-vendored lockfile); if not available, STOP and request authorization for a GitHub lookup. No push or remote run happens in this change.
  - Verify: `cd service && php artisan test --filter=CiWorkflowTest`.

## Slice 3: Accounts and keys domain

Commit: `feat(service): add account scoping, key generation and resolution, and bootstrap commands`
Specs: api-keys (commands, formats and storage, lifecycle states, mode as label), service-foundation (account scoping).
Reconciliation: 2, 3, 9. High-integrity zone (coverage >= 95).

- [x] 3.1 Account scoping that fails closed.
  - RED: `service/tests/Feature/AccountScopeTest.php` scenarios "Cross-account read", "Cross-account write", "Fail closed without context" (`LogicException` when no `CurrentAccount` is bound), "account_id in input is ignored" (not mass-assignable; `creating` hook stamps it), `CurrentAccount::runAs`.
  - GREEN: migrations `service/database/migrations/*_create_accounts_table.php` and `*_create_api_keys_table.php` (columns, unique `key_hash`, index `key_prefix`, CHECKs per design), `service/app/Accounts/{CurrentAccount,AccountScope,BelongsToAccount}.php`, `service/app/Models/{Account,ApiKey}.php` with factories.
  - Verify: `cd service && php artisan test --filter=AccountScopeTest`.
- [x] 3.2 `KeyType`, `KeyMode`, `Scope`, `KeyGenerator`.
  - RED: `service/tests/Unit/KeyGeneratorTest.php`: `hra_sk_{live|test}_` + 96 hex, `hra_pk_{live|test}_` + 48 hex, `key_prefix` is the type/mode prefix + first 8 hex, only the sha256 is stored, two keys differ; `Scope` enum values per reconciliation 4, `*` satisfies every scope. Port BEAI `ApiKeyGenerator` test first (locate: `rg -l 'ApiKeyGenerator' ../api/tests`, read-only).
  - GREEN: `service/app/Keys/{KeyType,KeyMode,Scope,KeyGenerator}.php`.
  - Verify: `cd service && php artisan test --filter=KeyGeneratorTest`.
- [x] 3.3 `KeyResolver` (hash first, state second).
  - RED: `service/tests/Unit/KeyResolverTest.php` scenarios "Valid key authenticates", "Same prefix, wrong remainder", "Malformed key" (no DB lookup), "Unknown key reveals nothing" (`unauthenticated`), "Expired key", "Revoked key" (`key_expired`/`key_revoked` only after a hash match, constant-time compare via `hash_equals`), wrong key type is `unauthenticated`, "last_used_at updated" (at most once per 60 s). Port BEAI `ApiKeyResolver` test first (read-only locate), dropping the legacy fallback and denylist cases.
  - GREEN: `service/app/Keys/KeyResolver.php`.
  - Verify: `cd service && php artisan test --filter=KeyResolverTest`.
- [x] 3.4 `hra:bootstrap-account` and `hra:issue-key`.
  - RED: `service/tests/Feature/BootstrapCommandsTest.php` scenarios "Bootstrap creates account and key" (scopes `["*"]`), "Key is not recoverable" (raw key printed once to stdout, never logged, never stored), "Test mode key" (`--mode=test`), "Unknown account on issue-key" (non-zero exit, no key).
  - GREEN: `service/app/Console/Commands/{BootstrapAccount,IssueKey}.php`.
  - Verify: `cd service && php artisan test --filter=BootstrapCommandsTest`.
- [x] 3.5 Key and account serialization exposure.
  - RED: `service/tests/Feature/ApiKeySerializationTest.php` scenario "Resource shape": model `toArray()` never contains `key_hash`; `SecretExposureTest` case for keys with `Log::spy`.
  - GREEN: `$hidden` on `ApiKey`; explicit resource lands in slice 5.
  - Verify: `cd service && php artisan test --filter=ApiKeySerializationTest && php artisan test --coverage --min=90`.

## Slice 4: Secret-key auth chain

Commit: `feat(service): add secret-key authentication, scopes, query-key rejection and rate limiting`
Specs: api-keys (scopes, secret keys reject browser origins, per-key rate limiting, publishable key grants nothing alone - SK side).
Reconciliation: 4, 8, 14. High-integrity zone.

- [x] 4.1 `RejectKeyInQuery`.
  - RED: `service/tests/Feature/RejectKeyInQueryTest.php`: `?api_key=`, `?key=` or any value starting `hra_sk_`/`hra_pk_` -> `400 api_key_in_query`; clean query passes.
  - GREEN: `service/app/Http/Middleware/RejectKeyInQuery.php`.
  - Verify: `cd service && php artisan test --filter=RejectKeyInQueryTest`.
- [x] 4.2 `AuthenticateSecretKey` and failed-auth limiter.
  - RED: `service/tests/Feature/AuthenticateSecretKeyTest.php`: "Missing credentials" (401 `unauthenticated`), "Live secret with Origin" (`403 secret_key_origin_forbidden`), "Live secret without Origin", "Test secret with Origin" (allowed), "Publishable key on SK endpoint" (401 `unauthenticated`), `key_expired`, `key_revoked`, binds `CurrentAccount` and sets `api_key_id`, `key_mode`, `key_scopes`; "Auth failure flood" (31st failure within a minute from one IP -> 429 `rate_limited`).
  - GREEN: `service/app/Http/Middleware/AuthenticateSecretKey.php`, aliases `auth.secret`, `scope`, and the middleware priority (both auth middlewares ahead of `SubstituteBindings`) in `service/bootstrap/app.php`.
  - Verify: `cd service && php artisan test --filter=AuthenticateSecretKeyTest`.
- [x] 4.3 `RequireScope`.
  - RED: `service/tests/Feature/RequireScopeTest.php` scenarios "Missing scope" (403 `insufficient_scope`), "Wildcard" (`*` passes every scope).
  - GREEN: `service/app/Http/Middleware/RequireScope.php`.
  - Verify: `cd service && php artisan test --filter=RequireScopeTest`.
- [x] 4.4 Rate limiters (secret + failed-auth; publishable arrives in slice 6).
  - RED: `service/tests/Feature/RateLimitTest.php` scenarios "Limit exceeded" (601st request in a minute from one secret key -> 429 `rate_limited`, `Retry-After`, `X-RateLimit-Limit`/`X-RateLimit-Remaining` present), "Separate budgets" (two keys do not share), env override `HRA_RATE_LIMIT_SECRET`.
  - GREEN: `RateLimiter::for('secret'|'failed-auth')` in `service/app/Providers/AppServiceProvider.php`, `throttle:secret` in the secret chain.
  - Verify: `cd service && php artisan test --filter=RateLimitTest`.
- [x] 4.5 High-integrity coverage file and CI step.
  - RED: `cd service && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` fails (config missing).
  - GREEN: `service/phpunit.high-integrity.xml` (coverage source `app/Keys`, `app/Accounts`, `app/Http/Middleware`; slices 21 and 25 add the signer and lifecycle); add the step to `.github/workflows/service.yml` and extend `service/tests/Feature/CiWorkflowTest.php` to assert it.
  - Verify: `cd service && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95 && php artisan test --filter=CiWorkflowTest`.

## Slice 5: API key endpoints

Commit: `feat(service): add API key management with rotation, publishable keys and cursor pagination`
Specs: api-keys (management endpoints, rotation, origin allowlist, scopes, mode label), service-foundation (pagination).
Reconciliation: 4, 10, 13, 32.

- [x] 5.1 `PaginationRequest` and cursor convention.
  - RED: `service/tests/Feature/PaginationTest.php` scenarios "Default page" (25), "Following the cursor", "Invalid limit" (`limit=500` -> 422 `validation_failed`), malformed cursor -> 422 (Laravel would silently serve page 1), order `created_at desc, id desc`, shape `{data, next_cursor}`.
  - GREEN: `service/app/Http/Requests/PaginationRequest.php`, helper used by list controllers.
  - Verify: `cd service && php artisan test --filter=PaginationTest`.
- [x] 5.2 `OriginNormalizer`.
  - RED: `service/tests/Unit/OriginNormalizerTest.php` scenarios "Normalization" (lowercase, `scheme://host[:port]`, no path/trailing slash), "Wildcard rejected", "Plain http refused except loopback" (`http://localhost`, `http://127.0.0.1` any port), 1-20 entries.
  - GREEN: `service/app/Keys/OriginNormalizer.php`.
  - Verify: `cd service && php artisan test --filter=OriginNormalizerTest`.
- [x] 5.3 Create and list keys.
  - RED: `service/tests/Feature/ApiKeyEndpointTest.php` scenarios "Create returns the key once", "Publishable key needs origins", "Secret key must not carry origins", "Unknown scope" (422), secret key requires an explicit non-empty `scopes` list (no API default), list is paginated and never returns the raw key or hash, "Another account's key" -> 404; `service/tests/Feature/ApiKeyScopeTest.php` (security table): creating a key with a scope the caller lacks -> `403 insufficient_scope` and nothing created (a `*` caller may), other mode -> `403 forbidden`.
  - GREEN: `service/app/Http/Controllers/V1/ApiKeyController.php`, `service/app/Http/Requests/CreateApiKeyRequest.php`, `service/app/Http/Resources/ApiKeyResource.php` (explicit fields), routes under `auth.secret` + `scope:api_keys:manage`.
  - Verify: `cd service && php artisan test --filter='ApiKeyEndpointTest|ApiKeyScopeTest'`.
- [x] 5.4 Revoke and rotate.
  - RED: `service/tests/Feature/ApiKeyRotationTest.php` scenarios "Revoke is idempotent" (second revoke keeps the original `revoked_at`, 200), "Overlap" (default 3600 s: both keys work), "Old key dies after overlap" (`Carbon::setTestNow`), "Immediate rotation" (`overlap_seconds: 0` sets `revoked_at`), "Lineage" (`rotated_from_id`), "Rotate a revoked key" (409 `invalid_state`), `overlap_seconds` range 0..604800, scope/mode checks as in create, no self-revoke guard.
  - GREEN: rotate/revoke actions on `ApiKeyController`, `service/app/Http/Requests/RotateApiKeyRequest.php`.
  - Verify: `cd service && php artisan test --filter=ApiKeyRotationTest && php artisan test --coverage --min=90`.

## Slice 6: Client authentication and CORS

Commit: `feat(service): add client_secret JWT, client authentication chain and per-key CORS`
Specs: api-keys (per-conversation client_secret, publishable key grants nothing alone, origin allowlist, rate limiting).
Reconciliation: 11, 12, 13, 14. High-integrity zone.
Note: the real client routes arrive in slices 26-27; here the tests register a temporary route under the full chain with a plain `{conversation}` string parameter.

- [x] 6.1 `ClientSecret` mint and parse.
  - RED: `service/tests/Unit/ClientSecretTest.php`: claims `iss hr-avatar`, `aud hr-avatar-client`, `sub conv_...`, `acc`, `jti` (ULID), `iat`, `exp = created + 900 + max_duration_seconds`, no `mode` claim; "Algorithm none" and tampered claims -> invalid; wrong `iss`/`aud` -> invalid; "Expired token" via `Carbon::setTestNow`; HS256 only. Port BEAI `SessionTokenMinter` tests (locate: `rg -l 'SessionTokenMinter' ../api/tests`, read-only).
  - GREEN: `service/app/Keys/ClientSecret.php`, `service/app/Keys/VerifiedClientSecret.php` (lcobucci/jwt, `Configuration::forSymmetricSigner(new Sha256, ...)`, manual `iss`/`aud`/`exp` checks).
  - Verify: `cd service && php artisan test --filter=ClientSecretTest`.
- [x] 6.2 `AuthenticateClient`.
  - RED: `service/tests/Feature/AuthenticateClientTest.php` and `ClientSecretBindingTest.php`: "Publishable alone" (401 `unauthenticated`), "Secret key in the publishable header" (401), missing/other `Origin` -> `403 origin_not_allowed`, "Valid token", "Token for another conversation" and "Publishable key of another account" (403 `forbidden`), tampered/`alg none` -> `401 client_secret_invalid`, expired -> `401 client_secret_expired`, missing bearer -> 401 `unauthenticated`, every 401 counts on the failed-auth limiter, binds `CurrentAccount`.
  - GREEN: `service/app/Http/Middleware/AuthenticateClient.php`, alias `auth.client`, priority entry.
  - Verify: `cd service && php artisan test --filter='AuthenticateClientTest|ClientSecretBindingTest'`.
- [x] 6.3 `ClientCors`.
  - RED: `service/tests/Feature/ClientCorsTest.php`: OPTIONS -> 204 echoing `Origin`, `Access-Control-Allow-Methods: POST`, headers including `X-HRA-Publishable-Key` and `X-Request-Id`, `Access-Control-Max-Age: 600`, `Vary: Origin`; a real request gets `Access-Control-Allow-Origin` only after the allowlist passed; a rejected origin gets none; `HandleCors` is not in the global stack; limited to `v1/client/*`.
  - GREEN: `service/app/Http/Middleware/ClientCors.php`, registration in `service/bootstrap/app.php`.
  - Verify: `cd service && php artisan test --filter=ClientCorsTest`.
- [x] 6.4 Publishable limiter.
  - RED: extend `service/tests/Feature/RateLimitTest.php`: 301st request/min per publishable key -> 429; "Separate budgets" between secret and publishable; env `HRA_RATE_LIMIT_PUBLISHABLE`.
  - GREEN: `RateLimiter::for('publishable')` in `service/app/Providers/AppServiceProvider.php`.
  - Verify: `cd service && php artisan test --filter=RateLimitTest && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`.

## Slice 7: Credentials and LLM

Commit: `feat(service): add provider and LLM credentials, resolvers and the LLM model catalogue`
Specs: credentials (all requirements).
Reconciliation: 29. Design AD5, credentials section.

- [x] 7.1 Schema, models, seeder.
  - RED: `service/tests/Feature/CredentialSchemaTest.php`: unique `(account_id, provider)` and `(account_id, llm_provider)`; `api_key` stored encrypted (raw column value differs from plaintext, "Encrypted at rest"); `LlmModelSeeder` idempotent (twice -> same row count); `llm_models` has no `account_id`.
  - GREEN: migrations for `provider_credentials`, `llm_models`, `llm_credentials`; models `ProviderCredential`, `LlmModel`, `LlmCredential` (encrypted cast, `$hidden`); `service/database/seeders/LlmModelSeeder.php`; `credential_providers` list and `platform_keys` in `service/config/hr-avatar.php`.
  - Verify: `cd service && php artisan test --filter=CredentialSchemaTest`.
- [x] 7.2 Resolvers.
  - RED: `service/tests/Unit/CredentialResolverTest.php` scenarios "Account wins over platform", "Platform fallback", "Neither" (null), "No cross-account use", "Mock needs none" (registry `credential: false` skip is exercised in slice 26); `LlmCredentialResolverTest` (account only, no platform fallback), `ProviderAuth` fingerprint = first 16 hex of sha256 of the key and a redacting `__debugInfo`.
  - GREEN: `service/app/Credentials/{ProviderAuth,CredentialResolver,LlmCredentialResolver}.php`.
  - Verify: `cd service && php artisan test --filter='CredentialResolverTest|LlmCredentialResolverTest'`.
- [x] 7.3 Provider credential endpoints.
  - RED: `service/tests/Feature/ProviderCredentialEndpointTest.php` scenarios "Create", "Replace", "Unknown provider" (404), "Scope required" (`credentials:manage`), "Sources" (`account|platform|none`), "Delete falls back", "Delete is idempotent" (204), "Short key" (`last_four` null for keys under 12 chars and for platform/none), fixed-size list not paginated, "Account isolation of listing".
  - GREEN: `service/app/Http/Controllers/V1/ProviderCredentialController.php`, request, resource, routes.
  - Verify: `cd service && php artisan test --filter=ProviderCredentialEndpointTest`.
- [x] 7.4 LLM models and LLM credential endpoints.
  - RED: `service/tests/Feature/LlmEndpointsTest.php` scenarios "Only enabled models", "Read-only" (POST -> 405), "Store and list", "Unknown LLM provider" (404), scope `llm_models:read` / `credentials:manage`, idempotent 204 delete.
  - GREEN: `LlmModelController`, `LlmCredentialController`, requests, resources, routes.
  - Verify: `cd service && php artisan test --filter=LlmEndpointsTest`.
- [x] 7.5 Non-disclosure sweep.
  - RED: `service/tests/Feature/CredentialExposureTest.php` scenarios "Model serialization", "Failed job payload" (a queued job holding a credential never serializes the plaintext), response scan and `Log::spy` for every credential response.
  - GREEN: fix any leak found (resources use explicit field lists, `$hidden`, `SensitiveParameter`).
  - Verify: `cd service && php artisan test --filter=CredentialExposureTest && php artisan test --coverage --min=90`.

## Slice 8: Avatar config and ledger persistence, LLM binding

Commit: `feat(service): add avatar config and provider resource persistence with LLM binding resolution`
Specs: avatar-configs (resource shape), credentials (LLM binding). Design AD6.

- [x] 8.1 Prefixed ids.
  - RED: `service/tests/Unit/HasPrefixedIdTest.php`: `avc_` + ULID, 31-char string key, prefix is per model.
  - GREEN: `service/app/Accounts/HasPrefixedId.php`.
  - Verify: `cd service && php artisan test --filter=HasPrefixedIdTest`.
- [x] 8.2 `avatar_configs` and `provider_resources`.
  - RED: `service/tests/Feature/AvatarConfigModelTest.php` and `ProviderResourceTest.php`: columns and CHECKs from the design data model (`sync_status` values, soft delete, `llm_model_id` FK restrict), `provider_state` jsonb round-trip, unique `(account_id, provider, key_fingerprint, kind, natural_key)`, both models carry `BelongsToAccount`, cross-account read is impossible.
  - GREEN: migrations, `service/app/Models/{AvatarConfig,ProviderResource}.php`, factories.
  - Verify: `cd service && php artisan test --filter='AvatarConfigModelTest|ProviderResourceTest'`.
- [x] 8.3 `LlmBinding` and `LlmBindingResolver`.
  - RED: port BEAI `LlmBindingResolver` tests (locate: `rg -l 'LlmBindingResolver' ../api/tests`, read-only): binding resolved from `AvatarConfig` + account credential; no `heygenConfigurationId` (read from `provider_state` by the adapter); `LlmBinding::__debugInfo` redacts the key; missing credential -> null.
  - GREEN: `service/app/Llm/{LlmBinding,LlmBindingResolver}.php`.
  - Verify: `cd service && php artisan test --filter=LlmBinding`.

## Slice 9: Provider contract, registry, Mock

Commit: `feat(service): add the provider contract, registry and Mock adapter`
Specs: provider-adapters (provider contract, mock adapter, error redaction, contract fixtures). Reconciliation: 22, 23, 24, 33.

- [x] 9.1 Value objects, enums, exceptions.
  - RED: port BEAI `ProviderTokenTest` (`../api/tests/Unit/C7a/ProviderTokenTest.php`, read-only) as `service/tests/Unit/AvatarProviders/ProviderSessionTest.php`: scenario "Join material shape" (non-empty guard, no key material, `expiresAt`); `ProviderExceptionTest`: scenario "Redacted message" (`Provider request failed (status N)`, no body/header/credential, status class only in logs), `FailureClass::Throttle|Upstream|ClientError`.
  - GREEN: `service/app/AvatarProviders/{ConversationSpec,JoinMaterial,ProviderSession,SessionStatus,TranscriptLine,TranscriptNotReady,TranscriptShapeError,ProviderEvent,ProviderEventKind,Ingestion,ProviderException,FailureClass}.php`.
  - Verify: `cd service && php artisan test --filter='ProviderSessionTest|ProviderExceptionTest'`.
- [x] 9.2 `AvatarProvider` (runtime methods) and `ProviderRegistry`.
  - RED: `service/tests/Unit/AvatarProviders/ProviderRegistryTest.php` scenarios "Registry resolves adapters", "Unknown provider" (domain error before any call), "Ingestion mode per adapter", `ids()`, `withIngestion()`, `requiresCredential()`, `eventKeyPrefix()` (`heygen` -> `liveavatar`); registry data lives only in `config/hr-avatar.php`.
  - GREEN: `service/app/AvatarProviders/{AvatarProvider,ProviderRegistry}.php`, `providers` block in `service/config/hr-avatar.php` (only `mock` at this point; `tavus` in slice 10, `heygen` in slice 13).
  - Verify: `cd service && php artisan test --filter=ProviderRegistryTest`.
- [x] 9.3 Mock adapter.
  - RED: `service/tests/Unit/AvatarProviders/MockProviderTest.php` scenarios "Deterministic output" (`mock_{id}` refs, `mock://conversation/{id}`, `mock_token_{id}`, `expiresAt` now + 3600), "No network" (`Http::preventStrayRequests()`), "Session ends after teardown" (cache flag `hra:mock:ended:{ref}`; `fetchSession` ended once set), `fetchTranscript` returns one `avatar` line with the opening text, else `[]`, `parseCallback` `[]`, `ingestion` `None`.
  - GREEN: `service/app/AvatarProviders/Mock/MockProvider.php`.
  - Verify: `cd service && php artisan test --filter=MockProviderTest`.
- [x] 9.4 Contract-fixture guard.
  - RED: `service/tests/Feature/AvatarProviders/StrayRequestsContractTest.php` scenario "Stray requests blocked": a provider call without `Http::fake` throws.
  - GREEN: already provided by `Pest.php`; assert it for the adapter namespace.
  - Verify: `cd service && php artisan test --filter=StrayRequestsContractTest && php artisan test --coverage --min=90`.

## Slice 10: Tavus adapter - start

Commit: `feat(service): add Tavus adapter start with payload mapping and concurrency guard`
Specs: provider-adapters (Tavus adapter start, concurrency guard, provider key never leaks). Reconciliation: 17, 33.

- [x] 10.1 `TavusLanguage` and `TavusPayload` (pure).
  - RED: port from BEAI `TavusProviderTest` (`../api/tests/Unit/C7a/TavusProviderTest.php`, read-only) the pure mapping cases: language map, body shape `conversational_context`, `custom_greeting`, `properties.{language, max_call_duration, participant_left_timeout?}`, `callback_url`, `replica_id`/`persona_id` from config.
  - GREEN: `service/app/AvatarProviders/Tavus/{TavusLanguage,TavusPayload}.php`.
  - Verify: `cd service && php artisan test --filter='TavusLanguage|TavusPayload'`.
- [x] 10.2 `TavusConcurrencyGuard`.
  - RED: port BEAI `TavusConcurrencyGuardTest` (`../api/tests/Unit/C7a/TavusConcurrencyGuardTest.php`, read-only): scenarios "Retry then succeed", "Exhausted" (`FailureClass::Throttle`), "Non-concurrency 4xx and 5xx are not retried", "Retries disabled" (`tavus.concurrency_retries` 0); `TavusConcurrencyGuardScanTest` (file scan) scenario "Never reaps other conversations": no list or end call to other conversations.
  - GREEN: `service/app/AvatarProviders/Tavus/TavusConcurrencyGuard.php`.
  - Verify: `cd service && php artisan test --filter='TavusConcurrencyGuard'`.
- [x] 10.3 `TavusProvider::start`.
  - RED: `service/tests/Unit/AvatarProviders/Tavus/TavusProviderStartTest.php` scenarios "Request mapping" (verb, path `POST /v2/conversations`, headers and body pinned with `Http::fake`), "Join material" (transport `daily`, `sessionToken` null, `expiresAt`), "Provider key never leaks" (`KeyNeverLeaksTest`: key absent from exceptions, logs, `JoinMaterial`), golden-body `ContractFixtureTest` port (`rg -l 'ProviderContractFixtureTest' ../api/tests`, read-only), 4xx/5xx -> `ProviderException`.
  - GREEN: `service/app/AvatarProviders/Tavus/TavusProvider.php` (`ingestion()` returns `Callback`; other operations arrive in slice 11), `tavus` entry in `service/config/hr-avatar.php` (`credential: true`).
  - Verify: `cd service && php artisan test --filter='TavusProviderStartTest|KeyNeverLeaksTest|ContractFixtureTest'`.

## Slice 11: Tavus adapter - remaining operations

Commit: `feat(service): add Tavus teardown, transcript, session status and callback event mapping`
Specs: provider-adapters (teardown, transcript reconciliation, session status), provider-webhooks (mapping). Reconciliation: 20, 25.

- [x] 11.1 Teardown.
  - RED: `service/tests/Unit/AvatarProviders/Tavus/TavusTeardownTest.php` scenarios "Normal teardown" (`POST /v2/conversations/{id}/end`, returns true), "Already gone" (404 counts as released), "Provider failure" (returns false, never throws, no message leak).
  - GREEN: `TavusProvider::teardown`.
  - Verify: `cd service && php artisan test --filter=TavusTeardownTest`.
- [x] 11.2 Transcript and session status.
  - RED: `TavusTranscriptTest`: scenario "Tavus mapping" (`assistant`/`replica` -> `avatar`, `user` -> `user`, `system` excluded), "Unknown role" (`TranscriptShapeError`), "Empty transcript" (`TranscriptNotReady`); `TavusSessionStatusTest`: status not active -> `ended`, an HTTP error throws `ProviderException` (an error is never an end).
  - GREEN: `TavusProvider::fetchTranscript`, `fetchSession`.
  - Verify: `cd service && php artisan test --filter='TavusTranscriptTest|TavusSessionStatusTest'`.
- [x] 11.3 `TavusEventMapper` and `parseCallback`.
  - RED: `service/tests/Unit/AvatarProviders/Tavus/TavusEventMapperTest.php`: `system.pal_joined` and legacy `system.replica_joined` -> `Joined`; `system.shutdown` -> `Ended` with `providerEndReason` from `properties.shutdown_reason`, else `properties.reason`, else `unknown`; `application.transcription_ready` -> `TranscriptReady` carrying rows; anything else -> `Ignored`; idempotency key `sha256(conversation_id|event_type|timestamp)`.
  - GREEN: `service/app/AvatarProviders/Tavus/TavusEventMapper.php`, `TavusProvider::parseCallback`.
  - Verify: `cd service && php artisan test --filter='TavusEventMapperTest' && php artisan test --coverage --min=90`.

## Slice 12: LiveAvatar registrars

Commit: `feat(service): add LiveAvatar LLM and voice registrars over the provider resource ledger`
Specs: provider-adapters (HeyGen LiveAvatar adapter), avatar-configs (voice binding). Design AD6.

- [x] 12.1 `LiveAvatarLlmRegistrar`.
  - RED: port BEAI `HeygenLlmRegistrar` tests (locate: `rg -l 'HeygenLlmRegistrar' ../api/tests`, read-only): secret created once per `(account, key fingerprint)` in `provider_resources` kind `llm_secret`, configuration created/updated and its id stored in `provider_state.llm_configuration_id`, idempotent re-run, switching the provider key creates new ledger rows, account B never reuses account A's secret, `Http::fake` verb/path/body pinned.
  - GREEN: `service/app/AvatarProviders/LiveAvatar/LiveAvatarLlmRegistrar.php`.
  - Verify: `cd service && php artisan test --filter=LiveAvatarLlmRegistrar`.
- [x] 12.2 `LiveAvatarVoiceRegistrar`.
  - RED: port BEAI `HeygenVoiceRegistrar` tests (locate: `rg -l 'HeygenVoiceRegistrar' ../api/tests`, read-only): vendor secret and bound voice rows (`vendor_secret`, `voice`), lock around concurrent binds, "never trust bound language", per-account vendor keys from `CredentialResolver`, unbindable voice raises `ProviderException` before any billable call, cross-account isolation.
  - GREEN: `service/app/AvatarProviders/LiveAvatar/LiveAvatarVoiceRegistrar.php`.
  - Verify: `cd service && php artisan test --filter=LiveAvatarVoiceRegistrar && php artisan test --coverage --min=90`.

## Slice 13: LiveAvatar adapter - start

Commit: `feat(service): add LiveAvatar adapter start with context, token and cleanup`
Specs: provider-adapters (HeyGen LiveAvatar adapter start). Reconciliation: 23.

- [x] 13.1 `LiveAvatarPayload` (pure).
  - RED: `service/tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarPayloadTest.php` scenario "Field allowlist" (only allowlisted config fields reach the token body; `mode FULL`, `avatar_persona.context_id`, `avatar_persona.language`, `llm_configuration_id`, bound voice; the env widening path is gone).
  - GREEN: `service/app/AvatarProviders/LiveAvatar/LiveAvatarPayload.php`.
  - Verify: `cd service && php artisan test --filter=LiveAvatarPayloadTest`.
- [x] 13.2 `LiveAvatarProvider::start`.
  - RED: port BEAI `HeygenProviderTest` (`../api/tests/Unit/C7a/HeygenProviderTest.php`, read-only) cases to `service/tests/Unit/AvatarProviders/LiveAvatar/LiveAvatarProviderStartTest.php`: scenario "Context then token" (`POST /v1/contexts` with `name = hra-{conversationId}-{ulid}`, `prompt`, `opening_text`; then `POST /v1/sessions/token`), "Token failure cleans up the context", unbindable voice fails before any billable call, `callbackUrl` ignored, transport `liveavatar`, `ingestion` `Poll`, provider key never leaks, golden bodies.
  - GREEN: `service/app/AvatarProviders/LiveAvatar/LiveAvatarProvider.php` (start only), `heygen` entry in `service/config/hr-avatar.php` (`credential: true`, `event_key_prefix: liveavatar`).
  - Verify: `cd service && php artisan test --filter='LiveAvatarProviderStartTest' && php artisan test --coverage --min=90`.

## Slice 14: LiveAvatar adapter - remaining operations

Commit: `feat(service): add LiveAvatar teardown, transcript and session status`
Specs: provider-adapters (teardown, transcript reconciliation, session status polling surface). Reconciliation: 20, 24.

- [x] 14.1 Teardown.
  - RED: `LiveAvatarTeardownTest`: scenarios "Normal teardown" (`POST /v1/sessions/stop {session_id, reason: USER_CLOSED}` then context delete), "Already gone", "Provider failure" (false, no throw), "Context removal".
  - GREEN: `LiveAvatarProvider::teardown`.
  - Verify: `cd service && php artisan test --filter=LiveAvatarTeardownTest`.
- [x] 14.2 Transcript.
  - RED: `LiveAvatarTranscriptTest`: scenario "LiveAvatar mapping" (`GET /v1/sessions/{id}/transcript`, rows `{role, transcript, absolute_timestamp}` -> lines), `session_active=true` -> `TranscriptNotReady`, "Unknown role"/shape drift -> `TranscriptShapeError`.
  - GREEN: `LiveAvatarProvider::fetchTranscript`.
  - Verify: `cd service && php artisan test --filter=LiveAvatarTranscriptTest`.
- [x] 14.3 Session status and callbacks.
  - RED: `LiveAvatarSessionStatusTest`: scenarios "LiveAvatar active", "LiveAvatar ended" (`end_at` or `end_reason` non-null; `endReason` = `end_reason`), "Error is not an end" (HTTP error throws `ProviderException`); `parseCallback` returns `[]`.
  - GREEN: `LiveAvatarProvider::fetchSession`, `parseCallback`.
  - Verify: `cd service && php artisan test --filter='LiveAvatarSessionStatusTest' && php artisan test --coverage --min=90`.

## Slice 15: Field specs, config validation, voice binding

Commit: `feat(service): add provider field specs, config validation, describe and syncRequired`
Specs: avatar-configs (config validation, voice, sync gate). Reconciliation: 30, 31.

- [x] 15.1 Field spec framework.
  - RED: `service/tests/Unit/AvatarProviders/FieldSpecValidatorTest.php` (port BEAI `ConfigValidator`/`ProviderFieldSpecs` tests: `rg -l 'ConfigValidator' ../api/tests`, read-only): unknown key -> `config.<key>` error, required keys, types, enums, limits.
  - GREEN: `service/app/AvatarProviders/{FieldSpec,FieldType,FieldError,FieldSpecValidator,VoiceBinding,ProviderDescription}.php`.
  - Verify: `cd service && php artisan test --filter=FieldSpecValidatorTest`.
- [x] 15.2 Per-provider specs and `describe`/`validateConfig`/`syncRequired`.
  - RED: `TavusFieldSpecsTest`, `LiveAvatarFieldSpecsTest`, `MockValidateConfigTest` (`mock` accepts `{}`): voice engine must be in the adapter's `describe()->voiceEngines`, otherwise a field error; `syncRequired` true for Tavus and for LiveAvatar with an LLM or an external voice, false for Mock and plain LiveAvatar. Back-office labels dropped.
  - GREEN: add `describe`, `validateConfig`, `syncRequired` to the `AvatarProvider` interface and to `MockProvider`, `TavusProvider`, `LiveAvatarProvider`; `service/app/AvatarProviders/Tavus/TavusFieldSpecs.php`, `service/app/AvatarProviders/LiveAvatar/LiveAvatarFieldSpecs.php`.
  - Verify: `cd service && php artisan test --filter='FieldSpecsTest|MockValidateConfigTest|SyncRequired' && php artisan test --coverage --min=90`.

## Slice 16: Avatar config endpoints

Commit: `feat(service): add avatar config CRUD endpoints with soft delete`
Specs: avatar-configs (resource and CRUD). Reconciliation: 30, 32.

- [x] 16.1 Create, read, list.
  - RED: `service/tests/Feature/AvatarConfigEndpointTest.php` scenarios "Create" (initial `sync_status` from `syncRequired`), validation through `validateConfig` (422 `config.<key>`), unknown provider 422, `llm_model_id` must reference an enabled model, paginated list, show, scopes `avatar_configs:read|write`, cross-account 404 on show/patch/delete.
  - GREEN: `service/app/Http/Controllers/V1/AvatarConfigController.php`, `service/app/Http/Requests/{CreateAvatarConfigRequest,UpdateAvatarConfigRequest}.php`, `service/app/Http/Resources/AvatarConfigResource.php` (never `provider_state` internals, never credentials), routes.
  - Verify: `cd service && php artisan test --filter=AvatarConfigEndpointTest`.
- [x] 16.2 Update and soft delete.
  - RED: scenarios "Provider is immutable" (PATCH with another provider -> 422), "Soft delete keeps history" (deleted config absent from list, `deleted_at` set), PATCH of `config`/`persona`/`llm_model_id`/`voice` re-evaluates `syncRequired` and resets to `pending` (dispatch lands in slice 17).
  - GREEN: update/destroy actions. The in-use guard and "deleted config cannot start conversations" need the `conversations` table and land in slice 20.
  - Verify: `cd service && php artisan test --filter=AvatarConfigEndpointTest && php artisan test --coverage --min=90`.

## Slice 17: Config sync

Commit: `feat(service): add queued avatar config sync with Tavus persona and LiveAvatar registration`
Specs: avatar-configs (sync requirement). Reconciliation: 30, 33.

- [x] 17.1 `TavusPersonaSync`.
  - RED: port BEAI `TavusPalSync` tests (locate: `rg -l 'TavusPalSync' ../api/tests`, read-only): `PATCH /v2/pals/{palId}` layers + LLM binding body pinned, idempotent, credentials from `ProviderAuth`.
  - GREEN: `service/app/AvatarProviders/Tavus/TavusPersonaSync.php`.
  - Verify: `cd service && php artisan test --filter=TavusPersonaSync`.
- [x] 17.2 Adapter `sync` and `SyncResult`.
  - RED: `service/tests/Unit/AvatarProviders/SyncTest.php`: Tavus delegates to `TavusPersonaSync`; LiveAvatar calls both registrars and stores refs; Mock returns `SyncResult::notRequired()`; `ProviderException` propagates redacted.
  - GREEN: add `sync` to the interface and adapters, `service/app/AvatarProviders/SyncResult.php`.
  - Verify: `cd service && php artisan test --filter=SyncTest`.
- [x] 17.3 `SyncAvatarConfig` job and endpoint.
  - RED: `service/tests/Feature/SyncAvatarConfigTest.php`: success -> `synced` + `synced_at`; tries 3, backoff `[30,120]`; `failed()` -> `failed` with `sync_error` through `SecretRedactor`-style redaction (no key, no provider body); `POST /v1/avatar-configs/{id}/sync` -> 202 and dispatches; create/PATCH of a `pending` config dispatches; `Queue::fake` and a real run with `Http::fake`.
  - GREEN: `service/app/Jobs/SyncAvatarConfig.php`, `service/app/Conversations/AvatarConfigSync.php` (dispatch helper), route, controller wiring. If redaction needs the `SecretRedactor` from slice 21, implement the minimal `Redact` helper here and have slice 21 reuse it (no duplicate).
  - Verify: `cd service && php artisan test --filter=SyncAvatarConfigTest && php artisan test --coverage --min=90`.

## Slice 18: Catalogue

Commit: `feat(service): add provider catalogue endpoints with cache`
Specs: avatar-configs (catalogue). Reconciliation: 31.

- [x] 18.1 Adapter catalogues.
  - RED: port BEAI `AvatarProviderCatalogueTest`, `TavusPalEditableCatalogueTest` (locate by name in `../api/tests`, read-only) as `service/tests/Unit/AvatarProviders/{TavusCatalogue,LiveAvatarCatalogue}Test.php`; Italian-detection assertions dropped; entries normalized `{id, name, language|null, preview_audio_url|null, preview_audio_via_api, preview_image_url|null, preview_video_url|null}`, `language` only when the provider states it.
  - GREEN: `service/app/AvatarProviders/{CatalogueEntry}.php`, `Tavus/TavusCatalogue.php`, `LiveAvatar/LiveAvatarCatalogue.php`, `catalogue` on the interface and adapters (Mock returns `[]`).
  - Verify: `cd service && php artisan test --filter='TavusCatalogueTest|LiveAvatarCatalogueTest'`.
- [x] 18.2 Endpoints and cache.
  - RED: `service/tests/Feature/CatalogueEndpointTest.php`: `GET /v1/catalogue` returns `describe()` per provider with no provider call; `GET /v1/catalogue/{provider}/{resource}` unknown pair 404, missing credential `422 provider_credential_missing`, provider failure `502 provider_error`; cache key `(credential owner, key fingerprint, provider, resource)` TTL 300 s; `fresh=1` bypasses; account A's cache never serves account B (different fingerprint).
  - GREEN: `service/app/Http/Controllers/V1/CatalogueController.php`, routes (`avatar_configs:read`).
  - Verify: `cd service && php artisan test --filter=CatalogueEndpointTest && php artisan test --coverage --min=90`.

## Slice 19: Voice vendors and preview

Commit: `feat(service): add voice vendors and voice preview with cached audio`
Specs: avatar-configs (voice preview). Reconciliation: 31.

- [x] 19.1 Vendors.
  - RED: port BEAI `VoiceProviderCatalogueTest` (read-only locate) to `service/tests/Unit/Voices/{CartesiaVoices,ElevenLabsVoices,LiveAvatarVoices}Test.php`; `VoiceVendorRegistryTest` (`cartesia`, `elevenlabs`, `heygen` -> class; unknown -> null).
  - GREEN: `service/app/Voices/{VoiceVendor,VoiceVendorRegistry,CartesiaVoices,ElevenLabsVoices}.php`, `service/app/AvatarProviders/LiveAvatar/LiveAvatarVoices.php`, `voice_vendors` config.
  - Verify: `cd service && php artisan test --filter='CartesiaVoices|ElevenLabsVoices|LiveAvatarVoices|VoiceVendorRegistry'`.
- [x] 19.2 `VoicePreview` (`CatalogueSample` dropped, reconciliation 43).
  - RED: port BEAI `VoicePreviewService` tests (read-only locate): `service/tests/Unit/Voices/VoicePreviewTest.php`: phrase per language from `hr-avatar.voice_preview.phrases`, audio cached per `(vendor, voice, model, phrase_version, language)`, vendor = `tts_engine ?? provider`; `CatalogueSampleTest`.
  - GREEN: `service/app/Voices/{VoicePreview,CatalogueSample}.php`, `voice_preview` config.
  - Verify: `cd service && php artisan test --filter='VoicePreviewTest|CatalogueSampleTest'`.
  - Note (apply): `CatalogueSample` was NOT built and is dropped for good (reconciliation 43). No spec requirement or route consumes Cartesia's catalogue clip, and the quality gate rejected the ported class as unreachable and speculative. `VoicePreview` is complete; see `apply-progress.md` slice 19.
- [x] 19.3 Endpoint.
  - RED: `service/tests/Feature/VoicePreviewEndpointTest.php`: `POST /v1/voice-preview` -> `200 {content_type, audio_base64}`; `text` key -> 422; `tavus` with null `tts_engine` -> 422 with no provider call; missing credential 422; provider failure 502; no key in any response.
  - GREEN: `service/app/Http/Controllers/V1/VoicePreviewController.php`, request, route.
  - Verify: `cd service && php artisan test --filter=VoicePreviewEndpointTest && php artisan test --coverage --min=90`.

## Slice 20: Conversations (model, create, show)

Commit: `feat(service): add conversations and events schema with create and read endpoints`
Specs: conversations (create, read, config in-use). Reconciliation: 1, 15, 18.

- [x] 20.1 Schema and enums.
  - RED: `service/tests/Feature/ConversationSchemaTest.php` (rewrite of BEAI `MigrationSchemaTest`): `conversations` columns, unique `callback_token_hash`, the three indexes, CHECKs for status/ended_reason/transcript_status, `metadata` is `json` not `jsonb`, `conversation_events` uniques `(conversation_id, idempotency_key)` and `(conversation_id, sequence)`, FK cascade, both models scoped by account.
  - GREEN: migrations, `service/app/Models/{Conversation,ConversationEvent}.php`, `service/app/Conversations/{ConversationStatus,EndedReason,TranscriptStatus}.php`, factories.
  - Verify: `cd service && php artisan test --filter=ConversationSchemaTest`.
- [x] 20.2 `POST /v1/conversations`.
  - RED: `service/tests/Feature/ConversationCreateTest.php` scenarios "Happy path" (201, `conv_` id, status `created`, `client_secret`, `client_secret_expires_at`, no provider call and no credential call under `Http::preventStrayRequests()`), "Default duration" (1800), "Above ceiling", "Oversize metadata" (> 8 KB), "Config not synced" (409 `config_not_synced`), "Config of another account" (404), "Opaque host fields round-trip" (`{}` stays an object, key order preserved, `external_ref` untouched); `language` `^[a-z]{2}$`, `system_prompt` 1-32000; `account_id` in body ignored.
  - GREEN: `service/app/Http/Controllers/V1/ConversationController.php` (store), `service/app/Http/Requests/CreateConversationRequest.php` (raw body `json_decode(..., false)` for `metadata`, AD13), `service/app/Http/Resources/ConversationResource.php`, route `conversations:write`.
  - Verify: `cd service && php artisan test --filter=ConversationCreateTest`.
- [x] 20.3 `GET /v1/conversations/{id}`.
  - RED: `ConversationShowTest` scenarios "Read own", "No internals" (no provider refs, callback token or hash, `client_secret`), cross-account 404 (per-endpoint isolation scenario), scope `conversations:read`.
  - GREEN: show action.
  - Verify: `cd service && php artisan test --filter=ConversationShowTest`.
- [x] 20.4 Config in-use guard (deferred from slice 16).
  - RED: `AvatarConfigInUseTest` scenarios "Delete in use" (409 `config_in_use` while a conversation is `created`/`connecting`/`live`), a terminal conversation does not block, "Deleted config cannot start conversations" (404 on create), existing conversations keep `avatar_config_id`.
  - GREEN: guard in `AvatarConfigController::destroy`.
  - Verify: `cd service && php artisan test --filter='AvatarConfigInUseTest|AvatarConfigEndpointTest' && php artisan test --coverage --min=90`.

## Slice 21: Consumer webhooks - endpoints, signing, destination safety

Commit: `feat(service): add webhook endpoint management, HMAC signing and verification, and destination guard`
Specs: consumer-webhooks (endpoint management, SSRF, signing). Reconciliation: 26, 27, 28. High-integrity zone: `WebhookSigner`.

- [x] 21.1 `WebhookSigner` sign and verify.
  - RED: port BEAI `WebhookSigner` tests (read-only locate) to `service/tests/Unit/WebhookSignerTest.php`: scenarios "Known vector" (`v1=` + lowercase hex hmac_sha256 of `{ts}.{body}`), "Verifier accepts a fresh delivery", "Replay outside the window" (> 300 s), "Future timestamp outside the window", "Tampered body", "Wrong secret"; `hash_equals`.
  - GREEN: `service/app/Webhooks/Outbound/WebhookSigner.php`; add the file to the coverage source of `service/phpunit.high-integrity.xml`.
  - Verify: `cd service && php artisan test --filter=WebhookSignerTest`.
- [x] 21.2 `OutboundHostGuard` (`RetryClassifier` and `SecretRedactor` dropped for good, reconciliation 46; see apply-progress slice 21).
  - RED: port BEAI tests (read-only locate: `OutboundHostGuard`, `RetryClassifier`, `SecretRedactor`): private, loopback and link-local literals refused ("Private address literal"), IPv6 and IPv4-mapped forms, every resolved address checked; retry classification; redaction of secrets in messages.
  - GREEN: `service/app/Webhooks/Outbound/{OutboundHostGuard,EventType}.php` (+ `DestinationNotAllowed`, `DestinationUrl`); `DeliveryStatus` lands with its first use in slice 22.
  - Note (apply): the spec has no permanent-failure class (every non-2xx is retried) and a `last_error` from a closed set of codes can never hold a secret, so a retry classifier and a redactor would be dead code. Same reasoning as slice 17's `sync_error`.
  - Verify: `cd service && php artisan test --filter='OutboundHostGuard|RetryClassifier|SecretRedactor'`.
- [x] 21.3 Endpoint management.
  - RED: `service/tests/Feature/WebhookEndpointTest.php` scenarios "Create returns the secret once" (`hra_whsec_` + 64 hex, absent from GET/list), "Non-https URL" (allowed only in `local`/`testing`), "Embedded credentials", "Unknown event name", "Limit" (21st endpoint -> 422), "Account isolation", "Secret encrypted at rest", URL <= 2048, private-address URL -> 422 at create outside local/testing, PATCH (`url`, `events`, `enabled`, `description`), DELETE 204, paginated list, scope `webhook_endpoints:manage`.
  - GREEN: migration `webhook_endpoints`, `service/app/Models/WebhookEndpoint.php` (`secret` encrypted + hidden), `WebhookEndpointController`, requests, resource, routes.
  - Verify: `cd service && php artisan test --filter=WebhookEndpointTest && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`.

## Slice 22: Consumer webhooks - dispatch and delivery

Commit: `feat(service): add webhook dispatcher, signed delivery with retries and the delivery log`
Specs: consumer-webhooks (event emission, delivery/retries/backoff, delivery log, non-disclosure). Reconciliation: 27, 28.
Note: lifecycle-driven emission scenarios are asserted end to end in slice 25; here the dispatcher is driven directly with factory conversations.

- [x] 22.1 Deliveries schema and `WebhookDispatcher::record`.
  - RED: `service/tests/Feature/WebhookDispatcherTest.php` scenarios "Not subscribed", "Disabled endpoint", "Other accounts' endpoints", "Opaque host data echoed" (exact body: `{id: evt_..., type, created_at, data.conversation{...}}`, `transcript {status, utterance_count}` for `transcript.ready`, no transcript text/provider refs/tokens), one `evt_` id shared across the fan-out, unique `(endpoint, event_type, conversation)` so a re-record is a no-op, rows inserted inside the caller's transaction, `DeliverWebhook` dispatched `afterCommit`.
  - GREEN: migration `webhook_deliveries`, `service/app/Models/WebhookDelivery.php` (`payload` hidden), `service/app/Webhooks/Outbound/WebhookDispatcher.php`.
  - Verify: `cd service && php artisan test --filter=WebhookDispatcherTest`.
- [x] 22.2 `DeliverWebhook`.
  - RED: port BEAI `DeliverWebhookJob` tests (read-only locate) to `service/tests/Feature/DeliverWebhookTest.php`: headers `X-HRA-Signature/Timestamp/Event-Id/Event-Type`, "Success", "Retry schedule" (`[10,60,300,1800,7200]`, sixth failure -> `failed` with `completed_at`), "Timeout" (10 s), "Recovery mid-way", "Endpoint disabled during retries" (marks `failed`, no request), "Retries are re-signed" (fresh timestamp each attempt), "Redirects not followed", "DNS rebinding at delivery" (request pinned to the checked address; blocked address = failed attempt `destination_not_allowed`, no request sent), "Signing zone coverage", "Does not block the request", `failed()` safety net, `last_error` only `timeout|connection_failed|destination_not_allowed|http_<status>`.
  - GREEN: `service/app/Jobs/DeliverWebhook.php`.
  - Verify: `cd service && php artisan test --filter=DeliverWebhookTest`.
- [x] 22.3 Delivery log and non-disclosure.
  - RED: `service/tests/Feature/WebhookDeliveryLogTest.php` scenarios "Log content", "No bodies or secrets", "Filter" (`status`), "Foreign endpoint" (404); `WebhookExposureTest` "Failed job record" (the failed-job payload holds only the delivery id).
  - GREEN: `service/app/Http/Controllers/V1/WebhookDeliveryController.php`, resource, route.
  - Verify: `cd service && php artisan test --filter='WebhookDeliveryLogTest|WebhookExposureTest' && php artisan test --coverage --min=90`.

## Slice 23: Transcript reconciler

Commit: `feat(service): add merge-based transcript reconciliation job`
Specs: conversations (transcript and reconciliation). Reconciliation: 20, 21.

- [x] 23.1 `TranscriptReconciler`.
  - RED: `service/tests/Unit/TranscriptReconcilerTest.php` scenarios "Merge adds only missing" (client lines kept), "Normalized comparison" (trim, whitespace collapse, case-insensitive), "Idempotent" (second run changes nothing and emits nothing: lost CAS is a no-op), `finalize` of a conversation with no provider session as an empty `ready`, `partial` finalization, sequences continue after the existing maximum, `transcript.ready` recorded through `WebhookDispatcher` in the same transaction.
  - GREEN: `service/app/Conversations/TranscriptReconciler.php`.
  - Verify: `cd service && php artisan test --filter=TranscriptReconcilerTest`.
- [x] 23.2 `ReconcileTranscript` job.
  - RED: `service/tests/Feature/ReconcileTranscriptTest.php` scenarios "Pending", "Partial" (5 attempts then `partial` via `failed()`), `TranscriptNotReady` and `ProviderException` retry (backoff `[30,60,120,300]`), `TranscriptShapeError` fails at once, receipt rows used when passed, account isolation ("Account isolation" scenario), runs under `CurrentAccount::runAs`.
  - GREEN: `service/app/Jobs/ReconcileTranscript.php`.
  - Note (apply): "receipt rows used when passed" is not built here: the receipts table lands in slice 29, and passing the rows through the job would put transcript text on the queue and in the failed-job record. Slice 29 passes the receipt id instead (reconciliation 47; see apply-progress, open decisions).
  - Verify: `cd service && php artisan test --filter='ReconcileTranscriptTest' && php artisan test --coverage --min=90`.

## Slice 24: Provider release job

Commit: `feat(service): add delayed provider session release job`
Specs: conversations (delayed provider release). Reconciliation: 21.

- [x] 24.1 `ReleaseProviderSession`.
  - RED: `service/tests/Feature/ReleaseProviderSessionTest.php` scenarios "Release after end" (teardown called, `released_at` set), "Teardown retried" (false releases the job back; tries 5, backoff `[10,30,60,120]`), "Conversation state unaffected by teardown failure" (after the fifth false: logs conversation id and status class only, never touches state, never logs exception messages), no-op when `released_at` is set, dispatches `ReconcileTranscript` on the first attempt with a delay landing no earlier than `ended_at + 60 s`, "No webhook dependency".
  - GREEN: `service/app/Jobs/ReleaseProviderSession.php` (scalar conversation id argument, loads with `withoutGlobalScope` then `runAs`).
  - Note (apply): the job takes the account id with the conversation id and loads the conversation under that account (the boundary rule allows no other unscoped read); the delay config `HRA_RELEASE_DELAY_SECONDS` is read by the lifecycle that queues the job (slice 25).
  - Verify: `cd service && php artisan test --filter=ReleaseProviderSessionTest && php artisan test --coverage --min=90`.

## Unit 0b: Rulings 45-49 (slices 20-24 review)

Commit: `fix(service): serialize config sync runs per config and make the database check parallel-safe`
Reconciliation: 45, 46, 47, 48, 49.

- [x] 0b.1 Record rulings 45-49 in `reconciliation.md`; amend the `conversations` spec and design AD9 for ruling 45 (one stored line accounts for one provider line).
- [x] 0b.2 Serialize `SyncAvatarConfig` runs per config (ruling 49).
  - RED: `service/tests/Feature/SyncSerializationTest.php` (9 tests): a run that finds another in flight queues a fresh copy and is not released, the lock is freed after a run and after an exception, other configs and accounts are not held up, and two runs make one provider call.
  - GREEN: `service/app/Jobs/Middleware/SerializeConfigSync.php`, `SyncAvatarConfig::middleware()`.
- [x] 0b.3 `DatabaseConnectionTest` compares the database name exactly in both runners (`hra_test` serially, `hra_test_test_<token>` under `--parallel`), so `php artisan test --parallel` passes.

## Slice 25: Conversation lifecycle and end

Commit: `feat(service): add the conversation state machine, webhook emission and the secret-key end endpoint`
Specs: conversations (state machine, becoming live, end a conversation). Reconciliation: 15, 16, 19, 21, 27. High-integrity zone (coverage >= 95).

- [x] 25.1 `ConversationLifecycle::transition`.
  - RED: `service/tests/Unit/ConversationLifecycleTest.php` plus `service/tests/Feature/ConversationLifecycleRaceTest.php` for every row of the design transition table: "Terminal is immutable", "Illegal transition" (409 `invalid_state` when request-driven), "Idempotent live", "Race between end causes" (conditional UPDATE, first writer wins, exactly one `conversation.ended`), no `created -> failed`, `connecting -> failed` with `provider_error`/`provider_capacity` and `ended_at` set, `created -> ended` finalizes immediately as an empty `ready` and emits `transcript.ready`, `connecting|live -> ended` dispatches `ReleaseProviderSession` delayed by `HRA_RELEASE_DELAY_SECONDS`, `conversation.live` on `connecting -> live`, delivery rows recorded in the same transaction, jobs `afterCommit`.
  - GREEN: `service/app/Conversations/ConversationLifecycle.php`; add it to the coverage source of `service/phpunit.high-integrity.xml`.
  - Note (apply): `transition()` returns whether it changed the row (false = already in the target, the idempotent no-op) and throws `IllegalTransition` (an `ApiProblem`, 409 `invalid_state`) otherwise; `end()` is the entry point for every cause that ends a conversation and leaves a terminal one untouched. A conversation with no provider session at the moment it becomes terminal is finalized at once (`created` -> `ended`, failed at connect); one with a session gets the delayed `ReleaseProviderSession`. `hr-avatar.release_delay_seconds` (`HRA_RELEASE_DELAY_SECONDS`) is added.
  - Verify: `cd service && php artisan test --filter='ConversationLifecycle' && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`.
- [x] 25.2 Webhook emission end to end.
  - RED: `service/tests/Feature/ConversationWebhookEmissionTest.php` scenarios "Live event", "Ended for failure" (`failed` conversation emits `conversation.ended` with status `failed`), "Transcript ready payload", subscribed-only delivery.
  - GREEN: wiring already in `transition()`; fix gaps.
  - Verify: `cd service && php artisan test --filter=ConversationWebhookEmissionTest`.
- [x] 25.3 `POST /v1/conversations/{id}/end`.
  - RED: `service/tests/Feature/ConversationEndTest.php` (rewrite of BEAI `InterviewEndTest`) scenarios "End live" (`ended_reason api`), "End twice" (idempotent 200, resource unchanged), "End before connect", cross-account 404, scope `conversations:write`.
  - GREEN: end action on `ConversationController`.
  - Verify: `cd service && php artisan test --filter=ConversationEndTest && php artisan test --coverage --min=90`.

## Slice 26: Client connect

Commit: `feat(service): add client connect with credential pre-checks, single-use CAS and callback token`
Specs: conversations (connect), provider-webhooks (callback token issuance), api-keys (client_secret). Reconciliation: 17, 25, 12. Design AD3.

- [x] 26.1 Pre-checks and CAS.
  - RED: `service/tests/Feature/ClientConnectTest.php` scenarios "Happy path with Mock" (`JoinMaterial` shape, status `connecting`), "Second connect" (`409 already_connected`), "Concurrent connects" (exactly one winner, one `start` call), "Credential missing keeps state" (`422 provider_credential_missing` / `llm_credential_missing`, status stays `created`, Mock skips both), "Connect on an ended conversation" (`409 invalid_state`); no partial state on any pre-check failure.
  - GREEN: `service/app/Conversations/ConnectConversation.php`, `service/app/Http/Controllers/V1/Client/ClientConversationController.php` (connect), route under the full client chain (`ClientCors`, `AssignRequestId`, `RejectKeyInQuery`, `auth.client`, `throttle:publishable`).
  - Note (apply): the claim goes through `ConversationLifecycle::transition(Connecting)` (one code path for the state machine); a conversation that ends while `start()` runs has its session released and the connect answers 409 `invalid_state`; `JoinMaterialResource` is the typed wire form; `hr-avatar.public_base_url` (`HRA_PUBLIC_BASE_URL`) is added; the connect class joins the 95 % coverage zone.
  - Verify: `cd service && php artisan test --filter=ClientConnectTest`.
- [x] 26.2 Start failure handling.
  - RED: scenarios "Provider failure" (`connecting -> failed`, `provider_error`, 502 `provider_error`) and "Capacity failure" (`FailureClass::Throttle` -> `provider_capacity`, 429 `provider_concurrency_limited`), start runs outside any transaction, refs persisted on success, `JoinMaterial` carries no key material.
  - GREEN: failure branch in `ConnectConversation`.
  - Verify: `cd service && php artisan test --filter=ClientConnectTest`.
- [x] 26.3 Callback token issuance.
  - RED: `service/tests/Feature/CallbackTokenTest.php` scenarios "Token shape and storage" (64 lowercase hex, only the sha256 in `callback_token_hash`, plain token only inside `callbackUrl`), "Tokens differ", "No token for LiveAvatar" (and none for Mock); `callbackUrl` built from `HRA_PUBLIC_BASE_URL` as `/v1/webhooks/providers/{provider}/{token}`.
  - GREEN: token minting for `Ingestion::Callback` adapters in `ConnectConversation`.
  - Verify: `cd service && php artisan test --filter=CallbackTokenTest`.
- [x] 26.4 Provider branching check on the connect path.
  - RED: `service/tests/Arch/ConnectProviderAgnosticTest.php` asserts `ConnectConversation` references no provider id literal.
  - GREEN: ensure the code uses `ProviderRegistry` only.
  - Verify: `cd service && php artisan test --filter=ConnectProviderAgnosticTest && php artisan test --coverage --min=90`.

## Slice 27: Client events, client end, transcript endpoint

Commit: `feat(service): add idempotent client event ingestion, client end and the transcript endpoint`
Specs: conversations (client event ingestion, end, transcript). Reconciliation: 19, 20.

- [x] 27.1 `EventIngestor`.
  - RED: rewrite BEAI `UtteranceControllerTest` as `service/tests/Feature/ClientEventsTest.php`: "Batch stored in order" (server-assigned monotonic `sequence`), "Idempotent replay", "Partial overlap" (`{accepted, duplicates}`), "Atomic validation" (one bad event -> 422, nothing stored), "Too many events" (> 100), "Grace window" (accepted within 60 s of `ended_at`), "Closed" (`409 conversation_closed`), "Utterance before connect" (`409 invalid_state`), lifecycle `live` in `created` stored without effect, lifecycle `live` in `connecting` -> `live`, lifecycle `error` stored with no state effect, row lock (`lockForUpdate`), cross-conversation/cross-account 404 (`CrossParticipantOwnershipTest` rewrite).
  - GREEN: `service/app/Conversations/EventIngestor.php`, `service/app/Http/Requests/ClientEventsRequest.php`, events action on `ClientConversationController`, route.
  - Note (apply): migration `..._100012` widens `conversation_events.occurred_at` to microseconds (a transcript is ordered by it; whole seconds would tie the lines of one second) and the reconciler now writes provider times as microsecond strings in UTC.
  - Verify: `cd service && php artisan test --filter=ClientEventsTest`.
- [x] 27.2 Client end.
  - RED: `service/tests/Feature/ClientEndTest.php` scenario "Client end" (`ended_reason client`), idempotent 200 on a terminal conversation, "End before connect".
  - GREEN: end action on `ClientConversationController`, route.
  - Verify: `cd service && php artisan test --filter=ClientEndTest`.
- [x] 27.3 `GET /v1/conversations/{id}/transcript`.
  - RED: `service/tests/Feature/TranscriptEndpointTest.php` scenarios "Pending", "Before connect" (`409 invalid_state` for `created`), ordering `occurred_at` asc nulls last then `sequence`, `source` field, status `ready|partial|pending`, "Account isolation", scope `conversations:read`.
  - GREEN: `service/app/Http/Controllers/V1/TranscriptController.php`, route.
  - Verify: `cd service && php artisan test --filter=TranscriptEndpointTest && php artisan test --coverage --min=90`.
- [x] 27.4 Mock end-to-end flow (runtime check).
  - RED: `service/tests/Feature/MockConversationFlowTest.php` drives bootstrap key -> mock config -> create -> connect -> events -> end -> release -> reconcile -> transcript with `Queue::fake` replaced by the sync queue; fails until slices 20-27 are wired correctly.
  - GREEN: fix wiring gaps only.
  - Verify: `cd service && php artisan test --filter=MockConversationFlowTest`.

## Slice 28: Sweeper

Commit: `feat(service): add the conversation sweeper and schedule`
Specs: conversations (max-duration sweeper). Reconciliation: 16.

- [x] 28.1 `hra:conversations:sweep`.
  - RED: `service/tests/Feature/SweepConversationsTest.php` scenarios "Overrun live conversation" (`connecting_at + max_duration_seconds + 30 s` -> `ended` `max_duration`), "Within limits", "Never connected" (`created_at + 900 s` -> `ended` `expired`), "Inside the connect window", "Two sweepers" (one `conversation.ended`), goes through `transition()`.
  - GREEN: `service/app/Console/Commands/SweepConversations.php`, `service/routes/console.php` (`everyMinute()->withoutOverlapping()`).
  - Note (apply): the command walks the accounts table and reads and ends each account's conversations under that account (no unscoped conversation read). `Conversation::reload()` (a scoped re-read) replaces `refresh()` in the lifecycle and connect.
  - Verify: `cd service && php artisan test --filter=SweepConversationsTest && php artisan test --coverage --min=90`.

## Slice 29: Provider event ingestion and polling

Commit: `feat(service): add idempotent provider event ingestion, event processing and the LiveAvatar poller`
Specs: provider-webhooks (normalized event, receipts, mapping onto state, LiveAvatar polling, end independent of webhooks). Reconciliation: 24, 25.

- [x] 29.1 Receipts and `ProviderEventIngestor`.
  - RED: `service/tests/Feature/ProviderEventIngestorTest.php` scenarios "Persist then ack", "Duplicate delivery is a no-op", "Concurrent duplicates" (unique `(account_id, provider, idempotency_key)`, ruling 51), "Unknown event type" (`ignored` receipt, `processed_at` set, nothing dispatched), `account_id` NOT NULL from the authenticated conversation, `insertOrIgnore`, only inserted non-`ignored` rows dispatch.
  - GREEN: migration `provider_webhook_receipts`, `service/app/Models/ProviderWebhookReceipt.php` (`payload` hidden), `service/app/Conversations/ProviderEventIngestor.php`.
  - Note (apply): `ingest($conversation, list<ProviderEvent>)` returns how many receipts were stored; a duplicate stores nothing and queues nothing (decided by the unique key, in the same statement); an `ignored` event is stored with `processed_at` set.
  - Verify: `cd service && php artisan test --filter=ProviderEventIngestorTest`.
- [x] 29.2 `ProcessProviderEvent`.
  - RED: `service/tests/Feature/ProcessProviderEventTest.php` scenarios "Joined makes live", "Shutdown ends with parsed reason (primary key)", "Shutdown reason from the alternate key", "Shutdown with no reason" (`unknown`), "First writer wins", "Transcript ready merges", "Transcript ready before shutdown" (ends first with reason `provider`), "Joined after end" (no-op), "Legacy duplicate joined" (CAS absorbs), `processed_at` set, `CurrentAccount::runAs`.
  - GREEN: `service/app/Jobs/ProcessProviderEvent.php`.
  - Note (apply): carries the account id and the receipt id. `transcript_ready` queues `ReconcileTranscript` with the receipt id (ruling 47, which now reads the receipt's rows where it runs); this adds `AvatarProvider::callbackTranscript(array $payload): list<TranscriptLine>` to the contract (Tavus implements it, the others return `[]`).
  - Verify: `cd service && php artisan test --filter=ProcessProviderEventTest`.
- [x] 29.3 `hra:conversations:poll`.
  - RED: `service/tests/Feature/PollProviderSessionsTest.php` scenarios "Provider reports end" (event key `liveavatar:{session_ref}:ended`), "Still active", "Idempotent across runs", "Provider error is not an end" (logged by status class, changes nothing), "Terminal conversations skipped", "Other providers untouched" (Tavus and Mock never polled), chunks of 100, selection via `ProviderRegistry::withIngestion(Ingestion::Poll)`; "Silent provider" end independence (sweeper still ends it).
  - GREEN: `service/app/Console/Commands/PollProviderSessions.php`, schedule entry.
  - Note (apply): like the sweeper, the command walks the accounts and reads every conversation under its account.
  - Verify: `cd service && php artisan test --filter=PollProviderSessionsTest && php artisan test --coverage --min=90`.

## Unit 0c: Rulings 50-56 (slices 25-29 review)

Commit: `fix(service): read only documented Tavus end statuses, fail a missing-credential transcript job at once and gate readiness on the public base url`
Reconciliation: 50, 51, 52, 53, 54, 55, 56.

- [x] 0c.1 Record rulings 50-56 in `reconciliation.md`; amend the provider-webhooks spec, design and task 29.1 for ruling 51, the Tavus session-status line of the design for ruling 53, and the health and connect text of the specs and design for ruling 55.
- [x] 0c.2 Ceiling comments (ruling 52).
  - RED: `service/tests/Arch/WalkedAccountsCeilingTest.php` requires `ponytail:`, `platform due-index` and `boundary rule` in `SweepConversations` and `PollProviderSessions`.
  - GREEN: the comments at both account loops.
- [x] 0c.3 Tavus allow-list (ruling 53).
  - RED: `TavusSessionStatusTest`: only `ended` is an end; `completed`, `failed`, `paused`, `Ended` are not and are logged by value (32 characters at most); `active` and `ended` log nothing.
  - GREEN: `TavusProvider::fetchSession`.
- [x] 0c.4 Missing credential is non-retryable (ruling 54).
  - RED: `ReconcileTranscriptTest`: the job fails itself with `CredentialMissing` without a request; the failure hook finishes the transcript as `partial`.
  - GREEN: `App\Credentials\CredentialMissing`, `ReconcileTranscript::handle`.
- [x] 0c.5 Public base url (ruling 55).
  - RED: `HealthTest` (readiness check present only while a registered adapter calls back; unset, blank, schemeless, non-http and hostless values fail; logs name the check, never the value) and `CallbackTokenTest` (connect answers 503 `service_unavailable` before any change or provider call; works once the address is set; providers that do not call back are unaffected).
  - GREEN: `App\Conversations\PublicBaseUrl`, `HealthController::ready`, `ConnectConversation`.
- [x] 0c.6 Ruling 56 is documentation only: the README (slice 40) lists the LiveAvatar token wording and lifetime and the Tavus `editable` flag under "Known assumptions".

## Slice 30: Inbound provider webhook endpoint

Commit: `feat(service): add the token-authenticated inbound provider webhook endpoint`
Specs: provider-webhooks (inbound endpoint authentication, receipt confidentiality). Reconciliation: 25.

- [x] 30.1 Body limit middleware.
  - RED: `service/tests/Feature/LimitWebhookBodyTest.php` scenario "Oversize": above 256 KB (checked on `Content-Length` and on the actual body) -> `413 payload_too_large`.
  - GREEN: `service/app/Http/Middleware/LimitWebhookBody.php`.
  - Verify: `cd service && php artisan test --filter=LimitWebhookBodyTest`.
- [x] 30.2 `ProviderWebhookController`.
  - RED: `service/tests/Feature/ProviderWebhookTest.php` (security row "Inbound forgery") scenarios "Valid token and matching conversation id" (`200 {"received": true}`), "Unknown token", "Token of another conversation" (payload `conversation_id` != ref), "Unsupported provider segment" (`heygen`, `mock` and unknown ids), "Responses are indistinguishable" (identical 404 body for all failures, nothing persisted), "Malformed body after auth" (422 `validation_failed`, nothing stored), "Plain token never stored"; `ProviderWebhookIdempotencyTest`: duplicate -> 200, one receipt, one transition.
  - Note (apply): authentication is a route middleware (`auth.provider`, `AuthenticateProviderCallback`) and validation a Form Request (`ProviderCallbackRequest`), as for the other chains. The `{provider}` segment must be a registered provider whose `ingestion()` is `Callback` (no provider name in the code); the token and the provider are judged before the body, so a malformed body with a bad token is a 404, never a 422.
  - GREEN: `service/app/Http/Controllers/V1/ProviderWebhookController.php`, route `POST /v1/webhooks/providers/{provider}/{token}` (`AssignRequestId` -> `throttle:provider-webhook` per IP -> `LimitWebhookBody`), `RateLimiter::for('provider-webhook')` 120/min, `hash_equals` on the stored hash.
  - Verify: `cd service && php artisan test --filter='ProviderWebhookTest|ProviderWebhookIdempotencyTest'`.
- [x] 30.3 Confidentiality.
  - RED: `service/tests/Feature/ProviderWebhookConfidentialityTest.php` scenarios "Token redacted in logs" (the token never appears in log output of a failing callback; log context holds the route name, not the path), "No customer-facing exposure" (receipt payload returned by no endpoint).
  - GREEN: adjust `AssignRequestId` log context and exception reporting if needed.
  - Note (apply): nothing in `AssignRequestId` had to change. The token is read from the route, never taken as an argument, so it is in no stack trace; the plain token is also taken out of the stored payload, since a provider may echo the address it was given.
  - Verify: `cd service && php artisan test --filter=ProviderWebhookConfidentialityTest && php artisan test --coverage --min=90`.

## Slice 31: Final service hardening

Commit: `test(service): enforce provider-agnostic core, all-routes account isolation, secret non-disclosure and coverage gates`
Specs: service-foundation (boundary rules, account scoping, coverage gates), api-keys/credentials/consumer-webhooks (non-disclosure). Expected to add tests only; any production fix it forces is part of the same commit and recorded.

- [x] 31.1 Provider branching and literal scan.
  - RED: `service/tests/Arch/ProviderBranchingTest.php` scenario "Provider branching outside adapters is rejected": scanner fixture with `if ($provider === 'tavus')` outside `App\AvatarProviders\*` fails and names the class; the literals `tavus`, `heygen`, `liveavatar` appear only under `app/AvatarProviders` and in `config/hr-avatar.php`; "Clean tree passes".
  - GREEN: move any stray literal behind the registry.
  - Note (apply): a token-based scanner (`tests/Arch/SourceScanner.php`) reads code, so a comment or a docblock that names a provider is not a finding; database factories are exempt (test support). Also here: `TransportValuesTest` (the transports the service names are exactly `daily`, `liveavatar`, `mock`).
  - Verify: `cd service && php artisan test --filter=ProviderBranchingTest`.
- [x] 31.2 Model and serialization arch rules.
  - RED: `service/tests/Arch/ModelRulesTest.php`: every model except `LlmModel` (and `Account`) uses `BelongsToAccount`; credential, key, secret and payload columns are `$hidden`; no `var_export`/`print_r`/`dd(` in `app/`.
  - GREEN: fix offenders.
  - Note (apply): also asserts that no model mass-assigns `account_id` and that every encrypted attribute is hidden; the `var_export`/`print_r`/`var_dump`/`dd`/`dump` ban reads tokens.
  - Verify: `cd service && php artisan test --filter=ModelRulesTest`.
- [x] 31.3 All-routes isolation dataset.
  - RED: `service/tests/Feature/AccountIsolationTest.php` (security row "Cross-account"): a dataset over every authenticated `/v1` route using a resource of account A under account B's key -> 404; plus a meta-test that fails when a registered authenticated route is missing from the dataset.
  - GREEN: add missing coverage.
  - Note (apply): deleting a credential B does not have is an idempotent 204 (A's stays); a catalogue read and a voice preview without B's own credential are refused (422), never served with A's.
  - Verify: `cd service && php artisan test --filter=AccountIsolationTest`.
- [x] 31.4 Secret-exposure sweep.
  - RED: `service/tests/Feature/SecretExposureTest.php` per resource: provider, LLM, key, webhook secret and callback token never appear in any response, `Log::spy`, failed-job record or exception message.
  - GREEN: fix leaks.
  - Note (apply): no leak was found; the sweep also covers a failing provider that echoes the keys back.
  - Verify: `cd service && php artisan test --filter=SecretExposureTest`.
- [x] 31.5 Coverage gates and OpenAPI refresh.
  - RED: run `cd service && php artisan test --coverage --min=90` and `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`; fill gaps with tests, never lower thresholds. Run `php artisan scramble:export --path=openapi.json` and `cd ../tools/spectral && bun run lint`; fix drift in code, not the ruleset. Extend `OpenApiTest` so every route is documented with the right security scheme.
  - GREEN: commit refreshed `service/openapi.json`.
  - Note (apply), open items closed here: `ProblemDocumentation` documents the `Problem` body once and 401/404/429 (and 422) on every endpoint, replacing the framework's own `{message}` shapes, which were wrong for this service; `OpenApiDriftTest` fails when `openapi.json` is untracked and the CI step marks it intent-to-add before `git diff`; `config/trustedproxy.php` reads `HRA_TRUSTED_PROXIES` so the failed-authentication and callback limiters can count the caller, not the proxy.
  - Verify: `cd service && php artisan test --coverage --min=90 && vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95 && composer run analyse && composer run lint && git diff --exit-code service/openapi.json`.

## Slice 32: Railway configuration as code

Commit: `build(service): add Railway config files and deployment topology doc (never applied)`
Specs: service-foundation (documentation and deployment config as code). Reconciliation: 36.

- [x] 32.1 Railway files.
  - RED: `service/tests/Feature/RailwayFilesTest.php` scenario "Railway files are valid": three files parse as JSON; `service/railway.json` has a Dockerfile build, pre-deploy `php artisan migrate --force` followed by `php artisan db:seed --class=LlmModelSeeder --force`, healthcheck `/v1/health`; `service/railway.worker.json` start `php artisan queue:work`; `service/railway.scheduler.json` start `php artisan schedule:work`.
  - GREEN: the three files.
  - Note (apply): the worker and scheduler restart always; the scheduler is pinned to one replica; a test limits every key to those the Railway schema defines (read from `railway.com/railway.schema.json` on 2026-10-19). Railway deprecated config as code (files keep working for existing services until 2026-12-01); recorded in `docs/railway.md` and the report.
  - Verify: `cd service && php artisan test --filter=RailwayFilesTest`.
- [x] 32.2 `docs/railway.md` and docs boundary scan.
  - RED: `service/tests/Arch/DocsBoundaryTest.php` scenarios "Env documentation completeness" (table names `APP_KEY`, `DB_URL`, `REDIS_URL`, `HRA_JWT_SECRET`, `HRA_PUBLIC_BASE_URL`, `TAVUS_API_KEY`, `HEYGEN_API_KEY`, `CARTESIA_API_KEY`, `ELEVENLABS_API_KEY`, the optional `HRA_RATE_LIMIT_SECRET`, `HRA_RATE_LIMIT_PUBLISHABLE`, `HRA_RELEASE_DELAY_SECONDS`, `HRA_MAX_DURATION_CEILING`, with the four provider keys marked optional) and "Docs are boundary-clean" (vocabulary scan over `README.md` and `docs/`), plus "never run from this repo".
  - GREEN: `docs/railway.md` (topology: api, worker, scheduler, PostgreSQL, Redis; one config file per service).
  - Note (apply): the variable table is parsed, so a setting read by `config/hr-avatar.php` or `config/trustedproxy.php` and missing from it fails; the document set is every README plus `docs/*.md` that exists, copied into one directory for the unchanged `VocabularyScanner`, so the slice 40 documents join the scan when they are written. `HRA_TRUSTED_PROXIES` and `HRA_RATE_LIMIT_PREVIEW` are documented too.
  - Verify: `cd service && php artisan test --filter='RailwayFilesTest|DocsBoundaryTest'`.

## Slice 33: SDK scaffold

Commit: `feat(sdk): scaffold the TypeScript SDK with Bun, tsup, Vitest, error type and API client`
Specs: client-sdk (connect - error surface and secrets stay private, test gates). Reconciliation: 34.

- [x] 33.1 Toolchain preflight (STOP gate). Local Bun is 1.4.2 (pin 1.4.0); see apply-progress-sdk.md.
  - RED: `bun --version` must print `1.4.0`; `bunx tsc --version` must print `5.9.3` after install. Missing Bun -> STOP and report.
  - GREEN: none.
  - Verify: `bun --version`.
- [x] 33.2 Package pins test, then package files.
  - RED: `sdk/tests/unit/package-pins.spec.ts` asserts `name @hr-avatar/sdk`, `type module`, `exports` for `.` and `./mock`, optional peers `@daily-co/daily-js 0.91.0` and `@heygen/liveavatar-web-sdk 0.0.18`, exact `typescript 5.9.3`, `@playwright/test 1.61.1`, `vitest ^3`, `tsup ^8`; fails (no package).
  - GREEN: `sdk/package.json` (scripts `build`, `test`, `test:coverage`, `size`), `sdk/tsconfig.json` (strict), `sdk/tsup.config.ts` (entries `src/index.ts`, `src/mock.ts`; ESM + CJS + dts; IIFE added in slice 39), `sdk/vitest.config.ts` (jsdom, thresholds 90 for lines, branches, functions), `sdk/.gitignore`; `cd sdk && bun install`. Unresolved pin -> STOP.
  - Verify: `cd sdk && bun run test -- package-pins`.
- [x] 33.3 `HrAvatarError`.
  - RED: `sdk/tests/unit/errors.spec.ts`: fields `code`, `message`, `recoverable`, `status`, `request_id`; `instanceof Error`.
  - GREEN: `sdk/src/errors.ts`.
  - Verify: `cd sdk && bun run test -- errors`.
- [x] 33.4 API client.
  - RED: `sdk/tests/unit/api.spec.ts` scenarios "Service error is surfaced" (Problem+JSON -> `HrAvatarError{code,status,request_id}`; 401 `client_secret_expired` etc. mapped), "Secrets stay private" (publishable key and client secret sent only to `baseUrl`, never logged or stored on the returned object), network failure -> recoverable error.
  - GREEN: `sdk/src/api.ts`.
  - Verify: `cd sdk && bun run test -- api`.
- [x] 33.5 Anonymity scan.
  - RED: port `provider-anonymity.spec.ts` (`../frontend/tests/unit/provider-anonymity.spec.ts`, read-only) as `sdk/tests/unit/anonymity.spec.ts`: a file scan of `sdk/src` for the host vocabulary ban list; fails if any term appears.
  - GREEN: keep `sdk/src` clean.
  - Verify: `cd sdk && bun run test -- anonymity && bunx tsc --noEmit && bun run build`.

## Slice 34: SDK outbox and completion phrases

Commit: `feat(sdk): add the transcript outbox with batching and retry, and completion phrase matching`
Specs: client-sdk (transcript auto-forwarding outbox, completion phrases). Reconciliation: 34.

- [x] 34.1 Port outbox specs first.
  - RED: port `../frontend/tests/unit/utterance-outbox.spec.ts` (read-only) to `sdk/tests/unit/outbox.spec.ts` with the new semantics: scenarios "Batching by count" (flush at 20), "Batching by time" (2 s after the first queued event), "Retry keeps keys" (`crypto.randomUUID()` at capture, same keys on retry), "Backoff growth" (1 s doubling, cap 30 s), "Closed stops forwarding" (409 `conversation_closed` or 401 -> stop and emit error), "Opt-out" (`forwardTranscript: false`), "Final flush on end", "Interim ignored", `pagehide` keepalive flush; conversation id is a string, role `user`.
  - GREEN: `sdk/src/outbox.ts` (in-memory, ported `UtteranceOutbox`).
  - Verify: `cd sdk && bun run test -- outbox`.
- [x] 34.2 Completion phrases.
  - RED: `sdk/tests/unit/completion.spec.ts` scenarios "Phrase triggers end" (case-insensitive substring on avatar speech, then 1500 ms silence settle), "User speech ignored", "Not configured" (no-op).
  - GREEN: `sdk/src/completion.ts`.
  - Verify: `cd sdk && bun run test -- completion && bun run test:coverage`.

## Slice 35: SDK transport infrastructure and mock

Commit: `feat(sdk): add transport loading, the scripted mock transport and the ./mock export`
Specs: client-sdk (provider transports and peer dependencies, exported mock provider). Reconciliation: 34. Design AD12.

- [x] 35.1 Transport interface and `loadTransport`.
  - RED: rewrite `../frontend/tests/unit/provider-factory.spec.ts` and `interview-provider-types.spec.ts` (read-only) as `sdk/tests/unit/load-transport.spec.ts` and `types.spec.ts`: scenarios "Lazy import" (library imported only when its transport is chosen), "Missing library" (`HrAvatarError('provider_library_missing')` naming the package), "Override" (`transports` replaces a whole factory, `loaders` replaces only the import), the single switch on `daily|liveavatar|mock`; no `window.__mock*` globals.
  - GREEN: `sdk/src/transports/types.ts`, `sdk/src/transports/index.ts`.
  - Verify: `cd sdk && bun run test -- load-transport types`.
- [x] 35.2 Mount resolution.
  - RED: `sdk/tests/unit/media.spec.ts`: a `<video>` mount is used as-is; any other element gets a created `<video>`.
  - GREEN: `sdk/src/media.ts`.
  - Verify: `cd sdk && bun run test -- media`.
- [x] 35.3 Mock transport and `./mock` export.
  - RED: `sdk/tests/unit/mock-transport.spec.ts` scenarios "Scripted line" (avatar lines emitted as final `avatar` transcripts once live), simulated avatar speech start/stop, "Simulated close" (transport close -> reason `provider` handled by the conversation in slice 36), every control supported, no network or media I/O.
  - GREEN: `sdk/src/transports/mock.ts`, `sdk/src/mock.ts` (`createMockTransport`, `MockScript`, `MockControls`).
  - Verify: `cd sdk && bun run test -- mock-transport && bun run build && bun run test:coverage`.

## Slice 36: SDK connect and conversation

Commit: `feat(sdk): add connect, the conversation object, controls, events and ended reasons`
Specs: client-sdk (connect, events, controls). Reconciliation: 34. Design SDK architecture.

- [x] 36.1 `connect`.
  - RED: `sdk/tests/unit/connect.spec.ts` scenarios "Happy path on the mock transport" (`POST connect` then transport start, state `connecting`), "Id derived from the token" (decode `sub` without verification), "Malformed secret" (`invalid_client_secret`, no request sent), "Service error is surfaced", "Secrets stay private".
  - GREEN: `sdk/src/connect.ts`, types and exports in `sdk/src/index.ts` (`HrAvatar`, `HrAvatarError`, `createMockTransport`).
  - Verify: `cd sdk && bun run test -- connect`.
- [x] 36.2 Events and state.
  - RED: `sdk/tests/unit/conversation-events.spec.ts` scenarios "State sequence" (`connecting` -> `live` -> `ended`; lifecycle `live` sent once when the transport reports ready), "Unsubscribe", "Ended once", interim transcripts delivered with `final: false` and never forwarded.
  - GREEN: `sdk/src/conversation.ts` (emitter).
  - Verify: `cd sdk && bun run test -- conversation-events`.
- [x] 36.3 Controls.
  - RED: `sdk/tests/unit/conversation-controls.spec.ts` scenarios "Toggle returns new state" (synchronous boolean), "Say", "Silence already satisfied", "Silence waits", "Ended while waiting", "Control after end" (`conversation_ended`), "End is idempotent and calls the service", "Unsupported control" (`unsupported_operation`), `sendContext(string|object)`.
  - GREEN: controls in `sdk/src/conversation.ts`.
  - Verify: `cd sdk && bun run test -- conversation-controls`.
- [x] 36.4 Ended reasons.
  - RED: `sdk/tests/unit/ended-reasons.spec.ts`: `end()` -> `user`; completion phrase -> `completion_phrase`; fatal transport error -> `error`; remote close -> flush outbox, call client end (idempotent 200), then `provider` when the resource's `ended_reason` is `provider` or was set by this call and `server` when it was already `api`, `max_duration` or `expired`.
  - GREEN: remote-close handling in `sdk/src/conversation.ts`.
  - Verify: `cd sdk && bun run test -- ended-reasons && bun run test:coverage`.

## Slice 37: SDK Daily transport

Commit: `feat(sdk): add the Daily transport harvested from the BEAI Tavus provider`
Specs: client-sdk (provider transports). Port map: `app/providers/tavus.ts` -> `src/transports/daily.ts`.

- [x] 37.1 Port specs first.
  - RED: port `../frontend/tests/unit/tavus-provider.spec.ts` and `tavus-provider-ended.spec.ts` (read-only) to `sdk/tests/unit/daily-transport.spec.ts` and `daily-transport-ended.spec.ts` with an injected fake `daily-js`; rewrite `tavus-provider-steering.spec.ts` as `sdk/tests/unit/send-context.spec.ts` (`append_llm_context`, other transports reject `unsupported_operation`). Cases: replica/pal utterance dedupe, call object rendered into the mount's `<video>`, speaking signals, mic mute with `audioDeviceId`, echo via `conversation.echo`, ended handling. Removed: boundary steering ack, echo filter, `end_interview` tool call.
  - GREEN: `sdk/src/transports/daily.ts`, register in `loadTransport`.
  - Verify: `cd sdk && bun run test -- daily-transport send-context && bun run test:coverage`.

## Slice 38: SDK LiveAvatar transport

Commit: `feat(sdk): add the LiveAvatar transport harvested from the BEAI HeyGen provider`
Specs: client-sdk (provider transports). Port map: `app/providers/heygen.ts` -> `src/transports/liveavatar.ts`.

- [x] 38.1 Port specs first.
  - RED: port `../frontend/tests/unit/heygen-provider.spec.ts` (read-only) to `sdk/tests/unit/liveavatar-transport.spec.ts` with an injected fake SDK: `voiceChat` config contract incl. `deviceId`, transcription events (interim vs final), `repeat()` for `say`, `interrupt`, speaking signals, disconnect -> transport close. Absent-phrase guard removed.
  - GREEN: `sdk/src/transports/liveavatar.ts`, register in `loadTransport`.
  - Verify: `cd sdk && bun run test -- liveavatar-transport && bun run test:coverage`.

## Slice 39: IIFE build, size budget, Playwright smoke, SDK CI

Commit: `build(sdk): add the IIFE bundle, size budget, Playwright smoke on Chromium and WebKit, and SDK CI`
Specs: client-sdk (build outputs and size budget, SDK test gates). Reconciliation: 34, 36.

- [x] 39.1 Build outputs and IIFE.
  - RED: `sdk/tests/unit/build-outputs.spec.ts` scenarios "Outputs exist" (`dist/index.{js,cjs,d.ts}`, `dist/mock.*`, `dist/hr-avatar.global.js`), "IIFE global" (`window.HrAvatar.connect` is a function), "Peers excluded" (neither provider library inlined); fails until the IIFE is configured.
  - GREEN: extend `sdk/tsup.config.ts` (IIFE, `globalName HrAvatar`, file `dist/hr-avatar.global.js`, provider libraries external).
  - Verify: `cd sdk && bun run build && bun run test -- build-outputs`.
- [x] 39.2 Size gate.
  - RED: `sdk/tests/unit/size-script.spec.ts` scenario "Size gate": `scripts/size.ts` exits non-zero when the ESM core entry or the IIFE exceeds 20 KB minified + gzip (fixture over budget fails, under passes).
  - GREEN: `sdk/scripts/size.ts` (`node:zlib` gzip, run by Bun, so it is type-checked without a new dependency), script `size`.
  - Verify: `cd sdk && bun run build && bun run size`.
- [x] 39.3 Playwright smoke.
  - RED: `sdk/e2e/smoke.spec.ts` (Chromium + WebKit): on `sdk/e2e/smoke.html` loading the IIFE with `page.route`-faked API responses, connect returns transport `mock`, a scripted avatar line is shown, the transcript is forwarded to `/events` with batch shape and stable idempotency keys, and the end call is made; fails until the page, server and config exist.
  - GREEN: `sdk/e2e/smoke.html`, `sdk/e2e/serve.ts` (`node:http` server of `dist/` and the page, run by Bun), `sdk/playwright.config.ts` (projects `chromium`, `webkit`; Playwright `1.61.1`).
  - Verify: `cd sdk && bunx playwright install --with-deps chromium webkit && bunx playwright test`.
- [x] 39.4 SDK CI workflow.
  - RED: `sdk/tests/unit/ci-workflow.spec.ts` (SDK-side; the session brief pins by release tag, not SHA) parses `.github/workflows/sdk.yml`: every `uses:` pinned to a release tag (`@v4`, `@v2`), steps `bun install --frozen-lockfile`, `bunx tsc --noEmit`, `bun run test:coverage`, `bun run build`, `bun run size`, `bunx playwright install --with-deps chromium webkit`, `bunx playwright test`. Tags need no lookup, so no remote is contacted.
  - GREEN: `.github/workflows/sdk.yml`.
  - Verify: `cd sdk && bun run test -- ci-workflow && bun run test:coverage && bun run size`.

## Slice 40: Documentation

Commit: `docs: add README, architecture, API and SDK usage guides and record the retention open item`
Specs: service-foundation (documentation), conversations (retention decision). Reconciliation: 36.

- [x] 40.1 README and architecture.
  - RED: extend `service/tests/Arch/DocsBoundaryTest.php`: `README.md` and `docs/architecture.md` exist, contain the retention open item (no purge of transcripts, events, receipts or delivery payloads in this change; owner decision pending), pass the vocabulary scan.
  - GREEN: `README.md` (what it is, layout, run locally with compose ports 55432/56379/8100, test commands for service and SDK, gates, Git Flow note, retention open item, the owner ratification items R1-R9 pointer), `docs/architecture.md` (provider contract, lazy start at connect, state machine, event flow diagram, key model, provider-agnostic rule).
  - Note (apply): also `service/README.md` and `sdk/README.md`; the retention open item, the owner ratification pointer and the "Known assumptions" list (ruling 56) are in the README. The tests also parse every relative link and heading anchor of every document.
  - Verify: `cd service && php artisan test --filter=DocsBoundaryTest`.
- [x] 40.2 API usage with curl and SDK examples.
  - RED: add to `DocsBoundaryTest` a check that `docs/api-usage.md` contains the curl sequence (bootstrap key, create credential, create mock config, create conversation, connect, events, end, transcript) and the webhook verification procedure (`X-HRA-Signature`, `X-HRA-Timestamp`, 300 s window, dedupe on `X-HRA-Event-Id`).
  - GREEN: `docs/api-usage.md`; run its curl sequence against the local stack (`cd service && docker compose up -d && php artisan migrate --force`) and fix any doc drift.
  - Note (apply): the curl sequence was run against a local service (the repository's PostgreSQL and Redis, the mock provider, a webhook receiver on loopback) and the answers pasted in the guide are the real ones; the PHP and Node verification snippets were run against three real signed deliveries (valid, tampered body and expired timestamp all judged correctly). A test checks every curl call against `openapi.json`.
  - Verify: `cd service && php artisan test --filter=DocsBoundaryTest`.
- [x] 40.3 SDK usage.
  - RED: `DocsBoundaryTest` requires `docs/sdk-usage.md` to document `HrAvatar.connect` options, events, controls, error codes, the `./mock` export, and the IIFE loader recipe (passing a CDN-backed `loaders.daily` / `loaders.liveavatar`, `provider_library_missing`).
  - GREEN: `docs/sdk-usage.md`.
  - Verify: `cd service && php artisan test --filter=DocsBoundaryTest && php artisan test --coverage --min=90 && cd ../sdk && bun run test:coverage`.

## Security fix unit (post-review)

Source: the native review findings of the slices 3-30 (`h450eb50` ... `83bdda7`, correction_required and approved lineages), triaged against HEAD `9f662dd`. Reconciliation: 57+ (one ruling per behavioural decision). Every item is strict TDD: a failing test first, the failure observed, then the smallest change. Existing tests are touched only where a finding proves them vacuous, flaky or where the ruling reverses the behaviour they pinned (each such edit is named in the ruling).

Commit boundary: one commit per item (or tight group) on `feature/extract-avatar-service`; no push.

- [x] SF-1 `OutboundHostGuard`: embedded IPv4 (IPv4-mapped `::ffff:0:0/96` in both spellings, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`) is extracted and re-judged by every IPv4 block; a host-shaped value that is not an address but holds `:` (zone identifier) is blocked; docblock and redundant check cleanups (R1-001, R3-002, R2-004, R2-005, R2-006, R3-006).
- [x] SF-2 `DeliverWebhook`: an enforced guard with no vetted address fails the attempt closed (`destination_not_allowed`); the connection is pinned to the vetted address; the client never resolves again (R4-003, R3-003).
- [x] SF-3 Relay of lost deliveries: `hra:relay` command, scheduled every minute, re-dispatches `pending` deliveries that are stale (lost push or lost retry dispatch), claimed with a conditional lease update so concurrent relays do not both queue one (R4-001, R4-002).
- [x] SF-4 Provider receipts: a redelivered or re-polled receipt that is stored and unprocessed is queued again; the relay re-queues stale unprocessed receipts under a lease; processing stays idempotent (R4-001 of slice 29).
- [x] SF-5 `ConnectConversation`: any `Throwable` after the claim (callback target, spec, start, keeping the references) fails the conversation; no conversation stays `connecting` (R3-connect-unguarded-tail-after-claim).
- [x] SF-6 Rate limits and auth chain: `(int) env()` limits clamped to a documented minimum of 1; unknown stored scope is a deny, not a 500; a live key with `Origin` counts against the failed-auth limiter; middleware priority documented; shared-address caveat documented (R3-001, R4-003, R3-003, R4-001, R2-middleware-priority-comment, R3-002, R4-002 of `hb3abddd`).
- [ ] SF-7 Keys: the rotated successor inherits `expires_at`; interleaved rotation test; deterministic pagination tie-break test; `client_secret` `iat` from the same clock as `exp`; CORS only on client routes documented by a test; live and test keys resolve the same account resources; key lookup by hash (R3-001..R3-003 `h450eb50`, R3-001..R3-006 `h83fe7cc`, R3-001/R3-002 `hbac90c4`).
- [ ] SF-8 Credentials: concurrent first `PUT` is an idempotent 200 (provider and LLM); `captureLogs` cannot drop an unencodable context; undecryptable account key throws with no platform fallback; the probabilistic ciphertext assertion is removed (R3-001..R3-004 `hd8c577a`).
- [ ] SF-9 Webhook endpoints: an unknown stored event name does not 500; delete stops pending retries (pinned by a test); docblock wording; the DNS-in-request, create-idempotency and concurrency-test findings recorded as rulings (R2-001, R2-008, R3-001, R3-004, R3-005, R4-001..R4-004 `h8d7d203`).
- [ ] SF-10 Session release: a conversation that ends while connecting with the reference not yet stored releases the session once the reference is kept (pinned by a test); `release_delay_seconds` clamped to a lower bound (R3-001, R3-002 `h86eff30`).
- [ ] SF-11 Ingestion: duplicate check before the closed-grace guard; the 2 KB `data` limit measured on the stored encoding; `LimitWebhookBody` caps a body without `Content-Length` while reading it; adapter parse failure is 422 `validation_failed`; the transcript read is capped; concurrent duplicate callback test (R3-grace-replay, R3-size-boundary, R3-unbounded-transcript, R3-001..R3-003 `83bdda7`).
- [ ] SF-12 Gates and records: full suite, coverage 90 and 95, pint, phpstan, Scramble export and drift, Spectral, README and docs notes, apply-progress.
