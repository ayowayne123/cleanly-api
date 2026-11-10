<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WorkDay;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;

class GenerateWorkDays extends Command
{
    protected $signature = 'work-days:generate {--days= : How many days ahead (defaults to the work_days_ahead setting)}';

    protected $description = 'Create open work days ahead of time from the work_hours setting';

    public function handle(): int
    {
        $hours = Setting::where('name', 'work_hours')->first()?->value;

        if (! $hours) {
            $this->error('Run the SettingSeeder first: work_hours is not set.');
            return self::FAILURE;
        }

        $ahead = (int) ($this->option('days')
            ?: Setting::where('name', 'work_days_ahead')->first()?->value
            ?: 90);

        $created = 0;

        foreach (CarbonPeriod::create(today(), today()->addDays($ahead)) as $date) {
            $opensAt = $hours[strtolower($date->format('D'))] ?? null; // mon, tue, ...

            if (! $opensAt) {
                continue; // weekday not in work_hours = closed
            }

            // firstOrCreate never overwrites a row, so a date admin has closed stays closed
            $day = WorkDay::firstOrCreate(
                ['work_date' => $date->toDateString()],
                ['is_open' => true, 'opens_at' => $opensAt],
            );

            if ($day->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("Created {$created} new work days.");

        return self::SUCCESS;
    }
}