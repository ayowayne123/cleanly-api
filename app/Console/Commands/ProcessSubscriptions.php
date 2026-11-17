<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Expire unpaid sign-ups, bill due renewals, retry failed charges, and create upcoming visits';

    public function handle(SubscriptionService $subscriptions): int
    {
        $expired = $subscriptions->expirePending();
        $renewals = $subscriptions->processRenewals();
        $retries = $subscriptions->processRetries();
        $visits = $subscriptions->generateAllVisits();

        $this->info("Unpaid sign-ups expired: {$expired}");
        $this->info('Renewals: '.json_encode($renewals));
        $this->info('Retries: '.json_encode($retries));
        $this->info('Visits: '.json_encode($visits));

        return self::SUCCESS;
    }
}