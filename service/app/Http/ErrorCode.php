<?php

namespace App\Http;

/**
 * The stable, machine-facing error vocabulary. Never localized.
 */
enum ErrorCode: string
{
    case ApiKeyInQuery = 'api_key_in_query';
    case Unauthenticated = 'unauthenticated';
    case KeyExpired = 'key_expired';
    case KeyRevoked = 'key_revoked';
    case ClientSecretInvalid = 'client_secret_invalid';
    case ClientSecretExpired = 'client_secret_expired';
    case InsufficientScope = 'insufficient_scope';
    case Forbidden = 'forbidden';
    case OriginNotAllowed = 'origin_not_allowed';
    case SecretKeyOriginForbidden = 'secret_key_origin_forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case AlreadyConnected = 'already_connected';
    case InvalidState = 'invalid_state';
    case ConversationClosed = 'conversation_closed';
    case ConfigNotSynced = 'config_not_synced';
    case ConfigInUse = 'config_in_use';
    case PayloadTooLarge = 'payload_too_large';
    case ValidationFailed = 'validation_failed';
    case ProviderCredentialMissing = 'provider_credential_missing';
    case LlmCredentialMissing = 'llm_credential_missing';
    case RateLimited = 'rate_limited';
    case ProviderConcurrencyLimited = 'provider_concurrency_limited';
    case InternalError = 'internal_error';
    case ProviderError = 'provider_error';
    case ServiceUnavailable = 'service_unavailable';

    public function status(): int
    {
        return match ($this) {
            self::ApiKeyInQuery => 400,
            self::Unauthenticated, self::KeyExpired, self::KeyRevoked,
            self::ClientSecretInvalid, self::ClientSecretExpired => 401,
            self::InsufficientScope, self::Forbidden, self::OriginNotAllowed,
            self::SecretKeyOriginForbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::AlreadyConnected, self::InvalidState, self::ConversationClosed,
            self::ConfigNotSynced, self::ConfigInUse => 409,
            self::PayloadTooLarge => 413,
            self::ValidationFailed, self::ProviderCredentialMissing, self::LlmCredentialMissing => 422,
            self::RateLimited, self::ProviderConcurrencyLimited => 429,
            self::InternalError => 500,
            self::ProviderError => 502,
            self::ServiceUnavailable => 503,
        };
    }

    /**
     * A fixed English sentence, used when the caller supplies no detail of its own.
     */
    public function defaultDetail(): string
    {
        return match ($this) {
            self::ApiKeyInQuery => 'API keys must be sent in a header, never in the query string.',
            self::Unauthenticated => 'Authentication is missing or invalid.',
            self::KeyExpired => 'The API key has expired.',
            self::KeyRevoked => 'The API key has been revoked.',
            self::ClientSecretInvalid => 'The client secret is not valid.',
            self::ClientSecretExpired => 'The client secret has expired.',
            self::InsufficientScope => 'The API key does not carry the scope this operation requires.',
            self::Forbidden => 'This operation is not permitted.',
            self::OriginNotAllowed => 'The request origin is not allowed for this key.',
            self::SecretKeyOriginForbidden => 'A live secret key must not be used from a browser origin.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'The HTTP method is not supported for this resource.',
            self::AlreadyConnected => 'The conversation has already been connected.',
            self::InvalidState => 'The operation is not valid for the current state.',
            self::ConversationClosed => 'The conversation is closed and no longer accepts events.',
            self::ConfigNotSynced => 'The avatar configuration has not been synchronized with its provider.',
            self::ConfigInUse => 'The avatar configuration is referenced by an open conversation.',
            self::PayloadTooLarge => 'The request body is too large.',
            self::ValidationFailed => 'The request failed validation.',
            self::ProviderCredentialMissing => 'No credential is configured for this provider.',
            self::LlmCredentialMissing => 'No credential is configured for this language model provider.',
            self::RateLimited => 'Too many requests. Retry later.',
            self::ProviderConcurrencyLimited => 'The provider has reached its concurrency limit.',
            self::InternalError => 'An unexpected error occurred.',
            self::ProviderError => 'The avatar provider request failed.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }
}
