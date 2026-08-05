<?php

/**
 * Driver module — Batch 1 (trust & correctness).
 *
 * The audit hit four problems in this batch:
 *
 *   1. driver_profiles.base_location was free text, so the same city
 *      landed as "JOHANNESBURG" / "Johannesburg" / "JOHANNESBUG" and
 *      the ops-dashboard filter dropdown surfaced all of them.
 *      Fixed by a driver_base_locations reference table + a data
 *      migration that canonicalises historical strings and a select
 *      picker on the create / edit forms.
 *
 *   2. "Expiring" was defined three different ways in the same
 *      module ("<60d" on the roster, "within 30d" on the ops
 *      dashboard). Fixed by pointing both surfaces at the same
 *      SystemSetting key with matching wording.
 *
 *   3. The Compliance Risks table on Driver Operations rendered a
 *      green pill for every non-at-risk credential in a row that
 *      appeared only because ONE credential was near expiry, drowning
 *      the actual warning. Fixed by only colouring the at-risk
 *      column and muting the others.
 *
 *   4. Card view for a driver whose base was Pretoria still showed
 *      Johannesburg because the raw string casing was fragmented.
 *      Fixed by (1) plus a defensive accessor that title-cases +
 *      trims on read, so every surface renders the same value.
 */

use App\Models\Company;
use App\Models\DriverBaseLocation;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner' => 'Owner',
        'developer' => 'Developer',
        'accounts' => 'Accounts',
        'super_admin' => 'Super Admin',
        'operations_controller' => 'Ops Controller',
        'dispatcher' => 'Dispatcher',
        'driver' => 'Driver',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $slug === 'driver' ? 'driver' : 'internal']);
    }
});

function dmb1User(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function dmb1PlatformCompany(): Company
{
    return Company::factory()->create([
        'name' => 'ProSelver',
        'type' => Company::TYPE_OEM,
        'is_platform_owner' => true,
    ]);
}

function dmb1DriverWithBase(?string $baseLocation, ?Company $platform = null): User
{
    $platform ??= dmb1PlatformCompany();
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('driver');
    $u->companies()->syncWithoutDetaching([$platform->id]);
    DriverProfile::create([
        'user_id' => $u->id,
        'base_location' => $baseLocation,
    ]);
    return $u;
}

// -----------------------------------------------------------------
// 1. Reference table + data migration
// -----------------------------------------------------------------

test('the reference table is seeded with the canonical SA cities', function () {
    $names = DriverBaseLocation::pickerOptions()->all();

    // Seeded list from the migration -- sanity check on the go-live
    // options ops picks from.
    expect($names)
        ->toContain('Johannesburg')
        ->toContain('Pretoria')
        ->toContain('Cape Town')
        ->toContain('Durban');
});

test('picker options only include active rows and respect sort order', function () {
    DriverBaseLocation::create(['name' => 'Sandton', 'is_active' => true, 'sort_order' => 5]);
    DriverBaseLocation::create(['name' => 'Retired Depot', 'is_active' => false, 'sort_order' => 5]);

    $options = DriverBaseLocation::pickerOptions()->all();

    expect($options)->toContain('Sandton');
    expect($options)->not->toContain('Retired Depot');
    // sort_order 5 sits above the seeded cities (sort_order 10+), so
    // Sandton comes before Johannesburg.
    expect(array_search('Sandton', $options))->toBeLessThan(array_search('Johannesburg', $options));
});

test('DriverProfile::base_location accessor title-cases and trims fragmented values on read', function () {
    // The accessor is the second line of defence: even if a raw row
    // survives the migration (or a fresh install without the migration
    // has ad-hoc data), the reader sees a tidy value.
    $cases = [
        'JOHANNESBURG'   => 'Johannesburg',
        '  pretoria  '   => 'Pretoria',
        'CAPE TOWN'      => 'Cape Town',
        'Cape Town CBD'  => 'Cape Town CBD', // mixed-case is preserved verbatim
        ''               => null,
        '   '            => null,
        null             => null,
    ];

    foreach ($cases as $raw => $expected) {
        // Use DB::insert to bypass the setter -- we're specifically
        // testing what the accessor does when it reads dirty rows.
        DB::table('driver_profiles')->truncate();
        $user = User::factory()->create();
        DB::table('driver_profiles')->insert([
            'user_id' => $user->id,
            'base_location' => $raw,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profile = DriverProfile::where('user_id', $user->id)->first();
        expect($profile->base_location)->toBe($expected, "raw='{$raw}' should read as '" . var_export($expected, true) . "'");
    }
});

// -----------------------------------------------------------------
// 2. Create / edit forms constrain writes to the controlled list
// -----------------------------------------------------------------

test('the create form rejects a base location outside the controlled list', function () {
    $accounts = dmb1User('accounts');
    dmb1PlatformCompany();
    $this->actingAs($accounts);

    Volt::test('admin.drivers.create')
        ->set('name', 'Test Driver')
        ->set('username', 'test.driver')
        ->set('password', 'password123')
        ->set('baseLocation', 'JOHANNESBUG')  // typo -- must be rejected now
        ->call('save')
        ->assertHasErrors(['baseLocation']);

    expect(DriverProfile::count())->toBe(0);
});

test('the create form accepts a base location from the controlled list and saves it verbatim', function () {
    $accounts = dmb1User('accounts');
    dmb1PlatformCompany();
    $this->actingAs($accounts);

    Volt::test('admin.drivers.create')
        ->set('name', 'Test Driver')
        ->set('username', 'test.driver')
        ->set('password', 'password123')
        ->set('baseLocation', 'Pretoria')
        ->call('save')
        ->assertHasNoErrors();

    $profile = DriverProfile::first();
    expect($profile)->not->toBeNull();
    expect($profile->base_location)->toBe('Pretoria');
});

test('the create form allows an empty base location', function () {
    $accounts = dmb1User('accounts');
    dmb1PlatformCompany();
    $this->actingAs($accounts);

    Volt::test('admin.drivers.create')
        ->set('name', 'No Depot Driver')
        ->set('username', 'no.depot')
        ->set('password', 'password123')
        ->set('baseLocation', '')
        ->call('save')
        ->assertHasNoErrors();
});

test('the edit form grandfathers in the drivers current value if it was removed from the picker', function () {
    // A depot ops has since deactivated (say a satellite yard closed)
    // must still let the driver's row save without a forced re-pick.
    $accounts = dmb1User('accounts');
    $platform = dmb1PlatformCompany();
    $driver = dmb1DriverWithBase('Retired Yard', $platform);
    $this->actingAs($accounts);

    Volt::test('admin.drivers.edit', ['user' => $driver])
        ->set('name', $driver->name)
        ->set('username', $driver->username)
        // baseLocation stays as-is (Retired Yard, not in the picker)
        ->call('save')
        ->assertHasNoErrors();
});

// -----------------------------------------------------------------
// 3. Unified expiry threshold
// -----------------------------------------------------------------

test('the roster Action Required panel honours drivers.expiry_soon_days', function () {
    SystemSetting::set('drivers.expiry_soon_days', 45);

    $owner = dmb1User('owner');
    $platform = dmb1PlatformCompany();

    // A driver whose PDP expires in 40 days -- inside the 45-day
    // configured window but outside the old hardcoded 60-day roster
    // window. Under the fix this driver appears in Action Required
    // because the ROSTER now uses the same key the ops dashboard has
    // always used (drivers.expiry_soon_days).
    $driver = dmb1DriverWithBase(null, $platform);
    $driver->driverProfile->update(['prdp_expiry' => now()->addDays(40)->toDateString()]);

    $this->actingAs($owner);

    $component = Volt::test('admin.drivers.index');
    expect($component->viewData('expiryWarnDays'))->toBe(45);
    expect($component->viewData('attentionExpiringCount'))->toBe(1);
});

test('the roster narrows when the threshold is tightened', function () {
    // Same driver, tighter window -- must fall OUT of the Action
    // Required list, proving the setting is the effective threshold
    // (not a hardcoded fallback).
    SystemSetting::set('drivers.expiry_soon_days', 15);

    $owner = dmb1User('owner');
    $platform = dmb1PlatformCompany();

    $driver = dmb1DriverWithBase(null, $platform);
    $driver->driverProfile->update(['prdp_expiry' => now()->addDays(40)->toDateString()]);

    $this->actingAs($owner);

    $component = Volt::test('admin.drivers.index');
    expect($component->viewData('expiryWarnDays'))->toBe(15);
    expect($component->viewData('attentionExpiringCount'))->toBe(0);
});

// -----------------------------------------------------------------
// 4. Compliance risks table dilution
//
// The Ops dashboard uses Postgres-only `count(*) filter (where ...)`
// aggregate SQL that SQLite rejects, so we can't render the whole
// dashboard in tests. Instead the badge classification was extracted
// to DriverProfile::expiryBadge() and we test it directly here --
// this is the exact code path the compliance table uses to decide
// whether to render a coloured pill or a muted date.
// -----------------------------------------------------------------

test('expiryBadge returns not-at-risk for a comfortably-future date', function () {
    [$variant, $label, $atRisk] = DriverProfile::expiryBadge(now()->addYears(4), 30);

    expect($variant)->toBe('green');
    expect($atRisk)->toBeFalse();
    expect($label)->not->toStartWith('Due ');
    expect($label)->not->toStartWith('Expired ');
});

test('expiryBadge returns at-risk for a date inside the soon window', function () {
    [$variant, $label, $atRisk] = DriverProfile::expiryBadge(now()->addDays(20), 30);

    expect($variant)->toBe('amber');
    expect($atRisk)->toBeTrue();
    expect($label)->toStartWith('Due ');
});

test('expiryBadge returns at-risk for a past date', function () {
    [$variant, $label, $atRisk] = DriverProfile::expiryBadge(now()->subDays(5), 30);

    expect($variant)->toBe('red');
    expect($atRisk)->toBeTrue();
    expect($label)->toStartWith('Expired ');
});

test('expiryBadge returns not-at-risk for a missing date so the row does not paint over an absent credential', function () {
    [$variant, $label, $atRisk] = DriverProfile::expiryBadge(null, 30);

    expect($variant)->toBe('slate');
    expect($atRisk)->toBeFalse();
    expect($label)->toBe('Missing');
});

test('a row with one at-risk credential and two far-future credentials only lights up the one at risk', function () {
    // The audit's Clifford Chinyani reproduction: a trade plate near
    // expiry sits alongside a licence and PDP dated 2030. The row IS
    // on the risk list (there is one real problem) but only the
    // trade plate column is at-risk. The other two must NOT be at
    // risk -- that's what stops the two green pills from drowning
    // the amber one.
    $today = now();
    [, , $licAtRisk] = DriverProfile::expiryBadge($today->copy()->addYears(4), 30);
    [, , $pdpAtRisk] = DriverProfile::expiryBadge($today->copy()->addYears(4), 30);
    [, , $tpAtRisk]  = DriverProfile::expiryBadge($today->copy()->addDays(20), 30);

    expect($licAtRisk)->toBeFalse();
    expect($pdpAtRisk)->toBeFalse();
    expect($tpAtRisk)->toBeTrue();
});
