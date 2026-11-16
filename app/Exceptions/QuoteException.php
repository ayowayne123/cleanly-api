<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown when a quote can't be worked out. The message is safe to show to the client.
class QuoteException extends RuntimeException
{
}