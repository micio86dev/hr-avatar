# Credentials Specification

## Purpose

Per-account encrypted provider and LLM credentials with a platform environment fallback, plus the
platform LLM model catalogue. Credentials are write-only: the API never returns them (last four
characters at most).

## ADDED Requirements

### Requirement: Provider credential storage

Auth: SK with scope `credentials:manage`. Credential providers are `tavus`, `heygen`, `cartesia`
and `elevenlabs`. `PUT /v1/provider-credentials/{provider}` with body `{ "api_key": string }`
(1-512 characters) MUST create or replace the account's credential for that provider and return
200 with `{ provider, source: "account", last_four, updated_at }`. The key MUST be stored with the
Eloquent `encrypted` cast, hidden from serialization, and never logged. An unknown `{provider}`
MUST yield 404 `not_found`; an empty or oversize key 422 `validation_failed`.

#### Scenario: Create

- GIVEN a SK with `credentials:manage`
- WHEN it sends `PUT /v1/provider-credentials/tavus` with `{"api_key":"tv_abcdefgh1234"}`
- THEN status is 200, `last_four` is `1234`, and the response contains no `api_key`

#### Scenario: Encrypted at rest

- GIVEN a stored credential
- WHEN the raw database column is read
- THEN it does not contain the plaintext key and decrypts to the original

#### Scenario: Replace

- GIVEN an existing credential
- WHEN `PUT` is called again with a new key
- THEN the stored key is the new one and only one row exists for (account, provider)

#### Scenario: Unknown provider

- GIVEN provider `acme`
- WHEN `PUT /v1/provider-credentials/acme` is called
- THEN status is 404

#### Scenario: Scope required

- GIVEN a SK without `credentials:manage`
- WHEN the endpoint is called
- THEN status is 403 and `code` is `insufficient_scope`

### Requirement: Credential listing and removal

Auth: SK with scope `credentials:manage`. `GET /v1/provider-credentials` MUST return one entry per
credential provider as `{ provider, source, last_four }` where `source` is `account` (an account
credential exists), `platform` (only the platform environment key is set) or `none`. `last_four`
MUST be the last four characters for `account` and MUST be `null` for `platform` and `none`; a key
shorter than 12 characters MUST report `last_four` null. `DELETE /v1/provider-credentials/{provider}`
MUST remove the account credential and return 204 (idempotent).

#### Scenario: Sources

- GIVEN an account credential for `tavus`, a platform key set for `heygen`, nothing for `cartesia` and `elevenlabs`
- WHEN the list is requested
- THEN `tavus` has source `account`, `heygen` has `platform` with null `last_four`, the others `none`

#### Scenario: Delete falls back

- GIVEN an account credential and a platform key for `tavus`
- WHEN the account credential is deleted
- THEN the list reports `tavus` source `platform`

#### Scenario: Delete is idempotent

- GIVEN no account credential
- WHEN `DELETE` is called
- THEN status is 204

#### Scenario: Short key

- GIVEN a stored key of 8 characters
- WHEN listed
- THEN `last_four` is null

### Requirement: Credential resolution order

When a provider credential is needed, the service MUST use the account credential if present,
otherwise the platform environment key (`TAVUS_API_KEY`, `HEYGEN_API_KEY`, `CARTESIA_API_KEY`,
`ELEVENLABS_API_KEY`), otherwise fail with 422 `provider_credential_missing`. A credential of one
account MUST NEVER be used for another account. The Mock provider requires no credential.

#### Scenario: Account wins over platform

- GIVEN an account credential and a platform key for `tavus`
- WHEN a Tavus call is made for that account
- THEN the request is authenticated with the account key

#### Scenario: Platform fallback

- GIVEN no account credential and a platform key
- WHEN a Tavus call is made
- THEN the platform key is used

#### Scenario: Neither

- GIVEN no account credential and no platform key
- WHEN a conversation using a Tavus config is connected
- THEN status is 422 and `code` is `provider_credential_missing`

#### Scenario: No cross-account use

- GIVEN account A holds a Tavus credential and account B holds none and no platform key exists
- WHEN account B connects a Tavus conversation
- THEN status is 422 and A's key is not used

#### Scenario: Mock needs none

- GIVEN no credentials at all
- WHEN a Mock conversation is connected
- THEN no credential error occurs

### Requirement: LLM model catalogue

`llm_models` is a platform catalogue (no `account_id`) seeded by the platform with entries
`{ id, provider, model, display_name, base_url, enabled }`. Auth: SK with scope `llm_models:read`.
`GET /v1/llm-models` MUST return only enabled entries and MUST NOT include any credential or
internal field beyond those listed. The API MUST NOT offer endpoints to create, modify or delete
catalogue entries.

#### Scenario: Only enabled models

- GIVEN two enabled and one disabled catalogue entries
- WHEN the list is requested
- THEN exactly the two enabled entries are returned

#### Scenario: Read-only

- GIVEN the route table
- WHEN `POST`, `PUT`, `PATCH` or `DELETE` on `/v1/llm-models` is requested
- THEN status is 405

### Requirement: LLM credentials

Auth: SK with scope `credentials:manage`. `PUT /v1/llm-credentials/{llm_provider}` (where
`{llm_provider}` is a `provider` value present in the catalogue) with `{ "api_key": string }` MUST
store the account's encrypted LLM credential and return `{ llm_provider, last_four, updated_at }`;
`GET /v1/llm-credentials` lists the account's entries (`llm_provider`, `last_four`) and
`DELETE /v1/llm-credentials/{llm_provider}` removes one (204, idempotent). LLM credentials follow
the same encryption, hiding and non-disclosure rules as provider credentials. There is no platform
fallback for LLM credentials: a conversation whose avatar config binds an LLM model with no account
credential for its provider MUST fail at connect with 422 `llm_credential_missing`.

#### Scenario: Store and list

- GIVEN a catalogue provider `gemini`
- WHEN `PUT /v1/llm-credentials/gemini` is called with a key and then the list is requested
- THEN the entry shows `llm_provider` `gemini` and `last_four`, never the key

#### Scenario: Unknown LLM provider

- GIVEN `{llm_provider}` not present in the catalogue
- WHEN `PUT` is called
- THEN status is 404

#### Scenario: Missing at connect

- GIVEN an avatar config bound to a `gemini` model and no LLM credential
- WHEN the conversation is connected
- THEN status is 422 and `code` is `llm_credential_missing` and no provider session is created

### Requirement: Credential non-disclosure

No endpoint, log line, Problem+JSON body, job payload, failed-job record or queue serialization
MUST contain a stored provider or LLM credential in plaintext. Models MUST hide the credential
attribute from `toArray`/`toJson`. A serialization-exposure test per credential resource MUST assert
this, and an exception raised while a credential is in scope MUST NOT include it in its message.

#### Scenario: Model serialization

- GIVEN a provider credential model with a stored key
- WHEN it is serialized with `toArray()` and `toJson()`
- THEN the key attribute is absent

#### Scenario: Failed job payload

- GIVEN a queued job that used a credential and fails
- WHEN the failed-job record is inspected
- THEN the plaintext credential does not appear in the payload or exception text

#### Scenario: Account isolation of listing

- GIVEN credentials of accounts A and B
- WHEN A lists credentials
- THEN only A's entries (and platform source flags) are reflected
