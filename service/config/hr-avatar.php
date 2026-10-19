<?php

use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\Tavus\TavusProvider;

/*
 * The only place the product name lives (plus the key prefix constants in Keys\KeyGenerator).
 * A key is added by the slice that reads it. Still to come: the release delay, the duration ceiling,
 * the catalogue cache lifetime and the voice vendor classes.
 */
return [

    /* The product name, used wherever the service has to name itself (for example the OpenAPI title). */
    'name' => 'HR-Avatar',

    /*
    | HS256 secret that signs the per-conversation client_secret. At least 32 bytes outside the
    | testing environment; the application refuses to boot otherwise, so a deployment can never
    | start with a missing or guessable signing secret (service-foundation: configuration bootstrap).
    */
    'jwt_secret' => env('HRA_JWT_SECRET'),

    /*
    | The provider registry: id => adapter class, whether the provider needs a credential, and
    | optionally the prefix of its event keys (default: the id). This file is the only place,
    | outside the adapters, where a provider's name appears.
    */
    'providers' => [
        'mock' => ['adapter' => MockProvider::class, 'credential' => false],
        'tavus' => ['adapter' => TavusProvider::class, 'credential' => true],
    ],

    /*
    | Tavus refuses a new conversation while the account is at its concurrent limit, and frees the slot
    | of one that just ended a few seconds late: a create is retried this many times, this many
    | milliseconds apart. 0 retries disables retrying.
    */
    'tavus' => [
        'concurrency_retries' => 3,
        'concurrency_backoff_ms' => 2000,
    ],

    /*
    | Providers an account may store a key for, in the order the credential list reports them: the
    | two avatar providers and the two voice vendors, as the credentials spec fixes them. Read by
    | CredentialResolver and the credential endpoints.
    */
    'credential_providers' => ['tavus', 'heygen', 'cartesia', 'elevenlabs'],

    /*
    | The platform's own key per credential provider, used when an account has none of its own.
    | Optional: a provider with no key here and none stored by the account is simply unconfigured.
    */
    'platform_keys' => [
        'tavus' => env('TAVUS_API_KEY'),
        'heygen' => env('HEYGEN_API_KEY'),
        'cartesia' => env('CARTESIA_API_KEY'),
        'elevenlabs' => env('ELEVENLABS_API_KEY'),
    ],

    /* Requests per minute. Every key has a budget of its own; failed authentications are counted per address. */
    'rate_limits' => [
        'secret' => (int) env('HRA_RATE_LIMIT_SECRET', 600),
        'publishable' => (int) env('HRA_RATE_LIMIT_PUBLISHABLE', 300),
        'failed_auth' => 30,
    ],

];
