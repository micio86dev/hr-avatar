# API usage

A whole conversation, end to end, with curl and with the browser SDK. Everything here runs against the built-in
**mock** provider, so it needs no provider account; the last sections say what changes for a real one.

The API is versioned under `/v1` and speaks JSON. The reference for every operation, with its request and
response shapes, is the generated OpenAPI document, [`service/openapi.json`](../service/openapi.json) (with the
Problem body and the common 401, 404, 422 and 429 answers documented once). This page is the guide to it.

Set these once in your shell; the examples use them. `HRA_URL` is where the service runs (the README's local
setup serves it on port 8100):

```sh
export HRA_URL=http://localhost:8100
export HRA_SECRET_KEY=hra_sk_live_xxxxxxxx      # from step 1
```

## Who calls what

| Caller | Credential | Calls |
|---|---|---|
| Your back end | secret key, `Authorization: Bearer hra_sk_…` | everything under `/v1` except `/v1/client/…` |
| The browser | publishable key `X-HRA-Publishable-Key: hra_pk_…` **and** the conversation's `client_secret` as `Authorization: Bearer …`, from an allowed `Origin` | `/v1/client/conversations/{id}/connect`, `events`, `end` |
| The avatar provider | the token in its callback address | `/v1/webhooks/providers/{provider}/{token}` (not for you to call) |

Never put a key in a query string: `?api_key=` and `?key=` are refused with `400 api_key_in_query`.

## 1. Create the account and its first key

The first key cannot come from the API, because the API needs a key. Create it on the server:

```sh
php artisan hra:bootstrap-account "My account"
```

It prints the account id and a secret key with every scope (`*`), **once**. Store it now; only its hash is kept.
`php artisan hra:issue-key <account_id>` issues another one later (`--mode=test`, `--scopes=conversations:write`).

## 2. Keys and scopes

Everything after the first key is the API's job. A **secret key** has explicit scopes; a **publishable key** has
none and is safe to ship to a browser, because it works only from the origins you list, and only together with a
conversation's client secret.

| Scope | Allows |
|---|---|
| `*` | everything |
| `api_keys:manage` | keys |
| `credentials:manage` | provider and language-model credentials |
| `avatar_configs:read`, `avatar_configs:write` | avatar configs, the catalogue, voice previews |
| `llm_models:read` | the language-model list |
| `conversations:read`, `conversations:write` | conversations and transcripts |
| `webhook_endpoints:manage` | webhook endpoints and their deliveries |

Create a publishable key for your front end (1 to 20 origins, `https` except `localhost`, no wildcards):

```sh
curl -sS -X POST "$HRA_URL/v1/api-keys" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"type":"publishable","mode":"live","allowed_origins":["https://app.example.com"]}'
```

The answer holds the new key in `key`, **once**, and its non-secret `key_prefix` for recognising it later:

```json
{ "id": "…", "type": "publishable", "mode": "live", "key_prefix": "hra_pk_live_bd0f2af1",
  "scopes": [], "allowed_origins": ["https://app.example.com"], "key": "hra_pk_live_xxxxxxxx", "…": "…" }
```

```sh
export HRA_PUBLISHABLE_KEY=hra_pk_live_xxxxxxxx
```

A secret key for a single job takes only the scopes it needs, and you cannot hand out a scope you do not hold:

```sh
curl -sS -X POST "$HRA_URL/v1/api-keys" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"type":"secret","mode":"live","scopes":["conversations:read"]}'
```

List keys with `GET /v1/api-keys`. Rotate one with `POST /v1/api-keys/{id}/rotate` (the old key keeps working for
`overlap_seconds`, an hour by default, so you can switch without a gap) and revoke one with
`POST /v1/api-keys/{id}/revoke`. A revoked key stops working at once.

`live` and `test` are labels that keep the two key spaces apart; they do not separate data.

## 3. Store a provider credential

The mock provider needs none. A real provider needs an API key, which an account stores for itself (or the
platform supplies for every account that stored none; see [Railway](railway.md)). The key is encrypted, never
returned, and only its last four characters are ever shown:

```sh
curl -sS -X PUT "$HRA_URL/v1/provider-credentials/tavus" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"api_key":"your-tavus-key"}'
```

```json
{ "provider": "tavus", "source": "account", "last_four": "1234", "updated_at": "2026-10-19T22:23:39Z" }
```

`GET /v1/provider-credentials` shows, for each provider, whether the account has its own key (`account`), the
platform's (`platform`) or none. A language model for the avatar's replies has its own credential:
`PUT /v1/llm-credentials/{llm_provider}`, with the models listed by `GET /v1/llm-models`.

## 4. Create an avatar config

A config is a named, reusable avatar setup. `external_ref` is yours to use to find it again (filter the list with
`GET /v1/avatar-configs?external_ref=…`); the service never interprets it.

```sh
curl -sS -X POST "$HRA_URL/v1/avatar-configs" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"name":"Demo avatar","provider":"mock","config":{}}'
```

```sh
export AVATAR_CONFIG_ID=avc_xxxxxxxx    # the id in the answer
```

For a real provider, `GET /v1/catalogue` lists what each provider's config accepts and
`GET /v1/catalogue/{provider}/{resource}` lists what the account can pick (avatars, voices, personas). A provider
that needs objects created on its side marks the config `sync_status: "pending"`; it syncs in the background, or
on demand with `POST /v1/avatar-configs/{id}/sync`, and a conversation cannot be created until it is `synced`
(`409 config_not_synced`). The mock provider needs no sync.

## 5. Create a conversation

```sh
curl -sS -X POST "$HRA_URL/v1/conversations" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d "{\"avatar_config_id\":\"$AVATAR_CONFIG_ID\",\"system_prompt\":\"You are a friendly guide.\",\"language\":\"en\",\"opening_text\":\"Hello!\",\"external_ref\":\"order-1042\",\"metadata\":{\"plan\":\"trial\"}}"
```

`system_prompt` and `language` (two lower-case letters) are required. `max_duration_seconds` runs from 30 seconds up
to the platform ceiling (1800 by default). `external_ref` and `metadata` (a JSON object up to 8 KB) come back as
you sent them. No provider is called yet.

```json
{ "id": "conv_…", "status": "created", "max_duration_seconds": 1800, "external_ref": "order-1042",
  "transcript_status": "pending",
  "client_secret": "eyJ0eXAi…", "client_secret_expires_at": "2026-10-19T23:07:56Z", "…": "…" }
```

Hand `client_secret` to the browser, and nothing else secret. It is bound to this one conversation and is the only
thing besides your publishable key that the browser needs. The browser must connect within 15 minutes, or the
conversation expires.

```sh
export CONVERSATION_ID=conv_xxxxxxxx
export CLIENT_SECRET=eyJ0eXAi…
```

## 6. Connect from the browser

With the SDK this is one call (see [SDK usage](sdk-usage.md) for everything it can do):

```js
import { HrAvatar } from '@hr-avatar/sdk'

const conversation = await HrAvatar.connect({
  baseUrl: 'https://hra.example.com',          // the service, without /v1
  publishableKey: 'hra_pk_live_xxxxxxxx',
  clientSecret,                                // from your back end
  mount: document.getElementById('avatar'),    // a <video>, or a container that gets one
})

conversation.on('transcript', (line) => console.log(line.role, line.text))
conversation.on('ended', ({ reason }) => console.log('ended', reason))
```

The SDK makes the three browser calls below for you: it connects, forwards what is said, and ends the conversation.
This is the same sequence by hand, which is also how to debug it. Browser calls need an `Origin` on the key's list:

```sh
curl -sS -X POST "$HRA_URL/v1/client/conversations/$CONVERSATION_ID/connect" \
  -H "X-HRA-Publishable-Key: $HRA_PUBLISHABLE_KEY" -H "Authorization: Bearer $CLIENT_SECRET" \
  -H "Origin: https://app.example.com"
```

The service starts the provider session now, and answers with what the SDK needs to join it:

```json
{ "provider": "mock", "transport": "mock", "conversation_url": "mock://conversation/conv_…",
  "session_token": "mock_token_conv_…", "expires_at": "2026-10-19T23:23:04Z" }
```

It can succeed once: a second connect is `409 already_connected`.

Send what happens, in order, up to 100 events at a time. Each has an `idempotency_key` of yours, so a retried batch
is safe (`duplicates` counts what was stored before). A `live` lifecycle event marks the conversation `live`:

```sh
curl -sS -X POST "$HRA_URL/v1/client/conversations/$CONVERSATION_ID/events" \
  -H "X-HRA-Publishable-Key: $HRA_PUBLISHABLE_KEY" -H "Authorization: Bearer $CLIENT_SECRET" \
  -H "Origin: https://app.example.com" -H "Content-Type: application/json" \
  -d '{"events":[{"idempotency_key":"evt-1","type":"lifecycle","name":"live"},{"idempotency_key":"evt-2","type":"utterance","role":"user","text":"Hi there","occurred_at":"2026-10-19T22:23:10.000Z"}]}'
```

```json
{ "accepted": 2, "duplicates": 0 }
```

End it from the browser, or from your back end (next step):

```sh
curl -sS -X POST "$HRA_URL/v1/client/conversations/$CONVERSATION_ID/end" \
  -H "X-HRA-Publishable-Key: $HRA_PUBLISHABLE_KEY" -H "Authorization: Bearer $CLIENT_SECRET" \
  -H "Origin: https://app.example.com"
```

## 7. Read and end a conversation from your back end

```sh
curl -sS -X GET "$HRA_URL/v1/conversations/$CONVERSATION_ID" -H "Authorization: Bearer $HRA_SECRET_KEY"
```

`status` is `created`, `connecting`, `live`, `ended` or `failed`. When it is over, `ended_reason` says why:
`client`, `api`, `max_duration`, `expired`, `provider`, `provider_error` or `provider_capacity`. Ending a conversation
that is already over answers 200 with it unchanged, so it is safe to call twice:

```sh
curl -sS -X POST "$HRA_URL/v1/conversations/$CONVERSATION_ID/end" -H "Authorization: Bearer $HRA_SECRET_KEY"
```

A conversation nobody ended is ended for you: 30 seconds after its maximum duration, or after 15 minutes if it was
never connected.

## 8. Read the transcript

```sh
curl -sS -X GET "$HRA_URL/v1/conversations/$CONVERSATION_ID/transcript" -H "Authorization: Bearer $HRA_SECRET_KEY"
```

```json
{ "conversation_id": "conv_…", "status": "ready",
  "utterances": [
    { "sequence": 2, "role": "user", "text": "Hi there", "occurred_at": "2026-10-19T22:23:10.000Z", "source": "client" },
    { "sequence": 3, "role": "avatar", "text": "Hello!", "occurred_at": null, "source": "provider" } ] }
```

The transcript merges what the browser sent with what the provider recorded. `status` is `pending` until the
provider's side has been merged in, `ready` once it has, and `partial` if the provider could not deliver it (you
then have the browser's lines only). The `transcript.ready` webhook tells you when it stops being `pending`. A
conversation that was never connected has no transcript (`409 invalid_state`).

## 9. Webhooks

Register an endpoint and the service tells you when something happens, instead of you polling:

```sh
curl -sS -X POST "$HRA_URL/v1/webhook-endpoints" \
  -H "Authorization: Bearer $HRA_SECRET_KEY" -H "Content-Type: application/json" \
  -d '{"url":"https://receiver.example.com/hooks/hra","events":["conversation.ended","transcript.ready"]}'
```

The answer holds the endpoint's signing `secret` (`hra_whsec_…`), **once**. Keep it in your receiver's
configuration. Events are `conversation.live`, `conversation.ended` and `transcript.ready` (all three when you
leave `events` out). Each delivery is a POST of this body:

```json
{ "id": "evt_…", "type": "conversation.ended", "created_at": "2026-10-19T22:23:05Z",
  "data": { "conversation": { "id": "conv_…", "status": "ended", "external_ref": "order-1042", "ended_reason": "api", "…": "…" } } }
```

`transcript.ready` adds `transcript: { status, utterance_count }` (the text itself is read from the API). A delivery
that fails (any answer outside 2xx, a timeout of 10 seconds, or an unreachable host) is retried with backoff.
`GET /v1/webhook-endpoints/{id}/deliveries` shows each attempt's status without any secret.

### Verify a webhook

Every delivery carries four headers:

| Header | Holds |
|---|---|
| `X-HRA-Signature` | `v1=` and the lower-case hex HMAC-SHA256 of `{timestamp}.{body}`, keyed with the endpoint's secret |
| `X-HRA-Timestamp` | Unix seconds when this attempt was signed |
| `X-HRA-Event-Id` | `evt_…`, the same for every attempt of the same event |
| `X-HRA-Event-Type` | the event type |

A receiver does four things, in this order:

1. Take the **raw** body bytes, before any JSON parsing.
2. Refuse a timestamp more than **300 seconds** from your clock, in either direction. Each attempt is signed afresh,
   so a retry is never "too old", but a recorded delivery replayed later is.
3. Compute `v1=` + HMAC-SHA256 hex of `"{timestamp}.{body}"` with the secret, and compare it with the header in
   **constant-time** (`hash_equals` in PHP, `crypto.timingSafeEqual` in Node). Never compare with `==`.
4. **Dedupe** on `X-HRA-Event-Id`: an event can arrive more than once (a retry after your answer was lost). Store
   the ids you handled and ignore a repeat, but still answer 2xx.

```php
$secret    = getenv('HRA_WEBHOOK_SECRET');                 // hra_whsec_…
$body      = file_get_contents('php://input');             // raw bytes
$timestamp = (int) ($_SERVER['HTTP_X_HRA_TIMESTAMP'] ?? 0);
$header    = $_SERVER['HTTP_X_HRA_SIGNATURE'] ?? '';

if (abs(time() - $timestamp) > 300) { http_response_code(400); exit; }

$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
if (! hash_equals($expected, $header)) { http_response_code(400); exit; }

// Verified. Dedupe on $_SERVER['HTTP_X_HRA_EVENT_ID'], then handle json_decode($body, true).
http_response_code(204);
```

```js
import { createHmac, timingSafeEqual } from 'node:crypto'

// `body` is the raw request body as a string or Buffer; `headers` are lower-cased.
export function verify(body, headers, secret, now = Date.now() / 1000) {
  const timestamp = Number(headers['x-hra-timestamp'])
  if (!Number.isFinite(timestamp) || Math.abs(now - timestamp) > 300) return false

  const expected = Buffer.from('v1=' + createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex'))
  const given = Buffer.from(String(headers['x-hra-signature'] ?? ''))
  return given.length === expected.length && timingSafeEqual(given, expected)
}
```

Disabling or deleting an endpoint stops its retries.

## 10. Errors, pages and limits

Every error is [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) **Problem** JSON, with a stable `code` you can
branch on (never translated), a `detail` for people, and a `request_id` that is also the `X-Request-Id` response
header. Quote it when you ask about a request; send your own `X-Request-Id` (letters, digits, `.`, `_`, `-`, up to
64) to correlate one.

```json
{ "type": "about:blank", "title": "Unprocessable Content", "status": 422, "detail": "The request failed validation.",
  "code": "validation_failed", "request_id": "01M5B46RRY…",
  "errors": { "language": ["The language field format is invalid."] } }
```

| Status | Codes |
|---|---|
| 401 | `unauthenticated`, `key_expired`, `key_revoked`, `client_secret_invalid`, `client_secret_expired` |
| 403 | `insufficient_scope`, `forbidden`, `origin_not_allowed`, `secret_key_origin_forbidden` |
| 404 | `not_found` (also for another account's resource) |
| 409 | `already_connected`, `invalid_state`, `conversation_closed`, `config_not_synced`, `config_in_use` |
| 413 | `payload_too_large` |
| 422 | `validation_failed`, `provider_credential_missing`, `llm_credential_missing` |
| 429 | `rate_limited`, `provider_concurrency_limited` |
| 502 / 503 | `provider_error` / `service_unavailable` |

A secret key has a budget of 600 requests a minute, a publishable key 300; a voice preview is also limited to 10 a
minute. Over a budget the answer is `429 rate_limited` with `Retry-After` (seconds) and `X-RateLimit-*` headers.
Repeated bad credentials from one address are slowed down too.

Lists that grow (keys, avatar configs, webhook endpoints, deliveries) take `limit` (1 to 100, 25 by default) and an
opaque `cursor`, and answer `{ "data": […], "next_cursor": "…" }`; pass `next_cursor` back as `cursor` until it
is `null`.

## Real providers

Everything above is the same for a real provider; only the config and the credentials differ:

- Store the provider's key (step 3), pick its settings from `GET /v1/catalogue` and create the config (step 4); wait
  for `sync_status` to be `synced`.
- Connecting a provider that calls back (Tavus) needs the service's `HRA_PUBLIC_BASE_URL` to be a public address; see
  [Railway](railway.md).
- The `transport` in the connect answer tells the SDK which provider library to load (`daily` for Tavus,
  `liveavatar` for HeyGen LiveAvatar). Both are optional packages the SDK imports on demand; see
  [SDK usage](sdk-usage.md#the-script-tag-build-and-provider-libraries).
