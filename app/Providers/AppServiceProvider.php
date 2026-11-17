<?php

namespace App\Providers;

use App\Contracts\SubscriptionCharger;
use App\Services\NullSubscriptionCharger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap NullSubscriptionCharger for the Paystack charger once Paystack is connected
        $this->app->bind(SubscriptionCharger::class, NullSubscriptionCharger::class);
    }

    public function boot(): void
    {
        //
    }
}