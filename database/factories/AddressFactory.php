<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Area;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'label' => fake()->randomElement(['Home', 'Office', 'Family house']),
            'street' => fake()->streetAddress(),
            'landmark' => fake()->optional(0.6)->words(3, true),
            // use a seeded area when there is one, otherwise make a new one
            'area_id' => Area::query()->inRandomOrder()->value('id') ?? Area::factory(),
            'is_default' => false,
        ];
    }
}