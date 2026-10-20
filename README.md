# HR-Avatar

A provider-agnostic engine for real-time **avatar conversations**. It creates, drives and tears down a
conversation with an avatar (Tavus, HeyGen LiveAvatar, or a built-in mock) for any product that wants to
embed one. It has no back office and no user interface: only an HTTP API and a browser SDK.

A host product keeps its own concepts. It passes an opaque `external_ref` and free-form `metadata` when it
creates a conversation, and gets them back unchanged; this service never reads them.

"HR-Avatar" is a placeholder name. It lives only in `service/config/hr-avatar.php`, the SDK package name, the
key prefixes (`hra_sk_`, `hra_pk_`) and the `HRA_` settings, so renaming it is a find-and-replace.

## How it fits together

```
 host back end ──(secret key)──▶  service (HTTP API)  ◀──(publishable key + client secret)── browser
        ▲                              │   ▲                                                    │
        │ signed webhooks              │   │ callbacks / polling                                │ @hr-avatar/sdk
        └──────────────────────────────┘   ▼                                                    ▼
                                       avatar provider  ◀──────────── media, directly ───────── avatar
```

1. The host back end creates a conversation with its **secret key** and hands the `client_secret` it gets back to
   the browser.
2. The browser connects through `@hr-avatar/sdk` with a **publishable key** and that `client_secret`. The
   service starts the provider's session at that moment, and the SDK joins it directly.
3. The service learns what happened from the browser, from the provider (callbacks or polling) and from its own
   sweeper, and tells the host with **signed webhooks**. The transcript is read from the API.

## Layout

| Path | What |
|---|---|
| `service/` | The API: Laravel 13, PHP 8.5, PostgreSQL 17, Redis 8, Pest, Scramble (OpenAPI). |
| `sdk/` | `@hr-avatar/sdk`: TypeScript, built with tsup (ESM, CJS and a script-tag build), Vitest, Playwright. |
| `docs/` | [Architecture](docs/architecture.md), [API usage](docs/api-usage.md), [SDK usage](docs/sdk-usage.md), [Railway](docs/railway.md). |
| `openspec/` | The specifications, design and task list the code was built from. |
| `tools/spectral/` | The OpenAPI linter, run in CI. |

## Run it locally

You need PHP 8.5 with `pdo_pgsql` and PCOV, Composer, Docker with Compose v2, and Bun 1.4 for the SDK.

```sh
cd service
cp .env.example .env
php artisan key:generate                       # fills APP_KEY
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # paste into HRA_JWT_SECRET in .env
docker compose up -d postgres redis            # PostgreSQL on 55432, Redis on 56379
composer install
php artisan migrate
php artisan db:seed --class=LlmModelSeeder     # the language-model catalogue
php artisan hra:bootstrap-account "My account" # prints the first secret key, once
php artisan serve --port=8100                  # http://localhost:8100
php artisan queue:work                         # in a second shell: webhooks, transcripts, releases
php artisan schedule:work                      # in a third: the sweeper, the poller and the relay
```

`docker compose build api && docker compose up -d` runs the same three processes as containers instead (the API
on port 8100). The compose file publishes PostgreSQL and Redis on `55432` and `56379`; set `HRA_DB_PORT`,
`HRA_REDIS_PORT` or `HRA_API_PORT` when those are taken, and use the same port in `DB_PORT` / `REDIS_PORT`.

Then follow [docs/api-usage.md](docs/api-usage.md): it runs a whole conversation against the built-in mock
provider with curl, with no provider account.

## Tests and gates

The test database is `hra_test` on the PostgreSQL above (`DB_PORT` overrides the port).

```sh
# service
cd service
php artisan test                                    # the suite
php artisan test --parallel                         # the same, in parallel
php -d memory_limit=1G artisan test --coverage --min=90                 # overall coverage gate
vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95       # keys, webhooks, state machine: 95 %
vendor/bin/pint --test                              # code style
composer run analyse                                # PHPStan, level 8
php artisan scramble:export --path=openapi.json     # regenerate the OpenAPI document; commit the result
(cd ../tools/spectral && bun install --frozen-lockfile && bun run lint)  # lint it

# sdk
cd sdk
bun install --frozen-lockfile
bunx tsc --noEmit
bun run test:coverage                               # Vitest, thresholds 90
bun run build
bun run size                                        # each build within 20 KB gzipped
bunx playwright install --with-deps chromium webkit && bunx playwright test
```

CI runs all of it for both parts (`.github/workflows/service.yml`, `sdk.yml`). `service/openapi.json` is the
published contract: a change to a route or a response that is not exported fails the drift check, and so does a
file git does not track.

Package manager for `sdk/` and `tools/` is **Bun**; never npm, pnpm or yarn.

## Branches, versions, deployment

Git Flow: `main` (released) and `develop` (integration), with `feature/*`, `release/*` and `hotfix/*`. Commits
are conventional commits. Nothing here deploys: the Railway configuration is files only, described in
[docs/railway.md](docs/railway.md), and is applied by a person in Railway.

## Retention: an open item

**There is no purge in this release, and the owner has not decided what there should be.** The service stores
personal data and keeps it until a later change adds a purge:

- **transcripts and events**: every utterance and lifecycle event of every conversation (`conversation_events`);
- **receipts**: every provider event received or polled (`provider_webhook_receipts`), whose payload can carry a
  transcript;
- **delivery payloads**: the exact signed body of every outbound webhook (`webhook_deliveries`).

Retention periods, and whether to delete or anonymise, are an **owner decision**, pending. Until then a host
that must erase a person's data has to do it with the database. Nothing here is a legal conclusion.

## Owner ratification items

The design departs from the approved extraction design in nine places (R1 to R9), each reflected in the
specifications and waiting for the owner's ratification. They are listed, with the reason for each, under
[Owner ratification items](openspec/changes/hr-avatar-engine/design.md#owner-ratification-items) in the design.

## Known assumptions

These rest on what a provider documents, or on what was observed, and stay assumptions until someone with a
provider account confirms them:

- **LiveAvatar session token.** LiveAvatar documents no lifetime for the session token and its answer carries no
  expiry. The service reports `expires_at` as one hour from the connect: a ceiling, not a promise. The browser joins
  right after connecting, and the SDK does not read `expires_at`.
- **LiveAvatar token wording.** The inbound callback token is a path segment, which OpenAPI cannot express as a
  credential, so the document describes it as a path parameter and declares no security scheme for it.
- **Tavus persona `editable`.** The persona listing is one call and carries no `editable` flag. A stock Tavus
  persona is listed like any other, and syncing persona settings into it fails with a provider error.
- **Tavus callbacks.** Tavus documents no signature, retry policy or required status code, so the unguessable token
  in the callback address is the only authentication, and every handler is idempotent.
- **LiveAvatar end.** LiveAvatar has no callbacks. The service polls, so a session that ended on the provider's side
  can take up to a minute to be noticed; the maximum-duration sweeper bounds it either way.
- **Tavus statuses.** Only `active` and `ended` are documented. Any other status is read as "not ended" and logged.

## Known limitations

- **Creating a webhook endpoint is not idempotent.** A client that retries after a timeout creates a second endpoint
  with its own signing secret (a secret is shown once, so it cannot be replayed). An account holds at most 20
  endpoints; delete the duplicate.
- **The destination check at create and update time resolves the host name in the request**, with the system
  resolver's timeout. It is early feedback; the enforcement is the check made again at delivery time, on the
  addresses the connection is then pinned to. Set a short resolver timeout in the container if DNS is slow.
