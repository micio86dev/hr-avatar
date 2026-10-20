<?php

namespace App\AvatarProviders;

use RuntimeException;

/**
 * The provider has no transcript yet (the session is still active or it is still being prepared);
 * asking again later may succeed.
 */
final class TranscriptNotReady extends RuntimeException {}
