<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Zone '.fake()->unique()->lexify('???'),
            'transport_fee_k' => fake()->randomElement([200000, 300000, 400000, 500000]),
            'is_active' => true,
        ];
    }
}