# Apply progress: SDK slices (hr-avatar-engine)

Worktree `hr-avatar-worktrees/sdk`, branch `feature/extract-avatar-service-sdk`. Mode: Strict TDD
(`cd sdk && bun run test`, coverage `bun run test:coverage`, thresholds 90). Service progress is
recorded separately by the other writer; this file covers slices 33-36 only.

## Toolchain note

Local Bun is `1.4.2`; the pin is `1.4.0` (Docker image `oven/bun:1.4.0`). `1.4.2` is a patch of the
same minor and was used as-is; `bun.lock` locks every package version. `bunx tsc --version` prints
`5.9.3`. All package pins (`@daily-co/daily-js 0.91.0`, `@heygen/liveavatar-web-sdk 0.0.18`,
`@playwright/test 1.61.1`, `vitest ^3` -> 3.2.7, `tsup ^8` -> 8.5.1) resolved without change.

## Slice 33: SDK scaffold

| Task | RED (observed) | GREEN | REFACTOR |
|------|----------------|-------|----------|
| 33.1 preflight | `bun --version` -> `1.4.2` (pin `1.4.0`), `tsc` -> `5.9.3` | accepted, recorded above | n/a |
| 33.2 package pins | `package-pins.spec.ts`: 9 of 10 failed (bootstrap manifest without name, exports, peers, pins) | `package.json`, `tsconfig.json`, `tsup.config.ts`, `vitest.config.ts`, `.gitignore`; 10 passed | none |
| 33.3 `HrAvatarError` | suite failed: `src/errors` not found | `src/errors.ts`; 3 passed | none |
| 33.4 API client | suite failed: `src/api` not found | `src/api.ts`; 21 passed (one test bug fixed: a `Response` body is single-use, mock returns a fresh one per call) | none |
| 33.5 anonymity | probe file `src/_probe.ts` containing `candidate` made "does not appear anywhere in sdk/src" fail, then the probe was removed | `sdk/src` clean; 3 passed | none |

### Work Unit Evidence (slice 33)

| Evidence | Value |
|---|---|
| Focused test command | `cd sdk && bun run test` -> exit 0, 4 files, 37 tests |
| Runtime harness | `cd sdk && bun run build` -> `dist/index.{js,cjs,d.ts,d.cts}`; `bunx tsc --noEmit` exit 0 |
| Coverage | `bun run test:coverage`: lines 98.75, branches 95.55, functions 90.9 (thresholds 90) |
| Rollback boundary | `sdk/` (new tree) |

Decisions: `JoinMaterial` is read as snake_case JSON (`provider`, `transport`, `conversation_url`,
`session_token`, `expires_at`) because the spec leaves the wire keys open and every other resource
is snake_case. A redirect is refused (`redirect: 'error'`) so the auth headers never leave `baseUrl`.

## Slice 34: Outbox and completion phrases

| Task | RED (observed) | GREEN | REFACTOR |
|------|----------------|-------|----------|
| 34.1 outbox | `outbox.spec.ts` failed: `src/outbox` not found | `src/outbox.ts`; 28 passed. One expectation of mine was wrong, not the code: with a synchronous enqueue loop the first drain sees all 150 events, so the first batch is 100, not 20 (`[100, 100, 50]`) | none |
| 34.2 completion | `completion.spec.ts` failed: `src/completion` not found | `src/completion.ts`; 10 passed | none |

### Work Unit Evidence (slice 34)

| Evidence | Value |
|---|---|
| Focused test command | `cd sdk && bun run test -- outbox completion` -> exit 0; full suite 6 files, 75 tests |
| Runtime harness | N/A: pure logic with injected `send` and fake timers; covered end to end by the slice 39 smoke |
| Coverage | `bun run test:coverage`: outbox 100/97.82/100/100, completion 100/100/100/100 (stmts/branch/funcs/lines) |
| Rollback boundary | `src/outbox.ts`, `src/completion.ts` and their two specs |

Decisions:
- `forwardTranscript: false` disables the whole outbox, including the lifecycle `live` event, because
  the spec scenario "Opt-out" requires that no request reaches `.../events`.
- A batch refused with a non-retryable status other than 401 or 409 `conversation_closed` (for
  example the atomic 422) is dropped with an `error` event and forwarding continues; holding it
  would block every later event.
- A failed last flush (`end()` or `pagehide`) is silent and not retried: the conversation is going
  away and `end()` must always resolve.
- "Interim ignored" is asserted at the conversation level in slice 36 (the outbox is generic; the
  conversation only enqueues final utterances).

## Slice 35: Transport infrastructure and mock

| Task | RED (observed) | GREEN | REFACTOR |
|------|----------------|-------|----------|
| 35.1 Transport interface and `loadTransport` | `types.spec.ts` and `load-transport.spec.ts` failed: modules not found | `src/transports/types.ts`, `src/transports/index.ts`; 7 + 9 passed (the default-loader test first logged jsdom canvas errors from the real `daily-js`; the specifiers are now mocked with `vi.mock`) | none |
| 35.2 mount resolution | `media.spec.ts` failed: `src/media` not found | `src/media.ts`; 4 passed | none |
| 35.3 mock transport and `./mock` | `mock-transport.spec.ts` failed: `src/mock` not found; after the mock existed, `loadTransport("mock")` still threw `unsupported_transport` (second RED) and a `srcObject` null assertion failed because jsdom leaves it `undefined` (test fixed) | `src/transports/mock.ts`, `src/mock.ts`, `mock` case in `loadTransport`, `src/mock.ts` entry in `tsup.config.ts`; 14 + 1 passed | none |

### Work Unit Evidence (slice 35)

| Evidence | Value |
|---|---|
| Focused test command | `cd sdk && bun run test -- load-transport mock-transport media` -> exit 0; full suite 10 files, 110 tests |
| Runtime harness | `cd sdk && bun run build` -> `dist/mock.{js,cjs,d.ts,d.cts}` emitted next to `dist/index.*`; `bunx tsc --noEmit` exit 0 |
| Coverage | `bun run test:coverage` exit 0 (thresholds 90): every new file 100% statements/lines/functions; branches 100% |
| Rollback boundary | `src/transports/{types,index,mock}.ts`, `src/mock.ts`, `src/media.ts`, their four specs and the `src/mock.ts` tsup entry |

Decisions and open points:
- `JoinMaterial.transport` is an opaque `string` in `src/api.ts` (gga flagged a provider-name union in
  core). `TransportName` lives in `src/transports/types.ts`, the adapter side.
- `loadTransport('daily' | 'liveavatar')` resolves the peer library (so a missing package reports
  `provider_library_missing`) and then rejects with `unsupported_transport`, because the Daily and
  LiveAvatar transports land in slices 37 and 38. Those slices replace that rejection with their
  builders and update the "imports the installed library by default" test.
- The mock emits scripted lines in a `setTimeout(0)` after `start()` resolves, so a host that
  subscribes right after `await connect()` sees them.
