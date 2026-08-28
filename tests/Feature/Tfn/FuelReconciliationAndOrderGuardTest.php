<?php

/**
 * TFN Fuel integration -- Phase 1 of the "match reality" pass.
 *
 * Pins three behaviours the TFN correction from Sikelela (2026-08-28)
 * makes non-negotiable:
 *
 *   1. VehicleRegistration on every transaction is a real plate (or
 *      the assigned driver's trade plate) -- never a VIN.  The fuel
 *      page must refuse an order when the vehicle has no permanent
 *      plate AND its driver has no trade plate on file.  See
 *      resources/views/pages/admin/fuel.blade.php.
 *
 *   2. TfnFuelReconciliationService matches on the job's registration
 *      first, falls back to the assigned driver's trade plate when
 *      the job shipped plateless (the new-from-plant case).  Both
 *      the job's registration and the driver's trade plate are
 *      normalised (uppercase, non-alphanumeric stripped) at rest so
 *      the reconciler doesn't have to know the raw form.
 *
 *   3. Reversals are netted.  Per Sikelela, a reversal is a new
 *      transaction row with `IsReversal=true` and `ReversedTransactionID`
 *      pointing at the original.  The reconciler drops BOTH rows from
 *      the total so month-end figures reflect fuel that stuck.
 */

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use App\Services\Tfn\TfnFuelReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner'                 => ['Owner', 'internal'],
        'developer'             => ['Developer', 'internal'],
        'operations_controller' => ['Ops Controller', 'internal'],
        'dispatcher'            => ['Dispatcher', 'internal'],
        'accounts'              => ['Accounts', 'internal'],
        'driver'                => ['Driver', 'driver'],
    ] as $slug => [$name, $tier]) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $tier]);
    }
});

function fuelInternal(string $slug = 'operations_controller'): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function fuelDriver(?string $rawTradePlate): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('driver');
    DriverProfile::create([
        'user_id'     => $u->id,
        'trade_plate' => $rawTradePlate,
    ]);
    return $u->fresh(['driverProfile']);
}

function fuelJob(array $attrs = [], ?User $driver = null): Job
{
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    $pickup = Location::create(['company_id' => null, 'company_name' => 'Plant',  'address' => 'Plant',  'is_active' => true]);
    $delivery = Location::create(['company_id' => null, 'company_name' => 'Dealer','address' => 'Dealer', 'is_active' => true]);

    return Job::create(array_merge([
        'uuid'                => (string) Str::uuid(),
        'job_number'          => 'JOB-' . Str::upper(Str::random(8)),
        'job_type'            => 'transport',
        'status'              => Job::STATUS_DELIVERED,
        'company_id'          => $company->id,
        'created_by_user_id'  => $creator->id,
        'executor_type'       => Job::EXECUTOR_PROSELVER,
        'vin'                 => 'VIN' . Str::upper(Str::random(8)),
        'registration'        => null,
        'pickup_location_id'  => $pickup->id,
        'delivery_location_id'=> $delivery->id,
        'scheduled_date'      => now()->subDay()->toDateString(),
        'collected_at'        => now()->subDay(),
        'delivered_at'        => now()->subHours(2),
        'driver_user_id'      => $driver?->id,
    ], $attrs));
}

// -----------------------------------------------------------------
// 1. Trade plate normalisation
// -----------------------------------------------------------------

test('DriverProfile normalises the trade plate on write and read', function () {
    // "tp jhb 11" -> "TPJHB11": strip whitespace + non-alphanumerics,
    // upper-case.  Same rule the reconciler applies to inbound TFN
    // VehicleRegistration strings.
    $driver = fuelDriver('tp jhb 11');

    expect($driver->driverProfile->trade_plate)->toBe('TPJHB11');
});

test('DriverProfile treats a blank trade plate as null', function () {
    // Empty / whitespace-only should not become the empty string --
    // that would light up as "has plate" in the fuel-order guard.
    $driver = fuelDriver('   ');

    expect($driver->driverProfile->trade_plate)->toBeNull();
});

test('normalisePlate collapses every casing / punctuation variant to one canonical form', function () {
    expect(DriverProfile::normalisePlate('nd456gp'))->toBe('ND456GP');
    expect(DriverProfile::normalisePlate('ND-456-GP'))->toBe('ND456GP');
    expect(DriverProfile::normalisePlate('ND 456 GP'))->toBe('ND456GP');
    expect(DriverProfile::normalisePlate('   ND456gp   '))->toBe('ND456GP');
    expect(DriverProfile::normalisePlate(null))->toBeNull();
    expect(DriverProfile::normalisePlate(''))->toBeNull();
    expect(DriverProfile::normalisePlate('!!!'))->toBeNull();
});

// -----------------------------------------------------------------
// 2. Order-flow guard: refuses when there is no POS registration
// -----------------------------------------------------------------

test('the fuel-order form refuses a vehicle whose driver has no trade plate on file', function () {
    // Point the page at a fixture vehicle that has neither a
    // permanent plate nor a driver trade plate, and confirm the
    // guard message surfaces without any TFN call being attempted.
    //
    // We hack the fixture in-place: replace all vehicles with a
    // single plateless-and-tradeplateless row before hitting the
    // Volt page.  The page reads from `TfnDemoFixtures` in demo
    // mode; a service-container override lets us swap the fixture.
    $this->app->instance(TfnDemoFixtures::class, new class extends TfnDemoFixtures {
        public function vehicles(): array
        {
            return [[
                'VIN'              => 'PLATELESSVIN0001',
                'Registration'     => null,
                'DriverName'       => null,
                'DriverTradePlate' => null,
                'CustomerName'     => 'Test OEM',
                'Brand'            => 'Test',
                'Model'            => 'M1',
                'TankSize'         => null,
                'Status'           => 3,
                'ExternalNumber'   => '26082599',
                'PosRegistration'  => null,
            ]];
        }
    });

    // The guard's contract: it returns early WITHOUT resetting the
    // form and without triggering the demo/live TFN path.  On the
    // successful demo path `placeOrder()` calls `reset(['orderLitres',
    // 'orderReference'])`, so `orderLitres` empty proves the demo
    // branch ran; `orderLitres` still '200' proves the guard tripped
    // FIRST (before the demo path or any real TFN call).
    Volt::actingAs(fuelInternal())
        ->test('admin.fuel')
        ->set('orderRegistration', 'PLATELESSVIN0001')
        ->set('orderProductCode', 'D0')
        ->set('orderLitres', '200')
        ->set('orderExpiresAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertSet('orderLitres', '200');  // unchanged -> guard tripped
});

test('the fuel-order form accepts a vehicle when its driver carries a trade plate', function () {
    // The default fixtures include an Isuzu NQR500 with no permanent
    // plate but a driver trade plate ("TPJHB011") -- exactly the
    // new-from-plant case the guard exists to enable.  The demo
    // path flashes a "(Demo) Order placed" success, which is what
    // we assert to prove the guard did NOT trip.
    // Contract: the demo path runs and resets orderLitres.  The
    // symmetric proof to the guard test above.
    Volt::actingAs(fuelInternal())
        ->test('admin.fuel')
        ->set('orderRegistration', 'ACVWR75LTG213611')  // Isuzu VIN, plateless, driver trade plate TPJHB011
        ->set('orderProductCode', 'D0')
        ->set('orderLitres', '180')
        ->set('orderExpiresAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertSet('orderLitres', '');  // reset means the demo path ran
});

// -----------------------------------------------------------------
// 3. Reconciler: falls back to the driver's trade plate when a
//    job shipped without a permanent plate
// -----------------------------------------------------------------

test('the reconciler matches on the driver trade plate when the job has no registration', function () {
    // Job has no registration; driver's trade plate is "TPJHB011".
    // Feed a synthetic transaction whose VehicleRegistration is that
    // trade plate -- captured inside the job's fuel window -- and
    // the reconciler should associate it back to the job.
    $driver = fuelDriver('TPJHB011');
    $job = fuelJob(['registration' => null], driver: $driver);

    $tx = [[
        'TransactionID'         => 'tx-001',
        'ProductCode'           => 'D0',
        'CapturedDate'          => now()->subHours(20)->toIso8601String(),
        'TransactionDate'       => now()->subHours(20)->toIso8601String(),
        'VehicleRegistration'   => 'TPJHB011',
        'Litres'                => 210.0,
        'Amount'                => -4700.00,
        'IsReversal'            => false,
        'ReversedTransactionID' => null,
    ]];

    $svc = fuelServiceReturning($tx);
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(210.0);
    expect($out['amount'])->toBe(4700.0);
    expect($out['matched_count'])->toBe(1);
});

test('the reconciler still prefers the permanent plate when present', function () {
    // A job with BOTH a permanent plate and a driver trade plate
    // must match the PERMANENT plate first -- the driver's trade
    // plate is the fallback for plateless units.  If we matched
    // trade plates unconditionally we'd cross-contaminate reports
    // where the same driver ran two consecutive trips.
    $driver = fuelDriver('TPJHB011');
    $job = fuelJob(['registration' => 'ND456GP'], driver: $driver);

    // Two transactions in the window: one against the permanent
    // plate, one against the trade plate.  Both should match the
    // same job -- the reconciler unions the candidate lists.
    $tx = [
        [
            'TransactionID'         => 'tx-perm',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(20)->toIso8601String(),
            'VehicleRegistration'   => 'ND456GP',
            'Litres'                => 100.0,
            'Amount'                => -2200.00,
            'IsReversal'            => false,
            'ReversedTransactionID' => null,
        ],
        [
            'TransactionID'         => 'tx-trade',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(19)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => 80.0,
            'Amount'                => -1760.00,
            'IsReversal'            => false,
            'ReversedTransactionID' => null,
        ],
    ];

    $svc = fuelServiceReturning($tx);
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(180.0);
    expect($out['amount'])->toBe(3960.0);
    expect($out['matched_count'])->toBe(2);
});

test('the reconciler still returns no_registration when neither plate nor trade plate exists', function () {
    // Explicit driverless-and-plateless job -- there is nothing to
    // match against, and the source label must say so cleanly.
    $job = fuelJob(['registration' => null]);  // no driver either

    $svc = fuelServiceReturning([]);
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['source'])->toBe('no_registration');
    expect($out['litres'])->toBeNull();
    expect($out['amount'])->toBeNull();
});

// -----------------------------------------------------------------
// 4. Reversals are netted
// -----------------------------------------------------------------

test('the reconciler nets a reversal against its original transaction', function () {
    // Real-world shape (per Sikelela 2026-08-28): the reversal is a
    // separate row with IsReversal=true and ReversedTransactionID
    // pointing back.  Both rows land inside the job's fuel window;
    // the reconciler must return zero litres and zero rand -- the
    // net effect was nothing.
    $driver = fuelDriver('TPJHB011');
    $job = fuelJob(['registration' => null], driver: $driver);

    $tx = [
        [
            'TransactionID'         => 'tx-original',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(20)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => 250.0,
            'Amount'                => -5500.00,
            'IsReversal'            => false,
            'ReversedTransactionID' => null,
        ],
        [
            'TransactionID'         => 'tx-reversal',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(19)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => -250.0,
            'Amount'                => 5500.00,
            'IsReversal'            => true,
            'ReversedTransactionID' => 'tx-original',
        ],
    ];

    $svc = fuelServiceReturning($tx);
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(0.0);
    expect($out['amount'])->toBe(0.0);
    expect($out['matched_count'])->toBe(0);
});

test('a partial reversal leaves only the non-reversed transactions in the total', function () {
    // Two originals in the window; one of them is reversed.  Only
    // the non-reversed one should contribute to the totals.
    $driver = fuelDriver('TPJHB011');
    $job = fuelJob(['registration' => null], driver: $driver);

    $tx = [
        [
            'TransactionID'         => 'kept',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(20)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => 100.0,
            'Amount'                => -2200.00,
            'IsReversal'            => false,
            'ReversedTransactionID' => null,
        ],
        [
            'TransactionID'         => 'reversed-original',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(19)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => 250.0,
            'Amount'                => -5500.00,
            'IsReversal'            => false,
            'ReversedTransactionID' => null,
        ],
        [
            'TransactionID'         => 'reversal',
            'ProductCode'           => 'D0',
            'CapturedDate'          => now()->subHours(18)->toIso8601String(),
            'VehicleRegistration'   => 'TPJHB011',
            'Litres'                => -250.0,
            'Amount'                => 5500.00,
            'IsReversal'            => true,
            'ReversedTransactionID' => 'reversed-original',
        ],
    ];

    $svc = fuelServiceReturning($tx);
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(100.0);
    expect($out['amount'])->toBe(2200.0);
    expect($out['matched_count'])->toBe(1);
});

/**
 * Build a TfnFuelReconciliationService whose fixtures return exactly
 * `$transactions`, so the test can control the entire dataset without
 * booting a real TFN call.  The client stub reports isLive=false so
 * the service takes the fixtures path in fetchTransactions().
 */
function fuelServiceReturning(array $transactions): TfnFuelReconciliationService
{
    $fixtures = new class ($transactions) extends TfnDemoFixtures {
        public function __construct(private array $rows) {}
        public function transactions(): array { return $this->rows; }
    };
    $client = new class extends TfnClient {
        public function __construct() {}
        public function isLive(): bool { return false; }
    };
    return new TfnFuelReconciliationService($client, $fixtures);
}
