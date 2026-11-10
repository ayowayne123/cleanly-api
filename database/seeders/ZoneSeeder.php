<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    // PLACEHOLDERS: only Egbeda and Igando (Lagos Mainland A) are real so far.
    // Fees are in kobo.
    public function run(): void
    {
        $zones = [
            ['Lagos Mainland A', 200000, ['Egbeda', 'Igando', 'Ikotun', 'Alimosho']],
            ['Lagos Mainland B', 250000, ['Yaba', 'Surulere', 'Mushin', 'Akoka']],
            ['Ikeja & Environs', 300000, ['Ikeja GRA', 'Maryland', 'Ogba', 'Ojodu', 'Magodo']],
            ['Lagos Island', 400000, ['Victoria Island', 'Ikoyi', 'Lagos Island']],
            ['Lekki', 450000, ['Lekki Phase 1', 'Ikate', 'Chevron', 'Osapa London']],
            ['Ajah & Beyond', 500000, ['Ajah', 'Sangotedo', 'Abraham Adesanya', 'Awoyaya']],
        ];

        foreach ($zones as $i => [$name, $fee, $areas]) {
            $zone = Zone::updateOrCreate(['name' => $name], [
                'transport_fee_k' => $fee,
                'is_active' => true,
                'sort_order' => $i + 1,
            ]);

            foreach ($areas as $area) {
                $zone->areas()->firstOrCreate(['name' => $area], ['is_active' => true]);
            }
        }
    }
}