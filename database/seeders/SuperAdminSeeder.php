<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Creates the initial super_admin account on an empty install.
     *
     * Hardening notes:
     *  - Refuses to run on production with an empty or default
     *    SUPER_ADMIN_PASSWORD. The previous behaviour silently fell back to
     *    the published default "changeme", which is the first thing an
     *    attacker tries.
     *  - When the seeder DOES run it sets `must_change_password = true` so
     *    the very first login is forced into a rotation regardless of how
     *    strong the bootstrap password is.
     */
    public function run(): void
    {
        $username = env('SUPER_ADMIN_USERNAME', 'admin');
        $password = env('SUPER_ADMIN_PASSWORD');
        $name = env('SUPER_ADMIN_NAME', 'System Admin');

        $isProduction = app()->environment('production');
        $isWeak = empty($password)
            || strtolower((string) $password) === 'changeme'
            || strlen((string) $password) < 12;

        if ($isProduction && $isWeak) {
            throw new \RuntimeException(
                'SuperAdminSeeder refused to run: SUPER_ADMIN_PASSWORD is empty, the default "changeme", or shorter than 12 characters. Set a strong value in .env before seeding in production.'
            );
        }

        if ($isWeak) {
            $password = $password ?: 'changeme';
            $this->command?->warn('SuperAdminSeeder: using weak/default password — acceptable in local/dev only.');
        }

        $user = User::firstOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        $user->assignRole('super_admin');
    }
}
