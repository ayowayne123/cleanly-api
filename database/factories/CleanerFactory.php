<?php

namespace Database\Factories;

use App\Models\Cleaner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cleaner>
 */
class CleanerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->cleaner(),
            'whatsapp_number' => fake()->numerify('+23481########'),
            'date_of_birth' => fake()->dateTimeBetween('-45 years', '-21 years'),
            'home_address' => fake()->streetAddress().', Lagos',
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => fake()->numerify('+23470########'),
            'emergency_contact_relationship' => fake()->randomElement(['Spouse', 'Sibling', 'Parent', 'Friend']),
            'status' => 'active',
            'weekly_off_day' => fake()->numberBetween(0, 6),
            'bank_name' => fake()->randomElement(['GTBank', 'Access Bank', 'Zenith Bank', 'First Bank', 'UBA']),
            'account_number' => fake()->numerify('##########'),
            'account_name' => fake()->name(),
            'hired_at' => fake()->dateTimeBetween('-2 years', 'now'),
        ];
    }

    // Still being onboarded: no off day or emergency contact yet
    public function onboarding(): static
    {
        return $this->state([
            'status' => 'onboarding',
            'weekly_off_day' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'emergency_contact_relationship' => null,
        ]);
    }

    // 0 = Sunday ... 6 = Saturday
    public function offOn(int $weekday): static
    {
        return $this->state(['weekly_off_day' => $weekday]);
    }

    public function inZones(iterable $zones): static
    {
        return $this->afterCreating(fn (Cleaner $cleaner) => $cleaner->zones()->attach($zones));
    }
}