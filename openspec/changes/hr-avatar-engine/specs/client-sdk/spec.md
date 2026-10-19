# Client SDK Specification

## Purpose

`@hr-avatar/sdk`: the browser library that joins a conversation created by the host back end,
drives the avatar over the provider's transport, and forwards the transcript to the service. It
talks to the service only with the publishable key plus the per-conversation `client_secret`.
Provider libraries are optional, lazily imported peer dependencies.

## ADDED Requirements

### Requirement: Connect

`HrAvatar.connect(options)` MUST accept `{ baseUrl, publishableKey, clientSecret, mount:
HTMLElement, audioDeviceId?, completionPhrases?, forwardTranscript?, transports? }` and return a
`Promise<Conversation>`. It MUST read the conversation id from the `sub` claim of `clientSecret`
(decoded without verification; the service verifies), call `POST {baseUrl}/v1/client/conversations/{id}/connect`
with `X-HRA-Publishable-Key: <publishableKey>` and `Authorization: Bearer <clientSecret>`, select the
transport from the returned `JoinMaterial.transport` (`daily`, `liveavatar`, `mock`), attach media
to `mount`, and resolve once the transport is connected. `forwardTranscript` defaults to true.
The SDK MUST send the publishable key and client secret only to `baseUrl`, MUST never log or
persist them, and MUST not expose them on the returned object. A malformed `clientSecret` MUST
reject with `HrAvatarError` code `invalid_client_secret` without any network call. A non-2xx
answer MUST reject with `HrAvatarError` carrying the service's Problem+JSON `code`, `status` and
`request_id`.

#### Scenario: Happy path on the mock transport

- GIVEN a fake service returning `JoinMaterial` with transport `mock`
- WHEN `HrAvatar.connect` is called with valid options
- THEN the connect request carries both auth headers, the promise resolves, and the conversation state becomes `live` once the mock transport joins

#### Scenario: Id derived from the token

- GIVEN a client secret whose `sub` is `conv_01ABC`
- WHEN `connect` runs
- THEN the request path is `/v1/client/conversations/conv_01ABC/connect`

#### Scenario: Malformed secret

- GIVEN `clientSecret` `not-a-jwt`
- WHEN `connect` is called
- THEN it rejects with `HrAvatarError` code `invalid_client_secret` and no request was made

#### Scenario: Service error is surfaced

- GIVEN the service answers 409 with `code` `already_connected`
- WHEN `connect` is called
- THEN it rejects with an `HrAvatarError` whose `code` is `already_connected` and `status` is 409

#### Scenario: Secrets stay private

- GIVEN a connected conversation
- WHEN the returned object, its enumerable properties and console output are inspected
- THEN neither the client secret nor the publishable key appears

### Requirement: Events

The conversation MUST expose `on(event, handler)` returning an unsubscribe function for events
`state`, `transcript`, `error` and `ended`. `state` handlers receive one of `connecting`, `live`,
`ended`, `error`; `transcript` handlers receive `{ role: "user"|"avatar", text, final: boolean,
occurredAt }`; `error` handlers receive an `HrAvatarError` (`code`, `message`, `recoverable`);
`ended` handlers receive `{ reason: "user"|"server"|"provider"|"completion_phrase"|"error" }`.
`ended` MUST fire exactly once per conversation.

#### Scenario: State sequence

- GIVEN a mock conversation
- WHEN it connects and then ends
- THEN `state` emits `connecting`, `live`, `ended` in order

#### Scenario: Unsubscribe

- GIVEN a handler registered via `on`
- WHEN the returned function is called and an event occurs
- THEN the handler is not invoked

#### Scenario: Ended once

- GIVEN an ended conversation
- WHEN `end()` is called again or the transport reports closed again
- THEN no second `ended` event is emitted

### Requirement: Controls

The conversation MUST provide: `setMicMuted(muted: boolean)`; `toggleMic(): boolean` returning the new
muted state; `say(text: string)` (the avatar speaks the text verbatim); `interrupt()` (stops the
current avatar speech); `sendContext(payload: string | object)` (delivers extra context to the
avatar mid-conversation); `whenAvatarSilent(ms: number): Promise<void>`; and `end(): Promise<void>`.
`whenAvatarSilent` MUST resolve once the avatar has produced no speech for `ms` milliseconds (immediately
if it already has been silent that long) and MUST also resolve if the conversation ends first, so it
never hangs. Calling a control after the conversation ended MUST reject (async controls) or throw
(sync controls) `HrAvatarError` code `conversation_ended`. A control the active transport does not
support MUST reject with `HrAvatarError` code `unsupported_operation`. `audioDeviceId`, when given,
MUST be applied to the microphone on join. `end()` MUST call `POST .../end` on the service, stop the
transport, release media tracks and flush the outbox, and be idempotent.

#### Scenario: Toggle returns new state

- GIVEN an unmuted microphone
- WHEN `toggleMic()` is called
- THEN it returns true and the microphone is muted

#### Scenario: Say

- GIVEN a live mock conversation
- WHEN `say("hello")` is called
- THEN the transport receives a speak command with text `hello`

#### Scenario: Silence already satisfied

- GIVEN the avatar has been silent for 3000 ms
- WHEN `whenAvatarSilent(1000)` is called
- THEN the promise resolves immediately

#### Scenario: Silence waits

- GIVEN the avatar is speaking
- WHEN `whenAvatarSilent(1000)` is awaited and speech stops
- THEN it resolves 1000 ms after the last speech activity

#### Scenario: Ended while waiting

- GIVEN a pending `whenAvatarSilent`
- WHEN the conversation ends
- THEN the promise resolves

#### Scenario: Control after end

- GIVEN an ended conversation
- WHEN `say("x")` is called
- THEN it rejects with `HrAvatarError` code `conversation_ended`

#### Scenario: End is idempotent and calls the service

- GIVEN a live conversation
- WHEN `end()` is called twice
- THEN exactly one `POST .../end` request is made and media tracks are stopped

#### Scenario: Unsupported control

- GIVEN a transport that cannot receive context
- WHEN `sendContext({a:1})` is called
- THEN it rejects with `HrAvatarError` code `unsupported_operation`

### Requirement: Completion phrases

When `completionPhrases` (array of strings) is provided and a final avatar utterance contains any
phrase (case-insensitive substring), the SDK MUST wait for avatar silence (1500 ms), then end the
conversation and emit `ended` with reason `completion_phrase`. Utterances from the user MUST never
trigger completion. Without `completionPhrases` nothing is inferred from avatar speech.

#### Scenario: Phrase triggers end

- GIVEN `completionPhrases: ["thank you, goodbye"]`
- WHEN the avatar utters a final line containing `Thank you, goodbye!`
- THEN after 1500 ms of silence the conversation ends and `ended` has reason `completion_phrase`

#### Scenario: User speech ignored

- GIVEN the same configuration
- WHEN the user utters `thank you, goodbye`
- THEN the conversation continues

#### Scenario: Not configured

- GIVEN no `completionPhrases`
- WHEN the avatar says any text
- THEN the conversation continues

### Requirement: Transcript auto-forwarding outbox

Unless `forwardTranscript` is false, every final utterance MUST be forwarded to `POST
.../events` as an `utterance` event with a stable unique `idempotency_key` generated at capture
time and the observed `occurred_at`. The SDK MUST also forward lifecycle `live` once when the
conversation becomes live. Events MUST be batched (flush when 20 are queued or 2 seconds after the
first queued event, whichever comes first), delivered in capture order, and retried on network
failure or HTTP 5xx/429 with exponential backoff (1 s base, doubling, 30 s cap) reusing the same
idempotency keys. HTTP 409 `conversation_closed` and 401 MUST stop forwarding and emit an `error`
event without ending the conversation by themselves. A forwarding failure MUST never end or break
the live conversation. On `end()` and on `pagehide` the outbox MUST attempt a final flush (using
`fetch` with `keepalive` on `pagehide`). Interim (non-final) utterances MUST NOT be forwarded.

#### Scenario: Batching by count

- GIVEN 20 final utterances captured quickly
- WHEN the 20th is queued
- THEN one batch of 20 events is posted immediately

#### Scenario: Batching by time

- GIVEN one final utterance
- WHEN 2 seconds pass
- THEN one batch of 1 event is posted

#### Scenario: Retry keeps keys

- GIVEN the first post fails with a network error
- WHEN the retry fires
- THEN the same events with the same `idempotency_key` values are posted again

#### Scenario: Backoff growth

- GIVEN consecutive failures
- WHEN retry delays are observed
- THEN they are 1 s, 2 s, 4 s ... capped at 30 s

#### Scenario: Closed stops forwarding

- GIVEN the service answers 409 `conversation_closed`
- WHEN the outbox receives it
- THEN no further posts are made, an `error` event is emitted, and the conversation is not ended by this alone

#### Scenario: Opt-out

- GIVEN `forwardTranscript: false`
- WHEN final utterances occur
- THEN no request to `.../events` is made

#### Scenario: Final flush on end

- GIVEN queued unsent events
- WHEN `end()` is called
- THEN a final batch is posted before `end()` resolves

#### Scenario: Interim ignored

- GIVEN a non-final transcript fragment
- WHEN it is emitted
- THEN it is delivered to `transcript` handlers with `final` false and is not queued for forwarding

### Requirement: Provider transports and peer dependencies

The Daily transport MUST use `@daily-co/daily-js` and the LiveAvatar transport MUST use
`@heygen/liveavatar-web-sdk`; both MUST be declared as optional `peerDependencies` and loaded with
a dynamic `import()` only when the connect response requires that transport. The `mock` transport
MUST be built into the package, perform no network or media I/O, and be deterministic. If the
required library is not installed the SDK MUST reject `connect` with `HrAvatarError` code
`provider_library_missing` naming the package. `transports` option entries MAY override a
transport factory (used for tests and custom hosts).

#### Scenario: Lazy import

- GIVEN a mock-only session
- WHEN the SDK runs
- THEN neither provider library is imported

#### Scenario: Missing library

- GIVEN a `daily` connect response and `@daily-co/daily-js` not installed
- WHEN `connect` is called
- THEN it rejects with `provider_library_missing` and the message names `@daily-co/daily-js`

#### Scenario: Override

- GIVEN `transports: { daily: customFactory }`
- WHEN a `daily` session is connected
- THEN `customFactory` is used and the library is not imported

### Requirement: Exported mock provider

The package MUST export the Mock provider (entry `@hr-avatar/sdk/mock` and from the main entry) so
host tests can run the full SDK flow without a service or a browser provider: it produces
deterministic avatar utterances, supports every control, and can be scripted (queue avatar lines,
simulate avatar speech start/stop and transport close).

#### Scenario: Scripted line

- GIVEN a mock transport scripted with the avatar line `Hello`
- WHEN the conversation is live
- THEN a final `transcript` event `{role:"avatar", text:"Hello", final:true}` is emitted

#### Scenario: Simulated close

- GIVEN a live mock conversation
- WHEN the script simulates a transport close
- THEN `ended` is emitted with reason `provider`

### Requirement: Build outputs and size budget

The package MUST build with tsup to ESM, CJS and a minified IIFE (global `HrAvatar`) plus type
declarations, and declare an `exports` map for `.` and `./mock` with `types`, `import` and
`require` conditions. The core bundle (ESM entry, minified, gzipped, excluding peer dependencies
and lazily imported chunks) MUST NOT exceed 20 KB; `bun run size` MUST fail the CI when it does.
The `sdk/` tree MUST use Bun only and contain no host-product vocabulary.

#### Scenario: Outputs exist

- GIVEN a successful build
- WHEN the `dist` directory is listed
- THEN it contains ESM, CJS, IIFE and `.d.ts` outputs for the main and mock entries

#### Scenario: IIFE global

- GIVEN the IIFE file loaded in a page
- WHEN `window.HrAvatar` is inspected
- THEN it exposes `connect`

#### Scenario: Size gate

- GIVEN a core bundle of 21 KB gzipped
- WHEN `bun run size` runs
- THEN it exits non-zero

#### Scenario: Peers excluded

- GIVEN the ESM bundle
- WHEN searched for the inlined code of `@daily-co/daily-js` or `@heygen/liveavatar-web-sdk`
- THEN none is found

### Requirement: SDK test gates

Vitest MUST enforce 90% thresholds for lines, branches and functions via `bun run test:coverage`.
A Playwright smoke page using the mock provider and a stubbed service MUST pass in Chromium and
WebKit via `bunx playwright test`, covering connect, a scripted avatar utterance, transcript
forwarding, and end.

#### Scenario: Threshold enforced

- GIVEN branch coverage of 89%
- WHEN `bun run test:coverage` runs
- THEN it fails

#### Scenario: Smoke on both engines

- GIVEN the smoke page
- WHEN Playwright runs the Chromium and WebKit projects
- THEN both pass, and the stubbed service received at least one `/events` batch and one `/end` call
