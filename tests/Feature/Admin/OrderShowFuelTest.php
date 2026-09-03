<?php

/**
 * TFN fuel pre-authorisation from the order-show page.
 *
 * Ops places diesel and/or overnight-stay orders from the order page.
 * The plate is always editable -- for trips with a permanent
 * registration the save-back writes to jobs.registration; for
 * plateless trips it writes to driverProfile.trade_plate.  Both
 * diesel and overnight can be ticked in the same click and fire as
 * two separate /api/Orders calls.  The actual TFN payload build
 * lives in TfnFuelOrderService; these tests exercise the Volt page's
 * guards, wiring, and audit trail.
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
 * Order-show test scenario: internal ops user, an FAW-ish job (with
 * or without a permanent plate), and (optionally) an assigned driver
 * with a trade plate.
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
// 1. Modal open / close guards
// -----------------------------------------------------------------

test('openFuelModal succeeds when the driver has a trade plate on a plateless trip', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('showFuelModal', true)
        ->assertSet('fuelIncludeDiesel', true)
        ->assertSet('fuelIncludeOvernight', false)
        // Overnight nights default to 1 -- almost every real trip
        // that includes an overnight is a single night.
        ->assertSet('fuelNights', '1')
        ->assertSet('fuelPlateConfirmed', false)
        // Plate input pre-filled from the driver's trade plate.
        ->assertSet('fuelPlateInput', 'TPJHB011');
});

test('openFuelModal seeds the plate input from the vehicle registration when the job has one', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'KVB719EC', tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('fuelPlateInput', 'KVB719EC');
});

test('openFuelModal refuses when there is no plate AND no driver assigned', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: null);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSet('showFuelModal', false);
});

// -----------------------------------------------------------------
// 2. Placement guards
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

test('placeFuelOrder refuses when neither diesel nor overnight is ticked', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelIncludeDiesel', false)
        ->set('fuelIncludeOvernight', false)
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    expect(TfnFuelOrderPlacement::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// 3. Happy paths -- single product, both products
// -----------------------------------------------------------------

test('placeFuelOrder records a diesel placement in demo mode', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
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

test('placeFuelOrder uses the ops-typed plate verbatim (not the stale profile one)', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'KVB719EC', tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('KVB719EC');
});

test('placeFuelOrder accepts overnight stay only (diesel unticked)', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelIncludeDiesel', false)
        ->set('fuelIncludeOvernight', true)
        // fuelNights defaults to '1' from openFuelModal -- test that
        // ops doesn't have to touch it for the common case.
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->product_code)->toBe('OS');
    expect((float) $row->litres)->toBe(1.0);
});

test('placeFuelOrder fires two TFN orders when both diesel and overnight are ticked', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelIncludeDiesel', true)
        ->set('fuelLitres', '200')
        ->set('fuelIncludeOvernight', true)
        ->set('fuelNights', '1')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder')
        ->assertSet('showFuelModal', false);

    $rows = TfnFuelOrderPlacement::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    $codes = $rows->pluck('product_code')->all();
    expect($codes)->toContain('D0');
    expect($codes)->toContain('OS');

    // Both orders bill against the same POS registration and
    // customer_reference (job number) so month-end reconciliation
    // ties them to the same trip.
    foreach ($rows as $row) {
        expect($row->vehicle_registration)->toBe('TPJHB011');
        expect($row->customer_reference)->toBe($job->job_number);
    }
});

// -----------------------------------------------------------------
// 4. UI badge -- previous placement lights up the button
// -----------------------------------------------------------------

test('the fuel button shows a badge for the most recent placement on this trip', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'ND456GP', tradePlate: null);

    TfnFuelOrderPlacement::query()->create([
        'order_number'         => 'ORD/01/6454/00099',
        'voucher_number'       => '812345',
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
        ->assertSee('Fuel · 200 L')
        // Voucher chip renders the pump code verbatim so ops can
        // read it on a phone call without opening the modal.
        ->assertSee('812345');
});

test('the fuel button omits the voucher chip when no voucher was captured', function () {
    // Older placement rows written before we captured
    // CurrentVirtualCardNumber have voucher_number = null.  The chip
    // should not render for those or ops will hunt for a code that
    // was never persisted.
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'ND456GP', tradePlate: null);

    TfnFuelOrderPlacement::query()->create([
        'order_number'         => 'ORD/01/6454/00098',
        'voucher_number'       => null,
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
        ->assertSee('Fuel · 200 L')
        ->assertDontSee('Voucher');
});

test('placeFuelOrder writes the TFN voucher into the placement audit for later lookup', function () {
    // Demo mode (default in tests) mints a 6-digit voucher inside
    // TfnFuelOrderService so the flash + audit look the same as live.
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '200')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->voucher_number)->toMatch('/^\d{6}$/');
});

// -----------------------------------------------------------------
// 5. Editable plate + persist to driver profile (trade plate case)
// -----------------------------------------------------------------

test('opening the modal on a driver with no trade plate leaves the input blank', function () {
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
        ->assertSet('fuelPlateInput', '');
});

test('opting to persist a new trade plate writes it back to the drivers profile', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelPlateInput', 'TPJHB022')
        ->set('fuelPersistPlateChange', true)
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    expect($row->vehicle_registration)->toBe('TPJHB022');

    $driver->refresh();
    expect($driver->driverProfile->trade_plate)->toBe('TPJHB022');
});

test('typing a new trade plate WITHOUT ticking persist keeps the profile untouched', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelPlateInput', 'TEMPPLATE99')
        // NOTE: fuelPersistPlateChange left false -- one-off stand-in.
        ->set('fuelLitres', '180')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('TEMPPLATE99');

    $driver->refresh();
    expect($driver->driverProfile->trade_plate)->toBe('TPJHB011');
});

// -----------------------------------------------------------------
// 6. Editable plate + persist to jobs.registration (booking correction)
// -----------------------------------------------------------------

test('opting to persist a corrected vehicle registration writes it back to the booking', function () {
    // Ops caught a typo on the booking -- KVB719EC should be KVB719FS.
    // Ticking Persist while placing the fuel order writes the fix
    // through to jobs.registration alongside the TFN placement.
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'KVB719EC', tradePlate: null);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelPlateInput', 'KVB719FS')
        ->set('fuelPersistPlateChange', true)
        ->set('fuelLitres', '200')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('KVB719FS');

    $job->refresh();
    expect($job->registration)->toBe('KVB719FS');
});

test('typing a different vehicle registration WITHOUT persist places against the new plate but leaves the booking alone', function () {
    ['ops' => $ops, 'job' => $job] = fuelOrderShowScenario(registration: 'KVB719EC', tradePlate: null);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelPlateInput', 'KVB719FS')
        // NOTE: fuelPersistPlateChange left false -- ops just uses
        // the corrected plate for THIS TFN order, doesn't want to
        // touch the booking until someone confirms the paperwork.
        ->set('fuelLitres', '200')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->vehicle_registration)->toBe('KVB719FS');

    $job->refresh();
    expect($job->registration)->toBe('KVB719EC');   // unchanged
});

// -----------------------------------------------------------------
// 7. TFN voucher SMS -- DriverCellNumber wiring
// -----------------------------------------------------------------

test('the modal shows the drivers cellphone as the SMS destination when it is on record', function () {
    ['ops' => $ops, 'job' => $job, 'driver' => $driver] = fuelOrderShowScenario(registration: null, tradePlate: 'TPJHB011');
    $driver->forceFill(['phone' => '083-555-1234'])->save();
    $job->refresh();

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->assertSee('TFN will SMS the voucher')
        ->assertSee('083-555-1234');
});

test('the modal warns when the driver has no cellphone on record', function () {
    // Default User factory populates a phone, so strip it (and the
    // legacy DriverProfile.cellphone fallback) explicitly.
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
            expect($driverCellNumber)->toBe('0835551234');
            return true;
        })
        ->andReturn(['order_number' => 'DEMO/TEST', 'voucher_number' => '654321', 'demo' => true]);

    app()->instance(\App\Services\Tfn\TfnFuelOrderService::class, $spy);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openFuelModal')
        ->set('fuelLitres', '200')
        ->set('fuelPlateConfirmed', true)
        ->call('placeFuelOrder');
});
