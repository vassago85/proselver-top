<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('SUPER_ADMIN_USERNAME', 'admin');
        $password = env('SUPER_ADMIN_PASSWORD', 'changeme');
        $name = env('SUPER_ADMIN_NAME', 'System Admin');

        $user = User::firstOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        $user->assignRole('super_admin');
    }
}
