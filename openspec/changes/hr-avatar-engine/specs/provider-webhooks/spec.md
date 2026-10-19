# Provider Webhooks Specification

## Purpose

Inbound ingestion of provider events and their mapping onto conversation state. Tavus delivers
per-conversation callbacks; HeyGen LiveAvatar has NO webhooks and is covered by a scheduled
poller. Both normalize into one internal provider event so state handling is shared. Conversation
end never depends on a webhook: explicit end, the max-duration sweeper and the release job stay
authoritative (see `conversations`).

Provider facts (binding, from `research.md`, verified 2026-10-19): Tavus callbacks are unsigned;
the envelope carries `conversation_id`, `event_type`, `message_type`, `timestamp`, `properties`.
LiveAvatar session end is observable via `GET /v1/sessions/{id}` (`end_at` / `end_reason`) and the
transcript via `GET /v1/sessions/{id}/transcript`.

## ADDED Requirements

### Requirement: Callback token issuance

When the Tavus adapter starts a session, the service MUST generate a per-conversation callback
token of at least 128 bits of cryptographically secure randomness (encoded as 64 lowercase hex
characters), build the callback target `{HRA_PUBLIC_BASE_URL}/v1/webhooks/providers/tavus/{token}`,
store ONLY the sha256 hash of the token on the conversation, and never persist or log the plain
token after handing it to the adapter. The token MUST be unique per conversation. Providers
without callbacks (`heygen`, `mock`) MUST receive no callback target and no token is created.

#### Scenario: Token shape and storage

- GIVEN a Tavus conversation is connected
- WHEN the create request sent to Tavus and the database row are inspected
- THEN `callback_url` ends with `/v1/webhooks/providers/tavus/` plus 64 hex characters, and the row holds only the sha256 hash of that token

#### Scenario: Tokens differ

- GIVEN two Tavus conversations
- WHEN their callback URLs are compared
- THEN the tokens differ

#### Scenario: No token for LiveAvatar

- GIVEN a HeyGen conversation is connected
- WHEN the session is created
- THEN no callback target is passed and no token hash is stored

### Requirement: Inbound endpoint authentication

Auth: PT. `POST /v1/webhooks/providers/{provider}/{token}` MUST accept only `provider` `tavus`.
The token MUST be hashed (sha256) and matched to a conversation using `hash_equals` semantics. A
request MUST be accepted only when (a) the token matches a conversation, (b) that conversation's
provider is `tavus`, and (c) the payload `conversation_id` equals that conversation's provider
session ref. Any failure of (a), (b) or (c), an unsupported `{provider}` value, or a token of the
wrong length MUST be answered with an identical 404 `not_found` (no oracle). The endpoint MUST NOT
require a bearer key, MUST NOT be subject to key rate limiting, MUST be limited per IP (120 per
minute, 429 `rate_limited`), and MUST reject bodies above 256 KB with 413 `payload_too_large`.
After authentication, a body that is not valid JSON or lacks `conversation_id`, `event_type` or
`timestamp` MUST yield 422 `validation_failed` and store nothing.

#### Scenario: Valid token and matching conversation id

- GIVEN a Tavus conversation with a known token and provider session ref `c123`
- WHEN a callback with `conversation_id` `c123` is posted to its URL
- THEN status is 200 with body `{"received":true}`

#### Scenario: Unknown token

- GIVEN a well-formed but unknown token
- WHEN a callback is posted
- THEN status is 404 and `code` is `not_found`

#### Scenario: Token of another conversation

- GIVEN a valid token for conversation X and a payload carrying the session ref of conversation Y
- WHEN posted
- THEN status is 404 `not_found` and neither conversation changes

#### Scenario: Unsupported provider segment

- GIVEN a valid Tavus token
- WHEN posted to `/v1/webhooks/providers/heygen/{token}`
- THEN status is 404 `not_found`

#### Scenario: Responses are indistinguishable

- GIVEN a wrong token, a mismatching conversation id, and an unsupported provider
- WHEN each is posted
- THEN the three responses have identical status, `code` and body shape

#### Scenario: Malformed body after auth

- GIVEN a valid token
- WHEN the body is `not json` or omits `event_type`
- THEN status is 422 and no receipt is stored

#### Scenario: Oversize

- GIVEN a valid token
- WHEN a 300 KB body is posted
- THEN status is 413

#### Scenario: Plain token never stored

- GIVEN a connected Tavus conversation
- WHEN all columns of all tables are searched for the plain token
- THEN it is not found

### Requirement: Normalized provider event and idempotent receipts

Every accepted callback and every poller observation MUST be normalized to `{ provider,
provider_ref, kind, occurred_at, payload }` with `kind` one of `joined`, `ended`,
`transcript_ready`, `ignored`, and persisted FIRST as a row in `provider_webhook_receipts` with a
unique constraint on (`account_id`, `provider`, `idempotency_key`) (an account never loses an event to a key another account stored; ruling 51). Tavus `idempotency_key` MUST be
`sha256(conversation_id|event_type|timestamp)`. The endpoint MUST respond 200 `{"received":true}`
after persistence without performing business processing inline; processing runs on the queue. A
delivery whose key already exists MUST return 200 and MUST NOT enqueue processing again. Under
concurrent identical deliveries exactly one receipt MUST exist and exactly one processing run MUST
happen. Tavus event mapping: `system.pal_joined` and `system.replica_joined` -> `joined`;
`system.shutdown` -> `ended`; `application.transcription_ready` -> `transcript_ready`; every other
`event_type` -> `ignored` (stored, no state effect).

#### Scenario: Persist then ack

- GIVEN a valid callback
- WHEN it is posted
- THEN a receipt row exists before the response is sent and processing is queued, not executed inline

#### Scenario: Duplicate delivery is a no-op

- GIVEN a receipt for the same `conversation_id`, `event_type` and `timestamp`
- WHEN the callback is posted again
- THEN status is 200, no second receipt exists, and no second processing job is queued

#### Scenario: Concurrent duplicates

- GIVEN two identical callbacks posted concurrently
- WHEN both complete
- THEN both answer 200, exactly one receipt exists and processing ran once

#### Scenario: Legacy duplicate joined

- GIVEN `system.pal_joined` and `system.replica_joined` for the same moment
- WHEN both are ingested
- THEN both map to `joined` and the conversation transitions to `live` once

#### Scenario: Unknown event type

- GIVEN `application.recording_ready`
- WHEN ingested
- THEN a receipt is stored with kind `ignored` and the conversation is unchanged

### Requirement: Mapping onto conversation state

Processing a receipt MUST apply the state machine of `conversations`. `joined`: `connecting ->
live` (no-op in any other state). `ended`: a non-terminal conversation moves to `ended` with
`ended_reason` `provider`, stores the provider reason, dispatches the release job and emits
`conversation.ended`; a terminal conversation is unchanged (first writer wins). The provider
reason MUST be read from `properties.shutdown_reason` and, when absent, from `properties.reason`;
when neither exists it is `unknown`. `transcript_ready`: run transcript reconciliation using the
callback's transcript rows (normalized like `fetchTranscript`) and, if the conversation is still
non-terminal, first end it with reason `provider`. Out-of-order events MUST be tolerated.

#### Scenario: Joined makes live

- GIVEN a `connecting` Tavus conversation
- WHEN a `joined` receipt is processed
- THEN the conversation is `live` and `conversation.live` is emitted

#### Scenario: Shutdown ends with parsed reason (primary key)

- GIVEN a `live` conversation and a shutdown event with `properties.shutdown_reason` `participant_left`
- WHEN processed
- THEN the conversation is `ended` with `ended_reason` `provider`, the provider reason is `participant_left`, and a release job is queued

#### Scenario: Shutdown reason from the alternate key

- GIVEN a shutdown event with only `properties.reason` set to `max_call_duration_reached`
- WHEN processed
- THEN the stored provider reason is `max_call_duration_reached`

#### Scenario: Shutdown with no reason

- GIVEN a shutdown event with neither key
- WHEN processed
- THEN the stored provider reason is `unknown`

#### Scenario: First writer wins

- GIVEN a conversation already `ended` by the API
- WHEN a shutdown event is processed
- THEN `ended_reason` stays `api` and no second `conversation.ended` is emitted

#### Scenario: Transcript ready merges

- GIVEN an `ended` conversation with client utterances and a `transcription_ready` event carrying rows
- WHEN processed
- THEN the transcript is reconciled per the merge rule and `transcript.ready` is emitted once

#### Scenario: Transcript ready before shutdown

- GIVEN a `live` conversation and a `transcription_ready` event arriving first
- WHEN processed
- THEN the conversation is ended with reason `provider` and the transcript is reconciled

#### Scenario: Joined after end

- GIVEN an `ended` conversation
- WHEN a `joined` receipt is processed
- THEN nothing changes

### Requirement: LiveAvatar polling

Because LiveAvatar has no webhooks, a scheduled job running every minute MUST select non-terminal
conversations whose provider is `heygen` and that hold a provider session ref, and for each call
the adapter `fetchSession`. When it reports `ended: true`, the job MUST create a normalized `ended`
receipt with idempotency key `liveavatar:{session_id}:ended` through the same pipeline and mapping
as Tavus events (reason `provider`, provider reason = LiveAvatar `end_reason`), and the release job
reconciles the transcript via `fetchTranscript`. A provider error while polling MUST be logged
without credentials and MUST NOT change any conversation; the next run retries. Conversations the
sweeper or an explicit end already terminated MUST NOT be polled. The poller MUST NOT touch
conversations of other providers and MUST be safe to run concurrently with itself.

#### Scenario: Provider reports end

- GIVEN a `live` HeyGen conversation and a session resource with `end_reason` `IDLE_TIMEOUT`
- WHEN the poller runs
- THEN the conversation is `ended` with `ended_reason` `provider`, provider reason `IDLE_TIMEOUT`, a release job is queued, and a receipt with key `liveavatar:{session_id}:ended` exists

#### Scenario: Still active

- GIVEN a session with null `end_at` and `end_reason`
- WHEN the poller runs
- THEN the conversation is unchanged

#### Scenario: Idempotent across runs

- GIVEN a session that ended and was already processed
- WHEN the poller runs again
- THEN no second receipt, no second `conversation.ended`

#### Scenario: Provider error is not an end

- GIVEN the session lookup answers 502
- WHEN the poller runs
- THEN the conversation is unchanged and the error is logged without credentials

#### Scenario: Terminal conversations skipped

- GIVEN a HeyGen conversation already `ended` by the API
- WHEN the poller runs
- THEN no provider call is made for it

#### Scenario: Other providers untouched

- GIVEN `live` Tavus and Mock conversations
- WHEN the poller runs
- THEN no provider call is made for them

### Requirement: Conversation end independent of webhooks

A conversation MUST reach a terminal state, release its provider session and reconcile its
transcript even if no provider event is ever received.

#### Scenario: Silent provider

- GIVEN a `live` Tavus conversation and no callbacks received
- WHEN the max-duration sweeper ends it and the release job runs
- THEN the conversation is `ended`, the session is torn down, and reconciliation ran via `fetchTranscript`

### Requirement: Receipt confidentiality and isolation

Receipts MUST be bound to the conversation and account they were authenticated against.
Receipt payloads MUST NOT be returned by any customer-facing endpoint. Logs of the endpoint MUST
NOT contain the URL token (the route parameter MUST be redacted in access and error logs).

#### Scenario: Token redacted in logs

- GIVEN an inbound callback that fails processing
- WHEN the log output is inspected
- THEN the token string does not appear

#### Scenario: No customer-facing exposure

- GIVEN receipts exist
- WHEN every SK and client endpoint response is inspected
- THEN none contains receipt payloads or idempotency keys
