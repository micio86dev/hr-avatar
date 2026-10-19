# Consumer Webhooks Specification

## Purpose

Outbound, signed, at-least-once event delivery from HR-Avatar to the host's endpoints:
`conversation.live`, `conversation.ended` and `transcript.ready`. Includes endpoint management,
HMAC signing with a replay window, retry with backoff, and an auditable delivery log.

## ADDED Requirements

### Requirement: Webhook endpoint management

Auth: SK with scope `webhook_endpoints:manage`. Resource: `{ id (we_ + ULID), url, events
(subset of conversation.live|conversation.ended|transcript.ready; default all three), enabled
(default true), description|null, created_at, updated_at }`. `POST /v1/webhook-endpoints` (201,
returns the signing `secret` once), `GET /v1/webhook-endpoints` (paginated), `GET
/v1/webhook-endpoints/{id}`, `PATCH /v1/webhook-endpoints/{id}` (url, events, enabled, description),
`DELETE /v1/webhook-endpoints/{id}` (204). An account MAY hold at most 20 endpoints (21st: 422).
The URL MUST be `https` (plain `http` accepted only when `APP_ENV` is `local` or `testing`), max
2048 chars, with no embedded credentials. The signing secret MUST be `hra_whsec_` followed by 64 hex
characters, stored encrypted (it is needed to sign), hidden from serialization, and returned only
by the creation response. Endpoints are account-scoped.

#### Scenario: Create returns the secret once

- GIVEN a SK with `webhook_endpoints:manage`
- WHEN it creates an endpoint with a valid https URL
- THEN status is 201, `secret` matches `^hra_whsec_[0-9a-f]{64}$`, and later reads never include `secret`

#### Scenario: Non-https URL

- GIVEN `APP_ENV=production` and url `http://example.com/hook`
- WHEN created
- THEN status is 422

#### Scenario: Embedded credentials

- GIVEN url `https://user:pass@example.com/hook`
- WHEN created
- THEN status is 422

#### Scenario: Unknown event name

- GIVEN `events: ["conversation.exploded"]`
- WHEN created
- THEN status is 422

#### Scenario: Limit

- GIVEN an account with 20 endpoints
- WHEN a 21st is created
- THEN status is 422

#### Scenario: Account isolation

- GIVEN an endpoint of account A
- WHEN account B reads, patches or deletes it
- THEN status is 404

#### Scenario: Secret encrypted at rest

- GIVEN a stored endpoint
- WHEN the raw column is read
- THEN it does not contain the plaintext secret

### Requirement: Destination safety (SSRF)

The service MUST NOT deliver to destinations that resolve to loopback, private (RFC 1918, RFC 4193),
link-local or otherwise non-public addresses outside `local` and `testing` environments. The
check MUST be applied at endpoint creation/update (hostname literal and resolution) and again at
delivery time, on the resolved address actually connected to. Redirects MUST NOT be followed. A
blocked delivery attempt counts as a failed attempt with a safe error `destination_not_allowed`.

#### Scenario: Private address literal

- GIVEN `APP_ENV=production` and url `https://10.0.0.5/hook`
- WHEN created
- THEN status is 422

#### Scenario: DNS rebinding at delivery

- GIVEN an endpoint whose hostname now resolves to `169.254.169.254`
- WHEN a delivery attempt runs
- THEN no request is sent and the attempt is recorded as failed with `destination_not_allowed`

#### Scenario: Redirects not followed

- GIVEN the endpoint answers 302 with a Location header
- WHEN delivered
- THEN the redirect is not followed and the attempt is a failure

### Requirement: Event emission

The service MUST emit `conversation.live` when a conversation first becomes `live`,
`conversation.ended` when it first becomes terminal (`ended` or `failed`; the payload `status`
distinguishes them), and `transcript.ready` when transcript reconciliation completes (payload
status `ready` or `partial`). Each emission MUST create one `webhook_deliveries` row per enabled
endpoint of the account subscribed to that event, in the same transaction as the state change that
caused it. An event MUST NOT be emitted twice for the same conversation transition. Envelope:
`{ "id": "evt_<ulid>", "type": <event>, "created_at": <ISO 8601>, "data": { "conversation": {
id, status, avatar_config_id, external_ref, metadata, ended_reason, created_at, live_at,
ended_at, transcript_status } } }`; `transcript.ready` data adds `transcript: { status,
utterance_count }`. The webhook MUST NOT carry transcript text, provider refs, tokens, or secrets.

#### Scenario: Live event

- GIVEN a subscribed endpoint and a conversation becoming `live`
- WHEN the transition commits
- THEN exactly one delivery of type `conversation.live` is queued for that endpoint

#### Scenario: Ended for failure

- GIVEN a conversation that fails during connect
- WHEN the transition commits
- THEN a `conversation.ended` delivery is queued with `data.conversation.status` `failed`

#### Scenario: Transcript ready payload

- GIVEN reconciliation completes with 7 utterances
- WHEN the event is built
- THEN `data.transcript` is `{status:"ready", utterance_count:7}` and the payload contains no utterance text

#### Scenario: Not subscribed

- GIVEN an endpoint subscribed only to `conversation.ended`
- WHEN a conversation becomes live
- THEN no delivery is created for it

#### Scenario: Disabled endpoint

- GIVEN an endpoint with `enabled` false
- WHEN any event occurs
- THEN no delivery is created

#### Scenario: Other accounts' endpoints

- GIVEN endpoints of account B
- WHEN an event occurs in account A
- THEN no delivery is created for B's endpoints

#### Scenario: Opaque host data echoed

- GIVEN a conversation with `external_ref` and `metadata`
- WHEN any event is built
- THEN both appear unchanged in `data.conversation`

### Requirement: Signing

Each delivery attempt MUST be an HTTPS `POST` with `Content-Type: application/json` and the headers
`X-HRA-Signature: v1=<hex>`, `X-HRA-Timestamp: <unix seconds>`, `X-HRA-Event-Id: <evt id>` and
`X-HRA-Event-Type: <event>`. `<hex>` MUST be the lowercase hex of `HMAC-SHA256` over the string
`"{timestamp}.{raw body}"` keyed with the endpoint secret, where the body is the exact bytes sent.
The timestamp MUST be the time of THAT attempt (every retry is re-signed with a fresh timestamp
and keeps the same event id and body). The service MUST ship a verifier that recomputes the
signature, compares it in constant time, and rejects a timestamp more than 300 seconds away from
the verifier's clock (past or future). The documentation MUST describe the verification procedure
and the 300 s window.

#### Scenario: Known vector

- GIVEN secret `S`, timestamp `1700000000` and body `{"a":1}`
- WHEN the signature header is built
- THEN it equals `v1=` + lowercase hex of `HMAC-SHA256("1700000000.{"a":1}", S)`

#### Scenario: Verifier accepts a fresh delivery

- GIVEN a delivery signed at T and a verifier clock at T+10
- WHEN verified
- THEN it returns true

#### Scenario: Replay outside the window

- GIVEN a delivery signed at T and a verifier clock at T+301
- WHEN verified
- THEN it returns false

#### Scenario: Future timestamp outside the window

- GIVEN a delivery signed at T and a verifier clock at T-301
- WHEN verified
- THEN it returns false

#### Scenario: Tampered body

- GIVEN a valid signature and a body modified by one byte
- WHEN verified
- THEN it returns false

#### Scenario: Wrong secret

- GIVEN a signature made with another secret
- WHEN verified
- THEN it returns false

#### Scenario: Retries are re-signed

- GIVEN a delivery retried after a failure
- WHEN the two attempts are compared
- THEN event id and body are identical and timestamp and signature differ

#### Scenario: Signing zone coverage

- GIVEN the signer and verifier classes
- WHEN coverage is measured
- THEN it is at least 95%

### Requirement: Delivery, retries and backoff

A delivery attempt MUST time out after 10 seconds. HTTP 2xx is success; anything else (non-2xx,
timeout, connection error, blocked destination) is a failed attempt. Failed deliveries MUST be
retried with these delays after attempt 1..5: 10 s, 60 s, 300 s, 1800 s, 7200 s, for at most 6
attempts in total, after which the delivery becomes `failed` permanently. Statuses: `pending`,
`succeeded`, `failed`. Deliveries MUST be at-least-once and consumers MUST be told to dedupe on
`X-HRA-Event-Id` (documented). Deleting or disabling an endpoint MUST stop pending retries for it.
Delivery MUST run on the queue and never block the originating request or state transition.

#### Scenario: Success

- GIVEN an endpoint answering 204
- WHEN the delivery job runs
- THEN the delivery is `succeeded` with `attempts` 1 and the response status recorded

#### Scenario: Retry schedule

- GIVEN an endpoint answering 500 each time
- WHEN the job runs repeatedly
- THEN next attempts are scheduled 10 s, 60 s, 300 s, 1800 s and 7200 s after the preceding failures

#### Scenario: Exhaustion

- GIVEN six consecutive failures
- WHEN the sixth fails
- THEN the delivery is `failed`, `attempts` is 6, and no further attempt is scheduled

#### Scenario: Timeout

- GIVEN an endpoint that does not answer within 10 seconds
- WHEN attempted
- THEN the attempt is recorded as a failure with error `timeout`

#### Scenario: Recovery mid-way

- GIVEN two failures and then a 200
- WHEN the third attempt runs
- THEN the delivery is `succeeded` with `attempts` 3

#### Scenario: Endpoint disabled during retries

- GIVEN a delivery waiting for retry
- WHEN its endpoint is disabled
- THEN the pending retry does not send a request

#### Scenario: Does not block the request

- GIVEN a failing endpoint
- WHEN a conversation ends
- THEN the end response is returned without waiting for any delivery attempt

### Requirement: Delivery log

Auth: SK with scope `webhook_endpoints:manage`. `GET /v1/webhook-endpoints/{id}/deliveries`
(paginated, optional `status` filter) MUST return per delivery: `id`, `event_id`, `event_type`,
`status`, `attempts`, `last_response_status|null`, `last_error|null`, `next_attempt_at|null`,
`created_at`, `completed_at|null`. `last_error` MUST be a short safe code or message
(`timeout`, `connection_failed`, `destination_not_allowed`, `http_<status>`) and MUST NOT contain
the secret, response body, or request body. The delivery payload MUST be retained while the delivery
exists but MUST NOT be returned by the log.

#### Scenario: Log content

- GIVEN a delivery that failed with HTTP 500
- WHEN the log is read
- THEN `status` is `pending` with `last_response_status` 500, `last_error` `http_500`, and `next_attempt_at` set

#### Scenario: No bodies or secrets

- GIVEN any delivery
- WHEN the log JSON is inspected
- THEN it contains neither the payload, the response body, nor the signing secret

#### Scenario: Filter

- GIVEN deliveries in several statuses
- WHEN requested with `status=failed`
- THEN only failed deliveries are returned

#### Scenario: Foreign endpoint

- GIVEN an endpoint of account A
- WHEN account B reads its deliveries
- THEN status is 404

### Requirement: Webhook secret and payload non-disclosure

The signing secret MUST NOT appear in logs, failed-job records, delivery log output, or error
bodies, and `webhook_deliveries.payload` MUST be treated as personal data (hidden from
serialization, no customer endpoint returns it).

#### Scenario: Failed job record

- GIVEN a delivery job that throws
- WHEN the failed-job record is inspected
- THEN it contains no plaintext signing secret
