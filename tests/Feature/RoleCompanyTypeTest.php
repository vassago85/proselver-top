<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Role / company-type model guarantees:
 *   - User::company() is deterministic (is_primary wins, then lowest id).
 *   - tenantRoleDisplayName re-skins customer roles by company type.
 *   - Legacy dealer and oem roles reach permission parity with their
 *     customer-tier equivalents after seeding.
 *   - roles:migrate-legacy-tenants is additive and sets company type.
 */

test('company() prefers the is_primary pivot then the lowest company id', function () {
    $user = User::factory()->create();
    $a = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $b = Company::factory()->create(['type' => Company::TYPE_DEALER]);

    // Attach high-id first; with no primary flag, lowest id wins.
    $user->companies()->attach([$b->id, $a->id]);
    expect($user->fresh()->company()->id)->toBe(min($a->id, $b->id));

    // Flag the higher-id company primary — now it wins regardless of id.
    $user->companies()->updateExistingPivot($b->id, ['is_primary' => true]);
    expect($user->fresh()->company()->id)->toBe($b->id);
});

test('tenantRoleDisplayName reskins customer roles by company type', function () {
    expect(tenantRoleDisplayName('Customer Owner', Company::TYPE_DEALER))->toBe('Dealer Owner');
    expect(tenantRoleDisplayName('Customer Owner', Company::TYPE_OEM))->toBe('OEM Owner');
    expect(tenantRoleDisplayName('Customer Owner', Company::TYPE_CUSTOMER))->toBe('Customer Owner');
    expect(tenantRoleDisplayName('Customer Owner', null))->toBe('Customer Owner');
});

test('dealer_principal reaches permission parity with customer_owner after seeding', function () {
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    Role::firstOrCreate(['slug' => 'dealer_principal'], ['name' => 'Dealer Principal', 'tier' => 'dealer']);

    $this->seed(PermissionSeeder::class);

    $owner = Role::where('slug', 'customer_owner')->first()->permissions->pluck('slug')->sort()->values();
    $principal = Role::where('slug', 'dealer_principal')->first()->permissions->pluck('slug')->sort()->values();

    expect($principal->all())->toBe($owner->all());
});

test('migrate-legacy-tenants attaches the customer equivalent and sets company type', function () {
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    $legacy = Role::firstOrCreate(['slug' => 'dealer_principal'], ['name' => 'Dealer Principal', 'tier' => 'dealer']);

    $company = Company::factory()->create(['type' => Company::TYPE_CUSTOMER]);
    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$legacy->id]);
    $user->companies()->attach($company->id);

    $this->artisan('roles:migrate-legacy-tenants')->assertSuccessful();

    $user->refresh();
    expect($user->hasRole('customer_owner'))->toBeTrue();
    // Legacy role is kept (additive, non-destructive).
    expect($user->hasRole('dealer_principal'))->toBeTrue();
    expect($company->fresh()->type)->toBe(Company::TYPE_DEALER);
});

test('migrate-legacy-tenants dry run writes nothing', function () {
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    $legacy = Role::firstOrCreate(['slug' => 'dealer_principal'], ['name' => 'Dealer Principal', 'tier' => 'dealer']);

    $company = Company::factory()->create(['type' => Company::TYPE_CUSTOMER]);
    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$legacy->id]);
    $user->companies()->attach($company->id);

    $this->artisan('roles:migrate-legacy-tenants', ['--dry-run' => true])->assertSuccessful();

    $user->refresh();
    expect($user->hasRole('customer_owner'))->toBeFalse();
    expect($company->fresh()->type)->toBe(Company::TYPE_CUSTOMER);
});
