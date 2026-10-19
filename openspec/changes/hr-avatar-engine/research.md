# Research — provider event ingestion (2026-10-19)

Primary vendor documentation, fetched 2026-10-19. Verified facts unless marked otherwise.

## Tavus — callbacks exist, unsigned

- `callback_url` is a top-level field of `POST /v2/conversations`
  ([create-conversation](https://docs.tavus.io/api-reference/conversations/create-conversation.md)).
- Envelope: `properties`, `conversation_id`, `webhook_url`, `event_type`,
  `message_type` (`system` | `application`), `timestamp`
  ([webhooks-and-callbacks](https://docs.tavus.io/sections/webhooks-and-callbacks.md)).
- Events:
  - `system.pal_joined` (and its legacy duplicate `system.replica_joined`).
  - `system.shutdown` — reason under `properties.shutdown_reason` (webhooks page) or
    `properties.reason` (get-conversation example): **parse both**.
  - `application.transcription_ready` — `properties.transcript[]` of
    `{role, content, timestamp, seconds_from_start, duration}`, sent after the call ends.
  - `application.recording_ready`, `application.recording_copy_failed`,
    `application.perception_analysis`, `application.post_call_action_executed`.
- **No signature, secret, retry policy or required status code is documented.**
- Reconciliation: `GET /v2/conversations/{id}?verbose=true` returns the same events
  ([get-conversation](https://docs.tavus.io/api-reference/conversations/get-conversation.md)).
- `POST /v2/conversations/{id}/end` returns 200; idempotency undocumented.
- Properties: `max_call_duration` (s, default 3600, capped by plan), `participant_left_timeout`,
  `participant_absent_timeout` (default 300).

## HeyGen LiveAvatar — no HTTP webhooks

- No webhook/callback page in the docs index or changelog
  ([llms.txt](https://docs.liveavatar.com/llms.txt)); `POST /v1/sessions/token` has no callback field.
- Session events (`session.stopped`, `user.transcription`, `avatar.transcription`) travel only over
  LiveKit topics ([full-mode/events](https://docs.liveavatar.com/docs/full-mode/events)).
- Authoritative polling surfaces:
  - `GET /v1/sessions/{id}` — ended when `end_at` / `end_reason` is non-null
    (`USER_CLOSED`, `IDLE_TIMEOUT`, `MAX_DURATION_REACHED`, `ZOMBIE_SESSION_REAP`, …).
  - `GET /v1/sessions/{id}/transcript` — `session_active`, `next_timestamp` (cursor semantics
    undocumented — assumption), `transcript_data[] {role, transcript, absolute_timestamp, relative_timestamp}`.
- The separate HeyGen v3 webhook system (`api.heygen.com/v3/webhooks`, HMAC `signature` header)
  is **not** documented to cover LiveAvatar sessions — not used.

## Resulting design inputs

- **Tavus:** `callback_url = {base}/webhooks/providers/tavus/{token}`; token ≥128 bits, only its
  sha256 stored per conversation; a request is accepted only when the token's conversation matches
  the payload `conversation_id`. Persist first, answer 200 fast, process async. Idempotency key
  `sha256(conversation_id|event_type|timestamp)`, unique per provider.
- **LiveAvatar:** a scheduled poller over live sessions; on end, read the transcript; idempotency
  key `liveavatar:{session_id}:ended`.
- **Common:** both normalize into one internal provider event
  `{provider, provider_ref, kind, occurred_at, payload}` with unique `(provider, idempotency_key)`.
- Conversation end never depends on a webhook: explicit end, max-duration sweeper and the
  delayed release job remain authoritative.

## Open (non-blocking)

- Tavus retry/duplicate behaviour and signature: empirical test or vendor answer.
- LiveAvatar transcript completeness timing after end; `next_timestamp` pagination.
- Polling latency for LiveAvatar end (seconds to a minute) is accepted by default.
