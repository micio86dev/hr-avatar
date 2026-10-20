# Provider Adapters Specification

## Purpose

The provider-agnostic seam. A single provider contract turns a host-neutral `ConversationSpec`
into browser `JoinMaterial`; Tavus, HeyGen LiveAvatar and a deterministic Mock implement it.
Provider specifics live only in adapter classes. Provider identifiers: `tavus`, `heygen` (HeyGen
LiveAvatar), `mock`.

## ADDED Requirements

### Requirement: Provider contract

The service MUST define one provider contract implemented by every adapter, with at least these
operations (the full list also includes `parseCallback`, `validateConfig`, `sync` and `catalogue`):
`ingestion()` returning `callback`, `poll` or `none`, which tells the service how provider events
reach it, so no code outside the adapters branches on a provider name;
`start(ConversationSpec, AvatarConfig, ProviderCredential, CallbackTarget|null)` returning a
`ProviderSession { providerSessionRef, providerContextRef|null, joinMaterial }`;
`teardown(providerSessionRef, providerContextRef|null)` returning a boolean; `fetchTranscript(providerSessionRef)`
returning a list of normalized utterances; and `fetchSession(providerSessionRef)` returning
`{ ended: bool, endReason: string|null }`.

`ConversationSpec` MUST be exactly `{ systemPrompt, openingText, language, maxDurationSeconds,
metadata }`; `JoinMaterial` MUST be exactly `{ provider, transport, conversationUrl|null,
sessionToken|null, expiresAt }` with `transport` one of `daily`, `liveavatar`, `mock`. A single
registry MUST resolve a provider identifier to its adapter; an unknown identifier MUST be rejected
before any adapter call.

#### Scenario: Registry resolves adapters

- GIVEN provider identifiers `tavus`, `heygen`, `mock`
- WHEN the registry resolves each
- THEN each returns an adapter implementing the contract

#### Scenario: Unknown provider

- GIVEN the identifier `acme`
- WHEN the registry resolves it
- THEN resolution fails with a domain error and no HTTP call is made

#### Scenario: Ingestion mode per adapter

- GIVEN the adapters for `tavus`, `heygen` and `mock`
- WHEN `ingestion()` is called on each
- THEN they return `callback`, `poll` and `none` respectively

#### Scenario: Join material shape

- GIVEN any adapter's successful `start`
- WHEN `joinMaterial` is inspected
- THEN it has exactly the keys `provider`, `transport`, `conversationUrl`, `sessionToken`, `expiresAt`, and `transport` is one of `daily`, `liveavatar`, `mock`

### Requirement: Tavus adapter start

The Tavus adapter MUST create a conversation with one `POST` to the Tavus conversations endpoint,
authenticated with the resolved Tavus API key. The request MUST carry the system prompt as the
conversation context, the opening text as the custom greeting, `maxDurationSeconds` as the
provider maximum call duration, the avatar config's persona/replica identifiers, the language, and
a `callback_url` equal to the supplied callback target. It MUST return `transport` `daily`,
`conversationUrl` set to the provider's conversation URL, `sessionToken` null, and
`providerSessionRef` equal to the provider's conversation id.

#### Scenario: Request mapping

- GIVEN a `ConversationSpec` with prompt P, opening O, language `it`, max duration 600, and a callback target U, and `Http::fake` for Tavus
- WHEN `start` is called
- THEN the recorded request is authenticated with the Tavus key and contains P as context, O as greeting, 600 as max call duration, the config's persona/replica ids and `callback_url` U

#### Scenario: Join material

- GIVEN Tavus answers with conversation id `c123` and URL `https://tavus.daily.co/c123`
- WHEN `start` returns
- THEN `providerSessionRef` is `c123`, `transport` is `daily`, `conversationUrl` is that URL and `sessionToken` is null

#### Scenario: Provider key never leaks

- GIVEN Tavus answers 401 with a body echoing the API key
- WHEN `start` fails
- THEN the thrown error message and its logs contain neither the key nor any part of the provider body

### Requirement: HeyGen LiveAvatar adapter start

The HeyGen adapter MUST create the session context (system prompt and opening text) and then
request a session token from the LiveAvatar API, authenticated with the resolved HeyGen API key,
applying only an allowlist of avatar-config fields to the token request (unknown config keys MUST
be ignored, never forwarded). It MUST return `transport` `liveavatar`, `sessionToken` set to the
provider token, `conversationUrl` null, `providerSessionRef` equal to the provider session id and
`providerContextRef` equal to the created context id. When the config binds an external voice, the
adapter MUST send the bound voice identifier and engine; an unbindable voice MUST raise a provider
error before any billable session is created.

#### Scenario: Context then token

- GIVEN `Http::fake` for LiveAvatar
- WHEN `start` is called
- THEN a context is created first, the token request references that context id, and the result carries `providerContextRef`

#### Scenario: Field allowlist

- GIVEN an avatar config whose `config` contains an allowed key and a non-allowlisted key
- WHEN `start` is called
- THEN the token request contains the allowed key and does not contain the other

#### Scenario: Token failure cleans up the context

- GIVEN the context is created and the token request fails
- WHEN `start` fails
- THEN the created context is deleted (best effort) and a provider error is raised

### Requirement: Mock adapter

The Mock adapter MUST perform no network I/O and be fully deterministic: for conversation id `I`
and a fixed clock, `start` MUST return `provider` `mock`, `transport` `mock`, `conversationUrl`
`mock://conversation/{I}`, `sessionToken` `mock_token_{I}`, `providerSessionRef` `mock_{I}` and
`expiresAt` = now + 3600 seconds. `fetchTranscript` MUST return one `avatar` utterance holding the
opening text when it is non-empty, otherwise an empty list. `fetchSession` MUST report
`ended: true` once `teardown` has been called, `false` before. It MUST be selected only by an
avatar config whose provider is `mock`.

#### Scenario: Deterministic output

- GIVEN a fixed clock and conversation id `I`
- WHEN `start` is called twice
- THEN both results are identical

#### Scenario: No network

- GIVEN `Http::preventStrayRequests()`
- WHEN the Mock adapter runs `start`, `fetchTranscript`, `fetchSession` and `teardown`
- THEN no HTTP request is attempted

#### Scenario: Session ends after teardown

- GIVEN a started mock session
- WHEN `teardown` is called
- THEN `fetchSession` returns `ended: true`

### Requirement: Concurrency guard

Tavus session creation MUST be retried only when the provider rejects the call with a 4xx whose
diagnostic message matches the concurrency-limit pattern `maximum concurrent conversations`
(case-insensitive). The number of retries (default 3) and the backoff in milliseconds (default
2000) MUST be configurable and MAY be set to 0 to disable retrying. Any other failure (auth error,
malformed response, 5xx) MUST NOT be retried. The guard MUST NOT list, end or otherwise touch any
other conversation of the provider account. When retries are exhausted the adapter MUST raise a
concurrency error that the API maps to 429 `provider_concurrency_limited`.

#### Scenario: Retry then succeed

- GIVEN Tavus answers a concurrency 4xx once and then succeeds, retries configured to 3
- WHEN `start` is called
- THEN exactly two create requests are made and the call succeeds

#### Scenario: Exhausted

- GIVEN Tavus always answers the concurrency 4xx and retries are 2
- WHEN `start` is called
- THEN exactly three create requests are made and a concurrency error is raised

#### Scenario: Non-concurrency 4xx and 5xx are not retried

- GIVEN Tavus answers 401 once, and separately 503 once
- WHEN `start` is called in each case
- THEN exactly one create request is made in each case

#### Scenario: Retries disabled

- GIVEN retries configured to 0 and a concurrency 4xx
- WHEN `start` is called
- THEN exactly one create request is made

#### Scenario: Never reaps other conversations

- GIVEN the guard source and its tests
- WHEN scanned for provider calls that list or end conversations other than the one being created
- THEN none exist (grep-based negative test)

### Requirement: Transcript reconciliation

`fetchTranscript` MUST return utterances normalized to `{ role: "user"|"avatar", text, occurredAt|null,
offsetSeconds|null }`. For Tavus: role `assistant` and `replica` map to `avatar`, `user` stays
`user`, `system` entries are excluded. For LiveAvatar: `avatar` and `user` pass through. A row with
a missing role, an unknown role, or non-string text MUST raise a transcript-shape error; the
adapter MUST NOT silently drop or coerce it. Provider response bodies MUST NOT appear in the error.

#### Scenario: Tavus mapping

- GIVEN a Tavus transcript with roles `system`, `assistant`, `user`
- WHEN `fetchTranscript` is called
- THEN two utterances are returned (`avatar`, `user`) in provider order and the system entry is excluded

#### Scenario: LiveAvatar mapping

- GIVEN a LiveAvatar transcript with roles `avatar` and `user`
- WHEN `fetchTranscript` is called
- THEN both are returned with their text and timestamps

#### Scenario: Unknown role

- GIVEN a row with role `moderator`
- WHEN `fetchTranscript` is called
- THEN a transcript-shape error is raised

#### Scenario: Empty transcript

- GIVEN a provider answering an empty list
- WHEN `fetchTranscript` is called
- THEN an empty list is returned without error

### Requirement: Teardown

`teardown` MUST be best-effort, idempotent and MUST NOT throw: it MUST return `true` when the
provider session is confirmed ended (including when the provider reports it already ended or
unknown, e.g. 404) and `false` otherwise. For LiveAvatar it MUST also delete the context when a
`providerContextRef` is given. Calling it twice MUST be safe.

#### Scenario: Normal teardown

- GIVEN a live Tavus session and a provider that answers 200 to the end call
- WHEN `teardown` is called
- THEN it returns `true`

#### Scenario: Already gone

- GIVEN the provider answers 404
- WHEN `teardown` is called
- THEN it returns `true`

#### Scenario: Provider failure

- GIVEN the provider answers 500 or times out
- WHEN `teardown` is called
- THEN it returns `false` and does not throw

#### Scenario: Context removal

- GIVEN a LiveAvatar session with a context ref
- WHEN `teardown` succeeds
- THEN the context delete call was made

### Requirement: Session status polling surface

`fetchSession` MUST return `ended: true` with a non-null `endReason` for LiveAvatar when the
provider session resource has a non-null `end_at` or `end_reason`, `ended: false` otherwise. For
Tavus it MUST read the conversation resource and report `ended: true` when its status is not
active. A provider or network error MUST raise a provider error and MUST NOT be interpreted as
"ended".

#### Scenario: LiveAvatar active

- GIVEN a session with `end_at` null and `end_reason` null
- WHEN `fetchSession` is called
- THEN `ended` is false

#### Scenario: LiveAvatar ended

- GIVEN a session with `end_reason` `IDLE_TIMEOUT`
- WHEN `fetchSession` is called
- THEN `ended` is true and `endReason` is `IDLE_TIMEOUT`

#### Scenario: Error is not an end

- GIVEN the provider answers 502
- WHEN `fetchSession` is called
- THEN a provider error is raised

### Requirement: Provider error redaction

Every provider failure MUST surface as a single provider error type carrying the HTTP status
class and a generic message of the form `Provider request failed (status N)`. No provider
credential, request header or provider response body MUST be included in exception messages, logs
or API responses. The API MUST map provider errors to 502 `provider_error`.

#### Scenario: Redacted message

- GIVEN a provider answering 500 with a body containing `secret-token-123`
- WHEN the error is rendered by the API
- THEN status is 502, `code` is `provider_error`, and the body does not contain `secret-token-123`

### Requirement: Contract fixtures without live calls

Adapter tests MUST use `Http::fake` fixtures per provider and MUST fail when any stray request
would reach the network. No live provider key MAY be required to run the suite.

#### Scenario: Stray requests blocked

- GIVEN the adapter test suite
- WHEN a test triggers an unfaked request
- THEN the test fails
