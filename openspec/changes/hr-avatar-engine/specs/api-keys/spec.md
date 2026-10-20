# API Keys Specification

## Purpose

Accounts, the independent key space (secret and publishable keys), scopes, origin rules, rotation,
revocation, rate limiting, the per-conversation `client_secret`, and the bootstrap commands.
There is no admin UI: accounts and first keys are created from the command line.

## ADDED Requirements

### Requirement: Account bootstrap and key issuing commands

The service MUST provide `php artisan hra:bootstrap-account {name} {--mode=live}` which
creates an account and its first secret key with scope `*`, prints the full key exactly once on
standard output, and exits 0. It MUST also provide `php artisan hra:issue-key {account_id}
{--mode=live} {--scopes=*}` to issue an additional secret key for an existing account (lockout
recovery). Neither command MUST write the full key to any log or database column.

#### Scenario: Bootstrap creates account and key

- GIVEN an empty database
- WHEN `hra:bootstrap-account Acme` runs
- THEN one account named `Acme` and one `api_keys` row of type `secret`, mode `live`, scopes `["*"]` exist, and the output contains a key matching `^hra_sk_live_[0-9a-f]{96}$`

#### Scenario: Key is not recoverable

- GIVEN a bootstrapped account
- WHEN every column of `api_keys` is searched for the printed key
- THEN no column contains it (only its sha256 hash and a prefix are stored)

#### Scenario: Test mode key

- GIVEN `--mode=test`
- WHEN the command runs
- THEN the printed key matches `^hra_sk_test_[0-9a-f]{96}$`

#### Scenario: Unknown account on issue-key

- GIVEN a nonexistent account id
- WHEN `hra:issue-key` runs
- THEN it exits non-zero and creates no key

### Requirement: Key formats and storage

Secret keys MUST be `hra_sk_{live|test}_` followed by 96 lowercase hex characters (48 random
bytes). Publishable keys MUST be `hra_pk_{live|test}_` followed by 48 lowercase hex characters.
Only the sha256 hash (`key_hash`) and an indexed `key_prefix` (the type/mode prefix plus the first
8 hex characters) MUST be stored. Verification MUST look the key up by prefix and compare hashes
with `hash_equals`. A key MUST never be logged and MUST never be returned by any endpoint after the
creation or rotation response.

#### Scenario: Valid key authenticates

- GIVEN an active secret key
- WHEN a request presents it as `Authorization: Bearer <key>`
- THEN the request is attributed to the key's account

#### Scenario: Same prefix, wrong remainder

- GIVEN an active key and a presented key with the same 8-hex prefix but a different remainder
- WHEN the request is made
- THEN status is 401 and `code` is `unauthenticated`

#### Scenario: Malformed key

- GIVEN a bearer token `hra_sk_prod_abc`
- WHEN the request is made
- THEN status is 401 and `code` is `unauthenticated` and no database lookup by hash occurs

#### Scenario: Missing credentials

- GIVEN no `Authorization` header on an SK endpoint
- WHEN the request is made
- THEN status is 401 and `code` is `unauthenticated`

### Requirement: Key lifecycle states

An expired key (`expires_at` in the past) MUST be rejected with 401 `key_expired`; a revoked key
(`revoked_at` set) with 401 `key_revoked`. These specific codes MUST only be returned after the
presented key matched the stored hash; an unknown or wrong key MUST always yield `unauthenticated`.
A successful authentication MUST update `last_used_at` (the write MAY be throttled to at most once
per 60 seconds per key).

#### Scenario: Expired key

- GIVEN a key whose `expires_at` is one second in the past
- WHEN it is used
- THEN status is 401 and `code` is `key_expired`

#### Scenario: Revoked key

- GIVEN a key with `revoked_at` set
- WHEN it is used
- THEN status is 401 and `code` is `key_revoked`

#### Scenario: Unknown key reveals nothing

- GIVEN a well-formed key that does not exist
- WHEN it is used
- THEN `code` is `unauthenticated` (not `key_expired` or `key_revoked`)

#### Scenario: last_used_at updated

- GIVEN a key with `last_used_at` null
- WHEN it authenticates a request
- THEN `last_used_at` is set to the current time

### Requirement: Scopes

Each secret key MUST carry a `scopes` array. Known scopes: `*`, `avatar_configs:read`,
`avatar_configs:write`, `credentials:manage`, `llm_models:read`, `conversations:read`,
`conversations:write`, `webhook_endpoints:manage`, `api_keys:manage`. An endpoint MUST require its
scope (documented per endpoint) and `*` satisfies every scope. A missing scope MUST yield 403
`insufficient_scope`. Publishable keys MUST carry an empty scope list. A key MUST NOT be able to
create or rotate into a key with a scope it does not itself hold (unless it holds `*`).

#### Scenario: Missing scope

- GIVEN a secret key with scopes `["conversations:read"]`
- WHEN it calls `POST /v1/conversations`
- THEN status is 403 and `code` is `insufficient_scope`

#### Scenario: Wildcard

- GIVEN a secret key with scopes `["*"]`
- WHEN it calls any SK endpoint with valid input
- THEN the scope check passes

#### Scenario: No privilege escalation

- GIVEN a key with scopes `["api_keys:manage","conversations:read"]`
- WHEN it creates a key with scopes `["conversations:write"]`
- THEN status is 403 and `code` is `insufficient_scope` and no key is created

#### Scenario: Unknown scope

- GIVEN a create-key request with scope `"bogus:scope"`
- WHEN it is validated
- THEN status is 422 and `code` is `validation_failed`

### Requirement: Key mode is a key-space label only

The `mode` (`live` or `test`) MUST only distinguish the key space and its prefix. Test keys MUST NOT
alter provider selection, MUST NOT force the Mock provider, and MUST NOT partition data: both modes
read and write the same account's resources. A key MUST only be able to create keys of its own
mode. The Mock provider MUST be selected solely by an avatar config whose `provider` is `mock`.

#### Scenario: Test key does not force Mock

- GIVEN a test-mode secret key and an avatar config with provider `tavus`
- WHEN a conversation is created and connected
- THEN the Tavus adapter is used, not the Mock adapter

#### Scenario: Live key with Mock config

- GIVEN a live-mode secret key and an avatar config with provider `mock`
- WHEN a conversation is created and connected
- THEN the Mock adapter is used

#### Scenario: Cross-mode issuance refused

- GIVEN a test-mode secret key with `api_keys:manage`
- WHEN it creates a key with `mode` `live`
- THEN status is 403 and `code` is `forbidden`

### Requirement: Secret keys reject browser origins in live mode

A live-mode secret key presented on a request carrying an `Origin` header MUST be rejected with 403
`secret_key_origin_forbidden`, whatever the origin value. A test-mode secret key MAY be used with
an `Origin` header. Requests with no `Origin` header (server-to-server) MUST be unaffected.

#### Scenario: Live secret with Origin

- GIVEN an active live secret key
- WHEN it is used on a request with `Origin: https://app.example.com`
- THEN status is 403 and `code` is `secret_key_origin_forbidden`

#### Scenario: Live secret without Origin

- GIVEN an active live secret key
- WHEN it is used with no `Origin` header
- THEN the request is authenticated

#### Scenario: Test secret with Origin

- GIVEN an active test secret key
- WHEN it is used on a request with an `Origin` header
- THEN the request is authenticated

### Requirement: Key management endpoints

Auth: SK with scope `api_keys:manage`. `GET /v1/api-keys` lists the account's keys (id, type, mode,
`key_prefix`, scopes, allowed_origins, expires_at, revoked_at, last_used_at, rotated_from_id,
created_at; never the hash or the key). `POST /v1/api-keys` creates a key and returns 201 with the
resource plus the full `key` once; body: `type` (`secret|publishable`), `mode`, `scopes` (secret
only), `allowed_origins` (publishable only, required, 1-20 entries), `expires_at` (optional, future).
`POST /v1/api-keys/{id}/revoke` sets `revoked_at` and returns 200; revoking a revoked key is
idempotent.

#### Scenario: Create returns the key once

- GIVEN a SK with `api_keys:manage`
- WHEN it creates a publishable key
- THEN status is 201, the body contains `key` matching `^hra_pk_(live|test)_[0-9a-f]{48}$`, and a subsequent `GET /v1/api-keys` never contains `key` or `key_hash`

#### Scenario: Publishable key needs origins

- GIVEN a create request of type `publishable` with no `allowed_origins`
- WHEN validated
- THEN status is 422 and `errors` names `allowed_origins`

#### Scenario: Secret key must not carry origins

- GIVEN a create request of type `secret` with `allowed_origins`
- WHEN validated
- THEN status is 422

#### Scenario: Revoke is idempotent

- GIVEN a key revoked at T
- WHEN `POST /v1/api-keys/{id}/revoke` is called again
- THEN status is 200 and `revoked_at` is still T

#### Scenario: Another account's key

- GIVEN a key owned by account A
- WHEN account B revokes it
- THEN status is 404 and the key stays active

### Requirement: Key rotation with overlap window

Auth: SK with scope `api_keys:manage`. `POST /v1/api-keys/{id}/rotate` with optional
`overlap_seconds` (integer 0-604800, default 3600) MUST create a new key of the same type, mode,
scopes, allowed_origins and `expires_at` (none when the old key had none; rotation never extends an expiry) with
`rotated_from_id` set to the old key, return 201 with the new full
`key`, and set the old key's `expires_at` to now + `overlap_seconds` (or revoke it immediately when
0). During the overlap both keys authenticate. Rotating a revoked or already-expired key MUST fail
with 409 `invalid_state`.

#### Scenario: Overlap

- GIVEN an active key rotated with `overlap_seconds` 3600
- WHEN the old key is used 10 minutes later
- THEN it still authenticates, and so does the new key

#### Scenario: Old key dies after overlap

- GIVEN the same rotation
- WHEN the old key is used 61 minutes later
- THEN status is 401 and `code` is `key_expired`

#### Scenario: Immediate rotation

- GIVEN `overlap_seconds` 0
- WHEN the key is rotated
- THEN the old key is rejected with `key_revoked` immediately

#### Scenario: Lineage

- GIVEN a rotation
- WHEN the new key is read
- THEN `rotated_from_id` equals the old key id and type, mode, scopes, origins and expiry are copied

#### Scenario: Rotate a revoked key

- GIVEN a revoked key
- WHEN it is rotated
- THEN status is 409 and `code` is `invalid_state`

### Requirement: Publishable key origin allowlist

Allowed origins MUST be stored normalized as `scheme://host[:port]` (lowercase, no path, no
trailing slash); wildcards (`*`) MUST be rejected with 422; `https` MUST be required except for
`http://localhost` and `http://127.0.0.1` (any port). A request authenticated with a publishable
key MUST carry an `Origin` header exactly matching one allowed origin; otherwise it MUST be
rejected with 403 `origin_not_allowed`. For CORS preflight (`OPTIONS`) on `/v1/client/*`, the
service MUST answer 204 and echo the request origin in `Access-Control-Allow-Origin` with the
headers `Authorization, Content-Type, X-HRA-Publishable-Key, X-Request-Id` allowed, because a
preflight carries no key; the actual request enforces the allowlist and MUST NOT emit CORS allow
headers when the origin is rejected.

#### Scenario: Allowed origin

- GIVEN a publishable key allowing `https://app.example.com`
- WHEN a client request carries `Origin: https://app.example.com`
- THEN the origin check passes

#### Scenario: Other origin

- GIVEN the same key
- WHEN a request carries `Origin: https://evil.example.com`
- THEN status is 403, `code` is `origin_not_allowed`, and no `Access-Control-Allow-Origin` header is present

#### Scenario: Missing Origin

- GIVEN a publishable key
- WHEN a request carries no `Origin` header
- THEN status is 403 and `code` is `origin_not_allowed`

#### Scenario: Wildcard rejected

- GIVEN a create request with `allowed_origins: ["*"]` or `["https://*.example.com"]`
- WHEN validated
- THEN status is 422

#### Scenario: Normalization

- GIVEN an allowed origin submitted as `HTTPS://App.Example.com/`
- WHEN stored
- THEN it is stored as `https://app.example.com`

#### Scenario: Plain http refused except loopback

- GIVEN allowed origins `http://app.example.com` and `http://localhost:3000`
- WHEN validated
- THEN the first is rejected with 422 and the second is accepted

### Requirement: Publishable key grants nothing alone

A publishable key MUST NOT authorize any `/v1` endpoint by itself. Client endpoints MUST require
both `X-HRA-Publishable-Key` and a valid `client_secret` bearer. A publishable key presented on an
SK endpoint, or a secret key presented as `X-HRA-Publishable-Key`, MUST be rejected with 401
`unauthenticated`.

#### Scenario: Publishable alone

- GIVEN a valid publishable key and an allowed origin, no bearer
- WHEN `POST /v1/client/conversations/{id}/connect` is called
- THEN status is 401 and `code` is `unauthenticated`

#### Scenario: Publishable key on SK endpoint

- GIVEN a valid publishable key sent as bearer
- WHEN `GET /v1/avatar-configs` is called
- THEN status is 401 and `code` is `unauthenticated`

#### Scenario: Secret key in the publishable header

- GIVEN a valid secret key sent in `X-HRA-Publishable-Key`
- WHEN a client endpoint is called
- THEN status is 401 and `code` is `unauthenticated`

### Requirement: Per-conversation client_secret

Auth to mint: created by `POST /v1/conversations` (SK). The `client_secret` MUST be a JWT signed
HS256 with `HRA_JWT_SECRET` carrying `iss` `hr-avatar`, `aud` `hr-avatar-client`, `sub` (the
conversation id), `acc` (the account id), `jti`, `iat` and `exp`. `exp` MUST equal creation time +
900 seconds + the conversation's `max_duration_seconds`. Verification MUST accept only HS256 (never
`none` or another algorithm), MUST check `iss`, `aud` and `exp`, and MUST require that the
conversation id in the URL equals `sub`, the publishable key's account equals `acc`, and the
conversation exists and is owned by that account. A token valid for another conversation MUST be
rejected with 403 `forbidden`; a bad signature or malformed token with 401 `client_secret_invalid`;
an expired token with 401 `client_secret_expired`. The client_secret MUST be returned only in the
creation response and MUST NOT be retrievable afterwards. Connect (see `conversations`) is
single-use per client_secret.

#### Scenario: Valid token

- GIVEN a client_secret for conversation `C` and a publishable key of the same account with an allowed origin
- WHEN it is used on a client endpoint for `C`
- THEN the request is authorized

#### Scenario: Token for another conversation

- GIVEN a client_secret for `C1`
- WHEN it is used on a client endpoint for `C2` of the same account
- THEN status is 403 and `code` is `forbidden`

#### Scenario: Publishable key of another account

- GIVEN a client_secret for a conversation of account A and a publishable key of account B
- WHEN used together on a client endpoint
- THEN status is 403 and `code` is `forbidden`

#### Scenario: Algorithm none

- GIVEN a token with header `alg: none` and valid-looking claims
- WHEN used
- THEN status is 401 and `code` is `client_secret_invalid`

#### Scenario: Tampered claims

- GIVEN a token whose payload `sub` was edited without re-signing
- WHEN used
- THEN status is 401 and `code` is `client_secret_invalid`

#### Scenario: Expired token

- GIVEN a token past its `exp`
- WHEN used
- THEN status is 401 and `code` is `client_secret_expired`

#### Scenario: Not retrievable

- GIVEN a created conversation
- WHEN `GET /v1/conversations/{id}` is called
- THEN the body contains no `client_secret`

### Requirement: Per-key rate limiting

Every authenticated request MUST be counted against its key: secret keys default to 600 requests
per minute, publishable keys to 300 per minute per key (configurable via `HRA_RATE_LIMIT_SECRET`,
`HRA_RATE_LIMIT_PUBLISHABLE`). Over the limit the service MUST answer 429 `rate_limited` with a
`Retry-After` header; successful responses MUST carry `X-RateLimit-Limit` and
`X-RateLimit-Remaining`. Failed authentication attempts MUST be limited per client IP (30 per
minute) and answered 429 `rate_limited` beyond that.

#### Scenario: Limit exceeded

- GIVEN a secret key with a limit of 3 per minute (test config)
- WHEN a fourth request is made within the minute
- THEN status is 429, `code` is `rate_limited`, and `Retry-After` is a positive integer

#### Scenario: Separate budgets

- GIVEN two different keys of the same account
- WHEN one exhausts its limit
- THEN the other is unaffected

#### Scenario: Auth failure flood

- GIVEN 31 requests with invalid keys from one IP within a minute
- WHEN the 31st is made
- THEN status is 429 and `code` is `rate_limited`

### Requirement: Secret and key serialization exposure

No API resource, log line, Problem+JSON body or OpenAPI example MUST contain a full key, a key
hash, or the `HRA_JWT_SECRET`. A serialization-exposure test MUST assert this for every key
resource.

#### Scenario: Resource shape

- GIVEN an `api_keys` resource serialized for list, revoke and rotate responses
- WHEN its members are inspected
- THEN `key_hash` is never present and `key` is present only in create and rotate responses
