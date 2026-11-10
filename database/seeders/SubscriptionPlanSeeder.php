<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Once a week',  'visits_per_week' => 1, 'weeks_per_period' => 4, 'discount_percent' => 10],
            ['name' => 'Twice a week', 'visits_per_week' => 2, 'weeks_per_period' => 4, 'discount_percent' => 15],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}