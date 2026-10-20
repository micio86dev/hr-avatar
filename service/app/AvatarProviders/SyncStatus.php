<?php

namespace App\AvatarProviders;

/**
 * Whether an avatar config's provider-side objects match the config: `not_required` when the
 * provider needs nothing created for it, `pending` after a change, `synced` once the objects
 * exist and match, `failed` when the last attempt gave up.
 */
enum SyncStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
