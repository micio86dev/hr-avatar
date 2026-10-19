# Avatar Configs Specification

## Purpose

Reusable avatar configurations: provider, provider-specific settings, persona, LLM and voice
binding, and the provider-side synchronization (persona/pal sync and LLM/voice registration), plus
the provider catalogue and voice preview.

## ADDED Requirements

### Requirement: Avatar config resource and CRUD

Auth: SK with scope `avatar_configs:read` (GET) or `avatar_configs:write` (POST/PATCH/DELETE).
Resource members: `id` (`avc_` + ULID), `name` (1-120), `provider` (`tavus|heygen|mock`), `config`
(object), `persona` (object|null), `llm_model_id` (string|null), `voice` (`{ engine, voice_id }`|null),
`external_ref` (string|null, max 255, opaque), `sync_status` (`not_required|pending|synced|failed`),
`sync_error` (redacted string|null), `synced_at`, `created_at`, `updated_at`.
`GET /v1/avatar-configs` (paginated), `POST /v1/avatar-configs` (201), `GET /v1/avatar-configs/{id}`,
`PATCH /v1/avatar-configs/{id}` and `DELETE /v1/avatar-configs/{id}` (204). `provider` is
immutable after creation. Deletion MUST be soft: the config disappears from lists and cannot start
new conversations, while historical conversations keep their reference. Deleting a config
referenced by any non-terminal conversation MUST yield 409 `config_in_use`.

#### Scenario: Create

- GIVEN a valid body with provider `mock`
- WHEN `POST /v1/avatar-configs` is called
- THEN status is 201, the id matches `^avc_`, and `sync_status` is `not_required`

#### Scenario: Provider is immutable

- GIVEN an existing config
- WHEN `PATCH` sends a different `provider`
- THEN status is 422 and the stored provider is unchanged

#### Scenario: Delete in use

- GIVEN a config referenced by a conversation in status `live`
- WHEN it is deleted
- THEN status is 409 and `code` is `config_in_use`

#### Scenario: Soft delete keeps history

- GIVEN a config referenced only by ended conversations
- WHEN it is deleted
- THEN status is 204, it is absent from the list, and `GET /v1/conversations/{id}` of an ended conversation still returns its `avatar_config_id`

#### Scenario: Deleted config cannot start conversations

- GIVEN a deleted config
- WHEN a conversation is created against it
- THEN status is 404 `not_found`

#### Scenario: Account isolation

- GIVEN a config of account A
- WHEN account B lists, reads, patches or deletes it
- THEN it is absent from the list and the other calls return 404

#### Scenario: Scope split

- GIVEN a SK with only `avatar_configs:read`
- WHEN it sends `POST /v1/avatar-configs`
- THEN status is 403 and `code` is `insufficient_scope`

### Requirement: Provider-specific config validation

`config` MUST be validated against the field specification declared by the selected provider's
adapter: unknown keys MUST be rejected with 422 `validation_failed`, required keys MUST be
present, and types and enumerated values MUST be enforced. The field specifications MUST be
discoverable via the catalogue endpoint. A `mock` config MUST accept an empty `config`.

#### Scenario: Unknown key

- GIVEN a `tavus` config body containing a key outside the Tavus field specification
- WHEN it is created
- THEN status is 422 and `errors` names `config.<key>`

#### Scenario: Missing required key

- GIVEN a `tavus` config missing a required identifier
- WHEN it is created
- THEN status is 422 and `errors` names the missing field

#### Scenario: Mock accepts empty config

- GIVEN provider `mock` and `config: {}`
- WHEN it is created
- THEN status is 201

### Requirement: LLM binding

`llm_model_id`, when set, MUST reference an enabled `llm_models` entry; otherwise 422
`validation_failed`. A null `llm_model_id` means the provider's own default conversational model.
Binding an LLM to a `mock` config is allowed and has no runtime effect. The bound model's
credential requirement is enforced at connect time (see `credentials`), not at config save.

#### Scenario: Valid binding

- GIVEN an enabled catalogue model `gem-1`
- WHEN a config is created with `llm_model_id` `gem-1`
- THEN status is 201

#### Scenario: Disabled or unknown model

- GIVEN a disabled or nonexistent model id
- WHEN a config is created with it
- THEN status is 422

### Requirement: Voice binding

`voice` is `{ "engine": "provider|cartesia|elevenlabs", "voice_id": string }`. Engine `provider`
means the provider's own stock voice. Engines `cartesia` and `elevenlabs` bind an external voice
and MUST be accepted only for providers whose adapter declares external-voice support; otherwise
422. A `voice_id` MUST be 1-128 characters. When a voice is bound the adapter MUST apply it when
starting a session, and an unbindable voice MUST fail before any billable provider session exists.

#### Scenario: External voice on supported provider

- GIVEN a `tavus` config with `voice` `{engine:"cartesia", voice_id:"v-1"}`
- WHEN it is created
- THEN status is 201

#### Scenario: Unsupported engine

- GIVEN a `mock` config with voice engine `elevenlabs`
- WHEN it is created
- THEN status is 422

### Requirement: Provider-side synchronization

Configs of providers that require provider-side objects (Tavus persona/pal; HeyGen LLM and
external-voice registration) MUST start with `sync_status` `pending` and be synchronized (a HeyGen
config with neither an `llm_model_id` nor an external voice requires no provider-side object and
MUST be `not_required`) by a
queued job that is idempotent: it MUST create the provider objects when absent and update them
when present, never duplicating. On success `sync_status` becomes `synced` and `synced_at` is set;
on failure it becomes `failed` with a redacted `sync_error` (no provider body, no credentials) and
the job MAY retry with backoff up to 3 attempts. Any PATCH changing `config`, `persona`,
`llm_model_id` or `voice` MUST reset `sync_status` to `pending` and re-dispatch the job.
`POST /v1/avatar-configs/{id}/sync` (scope `avatar_configs:write`) MUST re-dispatch it and answer
202. A `mock` config MUST be `not_required` and never synchronized. Creating a conversation against
a config whose `sync_status` is `pending` or `failed` MUST be refused with 409 `config_not_synced`.

#### Scenario: Create then sync

- GIVEN a valid `tavus` config and `Http::fake` for Tavus persona creation
- WHEN the config is created and the queue is processed
- THEN `sync_status` is `synced`, `synced_at` is set, and exactly one persona was created

#### Scenario: Sync is idempotent

- GIVEN a synced config
- WHEN the sync job runs again
- THEN the provider object is updated, not created a second time

#### Scenario: Failure is recorded and redacted

- GIVEN the provider rejects the sync with a body containing the API key
- WHEN the job exhausts its attempts
- THEN `sync_status` is `failed` and `sync_error` does not contain the key or provider body

#### Scenario: Edit resets sync

- GIVEN a synced config
- WHEN its `persona` is patched
- THEN `sync_status` is `pending` and a sync job is dispatched

#### Scenario: Conversation blocked while unsynced

- GIVEN a config with `sync_status` `pending`
- WHEN `POST /v1/conversations` references it
- THEN status is 409 and `code` is `config_not_synced`

#### Scenario: Manual resync

- GIVEN a `failed` config
- WHEN `POST /v1/avatar-configs/{id}/sync` is called
- THEN status is 202 and a sync job is queued

#### Scenario: Mock never syncs

- GIVEN a `mock` config
- WHEN it is created
- THEN `sync_status` is `not_required` and no job is dispatched

### Requirement: Provider catalogue

Auth: SK with scope `avatar_configs:read`. `GET /v1/catalogue` MUST return, per provider, its config
field specification (`key`, `type`, `required`, allowed values) and the resources it can list.
`GET /v1/catalogue/{provider}/{resource}` (providers `tavus`, `heygen`, `cartesia`, `elevenlabs`;
resources `voice`, `replica`, `pal`, `avatar` where applicable) MUST fetch the provider's inventory
using the resolved credential and return normalized entries `{ id, name, language|null,
preview_audio_url|null, preview_audio_via_api, preview_image_url|null, preview_video_url|null }`.
Results MUST be cached per (credential owner, provider, resource) for 5 minutes, with a `fresh=1`
query parameter bypassing the cache. The language MUST be reported only when the provider states
it, never inferred from a name. Raw provider response bodies and credentials MUST NEVER appear in
any response or error. A missing credential MUST yield 422 `provider_credential_missing`; an
unknown provider/resource pair 404.

#### Scenario: Normalized entry

- GIVEN `Http::fake` for the Tavus replica list
- WHEN `GET /v1/catalogue/tavus/replica` is called
- THEN each entry has exactly the normalized members and no raw provider fields

#### Scenario: Language is not inferred

- GIVEN a provider voice without a language field
- WHEN listed
- THEN `language` is null

#### Scenario: Cache

- GIVEN a first call fetched the inventory
- WHEN the same call is repeated within 5 minutes without `fresh=1`
- THEN no second provider request is made

#### Scenario: Fresh bypass

- GIVEN a cached inventory
- WHEN the call is repeated with `fresh=1`
- THEN a provider request is made

#### Scenario: Provider failure is opaque

- GIVEN the provider answers 500 with a body containing the API key
- WHEN the endpoint is called
- THEN status is 502, `code` is `provider_error`, and the body does not contain the key or provider body

#### Scenario: Missing credential

- GIVEN no account or platform credential for the provider
- WHEN the endpoint is called
- THEN status is 422 and `code` is `provider_credential_missing`

#### Scenario: Unknown pair

- GIVEN resource `replica` for provider `cartesia`
- WHEN requested
- THEN status is 404

### Requirement: Voice preview

Auth: SK with scope `avatar_configs:read`. `POST /v1/voice-preview` with `{ provider, voice_id,
tts_engine|null, language }` MUST return 200 `{ content_type, audio_base64 }`. The spoken sentence
MUST come from platform configuration keyed by language; callers MUST NOT be able to supply text
(an unknown body key `text` is rejected with 422). Cartesia and ElevenLabs synthesize the
configured phrase; HeyGen returns the provider's own sample; Tavus is previewable only when its
voice is routed through an external engine (`tts_engine` set), otherwise the endpoint MUST answer
422 `validation_failed`. Generated audio MUST be cached per (vendor, voice, model, phrase version,
language) so a repeat request makes no provider call.

#### Scenario: Synthesis

- GIVEN `Http::fake` for Cartesia
- WHEN a preview is requested for provider `tavus` with `tts_engine` `cartesia`
- THEN the synthesized phrase equals the configured phrase for the language and the response has `audio_base64`

#### Scenario: Caller cannot choose the text

- GIVEN a request body containing `text`
- WHEN validated
- THEN status is 422

#### Scenario: Tavus stock voice

- GIVEN provider `tavus` with `tts_engine` null
- WHEN a preview is requested
- THEN status is 422 and no provider call is made

#### Scenario: Cache hit

- GIVEN a preview already generated for the same key tuple
- WHEN requested again
- THEN no provider call is made and the same audio is returned
