<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

interface SubscriptionCharger
{
    // False until a payment provider is connected. Renewals wait instead of failing.
    public function isConfigured(): bool;

    /**
     * Charge the subscription's saved card for this invoice.
     * Return the successful Payment, or throw ChargeFailedException with a message.
     */
    public function charge(Subscription $subscription, SubscriptionInvoice $invoice): Payment;
}