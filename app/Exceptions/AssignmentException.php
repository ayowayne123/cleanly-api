<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a cleaner can't be put on (or taken off) a booking.
// The message is safe to show to admin.
class AssignmentException extends RuntimeException
{
}