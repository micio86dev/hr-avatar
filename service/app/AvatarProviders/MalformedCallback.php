<?php

namespace App\AvatarProviders;

use RuntimeException;

/**
 * A provider callback that has the right envelope but that the adapter cannot turn into an event (a time no database
 * can hold, a member of the wrong shape). It answers 422 `validation_failed`: the message is for the log and says what
 * is wrong without quoting the provider's body, and is never returned to the caller.
 */
final class MalformedCallback extends RuntimeException {}
