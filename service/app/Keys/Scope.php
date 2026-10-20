<?php

namespace App\Keys;

enum Scope: string
{
    case Wildcard = '*';
    case AvatarConfigsRead = 'avatar_configs:read';
    case AvatarConfigsWrite = 'avatar_configs:write';
    case CredentialsManage = 'credentials:manage';
    case LlmModelsRead = 'llm_models:read';
    case ConversationsRead = 'conversations:read';
    case ConversationsWrite = 'conversations:write';
    case WebhookEndpointsManage = 'webhook_endpoints:manage';
    case ApiKeysManage = 'api_keys:manage';

    /**
     * Whether a key holding these scopes may do what the required scope allows: the wildcard
     * satisfies any required scope, anything else only itself.
     *
     * @param  list<string>  $granted
     */
    public static function grants(array $granted, self $required): bool
    {
        return in_array(self::Wildcard->value, $granted, true) || in_array($required->value, $granted, true);
    }

    /**
     * Like `grants()`, for a scope given by name (a route definition, a stored key). A name this version does not
     * know is never granted, not even to the wildcard: it can only be a typo or a retired scope, and a refusal
     * is the safe answer to both.
     *
     * @param  list<string>  $granted
     */
    public static function grantsNamed(array $granted, string $required): bool
    {
        $scope = self::tryFrom($required);

        return $scope !== null && self::grants($granted, $scope);
    }
}
