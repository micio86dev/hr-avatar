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

59. **A scheduled relay queues again the work whose job was lost (R4-001, R4-002 of slice 22).** `hra:relay` (every minute, `withoutOverlapping`, one account at a time with the same `ponytail:` ceiling as ruling 52) takes the `pending` webhook deliveries that are stale: no attempt scheduled (`next_attempt_at` null) and created more than 300 s ago, or an attempt overdue by more than 300 s. A row is claimed by one conditional `UPDATE` that moves its lease (`next_attempt_at = now()`) under the same staleness predicate, and only the claimer pushes the job; two relays therefore never queue one row twice, and a push that failed after the claim is retried once the lease has run out (no state is lost: the row is still `pending`). The delivery job is unchanged: its writes stay conditional on the attempt count, so the rare duplicate job (the original was only slow) cannot count an attempt twice, and receivers deduplicate on `X-HRA-Event-Id`. A queue push failure inside the dispatcher's `afterCommit` still reaches the caller (the transaction has committed); the relay is what makes that survivable. A failure to queue one row is logged by kind (`what`, `id`, exception class) and the command exits with a failure after the rest are done.
60. **Provider receipts that are stored and never processed are queued again (R4-001 of slice 29).** Two paths, one claim. `ProviderEventIngestor::requeue()` queues a receipt that is still `processed_at IS NULL` and untouched for `STALE_SECONDS` (60), claiming it with a conditional `UPDATE` of its `updated_at` (the lease), so of a redelivery and the relay exactly one queues it. A redelivered or re-polled copy of an event already stored now calls it (a copy arriving inside the lease queues nothing, so concurrent copies still process once; the stored count still counts only inserts); `hra:relay` calls it for every unprocessed receipt of an account created less than 24 h ago (an event that failed for a day is left for a person: the relay does not loop on it). Processing stays idempotent (`processed_at` is set by a conditional update and the state machine absorbs repeats), so a duplicate job is harmless. No migration: `updated_at` is the lease.

61. **Connect fails the conversation on any error after the claim (R3-connect-unguarded-tail-after-claim).** At HEAD the provider lookup, the credential checks and the public-base-url check already run before the claim (slice 26 and ruling 55), so an unknown provider or a missing address leaves the conversation `created` and connectable (pinned by a test that was missing). What was still outside the guard is now inside it: preparing the callback target and the start both fail the conversation (`provider_error`, or `provider_capacity` for a throttle) and re-raise. Keeping the references is guarded separately: a failure there fails the conversation as well and, because a started session with no stored reference could never be released later, ends that session at once with the adapter's `teardown`; a failure of that `teardown` is logged by kind (conversation id and exception class) and never replaces the original failure. An `ApiProblem` from keeping (the conversation ended while the session was starting) is passed through unchanged, as before, with its release already queued. The remaining gap is a process killed between the start and the write of the references; that session ends at its own maximum duration (the spec passes `max_duration_seconds` to the provider) and the sweeper ends the conversation.

62. **Integer settings are validated at boot; an unknown scope is a refusal; an Origin refusal costs budget (`hb3abddd`, `h86eff30`).**
    - *Boot validation, not clamping.* The application refuses to start (`RuntimeException` naming the variable, never the value) when `HRA_RATE_LIMIT_SECRET`, `HRA_RATE_LIMIT_PUBLISHABLE` or `HRA_RATE_LIMIT_PREVIEW` is below 1, `HRA_MAX_DURATION_CEILING` below 30 (the shortest conversation) or `HRA_RELEASE_DELAY_SECONDS` below 5. A clamp was rejected: an empty variable casts to 0, and a clamp to the minimum would still serve one request a minute, which hides the misconfiguration instead of naming it. The check runs in every environment, `testing` included (the defaults are valid), and is documented in `config/hr-avatar.php`, `docs/railway.md` and the README. This also gives `release_delay_seconds` its lower bound (R3-002 of `h86eff30`).
    - *Unknown scope.* `Scope::grantsNamed()` replaces `Scope::from()` in `RequireScope` and in the key-issue scope comparison: a scope name this version does not know is never granted, not even to the wildcard. A route that names one answers 403 `insufficient_scope` and logs `Route requires an unknown scope` with the name (a route literal, not a secret); rotating a key that stores a retired scope answers 403 instead of 500.
    - *Origin refusal counted (reverses a slice 4 assertion).* A live secret key presented with an `Origin` header spends the caller address's failed-authentication budget like a 401 (R4-001). The test "does not count an Origin refusal as a failed authentication" is replaced by "counts an Origin refusal ..." (30 refusals, the 31st is 429 `rate_limited`); a caller on another address is unaffected. The cost is the shared-address caveat below.
    - *Documented, not changed.* The middleware priority ordering is explained in `bootstrap/app.php` and pinned by a test (R2-middleware-priority-comment). The shared-address behaviour of the failed-auth limiter (R3-002, R4-002) is documented in `docs/api-usage.md` and `config/hr-avatar.php`; the fix is `HRA_TRUSTED_PROXIES` (already shipped) and, for callers behind one NAT, fixing the credentials. Not changed, as suggestions or accepted design: the separate check-then-record calls of `FailedAuthLimiter` (R2, R3-005: best effort by design, the overshoot is bounded by the requests in flight), the window constant beside the budget in config (R2), and the fixed test credentials of `phpunit.high-integrity.xml` (R1-001: test-only fixtures, no production reach). R3-004 (the high-integrity config "forces" `DB_PORT`) was not reproducible: PHPUnit's `<env>` does not override a variable already set, and every run of this repository's gates used `DB_PORT=55442`; recorded as already correct.

63. **Key rotation does not escape an expiry; the rest of the key-domain findings (`h450eb50`, `h83fe7cc`, `hbac90c4`).**
    - *Expiry inherited (R3-001 `h450eb50`).* The rotated successor takes the old key's original `expires_at` (none when the old key had none), read before the old key is shortened to the overlap. The api-keys spec and the endpoint description are amended; `openapi.json` is regenerated. Intentional change of behaviour from slice 5, which documented "the successor does not inherit an expiry".
    - *Rotation lock (R3-002).* The interleaved case is pinned: a second rotation holding a copy bound before the first one revoked the key is judged on the row as the lock finds it (409 `invalid_state`, one successor). With an overlap greater than 0 the old key stays rotatable until it expires, so two rotations may both succeed, each with a successor; that is accepted (both successors carry the old key's expiry) and the controller comment now says so instead of claiming exclusivity. True parallel connections cannot be exercised under `RefreshDatabase` (one wrapping transaction), so the proof is the interleaving test.
    - *Pagination tie-break (R3-003).* The test now writes its ULIDs out (ids minted within one millisecond are not guaranteed to rise) and a second test pins the continuation across a tie.
    - *Key lookup (R3-002, R3-003 `h83fe7cc`).* `KeyResolver` reads the one row matching the prefix **and** the hash (the hash is unique and indexed), then still judges it with `hash_equals`; a prefix bucket no longer costs a read per key. Pinned by counting hydrated rows with colliding prefixes. `last_used_at` (R3-001) stays a best-effort 60-second throttle: a concurrent request may write it again, which is harmless. `BootstrapAccount` creating the account row outside a `CurrentAccount` binding (R3-004) is correct because `accounts` is the tenant root (CLAUDE.md boundary rule) and is accepted. `CurrentAccount` in queue workers (R3-005) is reset by Laravel's worker after each job (`forgetScopedInstances`), and every job in this service also binds the account with `runAs`; accepted.
    - *Live and test keys (R3-006).* `KeyModeTest` pins that both modes resolve the same account resources and neither reaches another account's.
    - *Client secret clock (R3-002 `hbac90c4`).* `iat` and `exp` both derive from the `createdAt` the caller passes (it was `iat` from the wall clock), pinned by a test with a past `createdAt`.
    - *CORS (R3-001 `hbac90c4`).* Replacing `HandleCors` removing CORS from every route outside `/v1/client` is intended (only the browser SDK calls from a page; a secret-key route must never be answerable to a browser origin); `ClientCorsTest` now pins that a preflight and a read of secret-key routes, and the inbound provider webhook, carry no allow headers from any origin.

64. **Credentials (`hd8c577a`).**
    - *Concurrent first PUT (R3-001).* Already correct at HEAD, by the framework: `updateOrCreate` runs `firstOrCreate`, whose `createOrFirst` inserts inside a savepoint, catches the unique violation and re-reads the winner's row, after which the caller's key replaces it (last writer wins, 200, one row). A plain `upsert` was rejected because the query builder would bypass the `encrypted` cast and store the key in clear. What was missing was proof: `ProviderCredentialEndpointTest` and `LlmEndpointsTest` now interleave the competing insert between the loser's read and its insert (a `DB::listen` hook on the read) and assert 200, one row and the loser's key. Both would fail with a 500 on a version of the framework without that behaviour.
    - *`captureLogs` (R3-002).* A context `json_encode` cannot encode is recorded with `print_r` instead of being dropped, so an "absent from the logs" assertion has always read the line; pinned by `CaptureLogsTest` (an invalid-UTF-8 context that contains a marker).
    - *Undecryptable account key (R3-003).* `CredentialResolverTest` pins the documented behaviour: the resolver throws `DecryptException` and does not return the platform key.
    - *Probabilistic assertion (R3-004).* The `not->toContain('1234')` check on the ciphertext is removed from `CredentialSchemaTest`; absence of the whole plaintext stays asserted (a short fragment can appear in a random ciphertext).
    - *Test support.* The review gate also read `tests/Pest.php` once it changed: `inboundReceiptCount()` now walks the accounts and counts under each (no scope removed), and the unused `$provider` parameter of `callbackConversation()` (the config was always Tavus) is dropped.
65. **Webhook endpoints (`h8d7d203`).**
    - *Fixed.* An event name stored on an endpoint that this version does not know is left out of `events` by `WebhookEndpointResource` (`tryFrom`), not a 500 on every read of the account's endpoints (R3-004, R4-003); a row with only unknown names shows `[]`. The create docblock says plain http and private addresses are accepted only where destinations are not enforced, the `local` and `testing` environments (R2-008). The request's `events` docblock refers to `EventType` and no longer lists the names (R2-001; the generated schema is the same `$ref`). The statement that exists only for its lock is a named `lockAccountRow()` (R2-007). `OutboundHostGuard::isBlocked()` is documented (R2-004), the redundant `is_array` check is gone (R2-005), `DestinationUrl` reads through a named helper (R2-006).
    - *Already correct at HEAD, now proved (R4-004).* Deleting an endpoint removes its deliveries (the foreign key cascades), so a delivery waiting for a retry stops: the job finds no row, the relay finds none, nothing is sent. Pinned by `WebhookEndpointTest`.
    - *Accepted with reasons.* **DNS in the request path (R3-001, R4-001):** the check at create/update is advisory early feedback; the enforcement is the delivery-time check and the connection pin (ruling 58). PHP offers no per-lookup timeout for `gethostbynamel`/`dns_get_record`; the worker is bounded by the system resolver's timeout (`options timeout:2 attempts:1` in the container's `resolv.conf` is the operational lever) and by the request's execution limit. Not changed. **Create idempotency (R4-002):** needs a stored idempotency key with its own retention and a replay of the one-time secret, which is not "cheap" and which a secret shown once cannot honour safely; the mitigation is that the 20-endpoint cap bounds the damage and a duplicate is removed with a delete. Not changed, and recorded as a known limitation in the README. **Concurrency test of the 20-endpoint cap (R3-005):** a second committed connection cannot see the rows of a `RefreshDatabase` test transaction, so a parallel proof is not available in this suite; the SQL-order assertion (the account row is locked before the count) and the 20th/21st boundary tests stay. **`WebhookEndpoint` allowlist repeated in `$fillable` and `changes()` (R2-003), the docblock on a rules key (R2-002; Scramble reads those docblocks as field documentation, so they are the documentation), the `hash_equals` source scan (R3-007):** suggestions, not changed.

66. **A session whose reference is not stored yet is released once (R3-001 `h86eff30`).** Already correct at HEAD, by the connect path that landed after the finding: `afterChange` queues nothing while `provider_session_ref` is null (there is nothing to release), and `ConnectConversation::keep` stores the references of the session the start created and, finding the conversation over, queues the one `ReleaseProviderSession` (delayed by `release_delay_seconds`) and answers 409. `ClientConnectTest` now pins both halves (nothing queued while starting, exactly one release afterwards) next to the existing "releases the session that was created" test. The lower bound of `release_delay_seconds` (R3-002) is enforced at boot (ruling 62).

67. **Ingestion hardening (slices 27 and 30).**
    - *Replay after the grace (R3-grace-replay).* `EventIngestor` finds the stored keys before it judges the batch, and the closed-conversation, event-cap and utterance-before-connect guards look at the **new** events only: a batch whose events are all stored is answered `{accepted: 0, duplicates: n}` even after the 60 s; a batch with any new event is refused whole as before. The existing test "closes a conversation that ended 61 seconds ago to events, a replay included" posts a new event, so it still holds; the misleading title is left.
    - *Size on the stored encoding (R3-size-boundary).* One constant, `ConversationEvent::DATA_JSON_FLAGS` (unescaped unicode and slashes), encodes `data` both for the 2 KB measurement and for the stored value; before, it was measured unescaped and stored escaped. The column is jsonb, so what reads back is unchanged. Pinned by capturing the bound value of the insert.
    - *Events per conversation, the transcript read (R3-unbounded-transcript).* Smaller diff than pagination (no new cursor, no new response shape): the bound is where events are written. A conversation holds at most 10,000 events (`EventIngestor::MAX_EVENTS`, about three times an hour of one utterance a second); a batch that would pass it is refused whole with 409 `conversation_closed` and a detail saying why, which the SDK already treats as the end of forwarding (no SDK change). The sequence is dense, so it is the count (no extra query). The transcript answer is therefore bounded by construction (plus the provider's own lines, bounded by the conversation's duration); documented on the endpoint. Pagination stays the way out if a host ever needs more.
    - *Streamed bodies (R3-001 `83bdda7`).* `LimitWebhookBody` reads the body from the request stream, never more than the limit plus one byte, so a chunked body (no `Content-Length`) is refused after 256 KB + 1 bytes instead of being buffered whole. The declared length is still checked first. Pinned with a stream four times the limit (position after the read is the limit + 1).
    - *Adapter parse failure (R3-002).* New `MalformedCallback` (thrown by an adapter, or by `ProviderEvent` for a time outside the years 1 to 9999, which no timestamp column holds: the realistic 500, found with `now +99999999999999999999 years`) is answered 422 `validation_failed` with the default detail, never the adapter's message. The contract documents it.
    - *Concurrent duplicate delivery (R3-003).* Proved by delivering the second copy from inside the first one's transaction, right after its insert (one connection cannot run two requests at once): one receipt, one job. A repeated callback whose first job never ran is queued again after the lease (ruling 60).
    - *Not changed.* The SQL-text lock assertion of `ClientEventsTest` (R3-lock-test-coupling, a suggestion; the behavioural proof is the existing numbering test, and true parallelism is unavailable under `RefreshDatabase`). The migration's lossy `down()` (R3-migration-lock) is accepted and documented in the migration.
    - *Browser event time (found by the review gate).* `events.*.occurred_at` must fall in the years 1 to 9999 once converted to UTC. A year-0 or year-0 UTC time passed the pattern and failed the insert (a 500), and a year-10000 UTC time was stored but cannot be read back by the model's date cast (it would fail the transcript read); both are now 422 `validation_failed`, with the first and last representable instants accepted.

## Rulings on the follow-up fix unit (2026-10-20, review round 3 of the security fix unit)

68. **The provider callback body stays readable after the bounded read (sf5 R3-001): not reproducible, pinned instead.** The review feared that `getContent(true)` leaves Symfony's cached content unset and a later `getContent()` throws `LogicException` (every callback a 500). The installed `symfony/http-foundation` 8.1.9 has no such exception: once the stream has been handed out, the next `getContent()` reads `php://input` again, which PHP keeps re-readable. Proved three ways, none RED: a request with no handed-over content (as the SAPI builds it) sent through the kernel with and without `Content-Length` reaches the form request and answers 422 `validation_failed` (an empty body is not a callback), not 500; a streamed body within the limit is handled whole (200); and a throwaway script under PHP's built-in server, plain and `Transfer-Encoding: chunked`, read the bounded stream, then `getContent()` and `Request::createFrom()` (what a form request does) with the full body each time. The middleware is unchanged except a comment; the kernel-path tests in `LimitWebhookBodyTest` fail if a future Symfony brings the exception back. Not done: rebuilding the request content by reflection or `initialize()` (it would drop headers set earlier and fixes nothing that is broken).

69. **The year of a provider event time is judged in UTC (sf5 R3-002).** `ProviderEvent` converts the time to UTC before reading the year, the same rule as `events.*.occurred_at` (ruling 67). Three of four cases failed before: `9999-12-31T23:30:00-02:00` (10000 in UTC) and `0001-01-01T00:30:00+02:00` (year 0 in UTC) were accepted and would fail the insert; `0000-12-31T23:30:00-02:00` (year 1 in UTC) was refused though storable.

70. **A session that could not be kept is ended before the conversation is failed (sf3 R3-001).** The references are kept with a write to the store; when that write fails, failing the conversation is another write to the same store and may throw too, skipping the teardown that follows it and leaving a live provider session nobody can release. `abandon()` (which never throws: it logs the failure kind) now runs first, then `fail()`. Pinned by a test that breaks both writes and expects exactly one teardown (it failed before: none). The unknown-provider test (sf3 R3-002) also asserts the problem code `internal_error`.

71. **A host that gave no address is `destination_unresolved`, not `destination_not_allowed` (sf1 R3-001; amends ruling 58).** Ruling 58 recorded the fail-closed refusal as `destination_not_allowed`; that reads as a policy block when nothing was judged and refused (the receiver's DNS may simply be down) and sends an owner to the wrong place. The refusal is unchanged (no request, attempt counted and retried); only the code differs. `last_error` is a free string in a `varchar(32)` column, not an enum: the closed set is documented in the endpoint description (so `openapi.json` is regenerated and committed), the migration comment, the design and the consumer-webhooks spec, all updated, with a new spec scenario. The "did not resolve" assertion of `DeliverWebhookTest` changes from `destination_not_allowed` to `destination_unresolved`; the assertions for an address that is not public stay.

72. **The DNS-rebinding feature test says what it proves (sf1 R3-002).** The test that used a guard returning no address and counted lookups cannot show connect-time resolution (the HTTP client is faked); it proves that nothing is sent and that the guard is consulted once. It is renamed accordingly. That the pin reaches the client is already proved by the `CURLOPT_RESOLVE` tests of the same file (one address, several addresses with IPv6, port 443 by default, exactly one resolution entry), which read the options the client receives; no new test is needed.

73. **Accepted, with reasons.** sf2 R3-001: the finding concerns counting only (no behaviour is affected). sf2 R3-002: the one extra SELECT happens only on a redelivered copy, which is the price of the duplicate check running before the closed-grace guard (ruling 67). sf2 R3-003: accepted as it is by the orchestrator's decision, no change.
