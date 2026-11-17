<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown by a SubscriptionCharger when the card could not be charged.
class ChargeFailedException extends RuntimeException
{
}