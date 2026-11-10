<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'work_hours' => [
                'mon' => '10:00', 'tue' => '10:00', 'wed' => '10:00',
                'thu' => '10:00', 'fri' => '10:00', 'sat' => '10:00',
                'sun' => '14:00',
            ],
            'work_days_ahead' => 90,
            // size score = bedrooms + bathrooms + extra rooms (1 point each)
            'size_score_formula' => ['bedroom' => 1, 'bathroom' => 1, 'extra_room' => 1],
            'service_fee_k' => 0,              // placeholder
            'extra_cleaner_fee_k' => 500000,   // placeholder: ₦5,000
            'reclean_window_hours' => 6,
            'unpaid_expiry_minutes' => 30,
            'visit_generation_weeks_ahead' => 8,
            'preferred_cleaner_max_conflicts' => 0,
            'payment_retry_schedule' => [1, 3, 5],   // days after a failed renewal
        ];

        foreach ($settings as $name => $value) {
            Setting::updateOrCreate(['name' => $name], ['value' => $value]);
        }
    }
}