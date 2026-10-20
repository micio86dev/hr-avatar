<?php

namespace App\Keys;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Mints and verifies the per-conversation `client_secret`: an HS256 JWT bound to one
 * conversation and one account, which a browser presents together with a publishable key.
 *
 * Only HS256 is accepted (`alg: none` and every other algorithm fail the signature check).
 * `iat` and `exp` come from the one instant the caller passes as `createdAt` (never the wall clock), so the two
 * claims always agree. `iss` and `aud` are compared here, and `exp` is read but not enforced at parse time, so the
 * caller can answer `client_secret_expired` rather than `client_secret_invalid`.
 */
final class ClientSecret
{
    private const ISSUER = 'hr-avatar';

    private const AUDIENCE = 'hr-avatar-client';

    /** How long after creation a conversation may still be connected, before its own duration. */
    public const CONNECT_WINDOW_SECONDS = 900;

    /**
     * @throws RuntimeException when HRA_JWT_SECRET is not configured
     */
    public function mint(string $conversationId, string $accountId, Carbon $createdAt, int $maxDurationSeconds): MintedClientSecret
    {
        $config = $this->configuration();
        $jti = (string) Str::ulid();
        $issuedAt = CarbonImmutable::instance($createdAt);
        $expiresAt = $issuedAt->addSeconds(self::CONNECT_WINDOW_SECONDS + $maxDurationSeconds);

        $token = $config->builder()
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->relatedTo(self::nonEmpty($conversationId))
            ->identifiedBy($jti)
            ->issuedAt($issuedAt)
            ->expiresAt($expiresAt)
            ->withClaim('acc', $accountId)
            ->getToken($config->signer(), $config->signingKey());

        return new MintedClientSecret($token->toString(), $jti, $expiresAt);
    }

    /**
     * The verified claims, or null for anything that is not a client secret minted by this
     * service: malformed, wrongly signed, wrong algorithm, issuer or audience, or missing a claim.
     *
     * @throws RuntimeException when HRA_JWT_SECRET is not configured
     */
    public function parse(string $raw): ?VerifiedClientSecret
    {
        $config = $this->configuration();

        if ($raw === '') {
            return null;
        }

        try {
            $token = $config->parser()->parse($raw);
        } catch (Throwable) {
            return null;
        }

        if (! $token instanceof Plain || ! $config->validator()->validate($token, new SignedWith($config->signer(), $config->signingKey()))) {
            return null;
        }

        $claims = $token->claims();
        $sub = $claims->get('sub');
        $acc = $claims->get('acc');
        $jti = $claims->get('jti');
        $exp = $claims->get('exp');

        if ($claims->get('iss') !== self::ISSUER || $claims->get('aud') !== [self::AUDIENCE]) {
            return null;
        }

        if (! is_string($sub) || ! is_string($acc) || ! is_string($jti) || ! $exp instanceof DateTimeImmutable) {
            return null;
        }

        return new VerifiedClientSecret($sub, $acc, $jti, CarbonImmutable::instance($exp));
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        return $value === '' ? throw new LogicException('A client secret needs a conversation id.') : $value;
    }

    private function configuration(): Configuration
    {
        $secret = config('hr-avatar.jwt_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('HRA_JWT_SECRET is not configured.');
        }

        return Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($secret));
    }
}
