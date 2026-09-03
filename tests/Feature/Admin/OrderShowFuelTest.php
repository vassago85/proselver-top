<?php

/**
 * TFN fuel pre-authorisation from the order-show page.
 *
 * Ops places diesel (or overnight-stay) orders from the same desk
 * moment as petty cash but through a separate button.  Trade plate
 * must be shown and explicitly confirmed before the TFN call fires.
 * The actual TFN payload build lives in `TfnFuelOrderService`; these
 * tests exercise the Volt page's guards, wiring, and audit trail.
 */

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\TfnFuelOrderPlacement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'operations_controller' => ['Ops Controller', 'internal'],
        'driver'                => ['Driver', 'driver'],
    ] as $slug => [$name, $tier]) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $tier]);
    }
});

/**
 * Order-show test scenario: internal ops user, a plateless FAW-ish job,
 * and (optionally) an assigned driver with a trade plate.
 *
 * @return array{ops:User, job:Job, driver:?User}
 */
function fuelOrderShowScenario(?string $registration = null, ?string $tradePlate = 'TPJHB011'): array
{
    $ops = User::factory()->create(['name' => 'Lize Ops', 'is_active' => true]);
    $ops->assignRole('operations_controller');

    $driver = null;
    if ($tradePlate !== null) {
        $driver = User::factory()->create(['name' => 'Sipho Driver', 'is_active' => true]);
        $driver->assignRole('driver');
        DriverProfile::create([
            'user_id'     => $driver->id,
            'trade_plate' => $tradePlate,
        ]);
        $driver = $driver->fresh(['driverProfile']);
    }

    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $pickup = Location::create([
        'uuid'         => (string) Str::uuid(),
        'company_name' => 'FAW HO',
        'address'      => 'Isando',
    ]);
    $delivery = Location::create([
        'uuid'         => (string) Str::uuid(),
        'company_name' => 'Dealer',
        'address'      => 'Bloemfontein',
    ]);

    $job = Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => 'transport',
        'status'               => Job::STATUS_DRIVER_ASSIGNED,
        'company_id'           => $company->id,
        'created_by_user_id'   => $ops->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'vin'                  => 'AAK' . Str::upper(Str::random(14)),
        'registration'         => $registration,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date'       => now()->addDay()->toDateString(),
        'driver_user_id'       => $driver?->id,
    ]);

    return ['ops' => $ops, 'job' => $job->fresh(['driver.driverProfile']), 'driver' => $driver];
}

// -----------------------------------------------------------------
// 1. Modal opens on plateless job when the driver has a trade plate
// -----------------------------------------------------------------

test('openFuelModal succeeds when the driver has a trade plate on a plateless trip', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('showFuelModal', true)
        ->assertSet('fuelProductCode', 'D0')
        ->assertSet('fuelPlateConfirmed', false);
});

test('openFuelModal refuses when there is no plate AND no driver assigned', function () {
    // No permanent plate on the vehicle, no driver on the job -> no
    // way to bill TFN.  Modal must not open.
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: null);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('showFuelModal', false);
});

// -----------------------------------------------------------------
// 2. Confirm-plate gate blocks placement
// -----------------------------------------------------------------

test('placeFuelOrder refuses when the operator has not confirmed the plate', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '200')
        // NOTE: fuelPlateConfirmed intentionally left false
        ->call('placeFuelOrder');

    expect(TfnFuelOrderPlacement::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// 3. Happy path -- demo mode records placement + placer
// -----------------------------------------------------------------

test('placeFuelOrder records a placement in demo mode with the confirmed trade plate', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelProductCode', 'D0')
        ->set('fuelLitres', '250')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder')
        ->assertSet('showFuelModal', false)
        ->assertSet('fuelLitres', '');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    expect($row->vehicle_registration)->toBe('TPJHB011');
    expect($row->product_code)->toBe('D0');
    expect((float) $row->litres)->toBe(250.0);
    expect($row->customer_reference)->toBe($job->job_number);
    expect($row->user_id)->toBe($ops->id);
    expect($row->placed_by_name)->toBe('Lize Ops');
    expect($row->order_number)->toStartWith('DEMO/');
});

test('placeFuelOrder prefers the permanent registration when the job has one', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'ND456GP', tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    // Permanent plate wins over trade plate -- if the vehicle has its
    // own registration TFN gets that, not the driver's trade plate.
    expect($row->vehicle_registration)->toBe('ND456GP');
});

// -----------------------------------------------------------------
// 4. OS product -- overnight stay counts nights not litres
// -----------------------------------------------------------------

test('placeFuelOrder accepts overnight stay (OS) with 1-14 nights', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelProductCode', 'OS')
        ->set('fuelLitres', '2')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->product_code)->toBe('OS');
    expect((float) $row->litres)->toBe(2.0);
});

// -----------------------------------------------------------------
// 5. UI badge -- previous placement lights up the button
// -----------------------------------------------------------------

test('the fuel button shows a badge for the most recent placement on this trip', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'ND456GP', tradePlate: null);

    TfnFuelOrderPlacement::query()->create([
        'order_number'         => 'ORD/01/6454/00099',
        'vehicle_registration' => 'ND456GP',
        'product_code'         => 'D0',
        'litres'               => 200,
        'customer_reference'   => $job->job_number,
        'user_id'              => $ops->id,
        'placed_by_name'       => 'Lize Ops',
        'placed_at'            => now()->subMinutes(5),
    ]);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->assertSee('Fuel · 200 L');
});

// -----------------------------------------------------------------
// 6. Editable trade plate + persist-to-profile
// -----------------------------------------------------------------

test('the modal pre-fills the trade plate from the drivers profile', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('fuelTradePlate', 'TPJHB011');
});

test('opening the modal on a driver with no trade plate leaves the input blank', function () {
    // Driver assigned, profile row exists, but trade_plate is null.
    // Modal must still open so ops can capture a new plate on the spot.
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: null);
    $driver = User::factory()->create(['name' => 'New Driver', 'is_active' => true]);
    $driver->assignRole('driver');
    DriverProfile::create(['user_id' => $driver->id, 'trade_plate' => null]);
    $job->driver_user_id = $driver->id;
    $job->save();
    $job->refresh();

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('showFuelModal', true)
        ->assertSet('fuelTradePlate', '');
});

test('opting to persist a new trade plate writes it back to the drivers profile', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        // Ops re-issued a new trade plate to the same driver.
        ->set('fuelTradePlate', 'TPJHB022')
        ->set('fuelPersistTradePlate', true)
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    // Order billed against the ops-typed plate, not the stale profile one.
    expect($row->vehicle_registration)->toBe('TPJHB022');

    // DriverProfile now carries the new plate for future trips.
    $driver->refresh();
    expect($driver->driverProfile->trade_plate)->toBe('TPJHB022');
});

test('typing a new trade plate WITHOUT ticking persist keeps the profile untouched', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelTradePlate', 'TEMPPLATE99')
        // NOTE: fuelPersistTradePlate left false -- ops using a one-off
        // stand-in and doesn't want the driver profile touched.
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('TEMPPLATE99');

    $driver->refresh();
    // Profile still shows the original plate.
    expect($driver->driverProfile->trade_plate)->toBe('TPJHB011');
});

// -----------------------------------------------------------------
// 7. TFN voucher SMS -- DriverCellNumber wiring
// -----------------------------------------------------------------

test('the modal shows the drivers cellphone as the SMS destination when it is on record', function () {
    // Driver has a phone on the User row -- that wins over the
    // legacy DriverProfile.cellphone value.  Modal must surface the
    // number so ops sees where the voucher lands before placing.
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');
    $driver->forceFill(['phone' => '083-555-1234'])->save();
    $job->refresh();

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSee('TFN will SMS the voucher to')
        ->assertSee('083-555-1234');
});

test('the modal warns when the driver has no cellphone on record', function () {
    // Driver row exists but has no phone / cellphone -- TFN has
    // nowhere to route the SMS.  Modal must flag this so ops isn't
    // surprised when the driver phones back asking for the code.
    // The default User factory populates a phone, so strip it (and
    // the legacy DriverProfile.cellphone fallback) explicitly.
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');
    $driver->forceFill(['phone' => null])->save();
    $driver->driverProfile->forceFill(['cellphone' => null])->save();
    $job->refresh();

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSee('No cellphone on file');
});

test('placeFuelOrder passes the drivers cellphone through to TfnFuelOrderService', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');
    $driver->forceFill(['phone' => '0835551234'])->save();
    $job->refresh();

    // Spy on the service so we can assert the exact payload argument
    // reaches place() without booting the TfnClient at all.  We keep
    // the resolvePosRegistrationForJob delegation working by leaving
    // that method unstubbed on the partial mock.
    $spy = Mockery::mock(\App\Services\Tfn\TfnFuelOrderService::class . '[place]', [
        app(\App\Services\Tfn\TfnClient::class),
    ]);
    $spy->shouldReceive('place')
        ->once()
        ->withArgs(function (
            string $posRegistration,
            string $productCode,
            float  $allocation,
            \DateTimeInterface $expiresAt,
            string $customerReference,
            string $driverCellNumber,
        ) use ($job) {
            expect($posRegistration)->toBe('TPJHB011');
            expect($productCode)->toBe('D0');
            expect($allocation)->toBe(200.0);
            expect($customerReference)->toBe($job->job_number);
            // The critical assertion: driver phone is forwarded so TFN
            // knows where to send the voucher SMS.
            expect($driverCellNumber)->toBe('0835551234');
            return true;
        })
        ->andReturn(['order_number' => 'DEMO/TEST', 'demo' => true]);

    app()->instance(\App\Services\Tfn\TfnFuelOrderService::class, $spy);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '200')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');
});

test('persist-to-profile is ignored when the vehicle has its own permanent plate', function () {
    // Vehicle plate wins over trade plate for TFN, so the persist
    // checkbox is meaningless here -- profile must stay untouched
    // even if the state accidentally flips through.
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: 'ND456GP', tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelTradePlate', 'TPJHB999')  // ignored, vehicle plate wins
        ->set('fuelPersistTradePlate', true)
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('ND456GP');

    $driver->refresh();
    expect($driver->driverProfile->trade_plate)->toBe('TPJHB011');
});
