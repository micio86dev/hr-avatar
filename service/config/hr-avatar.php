<?php

/*
 * The only place the product name lives (plus the key prefix constants in Keys\KeyGenerator).
 * Each later slice adds the keys it reads (token claims, rate limits, release delay, duration
 * ceiling, provider registry, platform keys, voice vendors).
 */
return [

    /*
    | HS256 secret that signs the per-conversation client_secret. At least 32 bytes outside the
    | testing environment; the application refuses to boot otherwise, so a deployment can never
    | start with a missing or guessable signing secret (service-foundation: configuration bootstrap).
    */
    'jwt_secret' => env('HRA_JWT_SECRET'),

];
