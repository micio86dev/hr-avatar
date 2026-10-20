<?php

use App\Webhooks\Outbound\WebhookSigner;

const SIGNER_SECRET = 'hra_whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

describe('sign', function () {
    it('builds v1= plus the lowercase hex HMAC-SHA256 of "{timestamp}.{body}", matching a vector computed outside this code', function () {
        // printf '1700000000.{"a":1}' | openssl dgst -sha256 -hmac 'S'
        expect((new WebhookSigner)->sign('S', 1700000000, '{"a":1}'))
            ->toBe('v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895');
    });

    it('signs the exact bytes it is given, unicode and slashes included', function () {
        // printf '1700000000.{"id":"evt_1","text":"héllo/ü"}' | openssl dgst -sha256 -hmac "$secret"
        expect((new WebhookSigner)->sign(SIGNER_SECRET, 1700000000, '{"id":"evt_1","text":"héllo/ü"}'))
            ->toBe('v1=201d01ee05a8fd04f7767d9a1896723bd8197078c696e2b891c4594a6400423a');
    });

    it('changes with the timestamp, the body and the secret', function () {
        $signer = new WebhookSigner;
        $base = $signer->sign('S', 1700000000, '{"a":1}');

        expect($signer->sign('S', 1700000001, '{"a":1}'))->not->toBe($base)
            ->and($signer->sign('S', 1700000000, '{"a":2}'))->not->toBe($base)
            ->and($signer->sign('T', 1700000000, '{"a":1}'))->not->toBe($base)
            ->and($signer->sign('S', 1700000000, '{"a":1}'))->toBe($base);
    });

    it('is a header value: v1= and 64 lowercase hex characters', function () {
        expect((new WebhookSigner)->sign('S', 1, 'x'))->toMatch('/^v1=[0-9a-f]{64}$/');
    });
});

describe('encode', function () {
    it('writes the bytes that are signed and sent: unescaped slashes and unicode, an empty object as {}', function () {
        $payload = ['url' => 'https://a.com/b', 'name' => 'héllo', 'metadata' => new stdClass, 'list' => []];

        expect((new WebhookSigner)->encode($payload))->toBe('{"url":"https://a.com/b","name":"héllo","metadata":{},"list":[]}');
    });

    it('differs from the default encoding, which is why every caller signs what encode() returns', function () {
        $signer = new WebhookSigner;
        $payload = ['url' => 'https://a.com/b', 'name' => 'héllo'];

        expect($signer->encode($payload))->not->toBe(json_encode($payload))
            ->and($signer->sign('S', 1, $signer->encode($payload)))->not->toBe($signer->sign('S', 1, json_encode($payload)));
    });

    it('refuses what cannot be encoded instead of sending a broken body', function () {
        expect(fn () => (new WebhookSigner)->encode(['bad' => "\xB1\x31"]))->toThrow(JsonException::class);
    });
});

describe('verify', function () {
    it('accepts a fresh delivery', function () {
        $signer = new WebhookSigner;
        $header = $signer->sign(SIGNER_SECRET, 1700000000, '{"a":1}');

        expect($signer->verify(SIGNER_SECRET, $header, 1700000000, '{"a":1}', 1700000010))->toBeTrue();
    });

    it('accepts the whole window and refuses beyond it, in both directions', function (int $skew, bool $accepted) {
        $signer = new WebhookSigner;
        $header = $signer->sign('S', 1700000000, 'body');

        expect($signer->verify('S', $header, 1700000000, 'body', 1700000000 + $skew))->toBe($accepted);
    })->with([
        'same instant' => [0, true],
        'the verifier is 300 s ahead' => [300, true],
        'replay at 301 s' => [301, false],
        'a day later' => [86400, false],
        'the verifier is 300 s behind' => [-300, true],
        'a future timestamp at 301 s' => [-301, false],
        'a year in the future' => [-31536000, false],
    ]);

    it('refuses a tampered body, timestamp or secret', function (string $secret, int $timestamp, string $body) {
        $signer = new WebhookSigner;
        $header = $signer->sign('S', 1700000000, '{"a":1}');

        expect($signer->verify($secret, $header, $timestamp, $body, 1700000000))->toBeFalse();
    })->with([
        'a body changed by one byte' => ['S', 1700000000, '{"a":2}'],
        'a body with a byte added' => ['S', 1700000000, '{"a":1} '],
        'another timestamp' => ['S', 1700000001, '{"a":1}'],
        'another secret' => ['T', 1700000000, '{"a":1}'],
    ]);

    it('refuses a header that is not exactly v1= and the lowercase hex of the right signature', function (string $header) {
        expect((new WebhookSigner)->verify('S', $header, 1700000000, '{"a":1}', 1700000000))->toBeFalse();
    })->with([
        'empty' => [''],
        'no scheme' => ['0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895'],
        'another scheme' => ['v2=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895'],
        'upper case hex' => ['v1=0EFDF39BD68EC1DEE5FBB75C839415C431D28CECD5DBEF8E0D039B99F5571895'],
        'truncated' => ['v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f557189'],
        'too long' => ['v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f55718950'],
        'trailing space' => ['v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895 '],
        'two signatures' => ['v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895,v1=00'],
        'a different valid-looking hex' => ['v1=0000000000000000000000000000000000000000000000000000000000000000'],
    ]);

    it('accepts the signature of the known vector', function () {
        expect((new WebhookSigner)->verify('S', 'v1=0efdf39bd68ec1dee5fbb75c839415c431d28cecd5dbef8e0d039b99f5571895', 1700000000, '{"a":1}', 1700000000))->toBeTrue();
    });

    it('compares in constant time', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Webhooks/Outbound/WebhookSigner.php');

        expect($source)->toContain('hash_equals(');
    });
});

describe('a delivery signed, retried and verified', function () {
    it('re-signs every attempt with its own timestamp and keeps the body', function () {
        $signer = new WebhookSigner;
        $body = $signer->encode(['id' => 'evt_1']);

        $first = $signer->sign(SIGNER_SECRET, 1700000000, $body);
        $retry = $signer->sign(SIGNER_SECRET, 1700000060, $body);

        expect($first)->not->toBe($retry)
            ->and($signer->verify(SIGNER_SECRET, $first, 1700000000, $body, 1700000005))->toBeTrue()
            ->and($signer->verify(SIGNER_SECRET, $retry, 1700000060, $body, 1700000065))->toBeTrue()
            // The first signature does not pass for the retry's timestamp.
            ->and($signer->verify(SIGNER_SECRET, $first, 1700000060, $body, 1700000065))->toBeFalse();
    });
});
