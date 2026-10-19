# Conversations Specification

## Purpose

The conversation lifecycle: creation by the host back end, connection and event ingestion by the
browser, explicit and automatic termination, the transcript, and provider teardown. States:
`created -> connecting -> live -> ended | failed`.

Retention decision (resolved default): this change implements NO purge or expiry of conversation
data. Transcripts and `conversation_events` are kept until a later change adds a purge; this MUST
be documented as an open item in the README.

## ADDED Requirements

### Requirement: Create a conversation

Auth: SK with scope `conversations:write`. `POST /v1/conversations` body: `avatar_config_id`
(required), `system_prompt` (required, 1-32000 chars), `opening_text` (optional, max 4000),
`language` (required, ISO 639-1 code), `max_duration_seconds` (optional integer 30 to the platform
ceiling `HRA_MAX_DURATION_CEILING`, default ceiling 3600; default value 1800), `external_ref` (optional,
max 255, opaque) and `metadata` (optional JSON object, max 8 KB serialized, opaque). The service
MUST NOT call any provider at this point. It MUST return 201 with the conversation resource in
status `created` and a one-time `client_secret` and `client_secret_expires_at`. Resource members:
`id` (`conv_` + ULID), `status`, `avatar_config_id`, `provider`, `language`,
`max_duration_seconds`, `external_ref`, `metadata`, `ended_reason`, `transcript_status`,
`created_at`, `connecting_at`, `live_at`, `ended_at`. The service MUST NOT interpret `external_ref`
or `metadata`.

#### Scenario: Happy path

- GIVEN a synced avatar config and a SK with `conversations:write`
- WHEN `POST /v1/conversations` is called with valid input
- THEN status is 201, `status` is `created`, a `client_secret` is present, `provider` equals the config's provider, and no provider HTTP call was made

#### Scenario: Default duration

- GIVEN no `max_duration_seconds`
- WHEN the conversation is created
- THEN `max_duration_seconds` is 1800

#### Scenario: Above ceiling

- GIVEN `max_duration_seconds` exceeding the ceiling
- WHEN validated
- THEN status is 422 and `errors` names `max_duration_seconds`

#### Scenario: Oversize metadata

- GIVEN `metadata` serializing to more than 8 KB
- WHEN validated
- THEN status is 422

#### Scenario: Config not synced

- GIVEN a config with `sync_status` `pending`
- WHEN the conversation is created
- THEN status is 409 and `code` is `config_not_synced`

#### Scenario: Config of another account

- GIVEN a config owned by another account
- WHEN referenced
- THEN status is 404 and `code` is `not_found`

#### Scenario: Opaque host fields round-trip

- GIVEN `external_ref` `order-9` and `metadata` `{"a":{"b":1}}`
- WHEN the conversation is created and later read
- THEN both are returned unchanged

### Requirement: Read a conversation

Auth: SK with scope `conversations:read`. `GET /v1/conversations/{id}` MUST return the conversation
resource, never the `client_secret`, and never provider refs or the provider callback token.

#### Scenario: Read own

- GIVEN a conversation of the account
- WHEN it is read
- THEN status is 200 and the resource has the documented members

#### Scenario: No internals

- GIVEN a connected conversation
- WHEN it is read
- THEN the body contains no `provider_session_ref`, `provider_context_ref`, callback token or token hash

### Requirement: Connect (client)

Auth: PK + CS. `POST /v1/client/conversations/{id}/connect` MUST succeed only for a conversation in
status `created`. Before changing state it MUST verify that the provider credential (and LLM
credential when an LLM is bound) can be resolved, failing with 422 `provider_credential_missing` or
`llm_credential_missing` and leaving the status `created`. For a provider that reports events by callback
it MUST also verify that `HRA_PUBLIC_BASE_URL` is a usable http(s) address, failing with 503
`service_unavailable` (no state change, no provider call) when it is not. It MUST then atomically move the
conversation to `connecting` (setting `connecting_at`), call the adapter `start` with a
`ConversationSpec` built from the conversation and its config, persist the provider refs, and
return 200 with `JoinMaterial`. A second connect for the same conversation MUST yield 409
`already_connected`, including under concurrent calls (exactly one succeeds). If `start` fails the
conversation MUST become `failed` with `ended_reason` `provider_error` (or `provider_capacity` for
a concurrency error) and the API MUST answer 502 `provider_error` (or 429
`provider_concurrency_limited`). For providers with callbacks the service MUST pass a callback
target (see `provider-webhooks`).

#### Scenario: Happy path with Mock

- GIVEN a `created` conversation on a `mock` config and valid PK + CS
- WHEN connect is called
- THEN status is 200, the body is a `JoinMaterial` with `transport` `mock`, and the conversation is `connecting`

#### Scenario: Second connect

- GIVEN a conversation already connected
- WHEN connect is called again with the same client_secret
- THEN status is 409 and `code` is `already_connected`

#### Scenario: Concurrent connects

- GIVEN two simultaneous connect calls
- WHEN both complete
- THEN exactly one returns 200 and the other 409, and the provider adapter `start` ran once

#### Scenario: Credential missing keeps state

- GIVEN a Tavus config with no resolvable credential
- WHEN connect is called
- THEN status is 422 `provider_credential_missing` and the conversation is still `created`

#### Scenario: Provider failure

- GIVEN the adapter raises a provider error
- WHEN connect is called
- THEN status is 502, the conversation is `failed` with `ended_reason` `provider_error`, and a `conversation.ended` event is queued

#### Scenario: Capacity failure

- GIVEN the adapter raises a concurrency error
- WHEN connect is called
- THEN status is 429, `code` is `provider_concurrency_limited`, and the conversation is `failed` with `ended_reason` `provider_capacity`

#### Scenario: Connect on an ended conversation

- GIVEN a conversation in `ended`
- WHEN connect is called
- THEN status is 409 and `code` is `invalid_state`

### Requirement: State machine

The only allowed transitions are `created -> connecting`, `created -> ended`, `connecting -> live`,
`connecting -> ended`, `connecting -> failed`, `live -> ended` and `live -> failed`. Every other transition (including
`created -> failed`, `created -> live` and any transition out of a terminal state) is illegal. `ended` and `failed` are terminal and immutable: no transition, no change
of `ended_reason`, `ended_at`. Applying a transition already satisfied (same target state) MUST be
an idempotent no-op that emits no second webhook. The first writer to reach a terminal state wins.
`ended_reason` values: `client`, `api`, `max_duration`, `provider`, `expired`, `provider_error`,
`provider_capacity`. Transitions MUST be applied under a row lock so concurrent causes (explicit
end, sweeper, provider event) cannot both succeed.

#### Scenario: Terminal is immutable

- GIVEN a conversation `ended` with reason `client`
- WHEN a provider ended event arrives
- THEN status stays `ended` and `ended_reason` stays `client`

#### Scenario: Illegal transition

- GIVEN a conversation in `created`
- WHEN a transition to `live` is attempted
- THEN it is rejected as an invalid state and the status is unchanged

#### Scenario: Idempotent live

- GIVEN a `live` conversation
- WHEN another live signal arrives
- THEN no state change occurs and `conversation.live` is not emitted again

#### Scenario: Race between end causes

- GIVEN an explicit end and a sweeper end run concurrently
- WHEN both complete
- THEN exactly one sets the reason and exactly one `conversation.ended` event is emitted

### Requirement: Becoming live

A conversation in `connecting` MUST become `live` (setting `live_at`) on the first of: a client
`lifecycle` event named `live` (see events) or a provider `joined` event (see `provider-webhooks`).
Reaching `live` MUST emit `conversation.live` exactly once.

#### Scenario: Client signals live

- GIVEN a `connecting` conversation
- WHEN the client posts a lifecycle event named `live`
- THEN the status is `live` and `live_at` is set

#### Scenario: Provider signals live

- GIVEN a `connecting` Tavus conversation
- WHEN a valid provider `joined` event is ingested
- THEN the status is `live`

#### Scenario: Live from created is ignored

- GIVEN a `created` conversation
- WHEN a client `live` lifecycle event is posted
- THEN the status stays `created` and the event is stored without effect

### Requirement: Client event ingestion

Auth: PK + CS. `POST /v1/client/conversations/{id}/events` body `{ "events": [...] }` with 1-100
events, each `{ idempotency_key (1-128 chars), type: "utterance"|"lifecycle", occurred_at
(ISO 8601, optional), role ("user"|"avatar", utterance only), text (utterance only, 1-8000 chars),
name ("live"|"error", lifecycle only), data (optional object, max 2 KB) }`. The service MUST store
events in `conversation_events` with a server-assigned monotonically increasing `sequence` per
conversation, unique on (conversation, `idempotency_key`). A repeated key MUST be counted as a
duplicate and stored once. The response MUST be 200 `{ "accepted": n, "duplicates": m }`. Events
MUST be accepted while the conversation is non-terminal and for 60 seconds after it became
terminal (grace for outbox flush); after that the answer MUST be 409 `conversation_closed`. A
batch with any invalid event MUST be rejected whole with 422 and store nothing. A `created`
conversation MUST NOT accept utterances (409 `invalid_state`).

#### Scenario: Batch stored in order

- GIVEN a `live` conversation
- WHEN a batch of three utterances is posted
- THEN `accepted` is 3 and sequences are strictly increasing in batch order

#### Scenario: Idempotent replay

- GIVEN a batch already accepted
- WHEN the identical batch is posted again
- THEN `accepted` is 0, `duplicates` equals the batch size, and the stored event count is unchanged

#### Scenario: Partial overlap

- GIVEN a batch with two known keys and one new key
- WHEN posted
- THEN `accepted` is 1 and `duplicates` is 2

#### Scenario: Atomic validation

- GIVEN a batch where the third event has an empty text
- WHEN posted
- THEN status is 422 and none of the events are stored

#### Scenario: Too many events

- GIVEN 101 events
- WHEN posted
- THEN status is 422

#### Scenario: Grace window

- GIVEN a conversation that ended 30 seconds ago
- WHEN a batch is posted
- THEN status is 200

#### Scenario: Closed

- GIVEN a conversation that ended 61 seconds ago
- WHEN a batch is posted
- THEN status is 409 and `code` is `conversation_closed`

#### Scenario: Utterance before connect

- GIVEN a `created` conversation
- WHEN an utterance is posted
- THEN status is 409 and `code` is `invalid_state`

### Requirement: End a conversation

Auth: SK with scope `conversations:write` (`POST /v1/conversations/{id}/end`, reason `api`) and PK + CS
(`POST /v1/client/conversations/{id}/end`, reason `client`). Both MUST move a non-terminal
conversation to `ended`, set `ended_at`, emit `conversation.ended`, dispatch the delayed release
job, and return 200 with the conversation resource. Ending a terminal conversation MUST be an
idempotent 200 returning the unchanged resource. A `created` conversation ended this way has no
provider session: no release job is dispatched.

#### Scenario: End live

- GIVEN a `live` conversation
- WHEN the SK ends it
- THEN status is 200, `status` is `ended`, `ended_reason` is `api`, and a release job is queued

#### Scenario: Client end

- GIVEN a `live` conversation
- WHEN the browser ends it with PK + CS
- THEN `ended_reason` is `client`

#### Scenario: End twice

- GIVEN an already ended conversation
- WHEN end is called again
- THEN status is 200, the resource is unchanged, and no second `conversation.ended` is emitted

#### Scenario: End before connect

- GIVEN a `created` conversation
- WHEN the SK ends it
- THEN it is `ended` with reason `api` and no release job is queued

### Requirement: Max-duration sweeper

A scheduled job running every minute MUST end every non-terminal conversation that has a
`connecting_at` and for which `connecting_at + max_duration_seconds + 30 seconds` has passed, with
`ended_reason` `max_duration`, dispatching the release job. It MUST also end conversations still
in `created` once `created_at + 900 seconds` (the connect window) has passed, with reason `expired`
(no release job). It MUST be idempotent and safe to run concurrently.

#### Scenario: Overrun live conversation

- GIVEN a `live` conversation connected 1900 seconds ago with `max_duration_seconds` 1800
- WHEN the sweeper runs
- THEN it is `ended` with reason `max_duration` and a release job is queued

#### Scenario: Within limits

- GIVEN a conversation connected 100 seconds ago
- WHEN the sweeper runs
- THEN it is unchanged

#### Scenario: Never connected

- GIVEN a `created` conversation created 901 seconds ago
- WHEN the sweeper runs
- THEN it is `ended` with reason `expired`

#### Scenario: Inside the connect window

- GIVEN a `created` conversation created 899 seconds ago
- WHEN the sweeper runs
- THEN it is unchanged

#### Scenario: Two sweepers

- GIVEN two sweeper runs executing concurrently
- WHEN both complete
- THEN each overdue conversation produced exactly one `conversation.ended`

### Requirement: Delayed provider release

After a conversation becomes terminal with a provider session, a release job MUST run after
`HRA_RELEASE_DELAY_SECONDS` (default 20): it MUST call the adapter `teardown` (idempotent), then
run transcript reconciliation (below). A teardown returning `false` MUST be retried with backoff
up to 5 attempts and then logged without affecting the conversation state. The job MUST NOT depend
on any provider webhook having arrived.

#### Scenario: Release after end

- GIVEN a conversation ended with a provider session
- WHEN the release job runs
- THEN `teardown` is called once for that session

#### Scenario: Teardown retried

- GIVEN `teardown` returns false twice and then true
- WHEN the job runs with retries
- THEN it is invoked three times in total

#### Scenario: Conversation state unaffected by teardown failure

- GIVEN `teardown` returns false on all 5 attempts
- WHEN the job gives up
- THEN the conversation remains `ended` and the failure is logged without credentials

#### Scenario: No webhook dependency

- GIVEN an ended Tavus conversation for which no provider webhook was received
- WHEN the release job runs
- THEN teardown and transcript reconciliation still run

### Requirement: Transcript and reconciliation

Auth: SK with scope `conversations:read`. `GET /v1/conversations/{id}/transcript` MUST return 200
`{ conversation_id, status, utterances: [ { sequence, role, text, occurred_at, source } ] }` for any
conversation past `created`; `created` yields 409 `invalid_state`. `status` is `pending` until
reconciliation completes, then `ready`, or `partial` when provider reconciliation failed after 5
attempts and only client-forwarded utterances exist. Reconciliation MUST call the adapter
`fetchTranscript` and merge: client-forwarded utterances are kept with `source` `client`; a
provider utterance is added with `source` `provider` unless a stored utterance of the same `role` and
the same normalized text (trimmed, whitespace collapsed, case-insensitive) accounts for it. Each stored
utterance accounts for exactly one provider utterance, so a line the speaker repeated is not lost
(provider `Yes, Yes` against one stored `Yes` adds one). Utterances MUST
be ordered by `occurred_at` ascending then `sequence`. Reconciliation MUST be idempotent. Completion
MUST emit `transcript.ready` exactly once (status `ready` or `partial`). A conversation that moves
`created -> ended` has no provider session: its transcript MUST be finalised immediately as `ready`
with no utterances, without waiting for any job.

#### Scenario: Ended before connect is finalised immediately

- GIVEN a `created` conversation
- WHEN it is ended by the SK
- THEN its transcript `status` is `ready`, `utterances` is empty, and `transcript.ready` is emitted once

#### Scenario: Merge adds only missing

- GIVEN client utterances A and B and a provider transcript A, B, C
- WHEN reconciliation runs
- THEN the transcript has A, B (source `client`) and C (source `provider`)

#### Scenario: Normalized comparison

- GIVEN a client utterance `Hello  there` and a provider utterance `hello there` with the same role
- WHEN reconciliation runs
- THEN no duplicate is added

#### Scenario: Idempotent

- GIVEN a reconciled conversation
- WHEN reconciliation runs again
- THEN the transcript is unchanged and `transcript.ready` is not emitted again

#### Scenario: Partial

- GIVEN `fetchTranscript` fails on all 5 attempts
- WHEN reconciliation gives up
- THEN `status` is `partial`, client utterances remain, and `transcript.ready` is emitted once with status `partial`

#### Scenario: Pending

- GIVEN an ended conversation whose reconciliation has not run
- WHEN the transcript is read
- THEN `status` is `pending`

#### Scenario: Before connect

- GIVEN a `created` conversation
- WHEN the transcript is read
- THEN status is 409 and `code` is `invalid_state`

#### Scenario: Account isolation

- GIVEN a conversation of account A
- WHEN account B reads its transcript
- THEN status is 404

### Requirement: Conversation state machine coverage and tenant tests

The conversation state machine and its transition guard MUST be covered by tests at 95% or higher,
including one cross-account test per endpoint of this capability asserting 404 for foreign
resources.

#### Scenario: Per-endpoint isolation tests

- GIVEN the endpoints of this capability
- WHEN the test suite is inspected
- THEN each endpoint has a test where a different account's key receives 404
