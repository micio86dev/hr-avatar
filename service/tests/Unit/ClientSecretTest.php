<?php

use App\Keys\ClientSecret;
use Illuminate\Support\Carbon;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;

const CS_CONVERSATION = 'conv_01ARZ3NDEKTSV4RRFFQ69G5FAV';
const CS_ACCOUNT = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'UTC')));

function csConfig(?string $secret = null, $signer = null): Configuration
{
    return Configuration::forSymmetricSigner($signer ?? new Sha256, InMemory::plainText($secret ?? config('hr-avatar.jwt_secret')));
}

/**
 * A token with every claim a real one carries, so that the single thing a test changes is the
 * thing that decides it.
 *
 * @param  array<string, mixed>  $overrides  claim => value; null removes the claim
 */
function csForge(array $overrides = [], ?Configuration $config = null): string
{
    $config ??= csConfig();
    $now = new DateTimeImmutable('2026-10-19 12:00:00');
    $claims = [
        'iss' => 'hr-avatar', 'aud' => ['hr-avatar-client'], 'sub' => CS_CONVERSATION, 'acc' => CS_ACCOUNT,
        'jti' => 'a-jti', 'iat' => $now, 'exp' => $now->modify('+1 hour'),
        ...$overrides,
    ];
    $builder = $config->builder();
    foreach ($claims as $name => $value) {
        if ($value === null) {
            continue;
        }
        $builder = match ($name) {
            'iss' => $builder->issuedBy($value),
            'aud' => $builder->permittedFor(...$value),
            'sub' => $builder->relatedTo($value),
            'jti' => $builder->identifiedBy($value),
            'iat' => $builder->issuedAt($value),
            'exp' => $builder->expiresAt($value),
            default => $builder->withClaim($name, $value),
        };
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

function csB64(array|string $part): string
{
    return rtrim(strtr(base64_encode(is_array($part) ? json_encode($part) : $part), '+/', '-_'), '=');
}

it('mints a token that parses back to the same conversation, account and id', function () {
    $minted = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 1800);

    $verified = (new ClientSecret)->parse($minted->token);

    expect($verified)->not->toBeNull()
        ->and($verified->conversationId)->toBe(CS_CONVERSATION)
        ->and($verified->accountId)->toBe(CS_ACCOUNT)
        ->and($verified->jti)->toBe($minted->jti)
        ->and($verified->isExpired())->toBeFalse();
});

it('expires 900 seconds plus the maximum duration after the conversation was created', function () {
    $created = Carbon::parse('2026-10-19 11:50:00', 'UTC');

    $minted = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, $created, 600);

    expect($minted->expiresAt->toIso8601ZuluString())->toBe('2026-10-19T12:15:00Z')
        ->and((new ClientSecret)->parse($minted->token)?->expiresAt->toIso8601ZuluString())->toBe('2026-10-19T12:15:00Z');
});

it('carries exactly iss, aud, sub, acc, jti, iat and exp, and no mode', function () {
    $token = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 600)->token;

    $parsed = csConfig()->parser()->parse($token);
    expect($parsed)->toBeInstanceOf(Plain::class);
    $claims = $parsed->claims()->all();

    expect(array_keys($claims))->toEqualCanonicalizing(['iss', 'aud', 'sub', 'acc', 'jti', 'iat', 'exp'])
        ->and($claims['iss'])->toBe('hr-avatar')
        ->and($claims['aud'])->toBe(['hr-avatar-client'])
        ->and($claims['jti'])->toMatch('/^[0-9A-Za-z]{26}$/')
        ->and($parsed->headers()->get('alg'))->toBe('HS256');
});

it('gives each token its own id', function () {
    $first = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 600);
    $second = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 600);

    expect($first->jti)->not->toBe($second->jti)->and($first->token)->not->toBe($second->token);
});

it('never parses a token that is not exactly what it mints', function (string $case, Closure $token) {
    expect((new ClientSecret)->parse($token()))->toBeNull();
})->with([
    'a different secret' => ['secret', fn () => csForge(config: csConfig('a-completely-different-secret-value-of-32-bytes'))],
    // The same secret, long enough for HS512, signed with the stronger algorithm: still refused.
    'another algorithm' => ['hs512', function () {
        config()->set('hr-avatar.jwt_secret', str_repeat('k', 64));

        return csForge(config: csConfig(signer: new Sha512));
    }],
    'alg none' => ['none', fn () => csB64(['alg' => 'none', 'typ' => 'JWT']).'.'.csB64(['iss' => 'hr-avatar', 'aud' => ['hr-avatar-client'], 'sub' => CS_CONVERSATION, 'acc' => CS_ACCOUNT, 'jti' => 'forged', 'iat' => time(), 'exp' => time() + 900]).'.'],
    'tampered payload' => ['tampered', function () {
        [$header, $payload, $signature] = explode('.', csForge());
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['sub'] = 'conv_01ARZ3NDEKTSV4RRFFQ69G5FAX';

        return "{$header}.".csB64($claims).".{$signature}";
    }],
    'wrong issuer' => ['iss', fn () => csForge(['iss' => 'someone-else'])],
    'wrong audience' => ['aud', fn () => csForge(['aud' => ['some-other-audience']])],
    'an extra audience' => ['aud2', fn () => csForge(['aud' => ['hr-avatar-client', 'another']])],
    'no subject' => ['sub', fn () => csForge(['sub' => null])],
    'no account' => ['acc', fn () => csForge(['acc' => null])],
    'no id' => ['jti', fn () => csForge(['jti' => null])],
    'no expiry' => ['exp', fn () => csForge(['exp' => null])],
    'an account that is not a string' => ['acc-int', fn () => csForge(['acc' => 7])],
    'garbage' => ['garbage', fn () => 'not-a-jwt-at-all'],
    'empty' => ['empty', fn () => ''],
    'two segments' => ['two', fn () => 'aaa.bbb'],
]);

it('still parses an expired token, and reports it as expired', function () {
    $token = csForge(['exp' => new DateTimeImmutable('2026-10-19 11:00:00')]);

    $verified = (new ClientSecret)->parse($token);

    expect($verified)->not->toBeNull()->and($verified->isExpired())->toBeTrue();
});

it('treats the expiry instant itself as expired and the second before it as valid', function () {
    $token = csForge(['exp' => new DateTimeImmutable('2026-10-19 12:00:30')]);
    $verified = (new ClientSecret)->parse($token);

    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:29', 'UTC'));
    expect($verified->isExpired())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:30', 'UTC'));
    expect($verified->isExpired())->toBeTrue();
});

it('fails loudly rather than signing or verifying with a missing secret', function () {
    config()->set('hr-avatar.jwt_secret', null);

    expect(fn () => (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 600))->toThrow(RuntimeException::class, 'HRA_JWT_SECRET')
        ->and(fn () => (new ClientSecret)->parse('anything'))->toThrow(RuntimeException::class, 'HRA_JWT_SECRET');
});

it('does not reveal the token when the minted value is dumped', function () {
    $minted = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, now(), 600);

    ob_start();
    var_dump($minted);
    $dump = ob_get_clean();

    expect($dump)->not->toContain($minted->token)->and($dump)->toContain('[redacted]');
});

it('stamps iat from the same instant its expiry is computed from, not from the wall clock', function () {
    $createdAt = Carbon::parse('2026-01-05 08:30:00', 'UTC');

    $minted = (new ClientSecret)->mint(CS_CONVERSATION, CS_ACCOUNT, $createdAt, 600);

    $claims = json_decode((string) base64_decode(strtr(explode('.', $minted->token)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    expect($claims['iat'])->toBe($createdAt->getTimestamp())
        ->and($claims['exp'])->toBe($createdAt->getTimestamp() + ClientSecret::CONNECT_WINDOW_SECONDS + 600)
        ->and($minted->expiresAt->getTimestamp())->toBe($claims['exp']);
});
