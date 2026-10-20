# Deploying on Railway

The service ships as one Docker image that runs as three Railway services. Their settings live in three
files next to the Dockerfile (config as code). They are **never applied from this repository**: no workflow,
script or command here talks to Railway, and a deployment is a person's decision, made in Railway.

> **Config as code is deprecated by Railway.** Railway's reference says files like these keep working for
> existing services until **2026-12-01** and recommends its Infrastructure as Code format after that. The
> three files below are the contract for this repository; moving to the newer format is a later change.

## Topology

| Service | Runs | Config file | Public |
|---|---|---|---|
| `api` | the HTTP API (nginx and php-fpm, port 8000) | `service/railway.json` | yes |
| `worker` | `php artisan queue:work` (webhook deliveries, transcript reconciliation, provider releases, config sync) | `service/railway.worker.json` | no |
| `scheduler` | `php artisan schedule:work` (conversation sweeper every minute, provider poller, relay of lost jobs) | `service/railway.scheduler.json` | no |
| PostgreSQL | the database (17) | none | no |
| Redis | cache and queue (8) | none | no |

All three application services build the same `service/Dockerfile`. Create each from this repository with
**Root Directory** `service` and set **Railway Config File** to the absolute path of its file, which starts at
the repository root and does not follow the root directory:

- `api`: `/service/railway.json`
- `worker`: `/service/railway.worker.json`
- `scheduler`: `/service/railway.scheduler.json`

What each file sets:

- `railway.json` (`api`): builds the Dockerfile; before a new version takes traffic it runs, in this order,
  `php artisan migrate --force` and `php artisan db:seed --class=LlmModelSeeder --force` (the seeder is
  idempotent: it restores the language-model catalogue on every deploy); it is healthy when `GET /v1/health`
  answers (liveness: it touches neither the database nor Redis); it restarts on failure, at most ten times.
- `railway.worker.json`: the start command `php artisan queue:work`, restart always. It runs no migration (the
  `api` does, once per deploy) and has no HTTP healthcheck, because it serves no HTTP.
- `railway.scheduler.json`: the start command `php artisan schedule:work`, restart always, **exactly one
  replica**. Two schedulers would run every sweep twice; the sweep is safe to repeat (the first writer of an
  ending wins), but it is wasted work.

Scale the `worker` by adding replicas; never the `scheduler`.

## Variables

Set them once as shared variables of the Railway environment and attach all of them to all three services; the
process that needs a setting differs from the one that sets it (a release is queued by the `api`, the sweeper
and the worker alike). Reference variables (`${{Postgres.DATABASE_URL}}`) keep the connection strings in step
with the databases.

`Required` means the service does not start, or does not work, without it.

| Variable | Required | Services | Value and purpose |
|---|---|---|---|
| `APP_KEY` | required | all | `php artisan key:generate --show`. Encrypts stored provider, language-model and webhook secrets: losing it makes them unreadable, so keep it. |
| `APP_ENV` | required | all | `production`. |
| `APP_DEBUG` | required | all | `false`. |
| `LOG_CHANNEL` | required | all | `stderr`, so Railway collects the logs. |
| `DB_CONNECTION` | required | all | `pgsql`. |
| `DB_URL` | required | all | `${{Postgres.DATABASE_URL}}`. The connection string of the PostgreSQL service. |
| `REDIS_CLIENT` | required | all | `phpredis`. |
| `REDIS_URL` | required | all | `${{Redis.REDIS_URL}}`. The connection string of the Redis service. |
| `CACHE_STORE` | required | all | `redis`. |
| `QUEUE_CONNECTION` | required | all | `redis`. |
| `HRA_JWT_SECRET` | required | all | At least 32 random bytes (`php -r 'echo bin2hex(random_bytes(32));'`). Signs the per-conversation client secret. A shorter value stops the process at boot. |
| `HRA_PUBLIC_BASE_URL` | required | all | The address the `api` is reached at from the internet, for example `https://hra.example.com`, with no path. A provider that reports by callback is given an address under it, so it must be reachable by the provider. Unset or not an http(s) address, `GET /v1/health/ready` fails (503, `public_base_url`) and connecting a conversation of such a provider answers 503 before changing anything. |
| `HRA_TRUSTED_PROXIES` | optional | api | `*` on Railway, where the service is reachable only through Railway's proxy. Without it the proxy's address stands for every caller, so one caller's failed authentications use up everyone's budget. Elsewhere list the proxies' addresses, comma separated. |
| `HRA_RATE_LIMIT_SECRET` | optional | api | Requests per minute per secret key. Default 600; a whole number of at least 1 (an empty, zero or non-numeric value makes the application refuse to start). |
| `HRA_RATE_LIMIT_PUBLISHABLE` | optional | api | Requests per minute per publishable key. Default 300; at least 1, enforced at boot. |
| `HRA_RATE_LIMIT_PREVIEW` | optional | api | Voice previews per minute per key, each of which is a paid synthesis. Default 10; at least 1, enforced at boot. |
| `HRA_RELEASE_DELAY_SECONDS` | optional | all | How long after a conversation ends its provider session is released. Default 20; at least 5 seconds, enforced at boot, so a release never fires before the browser has closed the call. |
| `HRA_MAX_DURATION_CEILING` | optional | api | The longest `max_duration_seconds` a conversation may ask for. Default 3600; at least 30, the shortest conversation, enforced at boot. |
| `TAVUS_API_KEY` | optional | api, worker | A platform-wide Tavus key, used for an account that stored none of its own. An account may always store its own key, so leave it unset to make every account bring one. |
| `HEYGEN_API_KEY` | optional | api, worker | The same for HeyGen LiveAvatar. |
| `CARTESIA_API_KEY` | optional | api | The same for Cartesia voices (catalogue and preview). |
| `ELEVENLABS_API_KEY` | optional | api | The same for ElevenLabs voices (catalogue and preview). |

A provider with no platform key and no key stored by the account is simply not configured for that account:
creating a conversation on it answers `422 provider_credential_missing`.

## First deployment, in order

1. Add the PostgreSQL and Redis services.
2. Create `api`, `worker` and `scheduler` with the root directory and config file path above.
3. Set the variables; generate `APP_KEY` and `HRA_JWT_SECRET` once and keep them.
4. Deploy the `api` first: its pre-deploy command creates the schema and the language-model catalogue. Then
   deploy the `worker` and the `scheduler`.
5. Create the first account and its key from a shell attached to the `api` service:
   `php artisan hra:bootstrap-account "Name"` prints the secret key once. See `docs/api-usage.md`.
6. Check `GET /v1/health/ready` answers 200 with every check `ok`.

## Why these choices

- **Migrations run in the pre-deploy command, not at boot.** An entrypoint runs once per replica and would race;
  the pre-deploy step runs once per deploy, in a separate container, and a failure stops the rollout.
- **The healthcheck is liveness.** Readiness also needs the database and Redis and a usable public base url;
  tying a rollout to it would stop a deploy for a dependency's bad minute. Monitor `/v1/health/ready` instead.
- **One image, three commands.** What runs locally is what runs in production; each service only passes its own
  start command.
