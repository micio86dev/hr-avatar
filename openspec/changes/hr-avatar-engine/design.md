# Design: HR-Avatar engine (service + SDK)

> Corrective revision 2026-10-19. This design adopts every item of `reconciliation.md` (binding
> for tasks and apply). Where this file and a spec still differ, `reconciliation.md` wins; the
> single known wording gap is noted under AD2.

## Technical Approach

The work ports BEAI's avatar mechanics and adds three new pieces: publishable keys, key rotation and
provider-event ingestion. It refines the owner-approved `docs/extraction/DESIGN.md` (D1-D11); every
deviation from D1-D11 is listed under **Owner ratification items**.

- `service/` is a conventional Laravel 13 API-only app. Framework-shaped code stays where Laravel
  tooling expects it: `Models`, `Http`, `Jobs` and `Console`. Domain logic sits in screaming
  folders: `Accounts`, `Keys`, `AvatarProviders`, `Credentials`, `Llm`, `Voices`, `Conversations`
  and `Webhooks`.
- **One provider contract** (`App\AvatarProviders\AvatarProvider`) has three adapters, registered
  under the provider ids `tavus`, `heygen` and `mock`. The HeyGen LiveAvatar adapter lives in the
  class directory `App\AvatarProviders\LiveAvatar`; `liveavatar` is otherwise only a transport
  value. Everything outside the adapters talks to `ProviderRegistry`, which maps provider ids to
  classes as config data, with no branching on a provider name.
- **The provider session starts lazily at client `connect`**, not at `POST /v1/conversations`.
  Create makes no provider call and no credential call. Join material is therefore fresh, and a
  conversation that is never joined costs nothing at the provider.
- **No end path depends on a webhook.** A conversation ends through any of these:
  - an explicit end from the host (`api`) or the client (`client`);
  - an ingested provider event (`provider`): Tavus callbacks, or the HeyGen poller;
  - the sweeper (`max_duration`, or `expired` for a conversation never connected).

  The delayed `ReleaseProviderSession` job then tears the provider session down and starts
  transcript reconciliation.
- `sdk/` (`@hr-avatar/sdk`) is a thin client with three transports: `daily`, `liveavatar` and
  `mock`. The transport is picked by `JoinMaterial.transport`. Provider libraries are optional peer
  dependencies, imported lazily through injectable loaders. The transcript is auto-forwarded
  through a ported outbox.

## Architecture Decisions

### AD1. Namespace layout: Laravel conventions plus screaming domain folders
**Choice**:
- `App\Models`, `App\Http\{Controllers\V1,Middleware,Requests,Resources}`, `App\Jobs` and
  `App\Console\Commands` follow Laravel conventions.
- Domain code lives in `App\Accounts`, `App\Keys`, `App\AvatarProviders\{Tavus,LiveAvatar,Mock}`,
  `App\Credentials`, `App\Llm`, `App\Voices`, `App\Conversations` and `App\Webhooks\Outbound`.

**Alternatives considered**:
- Full hexagonal modules (`App\Avatar\Domain|Infrastructure`). Rejected: speculative layering with
  no second consumer.
- `App\Providers\Avatar\*`. Rejected: `App\Providers` is Laravel's reserved home for service
  providers, and mixing in avatar adapters confuses tooling and readers.

**Rationale**: `make:*`, Larastan and Scramble work unmodified. The provider boundary is visible in
the tree, and the arch tests can target `App\AvatarProviders\*` precisely.

### AD2. Provider contract: one interface, with value objects in and out
**Choice**: `AvatarProvider` carries at least the operations named by the spec and reconciliation
item 22, plus two descriptive helpers:
- `ingestion(): Ingestion` (`Callback | Poll | None`): how provider events reach the service;
- `describe(): ProviderDescription`: field specs, catalogue resources and supported voice engines;
- `validateConfig`, `syncRequired`, `sync`, `catalogue`;
- `start`, `teardown(sessionRef, contextRef)`, `fetchTranscript`,
  `fetchSession → SessionStatus {ended, endReason}` (the poller builds the event);
- `parseCallback`.

Every network operation also receives the resolved `ProviderAuth` as its last argument (the spec's
`ProviderCredential`; the spec lists only the domain arguments). Where an operation does not apply,
the adapter returns a neutral value: `Ingestion::None`, `[]`, `SyncResult::notRequired()`.

Reconciliation item 22 names the session-creation operation `issue` (BEAI's name). Both the spec
and this design name it `start`, so there is no drift between them; `start` is kept rather than
introducing a rename across specs and design.

**Alternatives considered**:
- Capability sub-interfaces such as `PollsStatus` or `AcceptsCallbacks`. Rejected: each would have
  one implementation, which `CLAUDE.md` bans, and callers would need `instanceof` checks.
- Passing BEAI-style `QuestionContext`. Rejected: it carries host semantics.

**Rationale**: callers never branch on the provider. `ingestion()` lets the poller and the inbound
webhook controller select adapters by behaviour, not by name.

### AD3. Lazy provider start at `connect`, and the conversation row as the single-use ledger
**Choice**: `POST /v1/client/conversations/{id}/connect` runs in this order:
1. **Pre-checks, before any state change**: resolve the provider credential and, when the config
   binds an LLM model, the account LLM credential. A miss answers `422
   provider_credential_missing` or `422 llm_credential_missing`, and the conversation stays
   `created`. Providers whose registry entry declares `credential: false` (Mock) skip both checks.
2. **Atomic compare-and-set (CAS)**: `UPDATE conversations SET status='connecting',
   connecting_at=now() WHERE id=? AND status='created'`. The conditional UPDATE takes the row lock.
3. **CAS lost**: re-read the status. `connecting` or `live` → `409 already_connected`; `ended` or
   `failed` → `409 invalid_state`.
4. **CAS won**: mint the callback token when the adapter's `ingestion()` is `Callback`, then call
   `provider->start()` outside any transaction, persist the refs and return `JoinMaterial`.
5. **Start failed**: transition `connecting → failed`. A throttle failure sets `ended_reason
   provider_capacity` and answers `429 provider_concurrency_limited`; any other failure sets
   `provider_error` and answers `502 provider_error`.

**Alternatives considered**:
- Start the provider session at create. Rejected: stale join material, and an orphan provider
  session for every conversation that is never joined.
- A Redis or DB `jti` store for single use. Rejected: the status CAS already is an atomic
  single-use ledger, so a second store could only drift from it.

**Rationale**: no extra infrastructure, "connect exactly once" is enforced by Postgres, and a
credential problem never strands a conversation in `connecting`.

### AD4. `client_secret`: an HS256 JWT through `lcobucci/jwt`, bound to one conversation
**Choice**:
- `App\Keys\ClientSecret::mint()` and `::parse()` port `SessionTokenMinter`.
- The token is signed with `HRA_JWT_SECRET` through `Configuration::forSymmetricSigner(new Sha256,
  InMemory::plainText(...))`. Only HS256 is accepted; `alg: none` or any other algorithm fails
  `SignedWith`.
- `parse()` checks `SignedWith`. `iss`, `aud` and `exp` are compared manually against `now()`, the
  BEAI pattern, so `Carbon::setTestNow` drives the tests.
- Claims: see **`client_secret` claims** below. There is no `mode` claim.

**Alternatives considered**:
- `tymon/jwt-auth`. Rejected: it is single-secret and session-oriented.
- An opaque random token. Rejected: it needs a store and a lookup on every client call.

**Rationale**: the token is stateless, and the conversation row CAS (AD3) supplies single use.

### AD5. Account isolation through a global scope that fails closed
**Choice**:
- `App\Accounts\BelongsToAccount` adds `AccountScope`.
- The scope filters by the `CurrentAccount` bound by the auth middleware. When nothing is bound it
  **throws `LogicException`**.
- A `creating` hook stamps `account_id`, which is never mass-assignable.
- Jobs and commands load by explicit id with `withoutGlobalScope(AccountScope::class)`, then call
  `CurrentAccount::runAs($accountId, fn)`.
- Both auth middlewares are placed ahead of `SubstituteBindings` in the middleware priority list,
  so implicit route bindings are already account-scoped.

**Alternatives considered**:
- Explicit `whereBelongsTo` in every query. Rejected: one forgotten call is a cross-account leak.
- A scope that silently returns nothing. Rejected: it hides bugs in tests.

**Rationale**: `CLAUDE.md` treats a cross-account read as a security defect, and a loud failure is
the cheapest guard.

### AD6. A generic `provider_resources` ledger instead of HeyGen-named columns (refines D2)
**Choice**: one table keyed `(account_id, provider, key_fingerprint, kind, natural_key) →
external_id`. It replaces three BEAI stores:
- `heygen_vendor_secrets`;
- `heygen_bound_voices`;
- `llm_credentials.heygen_secret_id`.

`key_fingerprint` is the first 16 hex characters of the sha256 of the provider API key used, so the
ledger stays correct when an account switches from the platform key to its own key.
`avatar_configs.provider_state` (jsonb, adapter-owned) holds per-config refs such as the
LiveAvatar `llm_configuration_id`.

**Alternatives considered**: porting BEAI's three provider-named tables and columns. Rejected:
schema leakage of provider names, and the ledgers were platform-wide. With per-account credentials,
a platform-wide ledger hands account B a voice bound in account A's provider space.

**Rationale**: provider specifics stay behind the contract, and the ledger is correct under
per-account keys.

### AD7. Outbound webhook secret: random per endpoint, stored encrypted (refines D10)
**Choice**: each endpoint gets `secret = "hra_whsec_" . bin2hex(random_bytes(32))` (64 hex) at
create. It is stored in `webhook_endpoints.secret` with the Eloquent `encrypted` cast, hidden from
serialization, and returned once, by the create response. There is no
platform-wide master secret; rotating means deleting the endpoint and creating a new one.

**Alternatives considered**:
- One platform secret for every endpoint, the literal reading of D10. Rejected: account A could
  forge deliveries to account B's receiver.
- A secret derived from a master secret by HMAC. Rejected: the spec fixes a random
  `hra_whsec_` secret, and a master secret rotates every endpoint at once.

**Rationale**: the secret is distinct per endpoint, its blast radius is `APP_KEY` (like every other
encrypted credential), and the spec's format holds.

### AD8. Provider events normalized once and deduplicated twice
**Choice**:
- Tavus callbacks and HeyGen poll observations become one `ProviderEvent { provider, providerRef,
  kind: Joined|Ended|TranscriptReady|Ignored, occurredAt, idempotencyKey, providerEndReason,
  payload }`.
- Each event is inserted into `provider_webhook_receipts` with `insertOrIgnore` on the unique key
  `(account_id, provider, idempotency_key)`. This catches exact duplicates; only an inserted row dispatches
  `ProcessProviderEvent`. An `Ignored` receipt is stored with `processed_at` set and dispatches
  nothing.
- `ProcessProviderEvent` then applies the event through `ConversationLifecycle`, whose CAS
  transitions absorb semantic duplicates (for example `system.pal_joined` and the legacy
  `system.replica_joined`).

**Alternatives considered**: synchronous processing inside the webhook request. Rejected: Tavus
documents no retry policy, so the handler must persist the event and answer 200 fast, as stated in
`research.md`.

**Rationale**: research.md is binding. Idempotency holds at both layers without any coordination
logic.

### AD9. Transcript: merge, never replace
**Choice**:
- Client utterances are stored as `conversation_events` with `source=client`.
- Reconciliation keeps every client line and adds each provider line whose `role` and normalized
  text (trimmed, whitespace collapsed, case-insensitive) are not already accounted for by a stored
  line, with `source=provider`. A stored line accounts for exactly one provider line (reconciliation
  item 45), so a repeated utterance is not lost.
- `conversations.transcript_status` is `pending` until reconciliation completes, then `ready`, or
  `partial` when the provider transcript could not be fetched after 5 attempts.
- A terminal conversation with no provider session (ended from `created`, or failed at connect)
  is finalized immediately as `ready` with the lines it holds (none, for `created → ended`).

**Alternatives considered**: BEAI's REPLACE reconciliation. Rejected: it caused the
"silently empty transcript deletes every utterance" defect (F1 in `HeygenProvider`).

**Rationale**: the process never deletes data and always finishes.

### AD10. Key mode is a key-space label only
**Choice**: `live` and `test` distinguish only the key space and its prefix. Test keys do not force
the Mock provider and do not partition data; Mock is selected solely by a config whose `provider`
is `mock`. There is no `conversations.mode` column, no `mode` claim and no mode flag in webhook
payloads.
A key may only create (or rotate into) keys of its own mode (`403 forbidden`). A live secret key
presented with an `Origin` header answers `403 secret_key_origin_forbidden`; a test secret key may
carry an `Origin` header (local development).

**Alternatives considered**: forcing test mode onto the Mock provider (the BEAI behaviour).
Rejected by the spec: it would silently override a config's provider.

**Rationale**: the smallest reading that the spec makes explicit and testable.

### AD11. Add a `scheduler` process (refines D10)
**Choice**: compose and Railway get a `scheduler` service running `php artisan schedule:work`,
next to `api` and `worker`.

**Alternatives considered**: running `schedule:work` and `queue:work` together in one container,
which is two processes with fragile supervision.

**Rationale**: the sweeper and the HeyGen poller need a scheduler, and D10 omitted one. BEAI
already uses the same `worker` + `scheduler` split.

### AD12. SDK IIFE build loads provider libraries only through injectable loaders
**Choice**:
- Each transport takes `loaders.daily` / `loaders.liveavatar`. The defaults are
  `() => import('@daily-co/daily-js')` and `() => import('@heygen/liveavatar-web-sdk')`.
- In the IIFE build those bare dynamic imports reject at runtime. The SDK maps the rejection to
  `HrAvatarError('provider_library_missing')` naming the package, and the docs show passing a
  CDN-backed loader.
- `transports` overrides a whole transport factory (tests, custom hosts); `loaders` overrides only
  the library import.

**Alternatives considered**:
- Bundling both libraries into the IIFE. Rejected: it breaks the size budget and loads both for
  every consumer.
- Reading guessed `window` globals. Rejected: an unverified contract.

**Rationale**: it reuses BEAI's existing `sdkLoader` injection pattern and adds no special build
path.

### AD13. Opaque `metadata` kept as a JSON object, byte-faithful in shape
**Choice**: `metadata` is read from the raw request body with `json_decode(..., false)` so an
empty object stays an object, validated as a JSON object (not a list) of at most 8192 encoded
bytes, stored in a Postgres `json` column (not `jsonb`, which reorders keys) with an `object` cast,
and emitted unchanged in resources and webhook payloads.

**Alternatives considered**: Laravel's parsed `array` input with an `array` cast. Rejected: `{}`
round-trips as `[]`, the exact defect BEAI's public API shipped and later fixed.

**Rationale**: the spec requires the host's opaque object to round-trip as-is.

### AD14. Own client CORS middleware instead of `HandleCors`
**Choice**: `App\Http\Middleware\ClientCors`, global and limited to `v1/client/*`:
- `OPTIONS` answers `204` and echoes the request `Origin` in `Access-Control-Allow-Origin`, with
  `Access-Control-Allow-Methods: POST`, `Access-Control-Allow-Headers: Authorization,
  Content-Type, X-HRA-Publishable-Key, X-Request-Id`, `Access-Control-Max-Age: 600` and
  `Vary: Origin`. A preflight carries no key, so it cannot be allowlisted.
- On a real request it adds `Access-Control-Allow-Origin` (plus `Vary: Origin` and
  `Access-Control-Expose-Headers: X-Request-Id`) **only** when `AuthenticateClient` has marked the
  origin as allowlisted. A rejected origin gets no CORS header.
- Laravel's global `HandleCors` is removed in `bootstrap/app.php`, so there is one CORS source.

**Alternatives considered**: `HandleCors` with `config/cors.php`. Rejected: it decides from static
config before authentication, so it cannot tie the allow header to the per-key allowlist.

**Rationale**: the spec requires that no allow header is emitted for a rejected origin.

## Service layout (`service/`)

```
service/
  app/
    Accounts/      CurrentAccount (scoped binding + runAs), AccountScope, BelongsToAccount, HasPrefixedId
    Keys/          KeyGenerator, KeyResolver, KeyType{Secret,Publishable}, KeyMode{Live,Test}, Scope (enum),
                   OriginNormalizer, ClientSecret, VerifiedClientSecret
    AvatarProviders/
      AvatarProvider.php (interface)  ProviderRegistry.php  Ingestion.php (enum)  ProviderDescription.php
      ConversationSpec.php  JoinMaterial.php  ProviderSession.php  SessionStatus.php  ProviderEvent.php
      ProviderEventKind.php  TranscriptLine.php  TranscriptNotReady.php  TranscriptShapeError.php
      SyncResult.php  CatalogueEntry.php  FieldSpec.php  FieldType.php  FieldError.php  FieldSpecValidator.php
      VoiceBinding.php  ProviderException.php  FailureClass.php
      Tavus/       TavusProvider, TavusPayload, TavusPersonaSync, TavusCatalogue, TavusConcurrencyGuard,
                   TavusEventMapper, TavusFieldSpecs, TavusLanguage
      LiveAvatar/  LiveAvatarProvider, LiveAvatarPayload, LiveAvatarLlmRegistrar, LiveAvatarVoiceRegistrar,
                   LiveAvatarCatalogue, LiveAvatarFieldSpecs, LiveAvatarVoices (implements VoiceVendor)
      Mock/        MockProvider
    Credentials/   CredentialResolver (account row → platform env), LlmCredentialResolver (account only),
                   ProviderAuth (apiKey, fingerprint, owner)
    Llm/           LlmBinding (redacting __debugInfo), LlmBindingResolver
    Voices/        VoiceVendor (interface), VoiceVendorRegistry, CartesiaVoices, ElevenLabsVoices, VoicePreview,
                   CatalogueSample
    Conversations/ ConversationLifecycle, ConversationStatus, EndedReason, TranscriptStatus, ConnectConversation,
                   EventIngestor (client events), ProviderEventIngestor, TranscriptReconciler, AvatarConfigSync
    Webhooks/Outbound/ WebhookDispatcher, WebhookSigner (sign + verify), OutboundHostGuard, RetryClassifier,
                   SecretRedactor, EventType, DeliveryStatus
    Http/
      Problem.php  ErrorCode.php (string enum)  ApiProblem.php (exception)  ProblemRenderer.php
      Middleware/  AssignRequestId, RejectKeyInQuery, ClientCors, AuthenticateSecretKey, AuthenticateClient,
                   RequireScope, LimitWebhookBody
      Controllers/V1/ HealthController, ApiKeyController, ProviderCredentialController,
                   LlmCredentialController, LlmModelController, AvatarConfigController, CatalogueController,
                   VoicePreviewController, ConversationController, TranscriptController,
                   WebhookEndpointController, WebhookDeliveryController, ProviderWebhookController
      Controllers/V1/Client/ ClientConversationController (connect, events, end)
      Requests/    one FormRequest per write endpoint, plus PaginationRequest (limit + cursor)
      Resources/   one JsonResource per model (explicit field lists; never $hidden-only)
    Jobs/          ProcessProviderEvent, ReleaseProviderSession, ReconcileTranscript, SyncAvatarConfig, DeliverWebhook
    Console/Commands/ BootstrapAccount (hra:bootstrap-account), IssueKey (hra:issue-key),
                   PollProviderSessions (hra:conversations:poll), SweepConversations (hra:conversations:sweep)
    Models/        Account, ApiKey, ProviderCredential, LlmModel, LlmCredential, AvatarConfig, Conversation,
                   ConversationEvent, ProviderWebhookReceipt, ProviderResource, WebhookEndpoint, WebhookDelivery
  bootstrap/app.php     routing (apiPrefix 'v1'), middleware aliases + priority, HandleCors removed, ProblemRenderer
  config/hr-avatar.php  the ONLY place the product name lives (plus prefix constants in Keys\KeyGenerator)
  config/scramble.php   api_path 'v1', export path, info.version from service/VERSION
  database/migrations/  one migration per table below
  database/seeders/LlmModelSeeder.php  idempotent upsert of the platform catalogue
  routes/api.php        all /v1 routes, grouped by auth chain
  routes/console.php    schedule: poll + sweep, everyMinute()->withoutOverlapping()
  tests/{Unit,Feature,Arch}/   Pest; tests/Pest.php calls Http::preventStrayRequests() for every test
  phpunit.xml                  full suite, coverage source = app/
  phpunit.high-integrity.xml   same suite, coverage source = app/Keys, app/Accounts, app/Http/Middleware,
                               app/Webhooks/Outbound/WebhookSigner.php, app/Conversations/ConversationLifecycle.php
  openapi.json                 committed Scramble export
  Dockerfile  docker-compose.yml  railway.json  railway.worker.json  railway.scheduler.json  VERSION
```

`config/hr-avatar.php` holds: `jwt_secret` (`HRA_JWT_SECRET`), `jwt_issuer` (`hr-avatar`),
`jwt_audience` (`hr-avatar-client`), `public_base_url` (`HRA_PUBLIC_BASE_URL`), the provider
registry (`providers`: id → `{adapter, credential: bool, event_key_prefix?}`), `voice_vendors`
(`cartesia`, `elevenlabs`, `heygen` → class), `platform_keys` (`TAVUS_API_KEY`, `HEYGEN_API_KEY`,
`CARTESIA_API_KEY`, `ELEVENLABS_API_KEY`), `rate_limits` (`HRA_RATE_LIMIT_SECRET` 600,
`HRA_RATE_LIMIT_PUBLISHABLE` 300, failed auth 30, provider webhook 120), `release_delay_seconds`
(`HRA_RELEASE_DELAY_SECONDS`, 20), `max_duration_ceiling` (`HRA_MAX_DURATION_CEILING`, 3600),
`tavus.concurrency_retries` (3) and `tavus.concurrency_backoff_ms` (2000), `voice_preview`
(phrases per language plus `phrase_version`) and `catalogue_ttl_seconds` (300). Provider literals
outside adapter classes appear only in this file.

## Identifiers

- Prefixed string primary keys: `conv_` (conversations), `avc_` (avatar configs) and `we_`
  (webhook endpoints), each followed by a ULID. `HasPrefixedId` overrides `newUniqueId()` of
  `HasUlids`; the columns are `string(31)`.
- `evt_` + ULID is the **event id** of one emission. It is stored as `webhook_deliveries.event_id`
  and shared by the deliveries that one emission fans out to; it is not a table key.
- Every other table uses a plain ULID primary key, except the `bigint` keys of
  `conversation_events` and `provider_webhook_receipts`.
- The JWT `sub` is the `conv_…` id.

## Data model (D2, refined)

Every table carries `account_id` (a foreign key with an index, leading every composite index),
except the platform catalogue `llm_models`. All tables have `timestamps`. Enum-like value sets are
enforced by CHECK constraints, except provider ids and voice engines, which are validated against
the registry at the HTTP boundary so no provider literal lands in a migration.

| Table | Columns (beyond id/account_id/timestamps) | Constraints |
|---|---|---|
| `accounts` | `name` | — |
| `api_keys` | `name` null, `type` (secret\|publishable), `mode` (live\|test), `key_prefix` (type/mode prefix + first 8 hex, e.g. `hra_sk_live_ab12cd34`), `key_hash` char(64), `scopes` jsonb not null default `[]` (publishable keys: always `[]`), `allowed_origins` jsonb null (publishable only, 1-20), `expires_at`, `revoked_at`, `last_used_at`, `rotated_from_id` (self FK null) | unique `key_hash`; index `key_prefix`; CHECK type-specific origins |
| `provider_credentials` | `provider` (tavus\|heygen\|cartesia\|elevenlabs), `api_key` text (encrypted cast, hidden), `last_four` char(4) null (null when the key has fewer than 12 characters) | unique `(account_id, provider)` |
| `llm_models` (platform) | `id` string PK (seeded slug, e.g. `gem-1`), `provider`, `model`, `display_name`, `base_url`, `enabled` bool | index `provider` |
| `llm_credentials` | `llm_provider`, `api_key` (encrypted, hidden), `last_four` null | unique `(account_id, llm_provider)` |
| `avatar_configs` | `id` `avc_…`, `name`, `provider`, `config` jsonb, `persona` jsonb null, `llm_model_id` FK null, `voice` jsonb null (`{engine, voice_id}`), `external_ref` null, `sync_status` (not_required\|pending\|synced\|failed), `sync_error` (redacted) null, `synced_at`, `provider_state` jsonb (adapter-owned), `deleted_at` (soft delete) | FK `llm_model_id` restrict; CHECK `sync_status` |
| `conversations` | `id` `conv_…`, `avatar_config_id` FK restrict, `provider`, `status`, `language`, `system_prompt` text, `opening_text` text null, `max_duration_seconds` int, `external_ref` null, `metadata` json (AD13), `provider_session_ref` null, `provider_context_ref` null, `callback_token_hash` char(64) null, `ended_reason` null, `provider_end_reason` null, `transcript_status` (pending\|ready\|partial, default pending), `connecting_at`, `live_at`, `ended_at`, `released_at` | unique `callback_token_hash`; index `(account_id, created_at)`; index `(status, connecting_at)`; index `(status, created_at)`; CHECK status, ended_reason, transcript_status |
| `conversation_events` | `conversation_id` FK cascade, `sequence` int, `source` (client\|provider), `type` (utterance\|lifecycle), `role` (user\|avatar) null, `text` null, `name` (live\|error) null, `data` jsonb null, `occurred_at` null, `idempotency_key` | unique `(conversation_id, idempotency_key)`; unique `(conversation_id, sequence)` |
| `provider_webhook_receipts` | `account_id` **NOT NULL**, `conversation_id` FK not null, `provider`, `idempotency_key`, `kind` (joined\|ended\|transcript_ready\|ignored), `payload` jsonb (hidden), `received_at`, `processed_at` | unique `(account_id, provider, idempotency_key)`; stores polled events too |
| `provider_resources` (AD6) | `provider`, `key_fingerprint` char(16), `kind` (llm_secret\|vendor_secret\|voice), `natural_key`, `external_id` | unique `(account_id, provider, key_fingerprint, kind, natural_key)` |
| `webhook_endpoints` | `id` `we_…`, `url` (≤ 2048), `events` jsonb, `enabled` bool default true, `description` null, `secret` text (encrypted, hidden; AD7) | at most 20 per account (application check) |
| `webhook_deliveries` | `webhook_endpoint_id` FK cascade, `conversation_id` FK, `event_id` (`evt_…`), `event_type`, `payload` text (exact signed body, hidden), `status` (pending\|succeeded\|failed), `attempts` int, `next_attempt_at`, `last_attempt_at`, `last_response_status` null, `last_error` null, `completed_at` null | unique `(webhook_endpoint_id, event_type, conversation_id)`; CHECK status |

- `ended_reason` takes one of: `client`, `api`, `max_duration`, `provider`, `expired`,
  `provider_error`, `provider_capacity`. `provider_end_reason` holds the provider's own reason
  (Tavus `shutdown_reason`/`reason`, LiveAvatar `end_reason`, else `unknown`) when `ended_reason`
  is `provider`.
- `failed` conversations also set `ended_at`, so the events grace window and the webhook payload
  treat both terminal states alike.

## Auth and middleware chains (D4)

| Route group | Chain (in order) | Auth source |
|---|---|---|
| `GET /v1/health`, `/v1/health/ready` | `AssignRequestId` | none |
| Secret-key routes | `AssignRequestId` → `RejectKeyInQuery` → `AuthenticateSecretKey` → `throttle:secret` → `RequireScope:{scope}` (per route) | `Authorization: Bearer hra_sk_…` |
| Client routes `/v1/client/conversations/{conversation}/*` | `ClientCors` (global, path-limited; AD14) → `AssignRequestId` → `RejectKeyInQuery` → `AuthenticateClient` → `throttle:publishable` | `X-HRA-Publishable-Key: hra_pk_…` + `Authorization: Bearer <client_secret>` |
| Inbound `POST /v1/webhooks/providers/{provider}/{token}` | `AssignRequestId` → `throttle:provider-webhook` (per IP) → `LimitWebhookBody` (413 above 256 KB) | the per-conversation token in the path |

**`RejectKeyInQuery`** answers `400 api_key_in_query` when the query string carries `api_key` or
`key`, or any value starting with `hra_sk_` or `hra_pk_`.

**`KeyResolver::resolve($raw, KeyType)`** runs these steps in order:
1. Parses the format: `^hra_sk_(live|test)_[0-9a-f]{96}$` or `^hra_pk_(live|test)_[0-9a-f]{48}$`
   for the expected type. A malformed key, or a key of the other type, is `unauthenticated`
   without any database lookup.
2. Loads **all** rows with that `key_prefix`, whatever their state.
3. Compares the sha256 of the presented key with each `key_hash` through `hash_equals`. No match →
   `unauthenticated`.
4. **Only after a hash match** checks the state: `revoked_at` set → `key_revoked`;
   `expires_at` in the past → `key_expired`.
5. Writes `last_used_at`, throttled to at most once per 60 seconds per key.

The resolver drops BEAI's legacy-row fallback and Redis denylist. State is read from the database
on every request, so revocation takes effect immediately.

**`AuthenticateSecretKey`**:
1. If the failed-auth limiter (30 per minute per IP) is exhausted, answers `429 rate_limited`.
2. Takes the Bearer token and calls the resolver with `KeyType::Secret`. Any failure answers 401
   with the resolver's code (`unauthenticated`, `key_expired`, `key_revoked`) and counts one hit on
   the failed-auth limiter. A publishable key sent as bearer is `unauthenticated`.
3. If the key mode is `live` and an `Origin` header is present, answers
   `403 secret_key_origin_forbidden`.
4. Binds `CurrentAccount` and sets request attributes `api_key_id`, `key_mode` and `key_scopes`.

**`AuthenticateClient`**:
1. Failed-auth limiter check, as above.
2. Resolves `X-HRA-Publishable-Key` with `KeyType::Publishable`. Missing or invalid → `401
   unauthenticated` (a secret key in that header is invalid); `key_expired`/`key_revoked` as
   above.
3. Requires an `Origin` header equal to one normalized `allowed_origins` entry. Missing or other →
   `403 origin_not_allowed`. On success it marks the request as origin-allowed for `ClientCors`.
4. Requires a Bearer token. Missing → `401 unauthenticated`.
5. `ClientSecret::parse()`: bad signature, wrong algorithm, malformed, wrong `iss`/`aud` → `401
   client_secret_invalid`; past `exp` → `401 client_secret_expired`.
6. Requires `sub` = the route conversation id and `acc` = the publishable key's account.
   Otherwise `403 forbidden`.
7. Binds `CurrentAccount`; the route binding then finds the conversation in that account, or 404.

Every 401 from either middleware counts one hit on the failed-auth limiter. A publishable key alone
authorizes nothing: every client route requires both credentials.

**Scopes** (`App\Keys\Scope`): `*`, `avatar_configs:read`, `avatar_configs:write`,
`credentials:manage`, `llm_models:read`, `conversations:read`, `conversations:write`,
`webhook_endpoints:manage`, `api_keys:manage`. `*` satisfies every scope.
- `POST /v1/api-keys` with `type: secret` requires an explicit non-empty `scopes` list; there is
  no API default. Only `hra:bootstrap-account` and `hra:issue-key` default to `["*"]`.
- An unknown scope answers `422 validation_failed`.
- Creating or rotating into a key with a scope the caller does not hold (unless the caller holds
  `*`) answers `403 insufficient_scope` and creates nothing.
- Creating or rotating into a key of another mode answers `403 forbidden`.
- `RequireScope` answers `403 insufficient_scope`.

**Origins** (`OriginNormalizer`): lowercase, `scheme://host[:port]`, no path, no trailing slash. A
wildcard anywhere → 422. `https` is required except `http://localhost` and `http://127.0.0.1` on
any port. 1-20 entries, required for publishable keys and refused for secret keys.

**Rate limits** are defined in `AppServiceProvider` with `RateLimiter::for`; Laravel's throttle
middleware emits `X-RateLimit-Limit` and `X-RateLimit-Remaining`, and `Retry-After` on 429:

| Limiter | Default | Key |
|---|---|---|
| `secret` | 600/min (`HRA_RATE_LIMIT_SECRET`) | `api_key_id` |
| `publishable` | 300/min (`HRA_RATE_LIMIT_PUBLISHABLE`) | publishable key id |
| failed auth | 30/min | client IP |
| `provider-webhook` | 120/min | client IP |

Over a limit the answer is `429 rate_limited` with `Retry-After`.

**Rotation** (`POST /v1/api-keys/{id}/rotate`, `{overlap_seconds?: 0..604800, default 3600}`):
1. A revoked or already-expired key answers `409 invalid_state`.
2. Scope and mode checks as above.
3. Creates a new key with the same type, mode, scopes and origins, and `rotated_from_id = id`.
4. Sets the old key's `expires_at = now + overlap`; `overlap_seconds = 0` sets `revoked_at = now`
   instead.
5. Returns `201` with the new raw key once.

`POST /v1/api-keys/{id}/revoke` sets `revoked_at` and answers 200; revoking a revoked key is an
idempotent 200 that keeps the original `revoked_at`. There is no self-revoke guard: lockout
recovery is `hra:issue-key`.

## Interfaces / Contracts

### Provider contract (PHP)

```php
namespace App\AvatarProviders;

enum Ingestion { case Callback; case Poll; case None; }

final readonly class ConversationSpec {           // exactly the spec's shape
    public function __construct(
        public string $systemPrompt, public ?string $openingText, public string $language,
        public int $maxDurationSeconds, public \stdClass $metadata,
    ) {}
}

final readonly class JoinMaterial {               // returned by connect; never carries key material
    public function __construct(
        public string $provider,                  // registry id: 'tavus' | 'heygen' | 'mock'
        public string $transport,                 // 'daily' | 'liveavatar' | 'mock'
        public ?string $conversationUrl, #[\SensitiveParameter] public ?string $sessionToken,
        public \DateTimeImmutable $expiresAt,
    ) {}
}

final readonly class ProviderSession { public function __construct(public string $sessionRef, public ?string $contextRef, public JoinMaterial $joinMaterial) {} }
final readonly class SessionStatus   { public function __construct(public bool $ended, public ?string $endReason) {} }
final readonly class TranscriptLine  { public function __construct(public string $role /* user|avatar */, public string $text, public ?\DateTimeImmutable $occurredAt, public ?float $offsetSeconds) {} }

interface AvatarProvider {
    public function ingestion(): Ingestion;
    public function describe(): ProviderDescription;            // field specs, catalogue resources, voice engines
    /** @return list<FieldError> */
    public function validateConfig(array $config, ?VoiceBinding $voice): array;
    public function syncRequired(AvatarConfig $config): bool;  // decides the initial sync_status
    public function sync(AvatarConfig $config, ProviderAuth $auth): SyncResult;          // idempotent; throws ProviderException
    /** @return list<CatalogueEntry> */
    public function catalogue(string $resource, ProviderAuth $auth): array;
    /** Outside any DB transaction. @throws ProviderException (FailureClass Throttle|Upstream|ClientError) */
    public function start(ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession;
    /** Best-effort, idempotent; 404 counts as released. Never throws. */
    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool;
    /** @return list<TranscriptLine> @throws TranscriptNotReady|TranscriptShapeError|ProviderException */
    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array;
    /** @throws ProviderException  (an error is never reported as "ended") */
    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus;
    /** @return list<ProviderEvent>  [] for adapters whose ingestion() is not Callback */
    public function parseCallback(array $payload): array;
}
```

The spec's `CallbackTarget|null` is the `?string $callbackUrl` argument.

| Adapter (id) | `ingestion` | `start` | `teardown` | `fetchTranscript` | `fetchSession` | `parseCallback` | `sync` |
|---|---|---|---|---|---|---|---|
| Tavus (`tavus`) | `Callback` | `POST /v2/conversations` with `conversational_context`, `custom_greeting`, `properties.{language (TavusLanguage), max_call_duration = spec.maxDurationSeconds, participant_left_timeout?}`, `callback_url`, plus config `replica_id`/`persona_id`; `TavusConcurrencyGuard` retries; transport `daily`, `sessionToken` null | `POST /v2/conversations/{id}/end` | `GET /v2/conversations/{id}?verbose=true` → transcript rows; `assistant`/`replica` → `avatar`, `user` → `user`, `system` excluded, unknown role → `TranscriptShapeError`; no transcript yet → `TranscriptNotReady` | same GET; status `ended` (the one documented terminal status) ⇒ `ended`, any other value is not an end and an unrecognised one is logged (ruling 53) | `TavusEventMapper`: `system.pal_joined`/`system.replica_joined` ⇒ `Joined`, `system.shutdown` ⇒ `Ended` (`providerEndReason` = `properties.shutdown_reason` ?? `properties.reason` ?? `unknown`), `application.transcription_ready` ⇒ `TranscriptReady`, anything else ⇒ `Ignored`; key `sha256(conversation_id\|event_type\|timestamp)` | `TavusPersonaSync` (PATCH `/v2/pals/{palId}` layers + LLM binding) |
| LiveAvatar (`heygen`) | `Poll` | resolve the bound voice and `llm_configuration_id` first (unbindable voice ⇒ `ProviderException` before any billable call); `POST /v1/contexts` (`name = hra-{conversationId}-{ulid}`, `prompt`, `opening_text`); `POST /v1/sessions/token` (allowlisted config fields only, `mode FULL`, `avatar_persona.context_id`, `avatar_persona.language`, `llm_configuration_id`, bound voice); deletes the context if the token call fails; transport `liveavatar`; ignores `callbackUrl` | `POST /v1/sessions/stop {session_id, reason: USER_CLOSED}`, then delete the context | `GET /v1/sessions/{id}/transcript`: `session_active=true` ⇒ `TranscriptNotReady`; rows `{role, transcript, absolute_timestamp}` ⇒ lines; shape drift ⇒ `TranscriptShapeError` | `GET /v1/sessions/{id}`: `end_at` or `end_reason` non-null ⇒ `ended`, `endReason = end_reason` | `[]` | `LiveAvatarLlmRegistrar` + `LiveAvatarVoiceRegistrar` |
| Mock (`mock`) | `None` | refs `mock_{conversationId}`, `conversationUrl` `mock://conversation/{id}`, `sessionToken` `mock_token_{id}`, `expiresAt` now + 3600 s, transport `mock`, no HTTP | writes `hra:mock:ended:{ref}` to the cache, `true` | one `avatar` line holding the opening text when non-empty, else `[]` | `ended` once the cache flag exists (the cache is shared by the api and worker processes) | `[]` | `notRequired` |

- `ProviderRegistry` reads `config('hr-avatar.providers')`. It exposes `ids(): list<string>`
  (validation `Rule::in`), `get(string): AvatarProvider` (unknown id → domain error before any
  call), `withIngestion(Ingestion): list<string>`, `requiresCredential(string): bool` and
  `eventKeyPrefix(string): string` (default the id; `heygen` maps to `liveavatar`, so poller keys
  are `liveavatar:{session_id}:ended` as the spec fixes).
- `ProviderException` carries the status class and the generic message
  `Provider request failed (status N)`. BEAI's `ProviderErrorMessage` is **not** ported: no
  provider body, header or credential ever reaches a message or a log, and logs record the status
  class only (e.g. `5xx`).
- `TavusConcurrencyGuard` retries `start` only on a 4xx whose message matches
  `maximum concurrent conversations` (case-insensitive), `tavus.concurrency_retries` times (default
  3, 0 disables) with `tavus.concurrency_backoff_ms` (default 2000). Exhaustion throws
  `ProviderException` with `FailureClass::Throttle`. It never lists or ends any other conversation;
  a file-scan negative test enforces this.

**Credentials**:
- `CredentialResolver::for(Account, string $provider): ?ProviderAuth` uses the account row, else
  `config("hr-avatar.platform_keys.$provider")`, else `null`. A credential of one account is never
  used for another. `ProviderAuth` carries the key, its fingerprint and its owner (`account id` or
  `platform`).
- `LlmCredentialResolver::for(Account, LlmModel): ?string` reads the account's `llm_credentials`
  row for `llm_models.provider`. There is no platform fallback.
- Both are called at **connect** (AD3) and by catalogue, voice preview and sync; never at create.

**Catalogue and voice preview**:
- `GET /v1/catalogue` returns, per provider, `describe()`: field specs (`key`, `type`, `required`,
  allowed values), catalogue resources and voice engines. It makes no provider call.
- `GET /v1/catalogue/{provider}/{resource}` resolves `{provider}` in `ProviderRegistry` (adapter
  `catalogue()`) or in `VoiceVendorRegistry` (`voices()`). An unknown pair answers 404, a missing
  credential `422 provider_credential_missing`, a provider failure `502 provider_error`. Entries
  are normalized `{id, name, language|null, preview_audio_url|null, preview_audio_via_api,
  preview_image_url|null, preview_video_url|null}`; `language` only when the provider states it.
  The cache key is `(credential owner, key fingerprint, provider, resource)`, TTL 300 s; `fresh=1`
  bypasses it.
- `POST /v1/voice-preview {provider, voice_id, tts_engine|null, language}` → `200
  {content_type, audio_base64}`. The vendor is `VoiceVendorRegistry.get(tts_engine ?? provider)`:
  Cartesia and ElevenLabs synthesize the configured phrase for the language, `heygen` returns the
  provider sample. `tavus` with `tts_engine` null is not a vendor → `422 validation_failed` with no
  provider call. An unknown body key (`text`) → 422. Audio is cached per `(vendor, voice, model,
  phrase_version, language)`.

### Avatar configs and sync

- `provider` is validated against `ProviderRegistry::ids()` and is immutable: a PATCH carrying a
  different value answers 422.
- `config` goes through `adapter->validateConfig($config, $voice)`, built on the shared
  `FieldSpecValidator` (unknown key → `config.<key>` error, required keys, types, enums). `mock`
  accepts `{}`.
- `voice` is `{engine: provider|cartesia|elevenlabs, voice_id (1-128)}`; the engine must be in the
  adapter's `describe()->voiceEngines`, otherwise 422.
- `llm_model_id` must reference an enabled `llm_models` row, otherwise 422. Its credential is
  checked at connect, not here.
- Initial `sync_status` is `pending` when `adapter->syncRequired($config)` is true (Tavus; HeyGen
  with an LLM or an external voice), otherwise `not_required` (Mock; HeyGen with neither). A
  `pending` config dispatches `SyncAvatarConfig`.
- A PATCH that changes `config`, `persona`, `llm_model_id` or `voice` re-evaluates `syncRequired`,
  resets to `pending` and re-dispatches. `POST /v1/avatar-configs/{id}/sync` re-dispatches and
  answers `202`.
- `SyncAvatarConfig` (tries 3, backoff `[30, 120]`) calls `adapter->sync()`, which creates provider
  objects when absent and updates them when present (via `provider_resources` and
  `provider_state`). Success → `synced` + `synced_at`. Final failure (`failed()`) → `failed` with
  `sync_error` passed through `SecretRedactor` (a short message, never a provider body or key).
- `DELETE` is a soft delete (`deleted_at`). It answers `409 config_in_use` while any conversation
  in `created`, `connecting` or `live` references the config. A deleted config is absent from
  lists and resolves to 404 for new conversations; existing conversations keep `avatar_config_id`.
- `POST /v1/conversations` against a config whose `sync_status` is `pending` or `failed` answers
  `409 config_not_synced`.

### Conversation state machine (`App\Conversations\ConversationLifecycle`, ≥95% coverage)

```
              connect CAS ok                     client lifecycle 'live' | provider joined
 created ─────────────────────→ connecting ──────────────────────────────────────────→ live
    │                             │      │                                              │
    │                             │      └── start failed → failed                      ├──→ failed (allowed; no trigger yet)
    │                             └──────────── end (api | client | provider | max_duration) ──┤
    ├─ api / client end → ended                                                             ▼
    └─ sweeper: created_at + 900 s < now → ended (expired)                               ended
```

| From → To | Trigger | `ended_reason` | Side effects (rows in the same transaction; jobs after commit) |
|---|---|---|---|
| created → connecting | client connect CAS | — | callback token for `Callback` providers; `provider->start()` outside the transaction; refs persisted |
| connecting → failed | `start` threw | `provider_error` or `provider_capacity` | `conversation.ended` (status `failed`); transcript finalized immediately (no provider session) |
| created → ended | host end / client end | `api` / `client` | `conversation.ended`; transcript finalized immediately as empty `ready` + `transcript.ready`; no release job |
| created → ended | sweeper, `created_at + 900 s < now` | `expired` | same as above |
| connecting → live | client lifecycle `live` **or** provider `joined` | — | `conversation.live` |
| connecting \| live → ended | host end, client end, provider `ended`, sweeper `connecting_at + max_duration_seconds + 30 s < now` | `api`, `client`, `provider`, `max_duration` | `conversation.ended`; `ReleaseProviderSession` delayed `HRA_RELEASE_DELAY_SECONDS` (20) |
| live → failed | permitted by the guard (spec); no trigger in this change | — | — |
| ended, failed | terminal and immutable | — | — |

`transition(Conversation, ConversationStatus $to, ?EndedReason, ?string $providerEndReason)`:
- runs inside `DB::transaction` one conditional `UPDATE … WHERE id = ? AND status IN
  (allowedFrom[$to])` that also sets the timestamp, `ended_reason` and `provider_end_reason`; the
  conditional UPDATE takes the row lock, so concurrent causes cannot both win;
- only the winner records webhook delivery rows (`WebhookDispatcher::record`) in the same
  transaction and dispatches jobs `afterCommit`;
- a loser whose row is already in `$to` is an idempotent no-op with no second webhook (first
  writer wins);
- a request-driven illegal transition answers `409 invalid_state`.

Ending (`POST /v1/conversations/{id}/end` with reason `api`, `POST /v1/client/conversations/{id}/end`
with reason `client`) a terminal conversation is an idempotent `200` returning the unchanged
resource.

**Jobs**:
- **`ReleaseProviderSession`** (scalar conversation id; tries 5, backoff `[10, 30, 60, 120]`):
  no-op when `released_at` is set. On its first attempt it dispatches `ReconcileTranscript` with a
  delay that lands no earlier than `ended_at + 60 s` (the client events grace window closes first),
  so reconciliation never waits on teardown. It calls `teardown()`; `false` releases the job for a
  retry; `true` sets `released_at`. After the fifth `false` it logs the conversation id and the
  status class only; the conversation state is never touched. It never logs exception messages.
- **`ReconcileTranscript`** (tries 5, backoff `[30, 60, 120, 300]`): calls `fetchTranscript()`
  (or uses the rows of a `transcript_ready` receipt when one is passed) and runs
  `TranscriptReconciler`. `TranscriptNotReady` and `ProviderException` retry;
  `TranscriptShapeError` fails at once. `failed()` finalizes as `partial`.
- **`TranscriptReconciler`**: in one transaction, inserts the provider lines absent by role +
  normalized text as `source=provider` events (next sequences), then CAS `transcript_status`
  `pending → ready|partial` and records `transcript.ready`. A lost CAS is a no-op, so a second run
  changes nothing and emits nothing.
- **`ProcessProviderEvent`** (receipt id): `CurrentAccount::runAs`, then `joined` → `connecting →
  live`; `ended` → `ended` with reason `provider` and `provider_end_reason`; `transcript_ready` →
  if non-terminal, end with reason `provider` first, then dispatch `ReconcileTranscript` with the
  receipt's rows (same grace delay). Sets `processed_at`.

**Commands** (`routes/console.php`, both `everyMinute()->withoutOverlapping()`):
- **`hra:conversations:poll`** selects conversations in `connecting` or `live`, with a
  `provider_session_ref`, whose provider is in `ProviderRegistry::withIngestion(Ingestion::Poll)`
  (today only `heygen`; Tavus and Mock are never polled), in chunks of 100. For each it calls
  `fetchSession()`; on `ended` it builds `ProviderEvent(kind Ended, key
  "{eventKeyPrefix}:{session_ref}:ended", providerEndReason = endReason)` and calls
  `ProviderEventIngestor::ingest()`. A `ProviderException` is logged (status class only) and
  changes nothing; the next run retries.
- **`hra:conversations:sweep`** ends `connecting`/`live` conversations past
  `connecting_at + max_duration_seconds + 30 s` (`max_duration`) and `created` conversations past
  `created_at + 900 s` (`expired`). Both go through `transition()`, so concurrent sweeps and
  concurrent ends emit one `conversation.ended`.
- **`hra:bootstrap-account {name} {--mode=live}`** creates the account and a secret key with
  scopes `["*"]`; **`hra:issue-key {account_id} {--mode=live} {--scopes=*}`** adds a secret key to
  an existing account (non-zero exit and no key for an unknown account). Both print the raw key
  once to stdout, never to a log.

### Inbound provider webhooks (research.md)

**Token**: 32 random bytes encoded as 64 lowercase hex, minted at connect **only** when the
adapter's `ingestion()` is `Callback`. Only its sha256 is stored (`callback_token_hash`); the plain
token is handed to the adapter inside `callbackUrl =
config('hr-avatar.public_base_url') . "/v1/webhooks/providers/{provider}/{token}"` and never
persisted or logged. HeyGen and Mock get no token and no callback URL.

`ProviderWebhookController` handles `POST /v1/webhooks/providers/{provider}/{token}`:
1. `{provider}` must be a registry id whose `ingestion()` is `Callback` (today `tavus`), and the
   token must match `^[0-9a-f]{64}$`.
2. Looks up the conversation by `sha256(token)` on `callback_token_hash`, without the account
   scope, and confirms the stored hash with `hash_equals`. The conversation's `provider` must equal
   `{provider}`.
3. Decodes the body. Invalid JSON, or a missing `conversation_id`, `event_type` or `timestamp`,
   answers `422 validation_failed` and stores nothing.
4. The payload `conversation_id` must equal `conversation.provider_session_ref`.
5. Any failure of steps 1, 2 or 4 answers one identical `404 not_found` (no oracle).
6. `adapter->parseCallback($payload)`; `ProviderEventIngestor::ingest()` inserts each receipt
   (`account_id` and `conversation_id` from the authenticated conversation) with `insertOrIgnore`;
   only inserted, non-`ignored` rows dispatch `ProcessProviderEvent` after commit.
7. Answers `200 {"received": true}`, also for duplicates.

Body size above 256 KB answers `413 payload_too_large` (`LimitWebhookBody`, checked on
`Content-Length` and on the actual body). The route is excluded from key rate limiting and has its
own per-IP limiter. The token is redacted from every log line: `AssignRequestId` sets a log context
that holds the route name, never the path, and a feature test asserts the token never appears in the
log output of a failing callback. Tavus documents no signature (research.md), so the token is the
authentication; a signature check is added if Tavus ever documents one. Receipt payloads are never
returned by any endpoint.

### Outbound consumer webhooks

`WebhookDispatcher::record(Conversation, EventType)` runs **inside** the state-change transaction:
1. Selects the account's enabled endpoints subscribed to the event type.
2. Mints one `evt_` event id and encodes the body once.
3. Inserts one `webhook_deliveries` row per endpoint with `insertOrIgnore` (unique per endpoint,
   event type and conversation), status `pending`, `attempts` 0.
4. Dispatches `DeliverWebhook($deliveryId)->afterCommit()`.

**Body** (stored as the exact bytes in `payload`):
```json
{"id":"evt_…","type":"conversation.ended","created_at":"…",
 "data":{"conversation":{"id":"conv_…","status":"ended","avatar_config_id":"avc_…","external_ref":null,
   "metadata":{},"ended_reason":"api","created_at":"…","live_at":"…","ended_at":"…","transcript_status":"pending"}}}
```
`transcript.ready` adds `data.transcript: {status: ready|partial, utterance_count}`. No transcript
text, provider refs, tokens or secrets are ever in a payload.

**Headers** (per attempt): `Content-Type: application/json`,
`X-HRA-Signature: v1=<lowercase hex hmac_sha256("{ts}.{body}", secret)>`, `X-HRA-Timestamp: <unix
seconds of this attempt>`, `X-HRA-Event-Id: evt_…`, `X-HRA-Event-Type: <event>`.

**`DeliverWebhook`** (ports BEAI's `DeliverWebhookJob`; `tries 1`, it schedules its own retries):
1. Loads the delivery and its endpoint. A missing or disabled endpoint stops: the delivery is
   marked `failed` and no request is sent.
2. Resolves the host and runs `OutboundHostGuard` on every resolved address; the request is then
   pinned to the checked address (`CURLOPT_RESOLVE`), so DNS rebinding cannot swap it. A blocked
   address is a failed attempt with `last_error destination_not_allowed`. Outside `local` and
   `testing` the guard also runs at endpoint create and update.
3. Signs the stored body with a fresh timestamp and sends it with `withBody`, a 10 s timeout and
   redirects disabled.
4. `2xx` → `succeeded`, `completed_at`. Anything else is a failed attempt: `last_error` is
   `timeout`, `connection_failed`, `destination_not_allowed` or `http_<status>` (never a body or
   secret); `last_response_status` is recorded.
5. After a failed attempt `n < 6`, sets `next_attempt_at` and re-dispatches itself with the delay
   `[10, 60, 300, 1800, 7200][n-1]` seconds; after the sixth it is `failed` with `completed_at`.
6. `failed()` is a safety net that marks the row `failed`.

`WebhookSigner::sign($secret, $ts, $body)` and `::verify($secret, $header, $ts, $body, $now)`;
`verify` recomputes, compares with `hash_equals` and rejects a timestamp more than 300 s away from
`$now` in either direction. The docs describe the procedure, the window and deduplication on
`X-HRA-Event-Id`.

**Endpoint management**: `POST` (201, returns `secret` once), `GET` (paginated), `GET {id}`,
`PATCH {id}` (`url`, `events`, `enabled`, `description`), `DELETE {id}` (204; deliveries cascade,
so pending retries find no row and stop). `events` defaults to all three and rejects unknown names;
the URL must be `https` (plain `http` only when `APP_ENV` is `local` or `testing`), at most 2048
characters, with no user info; the 21st endpoint answers 422.
`GET /v1/webhook-endpoints/{id}/deliveries` (paginated, optional `status` filter) returns `id`,
`event_id`, `event_type`, `status`, `attempts`, `last_response_status`, `last_error`,
`next_attempt_at`, `created_at`, `completed_at`; never the payload or the secret.

### `client_secret` claims

| Claim | Value |
|---|---|
| `iss` | `config('hr-avatar.jwt_issuer')` (`hr-avatar`) |
| `aud` | `config('hr-avatar.jwt_audience')` (`hr-avatar-client`) |
| `sub` | conversation id (`conv_…`) |
| `acc` | account id |
| `jti` | ULID (log correlation only; no store, AD3) |
| `iat` / `exp` | `exp = created_at + 900 s + max_duration_seconds` |

There is no `mode` claim. The create response returns `client_secret` and
`client_secret_expires_at` (= `exp`) once; no read returns them. The connect window is enforced by
the CAS from `created` and by the sweeper, which ends a `created` conversation as `expired` at
`created_at + 900 s`. After the connect, the same token keeps authorizing `events` and `end` until
`exp`.

### HTTP API surface (D5; every route names its auth)

| Method + path | Auth | Scope |
|---|---|---|
| `GET /v1/health`, `GET /v1/health/ready` | none | — |
| `GET/POST /v1/api-keys`, `POST /v1/api-keys/{id}/rotate`, `POST /v1/api-keys/{id}/revoke` | SK | `api_keys:manage` |
| `GET /v1/provider-credentials`, `PUT/DELETE /v1/provider-credentials/{provider}` | SK | `credentials:manage` |
| `GET /v1/llm-credentials`, `PUT/DELETE /v1/llm-credentials/{llm_provider}` | SK | `credentials:manage` |
| `GET /v1/llm-models` (read-only; other methods 405) | SK | `llm_models:read` |
| `GET /v1/avatar-configs`, `GET /v1/avatar-configs/{id}` | SK | `avatar_configs:read` |
| `POST /v1/avatar-configs`, `PATCH/DELETE /v1/avatar-configs/{id}`, `POST /v1/avatar-configs/{id}/sync` | SK | `avatar_configs:write` |
| `GET /v1/catalogue`, `GET /v1/catalogue/{provider}/{resource}`, `POST /v1/voice-preview` | SK | `avatar_configs:read` |
| `POST /v1/conversations`, `POST /v1/conversations/{id}/end` | SK | `conversations:write` |
| `GET /v1/conversations/{id}`, `GET /v1/conversations/{id}/transcript` | SK | `conversations:read` |
| `GET/POST /v1/webhook-endpoints`, `GET/PATCH/DELETE /v1/webhook-endpoints/{id}`, `GET /v1/webhook-endpoints/{id}/deliveries` | SK | `webhook_endpoints:manage` |
| `POST /v1/client/conversations/{id}/connect` → `JoinMaterial` | PK + CS | — |
| `POST /v1/client/conversations/{id}/events` → `{accepted, duplicates}` | PK + CS | — |
| `POST /v1/client/conversations/{id}/end` | PK + CS | — |
| `POST /v1/webhooks/providers/{provider}/{token}` | PT | — |

`PUT /v1/provider-credentials/{provider}` (`api_key` 1-512) answers `{provider, source: account,
last_four, updated_at}`; an unknown provider is 404. `GET` lists one entry per credential provider
`{provider, source: account|platform|none, last_four}` (`last_four` null for `platform`, `none` and
keys under 12 characters). `PUT /v1/llm-credentials/{llm_provider}` requires a `provider` present in
`llm_models` (else 404) and answers `{llm_provider, last_four, updated_at}`. Both `DELETE`s are an
idempotent 204.

**`POST /v1/conversations`** (FormRequest; makes no provider or credential call):

| Field | Rule |
|---|---|
| `avatar_config_id` | required, an undeleted config of the account (else 404 `not_found`); `sync_status` `pending`/`failed` → 409 `config_not_synced` |
| `system_prompt` | required, 1-32000 |
| `language` | required, `^[a-z]{2}$` |
| `opening_text` | optional, ≤ 4000 |
| `max_duration_seconds` | optional integer 30..`HRA_MAX_DURATION_CEILING` (default ceiling 3600), default 1800 |
| `external_ref` | optional, ≤ 255, opaque |
| `metadata` | optional JSON object, ≤ 8 KB encoded, opaque, round-tripped as-is (AD13) |

It answers `201` with the resource (`id`, `status`, `avatar_config_id`, `provider`, `language`,
`max_duration_seconds`, `external_ref`, `metadata`, `ended_reason`, `transcript_status`,
`created_at`, `connecting_at`, `live_at`, `ended_at`) plus `client_secret` and
`client_secret_expires_at`. No read returns provider refs, the callback token or its hash.

**`POST /v1/client/conversations/{id}/events`** `{events: [...]}`, 1-100 events, each
`{idempotency_key (1-128), type: utterance|lifecycle, occurred_at? (ISO 8601), role (user|avatar,
utterance only), text (1-8000, utterance only), name (live|error, lifecycle only), data? (object
≤ 2 KB)}`:
- Validation is atomic: one invalid event rejects the batch with 422 and stores nothing.
- Accepted while the conversation is non-terminal and for 60 s after `ended_at`; afterwards `409
  conversation_closed`.
- In `created`: a batch containing an utterance answers `409 invalid_state`; lifecycle `live` is
  stored without effect.
- `EventIngestor` locks the conversation row (`lockForUpdate`), skips keys already stored (counted
  as duplicates), and inserts the rest with `sequence = max + 1, + 2, …` in batch order.
- Lifecycle `live` in `connecting` transitions to `live`; lifecycle `error` is stored with no state
  effect.
- Answers `200 {"accepted": n, "duplicates": m}`.

**`GET /v1/conversations/{id}/transcript`** → `200 {conversation_id, status: pending|ready|partial,
utterances: [{sequence, role, text, occurred_at, source}]}`, ordered by `occurred_at` ascending
(nulls last) then `sequence`. A `created` conversation answers `409 invalid_state`.

### Problem+JSON and the error catalogue

- `App\Http\Problem::make(Request, ErrorCode, ?detail, ?errors, headers)` builds
  `{type: "about:blank", title: <HTTP reason phrase>, status, detail, code, request_id, errors?}`
  with content type `application/problem+json`. `detail` is always present (a fixed English
  sentence per code when none is given). `errors` is `{field: [messages]}`.
- `ErrorCode` is the stable machine vocabulary, never localized:

  | Status | Codes |
  |---|---|
  | 400 | `api_key_in_query` |
  | 401 | `unauthenticated`, `key_expired`, `key_revoked`, `client_secret_invalid`, `client_secret_expired` |
  | 403 | `insufficient_scope`, `forbidden`, `origin_not_allowed`, `secret_key_origin_forbidden` |
  | 404 | `not_found` |
  | 405 | `method_not_allowed` |
  | 409 | `already_connected`, `invalid_state`, `conversation_closed`, `config_not_synced`, `config_in_use` |
  | 413 | `payload_too_large` |
  | 422 | `validation_failed`, `provider_credential_missing`, `llm_credential_missing` |
  | 429 | `rate_limited`, `provider_concurrency_limited` |
  | 500 | `internal_error` |
  | 502 | `provider_error` |
  | 503 | `service_unavailable` |

- `ProblemRenderer` is registered in `bootstrap/app.php` `withExceptions` for `v1/*` (and for any
  unmatched path, so no HTML is ever served):

  | Exception | Response |
  |---|---|
  | `ValidationException` | 422 `validation_failed` (field errors) |
  | `AuthenticationException` | 401 `unauthenticated` |
  | `ModelNotFoundException`, `NotFoundHttpException` | 404 `not_found` |
  | `MethodNotAllowedHttpException` | 405 `method_not_allowed` with `Allow` |
  | `PostTooLargeException` | 413 `payload_too_large` |
  | `ThrottleRequestsException` | 429 `rate_limited` with `Retry-After` |
  | `ApiProblem` | its own status and code |
  | `ProviderException` | 429 `provider_concurrency_limited` for `FailureClass::Throttle`, otherwise 502 `provider_error` |
  | `ServiceUnavailableHttpException` | 503 `service_unavailable` |
  | anything else | 500 `internal_error` (message never exposed, also with `APP_DEBUG=true`) |

- `AssignRequestId` keeps an inbound `X-Request-Id` matching `^[A-Za-z0-9._-]{1,64}$` or mints a
  ULID. It echoes the id in the response header, the Problem body and the log context.

### Health and boot guard

- `GET /v1/health` → `200 {"status":"ok"}`, touching neither the database nor Redis.
- `GET /v1/health/ready` → `200 {"status":"ok","checks":{"database":"ok","redis":"ok"}}` (plus
  `"public_base_url":"ok"` while a registered adapter's `ingestion()` is `Callback`; unset or malformed
  `HRA_PUBLIC_BASE_URL` is `failed`, hence 503; connect then answers 503 `service_unavailable` and leaves
  the conversation `created`); when a
  dependency fails, `503` Problem+JSON `service_unavailable` with an extra `checks` member naming
  each dependency `ok` or `failed`. No versions, hostnames or connection strings.
- `AppServiceProvider::boot()` throws outside the `testing` environment when `HRA_JWT_SECRET` is
  missing or shorter than 32 bytes; the message names the variable, never a value. CI sets a
  32-byte dummy for the non-test `scramble:export` step.

### Pagination

Growing lists (`api-keys`, `avatar-configs`, `webhook-endpoints`, `deliveries`) take `limit`
(1-100, default 25) and an opaque `cursor`, and answer `{data, next_cursor}` ordered by
`created_at desc, id desc` (Laravel `cursorPaginate`). `PaginationRequest` decodes the cursor and
answers 422 on a malformed one, because Laravel silently falls back to the first page. Fixed-size
lists (`provider-credentials`, `llm-models`, `llm-credentials`) are exempt and answer `{data}`.

### Scramble (`dedoc/scramble ^0.13`)

- `config/scramble.php`: `api_path => 'v1'`; `info.version` read from `service/VERSION`; export
  target `openapi.json` (run from `service/`).
- `AppServiceProvider::boot()`:
  - `Scramble::configure()->withDocumentTransformers(...)` registers the security schemes:
    `secretKey` (http bearer), `clientSecret` (http bearer, `bearerFormat: JWT`),
    `publishableKey` (apiKey, header `X-HRA-Publishable-Key`) and `providerToken` (documented on
    the path parameter of the inbound route).
  - `withOperationTransformers(...)` sets per-operation security from the route middleware:

    | Route middleware | Security |
    |---|---|
    | `auth.secret` | `[secretKey]` |
    | `auth.client` | `[clientSecret + publishableKey]` |
    | inbound webhook | `[providerToken]` |
    | health | `[]` |

  - Verify the exact 0.13 API through context7 at implementation time.
- Problem responses are documented on controllers through `#[Response(status, type:
  Problem::SHAPE)]`.
- CI drift check:
  1. `php artisan scramble:export --path=openapi.json`, against Postgres, never SQLite (BEAI rule);
  2. `git diff --exit-code service/openapi.json`;
  3. Spectral `6.16.3` lint with the root `.spectral.yaml`, zero errors and zero warnings. Spectral
     is pinned in `tools/spectral/package.json` + `bun.lock` and run through `bun run lint`, never a
     floating `bunx`.

## SDK architecture (`sdk/`, D6)

```
sdk/
  package.json        name @hr-avatar/sdk, type module, exports {".": {types, import, require}, "./mock": {types, import, require}},
                      peerDependencies {"@daily-co/daily-js": "0.91.0", "@heygen/liveavatar-web-sdk": "0.0.18"} (both optional via
                      peerDependenciesMeta), devDependencies exact-pinned (typescript 5.9.3, vitest ^3, @vitest/coverage-v8 ^3,
                      jsdom, @playwright/test 1.61.1, tsup ^8, both provider libs)
  tsup.config.ts      entries src/index.ts + src/mock.ts; formats esm+cjs (dts) + iife (globalName HrAvatar, file dist/hr-avatar.global.js);
                      external: both provider libs (lazy import only)
  src/index.ts        export { HrAvatar, HrAvatarError, createMockTransport } + public types
  src/mock.ts         export { createMockTransport, type MockScript, type MockControls }
  src/connect.ts      decode client_secret `sub` (no verification; malformed → invalid_client_secret, no request) → POST connect
                      → loadTransport(join.transport) → transport.start(mount, join, opts) → Conversation
  src/conversation.ts Conversation: emitter (state|transcript|error|ended), controls, end (flush outbox → POST client end →
                      transport.stop → release tracks; idempotent), remote-close handling
  src/api.ts          fetch wrapper: headers only to baseUrl, Problem+JSON → HrAvatarError{code,status,request_id}
  src/outbox.ts       ported UtteranceOutbox (in-memory): flush at 20 events or 2 s after the first queued event;
                      idempotency_key = crypto.randomUUID() at capture; retry on network/5xx/429 with backoff 1 s doubling, cap 30 s;
                      409 conversation_closed or 401 → stop forwarding + emit error; pagehide → fetch keepalive flush
  src/completion.ts   optional completionPhrases matcher (case-insensitive substring) + 1500 ms silence settle
  src/media.ts        resolveMount(mount): a <video> is used as-is, otherwise a <video> is created inside the element
  src/errors.ts       HrAvatarError
  src/transports/types.ts   internal Transport interface (SDK side of the provider contract)
  src/transports/index.ts   loadTransport(): the single switch on 'daily'|'liveavatar'|'mock'; honours `transports` overrides
  src/transports/daily.ts   ported tavus.ts (call object → own <video>; utterance dedupe replica/pal; speaking signals)
  src/transports/liveavatar.ts ported heygen.ts (voiceChat config with deviceId; transcription events; repeat/interrupt)
  src/transports/mock.ts    deterministic scripted transport, no network or media I/O
  tests/unit/*.spec.ts      Vitest (jsdom)
  e2e/smoke.html, e2e/smoke.spec.ts, e2e/serve.ts (Bun.serve of dist/ + page), playwright.config.ts (chromium, webkit)
  scripts/size.ts           Bun.gzipSync budget check
```

```ts
export interface ConnectOptions {
  baseUrl: string; publishableKey: string; clientSecret: string; mount: HTMLElement
  audioDeviceId?: string; completionPhrases?: string[]; forwardTranscript?: boolean /* default true */
  transports?: Partial<Record<'daily' | 'liveavatar' | 'mock', TransportFactory>>
  loaders?: { daily?: () => Promise<unknown>; liveavatar?: () => Promise<unknown> }
}
export type ConversationState = 'connecting' | 'live' | 'ended' | 'error'
export interface TranscriptEntry { role: 'user' | 'avatar'; text: string; final: boolean; occurredAt: string }
export type EndedReason = 'user' | 'server' | 'provider' | 'completion_phrase' | 'error'
export class HrAvatarError extends Error {
  code: string; recoverable: boolean; status?: number; request_id?: string
}
export interface Conversation {
  readonly id: string; readonly state: ConversationState
  on(e: 'state', cb: (s: ConversationState) => void): () => void
  on(e: 'transcript', cb: (t: TranscriptEntry) => void): () => void
  on(e: 'error', cb: (err: HrAvatarError) => void): () => void
  on(e: 'ended', cb: (r: { reason: EndedReason }) => void): () => void
  setMicMuted(muted: boolean): Promise<void>
  toggleMic(): boolean                       // synchronous; returns the new muted state
  say(text: string): Promise<void>           // verbatim speech: Tavus conversation.echo, LiveAvatar repeat()
  interrupt(): Promise<void>
  sendContext(payload: string | object): Promise<void> // Tavus append_llm_context; else rejects 'unsupported_operation'
  whenAvatarSilent(ms: number): Promise<void>          // also resolves when the conversation ends
  end(): Promise<void>
}
export const HrAvatar: { connect(o: ConnectOptions): Promise<Conversation> }
```

- Error codes include `invalid_client_secret`, `conversation_ended` (any control after the end),
  `unsupported_operation`, `provider_library_missing` (naming the package) and every service
  Problem `code`. There is no `supports()` method: an unsupported control rejects.
- The publishable key and client secret go only to `baseUrl`, are never logged or persisted, and
  are held in closure scope, not on the returned object.
- The SDK sends lifecycle `live` once, when the transport reports the stream ready; that event
  drives the server's `connecting → live`. Interim utterances reach `transcript` handlers with
  `final: false` and are never forwarded.
- `ended` fires exactly once. Reasons: `end()` → `user`; completion phrase → `completion_phrase`;
  fatal transport error → `error`; remote transport close → flush the outbox, call client `end`
  (idempotent 200 returning the resource), then `provider` when the resource's `ended_reason` is
  `provider` or was set by this call, and `server` when it was already `api`, `max_duration` or
  `expired`.
- `./mock` exports `createMockTransport({script})`, usable through `transports.mock`: it queues
  avatar lines (emitted as final `avatar` transcripts once live), simulates avatar speech start and
  stop, and simulates a transport close (→ `ended` with reason `provider`). It supports every
  control.
- The IIFE build exposes `window.HrAvatar.connect`.

**Budgets and checks**:
- Bundle budget: the ESM core entry and the IIFE each ≤ 20 KB minified + gzip, measured without the
  peer libraries and lazily imported chunks, enforced by `bun run size`.
- Vitest thresholds 90 for lines, branches and functions (`bun run test:coverage`).
- Playwright runs a smoke on the IIFE build against `page.route`-faked API responses: connect
  returns transport `mock`, a scripted avatar line, a transcript forwarded to `/events` with batch
  shape and stable idempotency keys, and the end call; Chromium and WebKit.

## Data Flow

```
Host backend ──secret key──→ POST /v1/conversations ──→ conversations(created) + client_secret (no provider call)
     │ hands client_secret to its page
Browser SDK ──pk + client_secret──→ POST /client/…/connect
     → credential pre-checks → CAS created→connecting → ProviderRegistry.get(provider).start(spec, config, auth, callbackUrl?)
     ← JoinMaterial → transport (daily | liveavatar | mock) joins the provider directly (WebRTC)
SDK ──lifecycle 'live' + utterances (outbox)──→ POST /client/…/events ──→ conversation_events, live transition
Tavus ──callback──→ /webhooks/providers/tavus/{token} ┐
Scheduler: hra:conversations:poll (Poll adapters) ────┴→ receipts (dedupe) → ProcessProviderEvent → ConversationLifecycle
End (api | client | provider | sweeper) → ended → ReleaseProviderSession(+20 s): teardown + ReconcileTranscript(≥ ended_at + 60 s)
     → TranscriptReconciler (merge) → transcript_status → delivery rows (same tx) → DeliverWebhook ──signed──→ host
```

## Port map

### Service sources (BEAI `api/`, read-only)

| BEAI source | Target | Stripped / changed |
|---|---|---|
| `Services/Provider/ProviderSessionService.php` | `AvatarProviders/AvatarProvider.php` | `InterviewSession` and `QuestionContext` removed; `issue` becomes `start`; `reconcileTranscript` becomes `fetchTranscript` (returns lines); `teardown`, `fetchSession`, `ingestion`, `describe`, `parseCallback`, `validateConfig`, `syncRequired`, `sync` and `catalogue` added |
| `Services/Provider/ProviderToken.php` | `JoinMaterial.php` + `ProviderSession.php` | split into client-safe join material and server-only refs; non-empty provider guard kept |
| `Services/Provider/QuestionContext.php` | `ConversationSpec.php` | competency code, question index, prompt version and set ref, ordinals and totals dropped; `participantLeftTimeout` moves to the Tavus avatar config field |
| `Services/Provider/TavusProvider.php` | `AvatarProviders/Tavus/TavusProvider.php` (+ `TavusPayload`) | `ActiveTemplateResolver` and project lookup removed; the platform default `replica_id`/`persona_id` env floor removed; `callback_url` and `max_call_duration` added; credentials from `ProviderAuth` |
| `Services/Provider/HeygenProvider.php` | `AvatarProviders/LiveAvatar/LiveAvatarProvider.php` (+ `LiveAvatarPayload`), registry id `heygen` | `beai-` context name becomes `hra-{conversationId}-`; template and project resolution removed; platform avatar and voice env floor removed; `extra_token_fields` env widening removed (allowlist constant kept); transcript reads `absolute_timestamp` (research); candidate/avatar roles become user/avatar |
| `Services/Provider/MockProvider.php` | `AvatarProviders/Mock/MockProvider.php` | test-mode routing removed; ended flag in the cache |
| `Services/Provider/TavusConcurrencyGuard.php` | `AvatarProviders/Tavus/TavusConcurrencyGuard.php` | config keys become `hr-avatar.tavus.concurrency_*`; the "never list or reap active conversations" invariant is kept |
| `Services/Provider/ProviderPreflight.php` | `validateConfig()` per adapter + connect pre-checks | project, template and platform defaults removed |
| `Support/Provider/TavusLanguage.php`, `Exceptions/ProviderException`, `Enums/ProviderFailureClass` | `Tavus/TavusLanguage.php`, `AvatarProviders/{ProviderException,FailureClass}.php` | generic redacted message `Provider request failed (status N)` |
| `Support/Provider/ProviderErrorMessage.php` | **not ported** | provider diagnostics never reach messages or logs |
| `Support/AvatarTemplates/AvatarProviderCatalogue.php` | `Tavus/TavusCatalogue`, `LiveAvatar/LiveAvatarCatalogue`, `LiveAvatar/LiveAvatarVoices`, `Voices/{CartesiaVoices,ElevenLabsVoices}` | Italian-detection heuristic removed (provider `language` passed through); cache key gains credential owner and key fingerprint |
| `Support/AvatarTemplates/{ProviderFieldSpecs,FieldSpec,FieldType,ConfigValidator,TemplateReferenceValidator}.php` | `AvatarProviders/{FieldSpec,FieldType,FieldSpecValidator}`, `Tavus/TavusFieldSpecs`, `LiveAvatar/LiveAvatarFieldSpecs`, `validateConfig()` | back-office label and UI metadata removed (keys, types and limits kept) |
| `Support/AvatarTemplates/TemplatePayload.php` | `Tavus/TavusPayload`, `LiveAvatar/LiveAvatarPayload` (pure) | split per provider |
| `Support/AvatarTemplates/TavusPalSync.php` | `Tavus/TavusPersonaSync.php` | `AvatarTemplate` becomes `AvatarConfig`; platform key becomes resolved `ProviderAuth` |
| `Support/AvatarTemplates/{ActiveTemplateResolver,GlobalAvatarTemplateUsage,PlatformTemplateContext,TemplateDocument}.php` | not ported | org, project and platform-template concepts |
| `Services/ConversationLlm/{LlmBinding,LlmBindingResolver}.php` | `Llm/{LlmBinding,LlmBindingResolver}.php` | `heygenConfigurationId` removed (read from `provider_state` by the adapter); credential resolved per account through `LlmCredentialResolver` |
| `Services/ConversationLlm/ManagedLlmPayload.php` | `TavusPayload::llmLayer()` + `LiveAvatarPayload::llmConfiguration()` | split into the owning adapters |
| `Services/ConversationLlm/HeygenLlmRegistrar.php` | `LiveAvatar/LiveAvatarLlmRegistrar.php` | `heygen_secret_id` and `heygen_llm_configuration_id` columns replaced by `provider_resources` + `provider_state` |
| `Services/ConversationLlm/HeygenVoiceRegistrar.php` | `LiveAvatar/LiveAvatarVoiceRegistrar.php` | `heygen_vendor_secrets` and `heygen_bound_voices` replaced by `provider_resources`; vendor keys are per-account credentials; lock and "never trust bound language" kept |
| `Services/ConversationLlm/{ConversationLlmUsageEstimator,InterviewSessionLlmSnapshot,GeminiKeyValidator}.php` | not ported | BEAI billing and snapshot; key validation deferred (YAGNI) |
| `Services/AvatarPreview/{VoicePreviewService,CatalogueSampleService,VoicePreviewException}.php` | `Voices/{VoicePreview,CatalogueSample}.php` | Tavus PAL persona preview dropped (preview via `tts_engine`); phrases from `hr-avatar.voice_preview.phrases`; JSON base64 response |
| `Services/ApiKeyGenerator.php` | `Keys/KeyGenerator.php` | prefixes `hra_sk_{live\|test}_` + 96 hex and `hra_pk_{live\|test}_` + 48 hex; `key_prefix` keeps the first 8 hex |
| `Support/PublicApi/ApiKeyResolver.php` | `Keys/KeyResolver.php` | legacy fallback, legacy cache and Redis denylist removed; key type, hash-first then state |
| `Http/Middleware/PublicApi/{AuthenticatePublicApi,AssignRequestId,RejectApiKeyInQuery,RequireScope,RateLimitPublicApi,PublicApiTenantContext}.php` | `Http/Middleware/{AuthenticateSecretKey,AssignRequestId,RejectKeyInQuery,RequireScope}` + `RateLimiter::for` + `CurrentAccount` | tenancy becomes account; `IdempotencyKey` not ported (events are idempotent by key) |
| `Support/PublicApi/{Problem,PublicApiExceptionRenderer}.php` | `Http/{Problem,ProblemRenderer,ErrorCode}.php` | developers URL and BEAI codes removed |
| `Support/PublicApi/SessionTokenMinter.php` (+ `MintedSessionToken`, `VerifiedSessionToken`) | `Keys/{ClientSecret,VerifiedClientSecret}.php` | `Participant` and org claims become `sub` = conversation, `acc` = account; `exp` tied to max duration |
| `Services/Webhooks/{WebhookSigner,RetryClassifier,SecretRedactor,OutboundHostGuard}.php` | `Webhooks/Outbound/*` | header `X-BEAI-*` becomes `X-HRA-*`; `verify` with a 300 s window added |
| `Jobs/DeliverWebhookJob.php` | `Jobs/DeliverWebhook.php` | project secret becomes the per-endpoint random secret (AD7); `TenantContextScope`, `WebhookDeliveryDead` event and redeliver offset removed; self-scheduled retries re-signed per attempt |
| `Jobs/ReleaseEndedProviderSessionJob.php` | `Jobs/ReleaseProviderSession.php` | sibling-handover guard and refs-as-arguments removed (refs are immutable after connect); 5 attempts; `released_at` stamp; dispatches `ReconcileTranscript` |

### SDK sources (BEAI `frontend/`, read-only)

| BEAI source | Target | Stripped / changed |
|---|---|---|
| `app/types/interview-provider.ts` | `src/transports/types.ts` + `src/index.ts` types | `dbSessionId` becomes the conversation id; required `endPhrase`/`finalPhrase` become optional `completionPhrases`; `SupportsContextSteering`/`TavusBoundaryTicket` become `sendContext`; `nudgeWrapUp` dropped (host uses `say`) |
| `app/providers/tavus.ts` | `src/transports/daily.ts` | boundary steering ack, echo filter (N5/N15) and the `end_interview` tool call removed; replica/pal dedupe, call-object rendering and speaking signals kept |
| `app/providers/heygen.ts` | `src/transports/liveavatar.ts` | absent-phrase guard removed; `voiceChat` config contract and event names kept |
| `app/providers/factory.ts` | `src/transports/index.ts` + `src/transports/mock.ts` + `src/mock.ts` | `window.__mockInterviewProvider*` E2E globals and handover paint hold removed; scripted mock exported via `./mock` |
| `app/utils/utterance-outbox.ts` | `src/outbox.ts` | `session: number` becomes a conversation id string; `candidate` becomes `user`; batching (20 / 2 s) and idempotency keys added |
| `app/utils/{closing-completion,proctor-config#matchesEndPhrase}.ts` | `src/completion.ts` | case-insensitive substring match and the 1500 ms settle window only |

### Tests to re-home (red first, then BEAI-free)

| BEAI test | HR-Avatar target | Mode |
|---|---|---|
| api `tests/Unit/C7a/TavusProviderTest.php`, `HeygenProviderTest.php`, `TavusConcurrencyGuardTest.php`, `ProviderTokenTest.php` | `tests/Unit/AvatarProviders/{Tavus,LiveAvatar}/*`, `ProviderSessionTest` | port (`Http::fake`, verb, path and body pinned) |
| api `tests/Feature/C7a/ProviderContractFixtureTest.php`, `ProviderSecretTest.php` | `tests/Feature/AvatarProviders/ContractFixtureTest`, `KeyNeverLeaksTest` | port (golden bodies, redaction) |
| api `tests/Feature/C7a/TenantIsolationTest.php`, `CrossParticipantOwnershipTest.php`, `UtteranceControllerTest.php`, `InterviewEndTest.php`, `MigrationSchemaTest.php` | `tests/Feature/AccountIsolationTest`, `Client/EventsTest`, `ConversationEndTest`, `SchemaTest` | rewrite (concepts differ) |
| other api `tests/{Unit,Feature}/C7a/*` (participant, competency, suspend, snapshot, integrity) | — | not ported (BEAI-only) |
| api `tests/Unit/Support/AvatarTemplates/{AvatarProviderCatalogueTest,TavusPalEditableCatalogueTest,VoiceProviderCatalogueTest}.php` | `tests/Unit/AvatarProviders/*CatalogueTest`, `tests/Unit/Voices/*` | port (Italian detection assertions dropped) |
| api tests for `LlmBindingResolver`, `ManagedLlmPayload`, `HeygenLlmRegistrar`, `HeygenVoiceRegistrar`, `TavusPalSync`, `VoicePreviewService`, `WebhookSigner`, `DeliverWebhookJob`, `ApiKeyGenerator`, `ApiKeyResolver`, `SessionTokenMinter` | matching `tests/Unit/*` | port (`sdd-tasks` locates them by class name, e.g. `rg -l 'HeygenVoiceRegistrar' api/tests`) |
| frontend `tests/unit/heygen-provider.spec.ts`, `tavus-provider.spec.ts`, `tavus-provider-ended.spec.ts`, `utterance-outbox.spec.ts`, `provider-anonymity.spec.ts` | `sdk/tests/unit/{liveavatar-transport,daily-transport,daily-transport-ended,outbox,anonymity}.spec.ts` | port |
| frontend `tests/unit/tavus-provider-steering.spec.ts`, `provider-factory.spec.ts`, `interview-provider-types.spec.ts` | `send-context.spec.ts`, `load-transport.spec.ts`, `types.spec.ts` | rewrite |

## File Changes

| File | Action | Description |
|---|---|---|
| `service/**` | Create | Laravel app per the layout above |
| `service/docker-compose.yml` | Create | `postgres` `pgvector/pgvector:0.8.0-pg17` on host **55432**; `redis` `redis:8.0-alpine` on **56379**; `api` (build `.`) on **8100**→8000; `worker` (`php artisan queue:work`); `scheduler` (`php artisan schedule:work`). Named volume `hra-pgdata`. DBs `hr_avatar` + `hr_avatar_test` (init script). No clash with BEAI's 8000/3000/3001/5432/6379/8025. |
| `service/Dockerfile` | Create | mirrors BEAI `api/Dockerfile` (php `8.5.8-fpm-alpine`, the same pinned `COPY --from` tool images, extensions `pdo_pgsql redis pcntl`), with no frontend steps |
| `service/railway.json` | Create | api: Dockerfile build, `preDeployCommand: php artisan migrate --force && php artisan db:seed --class=LlmModelSeeder --force`, healthcheck `/v1/health`. Config as code only, **never applied**. |
| `service/railway.worker.json` | Create | worker: same image, `startCommand: php artisan queue:work` |
| `service/railway.scheduler.json` | Create | scheduler: same image, `startCommand: php artisan schedule:work` |
| `docs/railway.md` | Create | topology (api, worker, scheduler, Postgres, Redis), the per-service config path, env list (`APP_KEY`, `DB_URL`, `REDIS_URL`, `HRA_JWT_SECRET`, `HRA_PUBLIC_BASE_URL`; optional `TAVUS_API_KEY`, `HEYGEN_API_KEY`, `CARTESIA_API_KEY`, `ELEVENLABS_API_KEY`, `HRA_RATE_LIMIT_SECRET`, `HRA_RATE_LIMIT_PUBLISHABLE`, `HRA_RELEASE_DELAY_SECONDS`, `HRA_MAX_DURATION_CEILING`), "never run from this repo" |
| `sdk/**` | Create | per the SDK layout above |
| `tools/spectral/{package.json,bun.lock}`, `.spectral.yaml` | Create | pinned Spectral 6.16.3 |
| `.github/workflows/ci.yml` | Create | actions pinned to commit SHAs. Jobs: **service** (postgres 17 + redis 8 services; `pint --test`; `phpstan analyse` (larastan, level 8); `migrate`; `php artisan test --coverage --min=90`; `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95`); **openapi** (export, then `git diff --exit-code`, then Spectral lint); **sdk** (`bun install --frozen-lockfile`, `bunx tsc --noEmit`, `bun run test:coverage`, `bun run build`, `bun run size`, `bunx playwright install --with-deps chromium webkit`, `bunx playwright test`) |
| `README.md`, `docs/architecture.md`, `docs/api-usage.md`, `docs/sdk-usage.md` | Create | English docs, including the IIFE loader recipe (AD12), webhook signature verification (300 s window, dedupe on `X-HRA-Event-Id`) and the **retention open item** (no purge in this change) |
| `openspec/config.yaml` | Keep | already present |

**Versions** (BEAI catalog; constraints `^minor`, exact patches in locks):

| Area | Versions |
|---|---|
| Runtime | PHP `^8.5`; Postgres `pgvector/pgvector:0.8.0-pg17`; Redis `redis:8.0-alpine` |
| PHP packages | `laravel/framework ^13.8`; `dedoc/scramble ^0.13`; `lcobucci/jwt ^5.6` |
| PHP dev tools | `pestphp/pest ^4.0` + `pestphp/pest-plugin-laravel ^4.0`; `larastan/larastan ^3`; `laravel/pint ^1.27` |
| SDK toolchain | Bun `1.4.0`; TypeScript `5.9.3`; Vitest `^3`; `@playwright/test` `1.61.1` |
| SDK peers | `@daily-co/daily-js` `0.91.0`; `@heygen/liveavatar-web-sdk` `0.0.18` |

A dependency that fails to resolve stops the work (Dependency Resolution Policy).

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | Payload builders, field specs, registrars, concurrency guard, event mappers, transcript role mapping, `KeyGenerator`, `KeyResolver`, `OriginNormalizer`, `ClientSecret`, `WebhookSigner` (sign + verify, known vector, ±300 s), `RetryClassifier`, `OutboundHostGuard`, `TranscriptReconciler` merge | Pest, `Http::fake` with verb, path and body pinned; golden bodies ported |
| Feature | Every route: auth chains and their exact codes; cross-account access → 404 (one test per endpoint); state machine transitions and CAS races; connect pre-checks; idempotent events, grace window; inbound webhook (no-oracle 404s, 413, 422, duplicates); delivery state machine and retry schedule; sweeper and poller; Problem+JSON shape; pagination; health; boot guard | Pest against Postgres (json, jsonb and CHECK need it); `Queue::fake` / `Bus::fake`; `Carbon::setTestNow`; `Http::preventStrayRequests()` globally |
| Arch | No BEAI namespaces; host vocabulary (below) in `app/`, `routes/`, `database/`, `config/` and `sdk/src`; nothing outside `App\AvatarProviders\*` (adapters + registry) branches on a provider id, and the literals `tavus`/`heygen`/`liveavatar` appear only there and in `config/hr-avatar.php`; every model except `LlmModel` uses `BelongsToAccount`; credentials, keys and secrets hidden from serialization; no `var_export`/`print_r` | Pest `arch()` + a file-scan test; Vitest file-scan for the SDK |
| SDK unit | Transports (injected fake SDKs), outbox batching/backoff/stop, conversation API and ended reasons, completion phrases, error mapping, scripted mock | Vitest jsdom, thresholds 90 |
| E2E | IIFE + mock transport smoke | Playwright, Chromium and WebKit, `page.route` fake API |
| Coverage | ≥ 90 overall; ≥ 95 for keys, accounts, middleware, signer and lifecycle | `phpunit.high-integrity.xml` |

**Host vocabulary ban**: the superset of `CLAUDE.md` and the spec, matched case-insensitively with
word boundaries, plurals included: `beai`, `participant`, `candidate`, `competency`/`competencies`,
`bars`, `interview`, `project`, `organization`, `assessment`, `score`, `tenant-of-beai`. The arch
test file itself is exempt. The docs scan applies the same list to `README.md` and `docs/`.

## Security boundaries (RED tests carried into tasks)

| Boundary | Safe behavior | RED test |
|---|---|---|
| Secret key in a browser | live `hra_sk_` + `Origin` → `403 secret_key_origin_forbidden` | `AuthenticateSecretKeyTest` |
| Key in the query string | `?api_key=` / `?key=` / any `hra_sk_`/`hra_pk_` value → `400 api_key_in_query` | `RejectKeyInQueryTest` |
| Key state oracle | unknown or wrong key → `unauthenticated`; `key_expired`/`key_revoked` only after a hash match | `KeyResolverTest` |
| Publishable key alone | a client route without a client_secret → `401 unauthenticated` | `AuthenticateClientTest` |
| Token confusion | `alg: none`, tampered or malformed token → `401 client_secret_invalid`; `sub`/`acc` mismatch → `403 forbidden`; a secret key in `X-HRA-Publishable-Key` → `401 unauthenticated` | `ClientSecretBindingTest` |
| CORS | rejected origin → no `Access-Control-Allow-Origin`; preflight → 204 echo | `ClientCorsTest` |
| Privilege escalation | create or rotate into a scope not held → `403 insufficient_scope`; other mode → `403 forbidden` | `ApiKeyScopeTest` |
| Cross-account | every resource id from account A under B's key → 404 | `AccountIsolationTest` (dataset over all routes) |
| Inbound forgery | an unknown token, wrong `{provider}`, or payload `conversation_id` ≠ ref → identical 404, nothing persisted | `ProviderWebhookTest` |
| Replay | a duplicate callback → 200, one receipt, one transition | `ProviderWebhookIdempotencyTest` |
| SSRF | an endpoint URL resolving to private, loopback or link-local → 422 at create; at delivery a failed attempt `destination_not_allowed`, no request sent | `OutboundHostGuardTest`, `DeliverWebhookTest` |
| Concurrency guard | never lists or ends other conversations | `TavusConcurrencyGuardScanTest` (file scan) |
| Secret leakage | provider, LLM, key, webhook secret and callback token never in a response, log, failed-job record or exception (`Log::spy`, response scan) | `SecretExposureTest` per resource |

## Threat Matrix

N/A: no shell, subprocess, VCS/PR automation, executable-file classification or process-integration
boundary. The HTTP security boundaries are covered by the table above.

## Migration / Rollout

No migration of existing data. The repository is new, additive and local. Migrations create the
schema from empty. Rollout is the auto-chained slices below; nothing is pushed, deployed or applied
to Railway. Importing BEAI templates (D9) belongs to a later change.

### Work-unit slicing hint for `sdd-tasks` (≤ ~400 authored changed lines each, auto-chain)

| Slice | Content |
|---|---|
| S2a | Laravel scaffold (API-only, no web, no Vite), `config/hr-avatar.php`, boot guard, `AssignRequestId`, `Problem`/`ErrorCode`/`ProblemRenderer`, health endpoints |
| S2b | Scramble config + security transformers + export, compose, Dockerfile, CI workflow, Spectral, arch tests (vocabulary and namespace bans) |
| S3a | `accounts`, `api_keys`, `KeyGenerator`, `KeyResolver`, `AccountScope`/`CurrentAccount`, `AuthenticateSecretKey`, `RejectKeyInQuery`, `RequireScope`, rate limiters, `hra:bootstrap-account`, `hra:issue-key` |
| S3b | api-keys endpoints (create, list, rotate, revoke), pagination, publishable keys + origins, `ClientSecret`, `AuthenticateClient`, `ClientCors` |
| S4a | Contract value objects, `ProviderRegistry`, `ProviderException`/`FailureClass`, `MockProvider` |
| S4b | Tavus adapter: payload, concurrency guard, event mapper, teardown/fetchTranscript/fetchSession (ported tests first) |
| S4c | LiveAvatar adapter (`heygen`): context + token, stop + context delete, transcript, fetchSession (ported tests first) |
| S5a | `provider_credentials`, `llm_models` + seeder, `llm_credentials`, `CredentialResolver`, `LlmCredentialResolver`, `LlmBindingResolver`, credential endpoints |
| S5b | `avatar_configs` CRUD, soft delete, field specs, `validateConfig`, voice binding |
| S5c | Sync: `SyncAvatarConfig`, `TavusPersonaSync`, `provider_resources`, `LiveAvatarLlmRegistrar`, `LiveAvatarVoiceRegistrar` |
| S5d | Catalogue (Tavus, LiveAvatar, Cartesia, ElevenLabs) + voice preview + catalogue sample |
| S6a | `conversations` + `ConversationLifecycle` + create/show/end (secret) |
| S6b | Client connect (pre-checks, CAS) / events / end, `conversation_events`, transcript endpoint |
| S6c | Sweeper, `ReleaseProviderSession`, `ReconcileTranscript`/`TranscriptReconciler` |
| S7a | Inbound provider webhooks, receipts, `ProviderEventIngestor`, `ProcessProviderEvent`, poller |
| S7b | `webhook_endpoints` CRUD, `webhook_deliveries`, dispatcher, signer + verifier, `DeliverWebhook`, host guard, delivery log |
| S8a | SDK scaffold, api client, errors, outbox, conversation, mock transport + `./mock`, unit tests |
| S8b | Daily transport (ported) |
| S8c | LiveAvatar transport (ported) |
| S8d | tsup build, size check, Playwright smoke |
| S10 | README, architecture, API/SDK usage, `railway.json`, `railway.worker.json`, `railway.scheduler.json`, `docs/railway.md` |

## Owner ratification items

Deviations from the owner-approved `docs/extraction/DESIGN.md` (D1-D11). Each is already reflected
in the specs and this design; the owner should ratify them before apply.

| # | DESIGN.md | Deviation | Why |
|---|---|---|---|
| R1 | D2 | `provider_resources` ledger replaces BEAI's HeyGen-named tables and columns (AD6) | no provider names in the schema; correct under per-account keys |
| R2 | D10 | A random per-endpoint `hra_whsec_` secret, stored encrypted, **replaces** `HRA_WEBHOOK_SIGNING_SECRET`, which is dropped from the env list (AD7) | one platform secret lets account A forge deliveries to account B |
| R3 | D10 | A `scheduler` service (`schedule:work`) is added next to `api` and `worker`, and Railway config is **three** files (`railway.json`, `railway.worker.json`, `railway.scheduler.json`) instead of one (AD11) | the sweeper and the poller need a scheduler; Railway reads one config file per service |
| R4 | D10 | The api pre-deploy runs `migrate --force` **then** `db:seed --class=LlmModelSeeder --force` | the platform LLM catalogue must exist before any config binds a model |
| R5 | D10 | `HRA_PUBLIC_BASE_URL` is added to the env list | Tavus needs an absolute callback URL |
| R6 | D2 | `avatar_configs`: soft delete (`deleted_at`), `voice`, and `sync_status` values `not_required\|pending\|synced\|failed` | history keeps its config reference; voice binding; explicit sync gate |
| R7 | D2 | `conversations`: `transcript_status`, `provider_end_reason`, `connecting_at` (instead of a generic connect stamp), prefixed ids `conv_`/`avc_`/`we_` | merge-based transcript, provider reason kept apart from the stable `ended_reason` |
| R8 | D3 | `JoinMaterial.transport` gains `mock` | the exported Mock provider needs a transport |
| R9 | D6 | `mount` widens from a video element to any `HTMLElement` (a `<video>` is used as-is) | hosts can pass a container |

## Open Questions

- [ ] **Retention** (proposal open decision 1). There is no purge in this change. Transcripts,
      events, receipts and delivery payloads hold personal data, so the owner must confirm; the
      README records it as an open item.
- [ ] **Owner ratification items** R1-R9 above.
- [ ] **Tavus behaviour** (research, non-blocking). Callback retry and duplicate behaviour, and any
      future signature. The design is already idempotent and token-authenticated.
- [ ] **LiveAvatar transcript timing** and `next_timestamp` pagination (research, non-blocking).
      `ReconcileTranscript` retries 5 times over about 9 minutes, then finalizes as `partial`.
- [ ] **Tavus PAL voice preview** dropped (only previews through `tts_engine` are kept). Confirm it
      is not needed.
