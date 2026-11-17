<?php

namespace App\Services;

use App\Contracts\SubscriptionCharger;
use App\Exceptions\ChargeFailedException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

// The stand-in until Paystack is connected: it never charges anything.
class NullSubscriptionCharger implements SubscriptionCharger
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function charge(Subscription $subscription, SubscriptionInvoice $invoice): Payment
    {
        throw new ChargeFailedException('Card charging is not connected yet.');
    }
}