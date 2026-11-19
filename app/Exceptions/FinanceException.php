<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a payment, refund or expense can't be recorded.
// The message is safe to show to admin.
class FinanceException extends RuntimeException
{
}