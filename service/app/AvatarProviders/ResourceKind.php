<?php

namespace App\AvatarProviders;

/**
 * What a provider-side object in the `provider_resources` ledger is: a language-model secret, a
 * voice vendor's secret, or a voice bound into the provider.
 */
enum ResourceKind: string
{
    case LlmSecret = 'llm_secret';
    case VendorSecret = 'vendor_secret';
    case Voice = 'voice';
}
