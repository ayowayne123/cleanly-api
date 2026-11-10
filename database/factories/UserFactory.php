<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+23480########'),
            'email_verified_at' => now(),
            // hashed once and reused, so creating many users stays fast. Password is "password"
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'client',
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function client(): static
    {
        return $this->state(['role' => 'client', 'admin_level' => null]);
    }

    public function cleaner(): static
    {
        return $this->state(['role' => 'cleaner', 'admin_level' => null]);
    }

    public function admin(string $level = 'operations'): static
    {
        return $this->state(['role' => 'admin', 'admin_level' => $level]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}