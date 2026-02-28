<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
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
        $dealerAdmin->assignRole('dealer_principal');
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

        // Demo OEM company
        $oemCompany = Company::firstOrCreate(
            ['normalized_name' => 'demo oem trucks'],
            [
                'name' => 'Demo OEM Trucks',
                'type' => 'oem',
                'address' => '1 Factory Rd, Rosslyn, Pretoria',
                'vat_number' => '4987654321',
                'billing_email' => 'accounts@demooemtrucks.test',
                'phone' => '012 987 6543',
            ]
        );

        // Demo OEM admin
        $oemAdmin = User::firstOrCreate(
            ['username' => 'oemadmin'],
            [
                'name' => 'Demo OEM Admin',
                'email' => 'admin@demooemtrucks.test',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $oemAdmin->assignRole('oem_admin');
        $oemCompany->users()->syncWithoutDetaching([$oemAdmin->id]);

        // Demo OEM planner
        $oemPlanner = User::firstOrCreate(
            ['username' => 'oemplanner'],
            [
                'name' => 'Demo OEM Planner',
                'email' => 'planner@demooemtrucks.test',
                'password' => Hash::make('changeme'),
                'is_active' => true,
            ]
        );
        $oemPlanner->assignRole('oem_planner');
        $oemCompany->users()->syncWithoutDetaching([$oemPlanner->id]);

        // Demo locations (dealer-owned)
        $dealerLocations = [
            ['company_name' => 'Demo Motors Sandton', 'address' => '123 Main Rd, Sandton', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'customer_name' => 'John Dealer', 'customer_phone' => '011 123 4567'],
            ['company_name' => 'Demo Motors Pretoria', 'address' => '45 Church St, Pretoria', 'city' => 'Pretoria', 'province' => 'Gauteng', 'customer_name' => 'Jane Dealer', 'customer_phone' => '012 345 6789'],
        ];

        foreach ($dealerLocations as $loc) {
            Location::firstOrCreate(
                ['company_name' => $loc['company_name'], 'company_id' => $company->id],
                array_merge($loc, ['company_id' => $company->id])
            );
        }

        // Demo locations (OEM-owned)
        $oemLocations = [
            ['company_name' => 'OEM Factory Rosslyn', 'address' => '1 Factory Rd, Rosslyn', 'city' => 'Pretoria', 'province' => 'Gauteng', 'customer_name' => 'Factory Manager', 'customer_phone' => '012 987 6543'],
            ['company_name' => 'OEM Distribution Centre', 'address' => '200 Truck Ave, Isando', 'city' => 'Johannesburg', 'province' => 'Gauteng'],
        ];

        foreach ($oemLocations as $loc) {
            Location::firstOrCreate(
                ['company_name' => $loc['company_name'], 'company_id' => $oemCompany->id],
                array_merge($loc, ['company_id' => $oemCompany->id])
            );
        }

        // Shared (public) locations
        $sharedLocations = [
            ['company_name' => 'Johannesburg Depot', 'address' => '1 Truck St, Germiston', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'latitude' => -26.2041, 'longitude' => 28.0473],
            ['company_name' => 'Cape Town Depot', 'address' => '100 Voortrekker Rd, Bellville', 'city' => 'Cape Town', 'province' => 'Western Cape', 'latitude' => -33.9249, 'longitude' => 18.4241],
            ['company_name' => 'Durban Depot', 'address' => '50 South Coast Rd, Durban', 'city' => 'Durban', 'province' => 'KwaZulu-Natal', 'latitude' => -29.8587, 'longitude' => 31.0218],
            ['company_name' => 'Pretoria Depot', 'address' => '25 Church St, Pretoria', 'city' => 'Pretoria', 'province' => 'Gauteng', 'latitude' => -25.7479, 'longitude' => 28.2293],
            ['company_name' => 'Gqeberha Depot', 'address' => '10 Main St, Gqeberha', 'city' => 'Gqeberha', 'province' => 'Eastern Cape', 'latitude' => -33.9608, 'longitude' => 25.6022],
            ['company_name' => 'Bloemfontein Depot', 'address' => '5 Nelson Mandela Dr, Bloemfontein', 'city' => 'Bloemfontein', 'province' => 'Free State', 'latitude' => -29.0852, 'longitude' => 26.1596],
        ];

        foreach ($sharedLocations as $loc) {
            Location::firstOrCreate(
                ['company_name' => $loc['company_name'], 'company_id' => null],
                array_merge($loc, ['company_id' => null])
            );
        }
    }
}
