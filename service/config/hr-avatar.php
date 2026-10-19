<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\LiveAvatar\LiveAvatarVoices;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Voices\CartesiaVoices;
use App\Voices\ElevenLabsVoices;

/*
 * The only place the product name lives (plus the key prefix constants in Keys\KeyGenerator).
 * A key is added by the slice that reads it.
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
        'heygen' => ['adapter' => LiveAvatarProvider::class, 'credential' => true, 'event_key_prefix' => 'liveavatar'],
        'tavus' => ['adapter' => TavusProvider::class, 'credential' => true],
    ],

    /*
    | The voice vendors: id => class. An id is also the credential provider its calls use, and the
    | value of an external voice engine. LiveAvatar is both an avatar provider and a vendor.
    */
    'voice_vendors' => [
        'cartesia' => CartesiaVoices::class,
        'elevenlabs' => ElevenLabsVoices::class,
        'heygen' => LiveAvatarVoices::class,
    ],

    /*
    | Voice preview (POST /v1/voice-preview). The sentence a preview speaks is defined here and only
    | here: a caller passes a language, never text, so the endpoint cannot be used as a free
    | text-to-speech proxy on an account's or the platform's key. The sentences are language data, not
    | interface copy. Changing a sentence MUST bump `phrase_version`, which is part of the audio's cache
    | key; otherwise the old sentence keeps being served.
    */
    'voice_preview' => [
        'phrase_version' => 'v1',
        'phrases' => [
            'en' => 'Good morning, thank you for being here. Let us talk through a few topics calmly, listening carefully and without hurry.',
            'it' => 'Buongiorno, grazie per essere qui. Parliamo con calma di alcuni argomenti, ascoltando con attenzione e senza fretta.',
        ],
        // Pinned, so a vendor's own default cannot change what a cached sample sounds like.
        'models' => ['cartesia' => 'sonic-3', 'elevenlabs' => 'eleven_multilingual_v2'],
        // Cartesia requires an explicit API version header.
        'cartesia_version' => '2025-04-16',
        'output_formats' => [
            'cartesia' => ['container' => 'mp3', 'sample_rate' => 44100, 'bit_rate' => 128000],
            // ElevenLabs takes it as the `output_format` query parameter.
            'elevenlabs' => 'mp3_44100_128',
        ],
        // Upper bound on the audio taken from a vendor (a ~20 word sample is ~100 KB of mp3), so a misbehaving
        // vendor cannot fill the cache or a response.
        'max_bytes' => 2 * 1024 * 1024,
        'timeout_seconds' => 20,
        'cache_seconds' => 7 * 86400,
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

    /*
    | The address the service is reached at from outside. A provider that calls back is given a target
    | under it, so it must be reachable by the provider.
    */
    'public_base_url' => env('HRA_PUBLIC_BASE_URL'),

    /*
    | How long after a conversation ends its provider session is released, in seconds. The browser may still
    | be closing the call, and a provider may still be writing the transcript.
    */
    'release_delay_seconds' => (int) env('HRA_RELEASE_DELAY_SECONDS', 20),

    /* The longest a conversation may be asked to last, in seconds; a create asking for more is refused. */
    'max_duration_ceiling' => (int) env('HRA_MAX_DURATION_CEILING', 3600),

    /*
    | Requests per minute. Every key has a budget of its own; failed authentications are counted per address.
    | `preview` is a second, smaller budget per key for voice previews, which each cost a paid synthesis.
    */
    'rate_limits' => [
        'secret' => (int) env('HRA_RATE_LIMIT_SECRET', 600),
        'publishable' => (int) env('HRA_RATE_LIMIT_PUBLISHABLE', 300),
        'preview' => (int) env('HRA_RATE_LIMIT_PREVIEW', 10),
        'failed_auth' => 30,
    ],

];
