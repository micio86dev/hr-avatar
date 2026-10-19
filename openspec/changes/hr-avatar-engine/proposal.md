# Proposal: HR-Avatar engine (service + SDK)

## Intent

Avatar conversation mechanics (provider session lifecycle, LLM binding, voice, real-time join
material, transcript capture) are currently fused into BEAI's interview code. A second, unrelated
product needs only the avatar piece, with none of BEAI's assessment logic. This change builds
HR-Avatar as a standalone, provider-agnostic engine: an HTTP API (`service/`) plus a browser SDK
(`sdk/`), with its own account and key space, so a consumer can integrate HR-Avatar alone, BEAI
alone, or both, with no hidden coupling.

The analysis and design are approved and binding: `docs/extraction/FINDINGS.md` (F1-F8, owner
decisions 1-4) and `docs/extraction/DESIGN.md` (D1-D11) in the BEAI wrapper. This proposal
covers design slices **S2-S8** plus HR-Avatar's own documentation and Railway config-as-code (the
HR-Avatar part of **S10**).

Why now: the extraction is purely additive in a new repository, so it can proceed without risk to
the BEAI demo path, and it is the prerequisite for the later BEAI adapter change (S9).

## Scope

### In Scope
- **S2 Foundation** - Laravel 13 / PHP 8.5 / PostgreSQL 17 / Redis 8 scaffold in `service/`
  (versions per BEAI `docs/version-catalog.md`), Pest with PCOV, Scramble, `/v1` routing,
  RFC 9457 Problem+JSON errors with stable `code`, request id, health endpoints, own
  `docker-compose.yml`, CI workflow (tests + coverage gate + OpenAPI drift + Spectral lint),
  arch tests banning host-product vocabulary and BEAI namespaces.
- **S3 Accounts and keys** - `accounts`, `api_keys` (secret `hra_sk_{live|test}_` / publishable
  `hra_pk_{live|test}_`, sha256 hash + indexed prefix, `hash_equals`, scopes, origin allowlist,
  expiry, revocation, `last_used_at`, rotation with overlap window), live-secret-with-`Origin`
  rejection, per-key rate limit, per-conversation `client_secret` JWT; an artisan command to
  bootstrap an account and its first secret key (there is no admin UI).
- **S4 Provider adapters** - provider contract taking `ConversationSpec` and returning
  `JoinMaterial`; Tavus, HeyGen LiveAvatar and deterministic Mock adapters; concurrency guard;
  transcript reconciliation; teardown. Ported tests first.
- **S5 Configuration** - `avatar_configs` (provider, `config`, `persona`, LLM binding, sync
  status, `external_ref`), `provider_credentials` (per-account encrypted, platform env fallback,
  owner decision 3), `llm_models` platform catalogue, `llm_credentials`, Tavus persona/pal sync,
  HeyGen LLM and voice registrars, provider catalogue, voice preview.
- **S6 Conversations** - lifecycle `created -> connecting -> live -> ended | failed`, secret-key
  endpoints (create, show, end, transcript), client endpoints (connect, batched idempotent events,
  end), `conversation_events`, max-duration sweeper and delayed provider-release job.
- **S7 Webhooks** - NEW inbound provider-webhook ingestion
  (`POST /v1/webhooks/providers/{provider}/{token}`, per-conversation unguessable token, provider
  signature where offered, dedupe via `provider_webhook_receipts`); outbound consumer webhooks
  (`webhook_endpoints` CRUD, `webhook_deliveries`, `conversation.live`, `conversation.ended`,
  `transcript.ready`, `X-HRA-Signature: v1=hmac_sha256("{ts}.{body}")`, retries with backoff).
- **S8 SDK** - `@hr-avatar/sdk` (TypeScript, Bun, tsup ESM/CJS/IIFE): `HrAvatar.connect`,
  events `state|transcript|error|ended`, mic control, `say`, `interrupt`, `sendContext`,
  `whenAvatarSilent`, `end`; transcript auto-forwarding outbox (opt-out); provider libraries as
  optional lazy-imported peer deps; exported Mock provider; Vitest, Playwright smoke page
  (Chromium + WebKit), bundle-size check.
- **S10 (HR-Avatar part)** - English README, architecture notes, API usage examples for the
  service and the SDK, `service/railway.json` and `docs/railway.md` (config as code only).
- `openspec/config.yaml` for this repo if still absent when the spec phase starts (see Risks).

### Out of Scope
- **S9** BEAI adapters (api `HrAvatarProvider`, template export command, frontend adapter,
  backoffice option, BEAI Scramble re-export) - a separate later change in the BEAI wrapper.
- Any change to `@beai/embed` (owner decision 4: it stays unchanged and zero-dependency).
- Any change to BEAI `api/`, `frontend/`, `backoffice/`; BEAI templates and branding stay in BEAI.
- Any deploy or Railway apply; any push; any work on `main`. Adding `hr-avatar` as a wrapper
  submodule (needs a remote, owner action).
- Admin or back-office UI of any kind.
- Conversation data retention/purge (see open decisions).
- Providers beyond Tavus, HeyGen LiveAvatar and Mock.

## Capabilities

`hr-avatar/openspec/specs/` is empty: every capability is new.

### New Capabilities
- `service-foundation`: `/v1` routing, Problem+JSON error contract, request id, health
  endpoints, Scramble OpenAPI publication and drift gate, boundary arch rules.
- `api-keys`: accounts, secret and publishable keys, scopes, origin rules, rotation, revocation,
  rate limiting, per-conversation `client_secret`, account bootstrap command.
- `provider-adapters`: provider contract (`ConversationSpec` -> `JoinMaterial`), Tavus,
  HeyGen LiveAvatar and Mock adapters, concurrency guard, transcript reconciliation, teardown.
- `credentials`: per-account encrypted provider and LLM credentials with platform env fallback,
  never returned (last four characters at most); `llm_models` platform catalogue.
- `avatar-configs`: avatar config CRUD, LLM binding, voice binding and provider-side sync
  (persona/pal sync, LLM and voice registrars), provider catalogue, voice preview.
- `conversations`: conversation state machine, secret-key and client endpoints, idempotent
  event ingestion, transcript, max-duration and release teardown.
- `provider-webhooks`: authenticated, idempotent ingestion of provider callbacks mapped onto
  conversation state.
- `consumer-webhooks`: endpoint management, signed delivery, retry/backoff, delivery log.
- `client-sdk`: the `@hr-avatar/sdk` public interface, provider transports, outbox, mock, build
  outputs and size budget.

### Modified Capabilities
None.

## Approach

Port, do not reinvent: each slice re-homes BEAI tests first (made BEAI-free), watches them fail,
then ports the implementation, stripping host concepts at the seam BEAI already exposes
(`QuestionContext` -> `ConversationSpec { systemPrompt, openingText, language,
maxDurationSeconds, metadata }`). New behavior (publishable keys, rotation, inbound webhooks)
is test-first from scratch.

Concrete port sources (BEAI, read-only):

| HR-Avatar area | BEAI source |
|---|---|
| Provider contract and adapters | `api/app/Services/Provider/{ProviderSessionService,ProviderToken,TavusProvider,HeygenProvider,MockProvider,TavusConcurrencyGuard,ProviderPreflight}.php` |
| Config, catalogue, persona sync | `api/app/Support/AvatarTemplates/{AvatarProviderCatalogue,ProviderFieldSpecs,TavusPalSync}.php`, generic columns of `api/app/Models/AvatarTemplate.php` |
| LLM binding and voice | `api/app/Services/ConversationLlm/{LlmBindingResolver,ManagedLlmPayload,HeygenLlmRegistrar,HeygenVoiceRegistrar}.php`, `api/app/Models/{LlmModel,LlmCredential}.php` |
| Voice preview | `api/app/Services/AvatarPreview/{VoicePreviewService,CatalogueSampleService}.php` |
| Key pattern | `api/app/Services/ApiKeyGenerator.php`, `api/app/Support/PublicApi/ApiKeyResolver.php`, `api/app/Models/ApiClient.php`, `api/app/Http/Middleware/PublicApi/*` |
| Client secret | `api/app/Support/PublicApi/SessionTokenMinter.php` |
| Outbound webhooks | `api/app/Services/Webhooks/WebhookSigner.php`, `api/app/Jobs/DeliverWebhookJob.php` |
| Teardown | `api/app/Jobs/ReleaseEndedProviderSessionJob.php`, `api/app/Support/Interview/AvatarSilenceDetector.php` (SDK side) |
| SDK mechanics | `frontend/app/providers/{tavus,heygen,factory}.ts`, `frontend/app/types/interview-provider.ts`, `frontend/app/utils/utterance-outbox.ts`, `frontend/app/components/AvatarPlayer.client.vue` |
| Tests to re-home | api `tests/Unit/C7a/*`, `tests/Feature/C7a/*`, `tests/Feature/C14/*`, `ConversationLlm/*`, `Support/AvatarTemplates/*`; frontend provider, factory, anonymity, outbox and avatar-player specs |

BEAI-only code stays behind (`SystemPromptComposer`, competency/BARS composers,
`InterviewController`, participant state machine, `QuestionContext`). SDK renames:
`endPhrase/finalPhrase` -> `completionPhrases`, `dbSessionId` -> conversation id,
`TavusBoundaryTicket` -> `sendContext`.

Delivery: auto-chain. One feature branch `feature/extract-avatar-service` in `hr-avatar`, local
work-unit commits only, one chained slice per design slice (S2...S8, S10-docs); slices that
exceed the 400-line review budget are split by `sdd-tasks` into deliverable work units. Each
slice ends green on its own tests, coverage gate and OpenAPI drift check.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `hr-avatar/service/` | New | Laravel API, migrations, adapters, jobs, Pest suites, Scramble output |
| `hr-avatar/service/railway.json` | New | Railway topology as code (never applied) |
| `hr-avatar/sdk/` | New | `@hr-avatar/sdk` package, Vitest, Playwright smoke, size check |
| `hr-avatar/docs/` | New | Architecture, API usage, SDK usage, `railway.md` |
| `hr-avatar/README.md` | New | Entry documentation |
| `hr-avatar/.github/workflows/` | New | CI for service and SDK |
| `hr-avatar/openspec/` | New | Specs and change artifacts for this repo |
| BEAI `api/`, `frontend/`, `backoffice/`, `embed/` | None | Read-only port sources |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Provider webhook semantics (Tavus `callback_url`, LiveAvatar webhooks, signatures) unverified | High | Researched in parallel; resolved in `sdd-design` (dependency, not blocker). Ingestion authenticates by per-conversation token regardless; teardown never depends on webhooks (explicit end + sweeper + release job). |
| BEAI host vocabulary leaks into ported code | Med | Arch tests banning host terms and `App\` BEAI namespaces from S2; review gate rule in `CLAUDE.md`. |
| Live provider calls in tests or CI (cost, flakiness) | Med | `Http::fake` contract fixtures per provider; Mock adapter for all integration and SDK tests; no live keys in CI. |
| Slices exceed the 400-line review budget | High | auto-chain slicing in `sdd-tasks`; ported tests kept in the same unit as their code. |
| Pinned dependency fails to resolve | Low | BEAI Dependency Resolution Policy: stop and report, never downgrade or substitute. |
| Secret material exposure (keys, provider/LLM credentials) | Med | Hash-only key storage, encrypted casts, hidden serialization, serialization-exposure tests per resource, 95% coverage on auth and signatures. |
| `hr-avatar/openspec/config.yaml` does not exist yet | Low | Create it at the start of the spec phase with the rules from `hr-avatar/CLAUDE.md`; no `rules.proposal` could be applied to this proposal. |
| SDK bundle growth from provider libraries | Low | Peer deps, lazy import per provider, size budget enforced in CI. |

## Rollback Plan

Purely additive in a new local repository. Nothing in BEAI changes and nothing is pushed or
deployed. Rollback = reset or delete the `feature/extract-avatar-service` branch in `hr-avatar/`
(or drop individual chained slices by resetting to the previous slice commit). No data
migration, no shared infrastructure, no consumer depends on HR-Avatar until S9.

## Dependencies

- Approved inputs: BEAI `docs/extraction/FINDINGS.md`, `docs/extraction/DESIGN.md`.
- Provider webhook research (parallel) - required by `sdd-design` for S7 only.
- Pinned versions from BEAI `docs/version-catalog.md`; local toolchain per BEAI `docs/dev-setup.md`
  (PHP 8.5 + PCOV + pdo_pgsql, Composer, Bun, Node 24, Docker, Playwright browsers).
- Gemini through an OpenAI-compatible base URL as the LLM path (as in BEAI).

## Open Decisions (returned to the orchestrator)

1. **Conversation data retention.** HR-Avatar stores transcripts and events (personal data).
   Proposed default: no purge in this change, documented as an open item. Owner to confirm or
   ask for a parametric purge in scope.
2. **Test-mode key semantics.** Should `test` keys be forced to the Mock adapter (no provider
   cost) or only be a separate key space? Proposed default: separate key space only; design
   decides unless the owner rules otherwise. Neither blocks the spec phase.

## Success Criteria

- [ ] Every behavior lands with an observed RED test before implementation (strict TDD).
- [ ] Service: `php artisan test --coverage --min=90` passes; auth/keys, webhook signatures and
      the conversation state machine at >= 95%; nothing new below the 85% floor.
- [ ] SDK: Vitest thresholds 90 (lines, branches, functions) pass; Playwright smoke on the Mock
      provider passes in Chromium and WebKit; bundle-size check passes.
- [ ] `php artisan scramble:export` emits valid OpenAPI covering every HR-Avatar endpoint; it is
      committed, Spectral lint is clean, and CI fails on drift.
- [ ] Arch tests prove no BEAI namespace or host-product vocabulary in `service/` and `sdk/`.
- [ ] A host can, using only HR-Avatar: bootstrap an account, issue keys, store credentials,
      create an avatar config, create a conversation, connect from the SDK with publishable key +
      `client_secret` on the Mock provider, forward transcript events, end it, read the transcript,
      and receive signed `conversation.*` / `transcript.ready` webhooks.
- [ ] Live secret key with an `Origin` header is rejected; publishable key alone grants nothing;
      cross-account access is refused (tested).
- [ ] Inbound provider webhooks are authenticated and idempotent (duplicate delivery is a no-op).
- [ ] English README, architecture notes and API/SDK usage examples exist for service and SDK.
- [ ] `service/railway.json` and `docs/railway.md` exist; no Railway command was ever run.
- [ ] BEAI repositories show no diff from this change; `@beai/embed` unchanged.
