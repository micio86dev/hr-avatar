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
