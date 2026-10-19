<?php

/*
 * The only place the product name lives (plus the key prefix constants in Keys\KeyGenerator).
 * Each later slice adds the keys it reads (token claims, publishable limit, release delay, duration
 * ceiling, provider registry, platform keys, voice vendors).
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

    /* Providers an account may store a key for, in the order the credential list reports them. */
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
