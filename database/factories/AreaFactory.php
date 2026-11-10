<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'name' => fake()->unique()->streetName(),
            'is_active' => true,
        ];
    }
}