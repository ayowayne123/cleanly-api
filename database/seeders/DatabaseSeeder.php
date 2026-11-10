<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // order matters: tiers need the services, work days need the settings
        $this->call([
            SettingSeeder::class,
            SubscriptionPlanSeeder::class,
            ServiceSeeder::class,
            ExtrasSeeder::class,
            HomeSizeTierSeeder::class,
            ZoneSeeder::class,
            ExpenseCategorySeeder::class,
            AdminSeeder::class,
        ]);

        // fill the work calendar (today + 90 days)
        $this->command->call('work-days:generate');
    }
}