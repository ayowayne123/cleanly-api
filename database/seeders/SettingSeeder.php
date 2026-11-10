<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::updateOrCreate(['name' => 'work_hours'], ['value' => [
            'mon' => '10:00', 'tue' => '10:00', 'wed' => '10:00',
            'thu' => '10:00', 'fri' => '10:00', 'sat' => '10:00',
            'sun' => '14:00',
        ]]);

        Setting::updateOrCreate(['name' => 'work_days_ahead'], ['value' => 90]);
    }
}