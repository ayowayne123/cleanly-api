<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a booking can't be created, cancelled or expired.
// The message is safe to show to the client or admin.
class BookingException extends RuntimeException
{
}