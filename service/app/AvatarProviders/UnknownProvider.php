<?php

namespace App\AvatarProviders;

use DomainException;

/**
 * An identifier that no adapter is registered under. Raised before any adapter is built or any
 * request is made.
 */
final class UnknownProvider extends DomainException {}
