# HR-Avatar service

The HTTP API: Laravel 13, PHP 8.5, PostgreSQL 17, Redis 8, Pest, and Scramble for the OpenAPI document. It is API
only (no views). What it is, and how it fits with the SDK, is in the [root README](../README.md); this page is
for working on the service.

## Run it

```sh
cp .env.example .env
composer install                                       # needs no secret: package discovery runs as testing
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'      # into HRA_JWT_SECRET: every artisan command below needs it
php artisan key:generate
docker compose up -d postgres redis                    # PostgreSQL on 55432, Redis on 56379
php artisan migrate
php artisan db:seed --class=LlmModelSeeder
php artisan hra:bootstrap-account "My account"         # the first secret key, shown once
php artisan serve --port=8100
php artisan queue:work                                 # a second shell
php artisan schedule:work                              # a third
```

Every setting is in [`.env.example`](.env.example) and described in [docs/railway.md](../docs/railway.md).

## Commands

| Command | Does |
|---|---|
| `hra:bootstrap-account {name}` | Creates an account and prints its first secret key, once. |
| `hra:issue-key {account_id}` | Issues another key for an account (`--mode=test`, `--scopes=a,b`). |
| `hra:conversations:sweep` | Ends conversations past their maximum duration or never connected. Scheduled every minute. |
| `hra:conversations:poll` | Asks providers that send no callbacks whether their sessions ended. Scheduled. |
| `hra:relay` | Queues again the webhook deliveries and provider events whose job was lost (a push that failed after the commit, a worker killed between two writes). Takes a row only once it has been stale for a few minutes, claims it under a lease so two runs never queue it twice. Scheduled every minute. |

## Where things are

| Folder | Holds |
|---|---|
| `app/AvatarProviders/` | The provider contract and one folder per adapter (`Tavus/`, `LiveAvatar/`, `Mock/`), plus the registry. |
| `app/Conversations/` | The state machine, connect, event ingestion, transcript merge, provider event ingestion. |
| `app/Keys/`, `app/Accounts/` | Keys, client secrets, the account scope. |
| `app/Webhooks/Outbound/` | Signing, vetting destinations, dispatch. |
| `app/Http/` | Controllers, form requests, resources, middleware, the Problem+JSON renderer, OpenAPI documentation. |
| `app/Jobs/` | The queued work. |
| `config/hr-avatar.php` | The product's own settings and the provider registry. |

To add a provider: write an adapter that implements `AvatarProvider`, and add one entry to `providers` in
`config/hr-avatar.php` (and to `credential_providers` / `platform_keys` when it takes a key). Nothing else may name
the provider; a test fails if it does.

## Tests and gates

```sh
php artisan test                                  # needs PostgreSQL (hra_test); DB_PORT overrides the port
php artisan test --parallel
php -d memory_limit=1G artisan test --coverage --min=90
vendor/bin/pest -c phpunit.high-integrity.xml --coverage --min=95    # keys, webhooks, state machine
vendor/bin/pint --test
composer run analyse
php artisan scramble:export --path=openapi.json   # then commit openapi.json
```

A failing test comes before every behaviour. The architecture tests (`tests/Arch/`) hold the rules: no vocabulary
of a host product, no provider named outside its adapter, every model scoped to an account, and the documents
under `docs/` clean of host vocabulary.

Every outbound HTTP call in a test is faked (`Http::preventStrayRequests()`); a stray request fails the test.
