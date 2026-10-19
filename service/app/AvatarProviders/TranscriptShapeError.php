<?php

namespace App\AvatarProviders;

use RuntimeException;

/**
 * The provider's transcript is not shaped as expected (a missing or unknown role, a non-string
 * text). The message says what is wrong without quoting the provider's response.
 */
final class TranscriptShapeError extends RuntimeException {}
