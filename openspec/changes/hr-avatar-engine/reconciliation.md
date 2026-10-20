# Spec ↔ design reconciliation (binding for tasks and apply)

Produced 2026-10-19 by the fresh-context design validator (verdict: FAIL on vocabulary drift,
PASS on hallucination). Rule: **specs win where explicit and testable; the design wins only where
it is more secure or smaller**. Both `design.md` and the affected `specs/*/spec.md` are amended to
match this list; where they still differ, this file wins.

1. IDs: prefixed string PKs `conv_`, `avc_`, `we_`, `evt_`; JWT `sub` is `conv_…`.
2. Artisan commands use the `hra:` namespace: `hra:bootstrap-account`, `hra:issue-key`,
   `hra:conversations:poll`, `hra:conversations:sweep` (product name only in config/prefixes/`HRA_`).
3. Publishable key `hra_pk_{mode}_` + 48 hex; secret key `hra_sk_{mode}_` + 96 hex.
4. Scopes: `*`, `avatar_configs:read|write`, `credentials:manage`, `llm_models:read`,
   `conversations:read|write`, `webhook_endpoints:manage`, `api_keys:manage`. API requires explicit
   scopes; only `hra:bootstrap-account`/`hra:issue-key` default to `*`. 403 on privilege escalation,
   403 `forbidden` on cross-mode issuance, 422 on unknown scope.
5. Error catalogue = spec catalogue: `unauthenticated`, `key_expired`, `key_revoked`,
   `client_secret_invalid`, `client_secret_expired`, `insufficient_scope`, `forbidden`,
   `origin_not_allowed`, `secret_key_origin_forbidden`, `already_connected`, `invalid_state`,
   `conversation_closed`, `config_not_synced`, `config_in_use`, `provider_credential_missing`,
   `llm_credential_missing`, `provider_error`, `provider_concurrency_limited`, `payload_too_large`,
   `method_not_allowed`, `service_unavailable`, plus design-only `api_key_in_query` (400, kept).
   Renderer maps 405, 413, 503.
6. Problem body: `detail` always present; `errors` is `{field: [messages]}`.
7. Request id regex `^[A-Za-z0-9._-]{1,64}$`.
8. Live secret key + `Origin` header → 403 `secret_key_origin_forbidden`.
9. Key resolver: look up by prefix, `hash_equals`, THEN check state (`key_expired`/`key_revoked`
   only after a hash match); unknown key → `unauthenticated`.
10. Rotation: default `overlap_seconds` 3600; `0` sets `revoked_at`. No self-revoke guard.
11. client_secret: `aud` `hr-avatar-client`; `exp = created + 900 + max_duration_seconds`; no
    `mode` claim; no `conversations.mode` column.
12. Client auth: bad signature/alg/malformed → 401 `client_secret_invalid`; expired → 401
    `client_secret_expired`; valid token whose `sub`/`acc` disagrees with route or publishable key →
    403 `forbidden`; missing/invalid publishable key or bearer → 401 `unauthenticated`; missing
    `Origin` → 403 `origin_not_allowed`.
13. CORS: own client middleware (not `HandleCors`). OPTIONS 204 echoes origin, allows
    `X-Request-Id`; real responses emit `Access-Control-Allow-Origin` only after the allowlist
    check. Origins normalised to `scheme://host[:port]`, no wildcards, https except loopback, 1–20.
14. Rate limits: secret per key, publishable 300/min per key; env `HRA_RATE_LIMIT_SECRET`,
    `HRA_RATE_LIMIT_PUBLISHABLE`; 30/min per-IP limiter on failed auth; `X-RateLimit-*` headers.
15. `ended_reason`: `client|api|max_duration|provider|expired|provider_error|provider_capacity`;
    column `connecting_at`; nullable `provider_end_reason`.
16. State machine: no `created→failed`; provider failure is `connecting→failed`; never-connected
    `created→ended` reason `expired` at `created_at + 900 s`; sweep margin 30 s; ending a terminal
    conversation is idempotent 200; connect after CAS loss → `already_connected` if
    connecting/live, `invalid_state` if terminal.
17. Connect pre-checks resolve provider + LLM credentials before the CAS (422, stays `created`);
    create makes no provider/credential call. Throttle → 429 `provider_concurrency_limited`
    (reason `provider_capacity`); other failure → 502 `provider_error`.
18. Create body: `system_prompt` and `language` (`^[a-z]{2}$`) required; `opening_text` ≤ 4000;
    `max_duration_seconds` 30..`HRA_MAX_DURATION_CEILING` (default ceiling 3600, default 1800);
    `metadata` opaque JSON object ≤ 8 KB round-tripped as-is. Response adds
    `client_secret_expires_at`, `transcript_status`.
19. Events: 1–100 per request, each `{idempotency_key, occurred_at?, type, …}`; lifecycle `name`
    `live|error`, optional `data`; server-assigned monotonic `sequence`. 60 s grace after terminal,
    then 409 `conversation_closed`. Lifecycle `live` in `created` stored without effect; utterance in
    `created` → 409 `invalid_state`.
20. Transcript = merge: `{conversation_id, status: pending|ready|partial, utterances[{sequence,
    role, text, occurred_at, source}]}`; keep client lines, add provider lines whose role+normalised
    text is absent; 5 retries then `partial`; `transcript_status` column. Mock `fetchTranscript`
    returns the opening-text utterance or `[]`. Tavus roles: `assistant|replica`→`avatar`, `system`
    excluded, unknown → shape error. `created→ended` finalises immediately as empty `ready`.
21. Release job delay `HRA_RELEASE_DELAY_SECONDS` default 20; up to 5 attempts.
22. Contract: `start` (spec + design name; supersedes the validator's `issue`), `teardown(ref, contextRef)`, `fetchTranscript`, `fetchSession →
    {ended, endReason}` (the poller builds the event), plus `parseCallback`, `validateConfig`, `sync`,
    `catalogue`, and `ingestion(): Callback|Poll|None` (no provider-name branching outside adapters).
23. Provider id `heygen` everywhere (registry, credentials, catalogue, config enum); `liveavatar`
    only as transport value and class directory.
24. Poller covers adapters whose `ingestion()` is `Poll` (heygen); Tavus not polled.
25. Inbound webhook token: 32 random bytes as 64 hex, minted only for `Callback` providers;
    callback base `HRA_PUBLIC_BASE_URL`; 413 above 256 KB; 422 on malformed body after auth;
    `ignored` receipt kind; token redacted in logs; other providers in path → 404;
    `provider_webhook_receipts.account_id` NOT NULL.
26. Consumer webhook secret: random `hra_whsec_` + 64 hex per endpoint, encrypted + hidden; no
    `HRA_WEBHOOK_SIGNING_SECRET`.
27. Outbound headers `X-HRA-Signature`, `X-HRA-Timestamp`, `X-HRA-Event-Id`, `X-HRA-Event-Type`;
    body `{id: evt_…, type, created_at, data.conversation}`; `transcript.ready` adds
    `transcript {status, utterance_count}`. Delivery `pending|succeeded|failed`, column `attempts`;
    blocked destination = failed attempt, retried. Rows inserted in the state-change transaction,
    dispatched after commit; re-sign per attempt with fresh timestamp; 10 s timeout;
    `WebhookSigner::verify` 300 s window; disabling/deleting an endpoint stops retries.
28. Endpoint management: `description`; max 20 endpoints; URL https outside local/testing, no
    credentials, ≤ 2048 chars; `GET /v1/webhook-endpoints/{id}/deliveries` (status filter, safe
    `last_error` codes).
29. LLM credentials `PUT|GET|DELETE /v1/llm-credentials/{llm_provider}`, unique (account,
    llm_provider); no `avatar_configs.llm_credential_id`; resolved per account at connect, no
    platform fallback. `llm_models(provider, model, enabled)` read-only, scope `llm_models:read`.
    Provider credentials `source: account|platform|none`; `last_four` null for platform/none and
    keys < 12 chars.
30. Avatar configs: `sync_status not_required|pending|synced|failed` + redacted `sync_error`;
    queued `SyncAvatarConfig` (3 tries); `POST /v1/avatar-configs/{id}/sync` → 202; PATCH of
    config/persona/model/voice resets to `pending`; create conversation on unsynced → 409
    `config_not_synced`; soft delete; only non-terminal conversations block delete (`config_in_use`);
    `provider` immutable; `voice {engine, voice_id}` validated against adapter support.
31. `GET /v1/catalogue`; normalised entries; 5-minute cache, `fresh=1`. `POST /v1/voice-preview
    {provider, voice_id, tts_engine|null, language}` → JSON `{content_type, audio_base64}`;
    `text` key → 422; Tavus with null `tts_engine` → 422.
32. Pagination on growing lists: `limit` 1–100 (default 25), opaque `cursor`, `{data,
    next_cursor}`, order `created_at desc, id`. Fixed-size lists (provider-credentials,
    llm-models, llm-credentials) are exempt.
33. Do NOT port `ProviderErrorMessage`; generic `Provider request failed (status N)`, log status
    class only. `Http::preventStrayRequests()` in tests; negative test that the concurrency guard
    never lists or ends other conversations.
34. SDK: `mount: HTMLElement` (a `<video>` used as-is, else one is created); states
    `connecting|live|ended|error`; transcript `{role, text, final, occurredAt}`; `ended.reason`
    `user|server|provider|completion_phrase|error`; `HrAvatarError {code, message, recoverable,
    status, request_id}`; `toggleMic()` sync → boolean; `sendContext(string|object)`; no
    `supports()`; `transports` override + `loaders`; outbox flush at 20 events or 2 s, 409
    `conversation_closed`/401 stops forwarding and emits `error`, `pagehide` keepalive flush; error
    codes incl. `invalid_client_secret`, `conversation_ended`, `provider_library_missing`; `./mock`
    export with scripted mock; IIFE exposes `window.HrAvatar.connect`; size ≤ 20 KB gzip;
    `completionPhrases` case-insensitive substring + 1500 ms settle.
35. Boot refuses outside `testing` when `HRA_JWT_SECRET` < 32 bytes. `/v1/health` and
    `/v1/health/ready` bodies per spec; readiness 503 with `checks`.
36. README records the retention open item; docs scan for host-product terms. Railway: three
    files (`railway.json`, `railway.worker.json`, `railway.scheduler.json`); pre-deploy
    `migrate --force` then `LlmModelSeeder`; `railway.md` lists `HRA_PUBLIC_BASE_URL`.
37. Arch vocabulary ban: CLAUDE.md superset with word boundaries. Mock `fetchSession` state lives
    in cache (worker and api are separate processes).

## Additions from the BEAI adapter design validation (2026-10-19)

38. `GET /v1/avatar-configs` accepts an optional exact-match `external_ref` query filter (applied server-side before pagination), so a host sharing one account across its own tenants never loses configs to the page cap. Scope `avatar_configs:read`.
39. SDK `forwardTranscript: false` suppresses **utterance** forwarding only. Lifecycle events (`live`, `error`) are always forwarded, because for `mock` and `heygen` (no provider callback) the client `live` event is the only `connecting → live` transition.

## Additions from the slices 15-19 review (2026-10-19)

40. `POST /v1/avatar-configs/{id}/sync` on a config whose adapter has `syncRequired() === false` (Mock) answers 409 `invalid_state` and queues nothing. (Already the behaviour since slice 17; pinned by `AvatarConfigEndpointTest` "refuses a config whose provider needs no objects".)
41. Sync race: an integer `avatar_configs.sync_revision` moves up on every change to `config`, `persona`, `llm_model_id` or `voice` (whatever the change settles as) and on a manual sync. `SyncAvatarConfig` carries the revision it was queued for; its settling writes (synced, not required, failed) are one conditional `UPDATE ... WHERE sync_revision = ?`, so a run overtaken by a newer change is a no-op. A run that starts past its revision makes no provider call. The revision is not part of any resource.
42. `POST /v1/voice-preview` has its own per-key throttle (`throttle:preview`, `HRA_RATE_LIMIT_PREVIEW`, default 10 a minute), in addition to the general secret-key budget; over it the answer is 429 `rate_limited` with `Retry-After` and `X-RateLimit-*`.
43. `CatalogueSample` (task 19.2) is dropped: no requirement or route uses it.
44. The voice-preview cache key includes the credential owner and key fingerprint (more secure than the spec tuple `(vendor, voice, model, phrase version, language)`); the design wins.

## Rulings on the slices 20-24 batch (2026-10-19, owner/orchestrator)

45. Transcript merge matches each stored line to **one** provider line (the slice 23 behaviour). The `conversations` spec ("Transcript and reconciliation") and design AD9 are amended: a stored utterance accounts for exactly one provider utterance of the same role and normalized text, so a repeated line is not lost.
46. `RetryClassifier` and `SecretRedactor` (task 21.2) are dropped for good. Every non-2xx is a retried attempt and `last_error` is a closed set of codes, so there is nothing to classify or redact.
47. Slice 29 passes the **provider receipt id** (not rows) into `ReconcileTranscript`; the job reads the receipt's hidden payload where it runs, so no transcript text rides on the queue or lands in `failed_jobs`.
48. The voice-preview budget of 10 a minute per key (`HRA_RATE_LIMIT_PREVIEW`) is accepted (item 42).
49. `SyncAvatarConfig` runs are serialized per config (`SerializeConfigSync`, a cache lock keyed by account and config id). A run that finds another in flight queues a fresh copy a few seconds later instead of being released, so it spends none of its three attempts. This closes the residual race of item 41: the provider never receives two states at once. The lock expires after 300 s so a dead worker cannot hold a config.

## Rulings on the slices 25-29 batch (2026-10-19, owner/orchestrator)

50. `AvatarProvider::callbackTranscript` stays in the contract. Item 22's list of contract operations is one longer: `start`, `teardown`, `fetchTranscript`, `callbackTranscript`, `fetchSession`, `parseCallback`, `validateConfig`, `sync`, `catalogue`, `ingestion`.
51. Receipt uniqueness is `(account_id, provider, idempotency_key)`. The provider-webhooks spec, design (event flow and schema table) and task 29.1 are amended to match.
52. The sweeper and the poller keep their per-account scoped queries. Both loops carry a `ponytail:` comment naming the ceiling (cost grows with the number of accounts) and the way past it: a platform due-index needs the boundary rule (the two permitted unscoped reads) extended first.
53. `TavusProvider::fetchSession` reads only the documented terminal status `ended` as an end (an allow-list; Tavus documents `active` and `ended` for a conversation, in the get-conversation and list-conversations references). Any other status is not an end; one that is not `active` either is logged by value (cut to 32 characters) and nothing else. The max-duration sweeper still bounds a conversation read as running. Intentional change to an existing assertion: `TavusSessionStatusTest` no longer expects `completed` and `failed` to be ended.
54. A missing provider or LLM credential in `ReconcileTranscript` is non-retryable: the job fails itself at once (like an unreadable transcript) and the transcript is finished as `partial`, without spending the five attempts. Intentional change to an existing assertion: `ReconcileTranscriptTest` no longer expects the job to throw `ProviderException`.
55. An unset or unusable `HRA_PUBLIC_BASE_URL` is a failed readiness check (`checks.public_base_url`, 503) whenever any registered adapter's `ingestion()` is `Callback`; the check is absent otherwise. Connect keeps failing closed and now says why: 503 `service_unavailable` with a clear detail, before any state change and before any provider call (the conversation stays `created`, so it can be connected once the address is set). The earlier behaviour failed the conversation as `provider_error` and answered 500. Intentional change to existing assertions: the three exact `checks` bodies in `HealthTest` gain `public_base_url`.
56. The LiveAvatar session-token wording and lifetime, and the Tavus persona `editable` flag, stay documented assumptions, listed under "Known assumptions" in the README.

## Rulings on the security fix unit (2026-10-20, post-review of slices 3-30)

57. **Outbound destination guard, IPv6 spellings of IPv4.** `OutboundHostGuard` normalises an address before judging it: IPv4-mapped (`::ffff:a.b.c.d` and `::ffff:hhhh:hhhh`), IPv4-translated (`::ffff:0:a.b.c.d`), IPv4-compatible (`::a.b.c.d`) and 6to4 (`2002:hhhh:hhhh::/48`) addresses are replaced by the IPv4 address they carry, and every IPv4 block (private, loopback, link-local, shared 100.64/10, benchmarking 198.18/15, protocol assignments 192.0.0/24, multicast, reserved, 0/8) applies to it. NAT64 stays blocked **as a whole** (`64:ff9b::/96`, and now the local-use `64:ff9b:1::/48`): a gateway may translate to anything, so the embedded address is not trusted. Finding R1-001 was not reproducible at HEAD on PHP 8.5 (the platform's `FILTER_FLAG_NO_RES_RANGE` refuses all of `::ffff:0:0/96`, so a mapped public address was over-blocked) but the protection then rested on a filter's undocumented scope; it now rests on the explicit normalisation, and a mapped **public** address is allowed (it reaches that public IPv4 address). A host-shaped value with `:` or `[` that is not an address is blocked (R3-002; `parse_url` already refuses a zone identifier, the guard no longer depends on that). Two live-DNS assertions of `OutboundHostGuardTest` (a `.invalid` name and the trailing-dot `localhost.`) are removed as the finding R3-006 proves them resolver-dependent; the unresolvable case stays pinned by an injected resolver. `DestinationUrl` is split into a named `parts()` helper (R2-006); `isBlocked()` documents that it is false when destinations are not enforced (R2-004).

58. **Outbound delivery fails closed on an unresolved host (R4-003, R3-003).** When destinations are enforced and the guard returns no address for a host name, `DeliverWebhook` records the attempt as `destination_not_allowed` and sends nothing, so the HTTP client never resolves a name the guard did not judge (a name that resolves to nothing at check time and to a private address at connect time is not contacted). The connection stays pinned (`CURLOPT_RESOLVE`) to the vetted addresses; an IP literal needs no pin, and an un-enforced destination (`local`, `testing`) keeps its un-pinned behaviour. Intentional change to two existing assertions of `DeliverWebhookTest`: the "pins nothing for a host that does not resolve" dataset row is removed, and "attempts a host that does not resolve and records the connection failure" now expects no request and `destination_not_allowed` (the attempt is counted and retried like any other refusal). `OutboundHostGuard::vettedAddresses()` documents that an empty list is ambiguous without `enforced()`.
