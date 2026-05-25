<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('changeme');

        // ===== DEVELOPER =====
        $developer = $this->seedUser(
            ['username' => 'developer'],
            ['name' => 'Developer', 'email' => 'dev@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $developer->assignRole('developer');

        // ===== INTERNAL STAFF =====
        $owner = $this->seedUser(
            ['username' => 'owner'],
            ['name' => 'Business Owner', 'email' => 'owner@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $owner->assignRole('owner');

        $opsController = $this->seedUser(
            ['username' => 'ops'],
            ['name' => 'Cassius Jege', 'email' => 'cassius@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $opsController->syncRoles(['operations_controller']);

        $opsController2 = $this->seedUser(
            ['username' => 'wiaan'],
            ['name' => 'Wiaan Swart', 'email' => 'wiaan@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $opsController2->assignRole('operations_controller');

        $dispatcher = $this->seedUser(
            ['username' => 'dispatch'],
            ['name' => 'Michael Esbie', 'email' => 'michael@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $dispatcher->syncRoles(['dispatcher']);

        $dispatcher2 = $this->seedUser(
            ['username' => 'busani'],
            ['name' => 'Busani Ndwandwe', 'email' => 'busani@tcdc.test', 'password' => $password, 'is_active' => true]
        );
        $dispatcher2->assignRole('dispatcher');

        $accounts = $this->seedUser(
            ['username' => 'accounts'],
            ['name' => 'Demo Accounts', 'password' => $password, 'is_active' => true]
        );
        $accounts->assignRole('accounts');

        // ===== PLATFORM OWNER (TRIDENT Control & Dispatch Center) =====
        // The company operating the platform. Flagged `is_platform_owner = true`
        // so User::belongsToPlatformOwner() returns true for any user linked
        // here. Type is 'transporter' because the same entity also physically
        // moves vehicles today; once 3PL transporters are onboarded they
        // become additional `type = 'transporter'` rows without this flag.
        $platformOwner = Company::firstOrCreate(
            ['normalized_name' => 'trident control & dispatch center'],
            [
                'name' => 'TRIDENT Control & Dispatch Center',
                'type' => 'transporter',
                'workflow_type' => 'standard',
                'is_platform_owner' => true,
                'billing_email' => 'billing@tcdc.test',
            ]
        );
        // Ensure the flag is set even if the row pre-existed from an earlier
        // run that didn't know about is_platform_owner.
        if (! $platformOwner->is_platform_owner) {
            $platformOwner->update(['is_platform_owner' => true]);
        }

        // Link internal staff to the platform-owner company so the new
        // visibility helpers (belongsToPlatformOwner / operatingCompanyIds)
        // resolve correctly. syncWithoutDetaching preserves any prior pivot
        // rows, so this is idempotent and safe to re-run.
        $platformOwner->users()->syncWithoutDetaching([
            $owner->id => ['location_id' => null],
            $opsController->id => ['location_id' => null],
            $opsController2->id => ['location_id' => null],
            $dispatcher->id => ['location_id' => null],
            $dispatcher2->id => ['location_id' => null],
            $accounts->id => ['location_id' => null],
        ]);

        // ===== FAW COMPANY (requires external confirmation) =====
        $faw = Company::firstOrCreate(
            ['normalized_name' => 'faw south africa'],
            [
                'name' => 'FAW South Africa',
                'type' => 'oem',
                'workflow_type' => 'faw',
                'address' => '1 FAW Drive, Coega IDZ, Gqeberha',
                'vat_number' => '4111111111',
                'billing_email' => 'accounts@faw.co.za',
                'phone' => '041 404 0000',
            ]
        );

        // FAW locations (created first so we can assign them to users)
        $fawCoega = Location::firstOrCreate(
            ['company_name' => 'FAW Coega Plant', 'company_id' => $faw->id],
            ['address' => '1 FAW Drive, Coega IDZ', 'city' => 'Gqeberha', 'province' => 'Eastern Cape', 'customer_name' => 'Plant Manager', 'customer_phone' => '041 404 0001', 'company_id' => $faw->id]
        );
        $fawJhb = Location::firstOrCreate(
            ['company_name' => 'FAW Johannesburg Depot', 'company_id' => $faw->id],
            ['address' => '120 Buccleuch Dr, Sandton', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'customer_name' => 'JHB Manager', 'customer_phone' => '011 555 0001', 'company_id' => $faw->id]
        );

        $fawOwner = $this->seedUser(
            ['username' => 'fawowner'],
            ['name' => 'FAW Customer Owner', 'email' => 'owner@faw.test', 'password' => $password, 'is_active' => true]
        );
        $fawOwner->assignRole('customer_owner');
        $faw->users()->syncWithoutDetaching([$fawOwner->id => ['location_id' => null]]);

        $fawDispatcher = $this->seedUser(
            ['username' => 'fawdispatch'],
            ['name' => 'FAW Coega Dispatcher', 'email' => 'dispatcher@faw.test', 'password' => $password, 'is_active' => true]
        );
        $fawDispatcher->assignRole('customer_dispatcher');
        $faw->users()->syncWithoutDetaching([$fawDispatcher->id => ['location_id' => $fawCoega->id]]);

        $fawJhbDispatcher = $this->seedUser(
            ['username' => 'fawjhb'],
            ['name' => 'FAW JHB Dispatcher', 'email' => 'jhb@faw.test', 'password' => $password, 'is_active' => true]
        );
        $fawJhbDispatcher->assignRole('customer_dispatcher');
        $faw->users()->syncWithoutDetaching([$fawJhbDispatcher->id => ['location_id' => $fawJhb->id]]);

        $fawUser = $this->seedUser(
            ['username' => 'fawuser'],
            ['name' => 'FAW User', 'email' => 'user@faw.test', 'password' => $password, 'is_active' => true]
        );
        $fawUser->assignRole('customer_user');
        $faw->users()->syncWithoutDetaching([$fawUser->id => ['location_id' => $fawJhb->id]]);

        // FAW brand link
        $fawBrand = Brand::where('name', 'FAW')->first();
        if ($fawBrand) {
            $faw->brands()->syncWithoutDetaching([$fawBrand->id]);
        }

        // ===== STANDARD CUSTOMER (Isuzu) =====
        $isuzu = Company::firstOrCreate(
            ['normalized_name' => 'isuzu motors sa'],
            [
                'name' => 'Isuzu Motors SA',
                'type' => 'oem',
                'workflow_type' => 'standard',
                'address' => '1 Isuzu Way, Struandale, Gqeberha',
                'vat_number' => '4222222222',
                'billing_email' => 'accounts@isuzu.test',
                'phone' => '041 995 0000',
            ]
        );

        $isuzuPlant = Location::firstOrCreate(
            ['company_name' => 'Isuzu Struandale Plant', 'company_id' => $isuzu->id],
            ['address' => '1 Isuzu Way, Struandale', 'city' => 'Gqeberha', 'province' => 'Eastern Cape', 'customer_name' => 'Plant Manager', 'customer_phone' => '041 995 0001', 'company_id' => $isuzu->id]
        );

        $isuzuAdmin = $this->seedUser(
            ['username' => 'isuzuadmin'],
            ['name' => 'Isuzu Admin', 'email' => 'admin@isuzu.test', 'password' => $password, 'is_active' => true]
        );
        $isuzuAdmin->assignRole('customer_admin');
        $isuzu->users()->syncWithoutDetaching([$isuzuAdmin->id => ['location_id' => $isuzuPlant->id]]);

        $isuzuBrand = Brand::where('name', 'Isuzu')->first();
        if ($isuzuBrand) {
            $isuzu->brands()->syncWithoutDetaching([$isuzuBrand->id]);
        }

        // ===== LEGACY DEMO COMPANY (Dealer) =====
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

        $dealerAdmin = $this->seedUser(
            ['username' => 'dealer'],
            ['name' => 'Demo Dealer Admin', 'email' => 'dealer@demomotors.test', 'password' => $password, 'is_active' => true]
        );
        $dealerAdmin->assignRole('dealer_principal');
        $company->users()->syncWithoutDetaching([$dealerAdmin->id]);

        // Demo dealer locations
        Location::firstOrCreate(
            ['company_name' => 'Demo Motors Sandton', 'company_id' => $company->id],
            ['address' => '123 Main Rd, Sandton', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'customer_name' => 'John Dealer', 'customer_phone' => '011 123 4567', 'company_id' => $company->id]
        );
        Location::firstOrCreate(
            ['company_name' => 'Demo Motors Pretoria', 'company_id' => $company->id],
            ['address' => '45 Church St, Pretoria', 'city' => 'Pretoria', 'province' => 'Gauteng', 'customer_name' => 'Jane Dealer', 'customer_phone' => '012 345 6789', 'company_id' => $company->id]
        );

        $this->call(CfaoDealerSeeder::class);

        // ===== DRIVERS WITH FULL PROFILES =====
        $driver1 = $this->seedUser(
            ['username' => 'driver'],
            ['name' => 'Thabo Molefe', 'phone' => '082 123 4567', 'password' => $password, 'is_active' => true]
        );
        $driver1->assignRole('driver');
        DriverProfile::updateOrCreate(
            ['user_id' => $driver1->id],
            [
                'id_number' => '8501015123081',
                'cellphone' => '082 123 4567',
                'base_location' => 'Johannesburg',
                'license_code' => 'EC',
                'license_number' => 'LIC-001234',
                'license_expiry' => now()->addMonths(8),
                'prdp_expiry' => now()->addMonths(4),
            ]
        );

        $driver2 = $this->seedUser(
            ['username' => 'driver2'],
            ['name' => 'Sipho Nkosi', 'phone' => '083 987 6543', 'password' => $password, 'is_active' => true]
        );
        $driver2->assignRole('driver');
        DriverProfile::updateOrCreate(
            ['user_id' => $driver2->id],
            [
                'id_number' => '9003025234089',
                'cellphone' => '083 987 6543',
                'base_location' => 'Pretoria',
                'license_code' => 'EC1',
                'license_number' => 'LIC-005678',
                'license_expiry' => now()->addMonths(14),
                'prdp_expiry' => now()->addMonths(2),
            ]
        );

        $driver3 = $this->seedUser(
            ['username' => 'driver3'],
            ['name' => 'David Botha', 'phone' => '071 555 0000', 'password' => $password, 'is_active' => true]
        );
        $driver3->assignRole('driver');
        DriverProfile::updateOrCreate(
            ['user_id' => $driver3->id],
            [
                'id_number' => '8712105345083',
                'cellphone' => '071 555 0000',
                'base_location' => 'Gqeberha',
                'license_code' => 'C1',
                'license_number' => 'LIC-009012',
                'license_expiry' => now()->subMonths(1),
                'prdp_expiry' => now()->addMonths(6),
            ]
        );

        // ===== SHARED LOCATIONS =====
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

    private function seedUser(array $keys, array $values): User
    {
        $user = User::withTrashed()->firstOrCreate($keys, $values);

        if ($user->trashed()) {
            $user->restore();
        }

        return $user;
    }
}
