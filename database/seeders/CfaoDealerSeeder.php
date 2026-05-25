<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed the CFAO dealer group and Williams Hunt sibling dealerships.
 * Safe to re-run on production:
 *
 *   php artisan db:seed --class=CfaoDealerSeeder --force
 */
class CfaoDealerSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('changeme');

        $cfao = CompanyGroup::withTrashed()->firstOrCreate(
            ['normalized_name' => 'cfao'],
            ['name' => 'CFAO', 'is_active' => true]
        );
        if ($cfao->trashed()) {
            $cfao->restore();
        }

        $this->seedDealership(
            password: $password,
            groupId: $cfao->id,
            normalizedName: 'williams hunt pretoria',
            name: 'Williams Hunt Pretoria',
            address: 'Centurion, Pretoria',
            city: 'Pretoria',
            phone: '012 328 6580',
            billingEmail: 'accounts@williamshuntpretoria.test',
            username: 'wh_pretoria',
            adminName: 'WH Pretoria Admin',
            adminEmail: 'admin@williamshuntpretoria.test',
        );

        $this->seedDealership(
            password: $password,
            groupId: $cfao->id,
            normalizedName: 'williams hunt midrand',
            name: 'Williams Hunt Midrand',
            address: 'Midrand, Gauteng',
            city: 'Midrand',
            phone: '011 254 3294',
            billingEmail: 'accounts@williamshuntmidrand.test',
            username: 'wh_midrand',
            adminName: 'WH Midrand Admin',
            adminEmail: 'admin@williamshuntmidrand.test',
        );

        $this->seedDealership(
            password: $password,
            groupId: $cfao->id,
            normalizedName: 'williams hunt roodepoort',
            name: 'Williams Hunt Roodepoort',
            address: 'Roodepoort, Gauteng',
            city: 'Roodepoort',
            phone: '011 279 5695',
            billingEmail: 'accounts@williamshuntroodepoort.test',
            username: 'wh_roodepoort',
            adminName: 'WH Roodepoort Admin',
            adminEmail: 'admin@williamshuntroodepoort.test',
        );
    }

    private function seedDealership(
        string $password,
        int $groupId,
        string $normalizedName,
        string $name,
        string $address,
        string $city,
        string $phone,
        string $billingEmail,
        string $username,
        string $adminName,
        string $adminEmail,
    ): void {
        $company = Company::withTrashed()->firstOrCreate(
            ['normalized_name' => $normalizedName],
            [
                'name'             => $name,
                'type'             => 'dealer',
                'workflow_type'    => 'standard',
                'company_group_id' => $groupId,
                'address'          => $address,
                'phone'            => $phone,
                'billing_email'    => $billingEmail,
            ]
        );
        if ($company->trashed()) {
            $company->restore();
        }
        if ($company->company_group_id !== $groupId) {
            $company->update(['company_group_id' => $groupId]);
        }

        Location::firstOrCreate(
            ['company_name' => $name, 'company_id' => $company->id],
            [
                'address'         => $city,
                'city'            => $city,
                'province'        => 'Gauteng',
                'customer_name'   => 'Dealership Manager',
                'customer_phone'  => $phone,
                'company_id'      => $company->id,
            ]
        );

        $admin = User::withTrashed()->firstOrCreate(
            ['username' => $username],
            ['name' => $adminName, 'email' => $adminEmail, 'password' => $password, 'is_active' => true]
        );
        if ($admin->trashed()) {
            $admin->restore();
        }
        $admin->assignRole('dealer_principal');
        $company->users()->syncWithoutDetaching([$admin->id => ['location_id' => null]]);
    }
}
