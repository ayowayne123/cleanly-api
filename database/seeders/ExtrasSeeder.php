<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class ExtrasSeeder extends Seeder
{
    // Kobo. All placeholders.
    public function run(): void
    {
        $rooms = [
            // [name, icon, price_k]
            ['Living room', 'sofa', 500000],              // ₦5,000
            ['Kitchen', 'cooking-pot', 300000],           // ₦3,000
            ['Dining area', 'utensils', 300000],
            ['Study / home office', 'laptop', 300000],
            ['Balcony / terrace', 'sun', 200000],
            ['Laundry room', 'washing-machine', 200000],
        ];

        foreach ($rooms as [$name, $icon, $price]) {
            RoomType::updateOrCreate(['name' => $name], [
                'icon' => $icon,
                'price_k' => $price,
                'extra_minutes' => 0,   // room time is already covered by the home size tier
                'is_active' => true,
            ]);
        }

        $addons = [
            // [name, price_k, extra_minutes]
            ['Inside oven cleaning', 300000, 30],
            ['Inside fridge cleaning', 250000, 20],
            ['Interior windows', 400000, 45],
            ['Inside cabinets and wardrobes', 400000, 45],
            ['Carpet / rug shampooing', 600000, 60],
            ['Mattress sanitising (per mattress)', 500000, 30],
        ];

        foreach ($addons as [$name, $price, $minutes]) {
            Addon::updateOrCreate(['name' => $name], [
                'price_k' => $price,
                'extra_minutes' => $minutes,
                'is_active' => true,
            ]);
        }
    }
}