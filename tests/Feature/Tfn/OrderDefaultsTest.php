<?php

/**
 * TRIDENT places TFN pre-authorisation orders alongside ProSelver's
 * existing manual orders (Lize / Abri on the TFN portal), so orders
 * from both sources need to look the same to accounts at month-end.
 *
 * Two operational defaults captured from real `ORD/01/2951/*`
 * order data (2026-08-27..09-03):
 *
 *   1. Every Lize-style drive-away order uses a 4-day validity
 *      window.  TRIDENT's mount()/clearOrderForm() previously
 *      defaulted to 1 day.  Now 4 days.
 *
 *   2. Every Lize order carries a "Your ref: {DELIVERY} {VIN}"
 *      string (e.g. "BIDVEST ISUZU PTA EAST ACVFRR90LTN212673",
 *      "FAW HO AAK3534FDTB051552", "WILLIAM HUNT MIDRAND ACVBRRAR0T4209229").
 *      TRIDENT now auto-populates the Reference field from the
 *      selected vehicle's delivery-location + VIN so month-end
 *      reconciliation reads clean.
 *
 * These tests lock both defaults so a future refactor can't silently
 * regress them.
 */

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
});

function orderDefaultsUser(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');
    return $u;
}

function orderDefaultsJob(array $attrs): Job
{
    $company  = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator  = User::factory()->create();
    $pickup   = Location::create([
        'company_id' => null,
        'company_name' => $attrs['_pickup_company'] ?? 'Plant',
        'address' => 'Plant', 'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null,
        'company_name' => $attrs['_delivery_company'] ?? 'Dealer',
        'address' => 'Dealer', 'is_active' => true,
    ]);
    unset($attrs['_pickup_company'], $attrs['_delivery_company']);

    return Job::create(array_merge([
        'uuid'                => (string) Str::uuid(),
        'job_number'          => 'JOB-' . Str::upper(Str::random(8)),
        'job_type'            => 'transport',
        'status'              => Job::STATUS_IN_TRANSIT,
        'company_id'          => $company->id,
        'created_by_user_id'  => $creator->id,
        'executor_type'       => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'  => $pickup->id,
        'delivery_location_id'=> $delivery->id,
        'scheduled_date'      => now()->toDateString(),
    ], $attrs));
}

// -----------------------------------------------------------------
// 1. Four-day expiry default
// -----------------------------------------------------------------

test('the fuel-order form defaults to a 4-day expiry window on first render', function () {
    // Matches ProSelver ops's four-day operational default seen on
    // every real ORD/01/2951/* Lize-authored order.
    $expected = now()->addDays(4)->endOfDay()->format('Y-m-d\TH:i');

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->assertSet('orderExpiresAt', $expected);
});

test('Clear on the fuel-order form resets expiry back to 4 days out', function () {
    $expected = now()->addDays(4)->endOfDay()->format('Y-m-d\TH:i');

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderExpiresAt', now()->addHour()->format('Y-m-d\TH:i')) // operator picks something short
        ->call('clearOrderForm')
        ->assertSet('orderExpiresAt', $expected);
});

// -----------------------------------------------------------------
// 2. Reference auto-populates from the matching Job
// -----------------------------------------------------------------

test('picking a vehicle by VIN auto-populates the reference from the matching Jobs delivery + VIN', function () {
    // A live in-transit Job for VIN "ACVFRR90LTN212673" delivering to
    // "Bidvest Isuzu PTA East" should produce the exact reference
    // shape we see on real ProSelver orders:
    //   Your ref: BIDVEST ISUZU PTA EAST ACVFRR90LTN212673
    orderDefaultsJob([
        'vin'                => 'ACVFRR90LTN212673',
        'registration'       => null,
        'status'             => Job::STATUS_IN_TRANSIT,
        '_delivery_company'  => 'Bidvest Isuzu PTA East',
    ]);

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderRegistration', 'ACVFRR90LTN212673')
        ->assertSet('orderReference', 'BIDVEST ISUZU PTA EAST ACVFRR90LTN212673');
});

test('picking by driver trade plate auto-populates the reference just as well', function () {
    // The picker binds a VIN today, but the same auto-populate has to
    // work when future flows drop a plate or trade plate into the
    // field via a deep link.
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole('driver');
    DriverProfile::create(['user_id' => $driver->id, 'trade_plate' => 'TPJHB011']);

    orderDefaultsJob([
        'vin'                => 'AAK3534FDTB051552',
        'registration'       => null,
        'status'             => Job::STATUS_IN_TRANSIT,
        'driver_user_id'     => $driver->id,
        '_delivery_company'  => 'FAW HO',
    ]);

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderRegistration', 'TPJHB011')
        ->assertSet('orderReference', 'FAW HO AAK3534FDTB051552');
});

test('an operator-typed reference is never overwritten by the auto-populate', function () {
    // Operator override always wins.  Even if a matching Job would
    // produce a different reference, whatever the operator typed
    // sticks.
    orderDefaultsJob([
        'vin'                => 'ACVFRR90LTN212673',
        'status'             => Job::STATUS_IN_TRANSIT,
        '_delivery_company'  => 'Bidvest Isuzu PTA East',
    ]);

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderReference', 'MANUAL OVERRIDE 12345')
        ->set('orderRegistration', 'ACVFRR90LTN212673')
        ->assertSet('orderReference', 'MANUAL OVERRIDE 12345');
});

test('the reference falls back to the vehicle fixtures CustomerName + VIN when no Job matches', function () {
    // Demo-mode / stakeholder-walk-through path -- there's no Job in
    // the DB for the fixture vehicle, but the fixture itself carries a
    // realistic CustomerName + VIN pair so the reference field still
    // looks right on screen.  The fixture's Isuzu NQR500 entry has
    // VIN=ACVWR75LTG213611 and CustomerName="Isuzu Motors SA".
    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderRegistration', 'ACVWR75LTG213611')
        ->assertSet('orderReference', 'ISUZU MOTORS SA ACVWR75LTG213611');
});

test('the reference stays empty when neither a Job nor a fixture vehicle matches', function () {
    // Unknown vehicle key -- the auto-populate doesn't invent a value.
    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderRegistration', 'VIN_DOES_NOT_EXIST')
        ->assertSet('orderReference', '');
});

test('a delivered / historical Job is not picked up by the auto-populate', function () {
    // The auto-populate is scoped to in-flight statuses so it can't
    // resurrect a reference from a trip that ended last month.
    orderDefaultsJob([
        'vin'                => 'ACVFRR90LTN212673',
        'status'             => Job::STATUS_DELIVERED,   // NOT in the in-flight set
        '_delivery_company'  => 'Bidvest Isuzu PTA East',
    ]);

    Volt::actingAs(orderDefaultsUser())
        ->test('admin.fuel')
        ->set('orderRegistration', 'ACVFRR90LTN212673')
        // No live-Job match -> falls through to the fixture path,
        // which won't find this VIN in the fixture -> reference
        // stays blank.  If we ever start caching completed trips
        // as picker candidates, this assertion will loudly break.
        ->assertSet('orderReference', '');
});
