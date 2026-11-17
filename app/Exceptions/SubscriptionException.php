<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a subscription can't be created, changed or ended.
// The message is safe to show to the client or admin.
class SubscriptionException extends RuntimeException
{
}