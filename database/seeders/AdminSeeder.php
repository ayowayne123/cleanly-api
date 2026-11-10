<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrNew(['phone' => env('ADMIN_PHONE', '+2348000000000')]);

        $user->fill([
            'name' => env('ADMIN_NAME', 'Cleanly Admin'),
            'email' => env('ADMIN_EMAIL', 'admin@cleanly.test'),
            'role' => 'admin',
            'admin_level' => 'super_admin',
            'status' => 'active',
        ]);

        // Only set a password for a new admin, or when ADMIN_PASSWORD is in .env.
        // Re-running the seeder never silently changes an existing admin's password.
        $envPassword = env('ADMIN_PASSWORD');

        if ($envPassword || ! $user->exists) {
            $password = $envPassword ?: Str::password(14);
            $user->password = $password;

            if (! $envPassword) {
                $this->command?->warn("Generated admin password (shown once): {$password}");
            }
        }

        $user->save();

        $this->command?->info("Admin login: {$user->phone} / {$user->email}");
    }
}