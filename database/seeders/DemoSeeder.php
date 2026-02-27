<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Demo company
        $company = Company::firstOrCreate(
            ['normalized_name' => 'demo motors'],
            [
                'name' => 'Demo Motors',
                'address' => '123 Main Rd, Johannesburg',
                'vat_number' => '4123456789',
                'billing_email' => 'accounts@demomotors.test',
                'phone' => '011 123 4567',
            ]
        );

        // Demo dealer admin
        $dealerAdmin = User::firstOrCreate(
            ['username' => 'dealer'],
            [
                'name' => 'Demo Dealer Admin',
                'email' => 'dealer@demomotors.test',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $dealerAdmin->assignRole('dealer_admin');
        $company->users()->syncWithoutDetaching([$dealerAdmin->id]);

        // Demo ops manager
        $opsManager = User::firstOrCreate(
            ['username' => 'ops'],
            [
                'name' => 'Demo Ops Manager',
                'email' => 'ops@proselver.test',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $opsManager->assignRole('ops_manager');

        // Demo dispatcher
        $dispatcher = User::firstOrCreate(
            ['username' => 'dispatch'],
            [
                'name' => 'Demo Dispatcher',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $dispatcher->assignRole('dispatcher');

        // Demo driver
        $driver = User::firstOrCreate(
            ['username' => 'driver'],
            [
                'name' => 'Demo Driver',
                'phone' => '082 123 4567',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $driver->assignRole('driver');

        // Demo accounts
        $accounts = User::firstOrCreate(
            ['username' => 'accounts'],
            [
                'name' => 'Demo Accounts',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $accounts->assignRole('accounts');

        // Demo hubs
        $hubs = [
            ['name' => 'Johannesburg Hub', 'address' => '1 Truck St, Germiston', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'latitude' => -26.2041, 'longitude' => 28.0473],
            ['name' => 'Cape Town Hub', 'address' => '100 Voortrekker Rd, Bellville', 'city' => 'Cape Town', 'province' => 'Western Cape', 'latitude' => -33.9249, 'longitude' => 18.4241],
            ['name' => 'Durban Hub', 'address' => '50 South Coast Rd, Durban', 'city' => 'Durban', 'province' => 'KwaZulu-Natal', 'latitude' => -29.8587, 'longitude' => 31.0218],
            ['name' => 'Pretoria Hub', 'address' => '25 Church St, Pretoria', 'city' => 'Pretoria', 'province' => 'Gauteng', 'latitude' => -25.7479, 'longitude' => 28.2293],
            ['name' => 'Port Elizabeth Hub', 'address' => '10 Main St, Gqeberha', 'city' => 'Gqeberha', 'province' => 'Eastern Cape', 'latitude' => -33.9608, 'longitude' => 25.6022],
            ['name' => 'Bloemfontein Hub', 'address' => '5 Nelson Mandela Dr, Bloemfontein', 'city' => 'Bloemfontein', 'province' => 'Free State', 'latitude' => -29.0852, 'longitude' => 26.1596],
        ];

        foreach ($hubs as $hub) {
            Hub::firstOrCreate(['name' => $hub['name']], $hub);
        }
    }
}
