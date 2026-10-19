# Apply progress: hr-avatar-engine, Tavus lane (slices 10-11)

Mode: Strict TDD (Pest). Worktree `hr-avatar-worktrees/tavus`, branch `feature/extract-avatar-service-tavus`.
Parallel lane: LiveAvatar slices 12-14 are written in the main worktree; the orchestrator merges both.
Merge into `apply-progress.md` (and the Engram `sdd/hr-avatar-engine/apply-progress` record) is the
orchestrator's; this file holds only the Tavus lane.

Local environment (this lane): own database `hra_test_tavus` on host port 55442 (`DB_PORT=55442
DB_DATABASE=hra_test_tavus`), a throwaway `APP_KEY` and `HRA_JWT_SECRET` exported per command (no `.env`
file is written). `DatabaseConnectionTest` asserts the literal database name `hra_test`, so it is
excluded from lane runs (`--filter='^(?!.*DatabaseConnectionTest).*$'`) and must be run once on the main
worktree after the merge; it fails here only because of the lane's database name.

## Slice 10: Tavus adapter - start (tasks 10.1-10.3) - complete

Commit subject: `feat(service): add Tavus adapter start with payload mapping and concurrency guard`

| Task | Test file | RED (observed) | GREEN | Notes |
|---|---|---|---|---|
| 10.1 | `Tavus/TavusLanguageTest.php`, `Tavus/TavusPayloadTest.php`, `Arch/VocabularyScannerTest.php` (addition) | `Class "App\AvatarProviders\Tavus\TavusLanguage" not found` (5 errors), `TavusPayload` not found (16 errors), scanner flagged `participant_left_timeout` (1 failure) | 51/51 | ported from BEAI `TavusLanguageTest` and the pure parts of `TavusProviderTest` / `TemplatePayloadTest`; BEAI's `TemplatePayload::tavus` knobs beyond the task (audio_only, recording, captions, absent timeout) are not carried |
| 10.2 | `Tavus/TavusConcurrencyGuardTest.php`, `Tavus/TavusConcurrencyGuardScanTest.php` | `TavusConcurrencyGuard` not found (19 errors) and the scan found no adapter files | 30/30 with 10.3 | ported from BEAI `TavusConcurrencyGuardTest`; the message is matched, never kept, so BEAI's `ProviderErrorMessage` and its api-key redaction are not needed |
| 10.3 | `Tavus/TavusProviderStartTest.php`, `Tavus/TavusKeyNeverLeaksTest.php`, `Tavus/TavusContractFixtureTest.php` | `Class "App\AvatarProviders\Tavus\TavusProvider" not found` (19 errors, 5 failures) | 79/79 for `--filter=Tavus` | fixtures under `tests/Fixtures/Tavus` (docs-verified, not recordings) |

Mutation check on the safety nets (reverted afterwards): adding a `GET .../conversations?status=active` call and a
`Log::warning($response->body())` to `TavusProvider` made the scan test, the contract tests and the
refusal tests fail.

### Work Unit Evidence (slice 10)

| Evidence | Value |
|---|---|
| Focused test command | `DB_PORT=55442 DB_DATABASE=hra_test_tavus php artisan test --filter=Tavus` : 79 passed, 159 assertions, exit 0 |
| Full suite | 816 tests, 815 passed; the one failure is `DatabaseConnectionTest` (asserts `hra_test`, this lane runs on `hra_test_tavus`); with that test excluded: 815 passed, 2461 assertions |
| Coverage | `vendor/bin/pest --coverage --min=90` (DatabaseConnectionTest excluded): total 99.4 %, exit 0; Tavus guard, language, payload 100 %, `TavusProvider` 83.3 % (the four slice-11 stubs) |
| High integrity | `vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95` (same exclusion): 815 passed, 100.0 %, exit 0 |
| Lint / analysis | `composer run lint` passed; `composer run analyse` (level 8) passed |
| Scramble drift | `OpenApiDriftTest` green inside the full suite; no route added, `openapi.json` unchanged |
| Runtime harness | N/A-WIRE: adapters make no live calls by rule; `Http::fake` contract fixtures are the proof |
| Rollback boundary | `app/AvatarProviders/Tavus/*`, the `tavus` registry entry and `tavus` settings block in `config/hr-avatar.php`, `tests/Unit/AvatarProviders/Tavus`, `tests/Fixtures/Tavus`, the wire-identifier allowance in `tests/Arch/VocabularyScanner*` |

### Deviations and decisions (slice 10)

- **Stubs:** `AvatarProvider` already declares all six runtime operations (slice 9), so `TavusProvider` must implement
  `teardown`, `fetchTranscript`, `fetchSession` and `parseCallback` in this slice. They throw `LogicException`
  until slice 11 replaces them (RED tests first there); they are the only uncovered lines.
- **Shared file, scanner:** `participant_left_timeout` is Tavus's own wire key and the vocabulary scan bans the word
  `participant` everywhere. `tests/Arch/VocabularyScanner.php` gained `PROVIDER_WIRE_IDENTIFIERS`, an exact
  identifier removed from a line before it is read (the rest of the line is still scanned; RED test first). Nothing
  else was relaxed.
- **Shared file, config:** `config/hr-avatar.php` gained the `use` line and `'tavus' => [...]` registry entry, and a new
  `tavus` block (`concurrency_retries` 3, `concurrency_backoff_ms` 2000) with no env variables (the design lists none).
- `TavusConcurrencyGuard::attempt(callable)` takes no API key (BEAI passed it to redact messages): the message is only
  matched. It raises `ProviderException::throttled($status)` itself once the account is still at capacity after the last
  retry (also with retries 0), so `start` only has to map the non-throttle failures. A transport `ConnectionException`
  is not retried and becomes `ProviderException::noResponse()` with no previous exception.
- The backoff uses Laravel's `Sleep` (faked in tests: the exact waits are asserted, no real sleeping).
- `AvatarConfig->config` keys read by the payload: `replica_id`, `persona_id` (non-empty strings) and
  `participant_left_timeout` (an integer; other types are dropped). No platform env floor for replica/persona (the design removes it).
  `TavusFieldSpecs` (slice 15) must use the same keys.
- `JoinMaterial.expiresAt` is now + `maxDurationSeconds` (Tavus states no room expiry; it cannot outlive the call maximum).
- The scan test (`TavusConcurrencyGuardScanTest`) is broader than the BEAI one: besides "no list/active-status call", every HTTP
  verb call in `app/AvatarProviders/Tavus` must be the create POST or address `$sessionRef`; slice 11's calls follow that rule.
- Test class names are prefixed `Tavus` (`TavusKeyNeverLeaksTest`, `TavusContractFixtureTest`) so they cannot collide with the
  LiveAvatar lane's equivalents; the task filters (`KeyNeverLeaksTest`, `ContractFixtureTest`) still match them.
- Authored size about 880 lines (about 590 are tests and fixtures), above the 400 budget: three cohesive tasks (payload, guard,
  provider start) with their safety tests. Recommend `size:exception`.
