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

    /* Requests per minute. A secret key's budget is its own; failed authentications are counted per address. */
    'rate_limits' => [
        'secret' => (int) env('HRA_RATE_LIMIT_SECRET', 600),
        'failed_auth' => 30,
    ],

];
