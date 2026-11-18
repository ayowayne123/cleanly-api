<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a leave request, off-day change or replacement can't go ahead.
// The message is safe to show to admin.
class ReplacementException extends RuntimeException
{
}