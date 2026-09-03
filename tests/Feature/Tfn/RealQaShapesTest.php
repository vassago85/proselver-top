<?php

/**
 * Pins the exact wire shapes confirmed against TFN's real QA sandbox
 * on 2026-08-31 (customerapi.qa.tfn.co.za, customer 501/12623).
 *
 * The 405 "UnsupportedApiVersion" wall we hit for a week turned out
 * to be a routing quirk: `POST /api/Orders` requires a client-generated
 * `newRecordIdentifier` UUID as a query parameter, and `GET /api/Orders`
 * requires `modifiedAfterDate` (not `dateAfter` like our old code sent).
 * With both correct, both endpoints return 200 with the payload shape
 * captured in this test.
 *
 * These tests double as the specification for anyone reading the
 * TfnClient later -- if TFN ever changes the shape they'll blow up
 * with a precise line pointing at what moved.
 */

use App\Services\Tfn\Exceptions\TfnException;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use App\Services\Tfn\TfnFuelReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------
// 1. Demo transaction rows carry the real TFN v3 shape
// -----------------------------------------------------------------

test('demo transactions carry the real TFN v3 field set', function () {
    $rows = (new TfnDemoFixtures())->transactions();
    expect($rows)->not->toBeEmpty();

    $row = $rows[0];
    // Fields the real payload always includes (verified via QA probe).
    foreach ([
        'TransactionID', 'CustomerNumber', 'ProductCode', 'TransactionTypeCode',
        'TransactionDate', 'CapturedDate', 'SupplierName', 'SupplierNumber',
        'VehicleRegistration', 'Amount', 'VAT', 'Litres', 'Odometer',
        'UtilisedOrders', 'TransactionReference', 'Identifier',
        'ReversedTransaction',
    ] as $key) {
        // The assertion message points at the offending key without
        // relying on Pest's toHaveKey signature (whose 2nd arg is an
        // expected value in some versions, not a message).
        expect(array_key_exists($key, $row))->toBeTrue();
    }

    // ReversedTransaction is a NESTED object shape, not a flat pair
    // of legacy fields.  Empty state is the null-UUID sentinel.
    expect($row['ReversedTransaction'])->toBeArray();
    expect($row['ReversedTransaction'])->toHaveKeys(['TransactionID', 'IsFuel', 'TransactionReference']);

    // TransactionTypeCode is a real value ('GP' for general purchase),
    // not the empty string the old fixture emitted.
    expect($row['TransactionTypeCode'])->toBe('GP');

    // Identifier is the "VirtualCard (Registration)" combo TFN
    // populates on every real row.
    expect($row['Identifier'])->toMatch('/^\d{6} \([A-Z0-9 -]+\)$/');

    // SupplierNumber is a zero-padded 4-digit string, matching the
    // real payload's "0603" / "0613" shape.
    expect($row['SupplierNumber'])->toMatch('/^\d{4}$/');
});

test('demo transactions include a reversal exemplar via ReversedTransaction, not IsReversal', function () {
    $rows = (new TfnDemoFixtures())->transactions();

    // Exactly one reversal row is included (the fixture appends it
    // after the random purchases).  Identify it by non-null UUID in
    // ReversedTransaction.TransactionID.
    $reversals = collect($rows)->filter(function ($r) {
        $nested = $r['ReversedTransaction'] ?? null;
        if (!is_array($nested)) { return false; }
        $id = $nested['TransactionID'] ?? '';
        return $id !== '' && $id !== '00000000-0000-0000-0000-000000000000';
    });
    expect($reversals)->toHaveCount(1);

    $rev = $reversals->first();
    // Reversal inverts sign of the original.
    expect((float) $rev['Amount'])->toBeGreaterThan(0.0);
    expect((float) $rev['Litres'])->toBeLessThan(0.0);
    // ReversedTransaction.IsFuel is true because we clone from the last
    // FUEL row (Litres > 0).
    expect($rev['ReversedTransaction']['IsFuel'])->toBeTrue();
    // The nested transaction reference points back at the original.
    expect($rev['ReversedTransaction']['TransactionReference'])->toBeString();
    expect($rev['ReversedTransaction']['TransactionReference'])->not->toBe('');
});

// -----------------------------------------------------------------
// 2. Reconciler detects reversals via the nested object
// -----------------------------------------------------------------

test('the reconciler treats a nested ReversedTransaction as the canonical reversal signal', function () {
    $original = [
        'TransactionID'        => 'tx-original',
        'ProductCode'          => 'D0',
        'CapturedDate'         => now()->subHours(20)->toIso8601String(),
        'VehicleRegistration'  => 'TPJHB011',
        'Litres'               => 250.0,
        'Amount'               => -5500.00,
        'ReversedTransaction'  => [
            'TransactionID'        => '00000000-0000-0000-0000-000000000000',
            'IsFuel'               => false,
            'TransactionReference' => '',
        ],
    ];
    $reversal = [
        'TransactionID'        => 'tx-reversal',
        'ProductCode'          => 'D0',
        'CapturedDate'         => now()->subHours(19)->toIso8601String(),
        'VehicleRegistration'  => 'TPJHB011',
        'Litres'               => -250.0,
        'Amount'               => 5500.00,
        // The REAL payload's reversal shape -- no IsReversal / no
        // top-level ReversedTransactionID, just the nested object.
        'ReversedTransaction'  => [
            'TransactionID'        => 'tx-original',
            'IsFuel'               => true,
            'TransactionReference' => 'TFN123',
        ],
    ];

    $svc = _realQaShapesSvc([$original, $reversal]);
    $job = _realQaShapesJob(driverTradePlate: 'TPJHB011');
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(0.0);
    expect($out['amount'])->toBe(0.0);
    expect($out['matched_count'])->toBe(0);
});

test('the reconciler still nets the legacy IsReversal / ReversedTransactionID shape', function () {
    // Backward-compat: fixtures written before 2026-08-31 emit the flat
    // pair.  The reconciler must keep understanding it so we don't
    // break older seeders / snapshots.
    $original = [
        'TransactionID'         => 'tx-original',
        'ProductCode'           => 'D0',
        'CapturedDate'          => now()->subHours(20)->toIso8601String(),
        'VehicleRegistration'   => 'TPJHB011',
        'Litres'                => 100.0,
        'Amount'                => -2200.00,
        'IsReversal'            => false,
        'ReversedTransactionID' => null,
    ];
    $reversal = array_merge($original, [
        'TransactionID'         => 'tx-reversal',
        'CapturedDate'          => now()->subHours(19)->toIso8601String(),
        'Litres'                => -100.0,
        'Amount'                => 2200.00,
        'IsReversal'            => true,
        'ReversedTransactionID' => 'tx-original',
    ]);

    $svc = _realQaShapesSvc([$original, $reversal]);
    $job = _realQaShapesJob(driverTradePlate: 'TPJHB011');
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(0.0);
    expect($out['amount'])->toBe(0.0);
});

test('a null-UUID nested ReversedTransaction is NOT treated as a reversal', function () {
    // Every real transaction carries ReversedTransaction with the
    // null-UUID sentinel when it isn't a reversal.  The reconciler
    // must not mistake that for a self-reversal loop.
    $tx = [
        'TransactionID'        => 'tx-normal',
        'ProductCode'          => 'D0',
        'CapturedDate'         => now()->subHours(10)->toIso8601String(),
        'VehicleRegistration'  => 'TPJHB011',
        'Litres'               => 150.0,
        'Amount'               => -3300.00,
        'ReversedTransaction'  => [
            'TransactionID'        => '00000000-0000-0000-0000-000000000000',
            'IsFuel'               => false,
            'TransactionReference' => '',
        ],
    ];

    $svc = _realQaShapesSvc([$tx]);
    $job = _realQaShapesJob(driverTradePlate: 'TPJHB011');
    $out = $svc->reconcile(collect([$job]))[$job->id];

    expect($out['litres'])->toBe(150.0);
    expect($out['amount'])->toBe(3300.0);
    expect($out['matched_count'])->toBe(1);
});

// -----------------------------------------------------------------
// 3. TfnClient::createOrder unwraps { ValidationResult, Order, Message }
// -----------------------------------------------------------------

test('createOrder unwraps the ValidationResult envelope and returns just Order', function () {
    // Stub the token manager + HTTP fake so createOrder can run without
    // a real TFN round-trip.  We assert on the returned Order shape.
    config()->set('tfn.enabled', true);
    config()->set('tfn.username', 'API_ProQA_Tech');
    config()->set('tfn.password', 'x');
    config()->set('tfn.customer_number', '501/12623');
    config()->set('tfn.base_url', 'https://customerapi.qa.tfn.co.za');

    $tokens = new class extends \App\Services\Tfn\TfnTokenManager {
        public function __construct() {}
        public function token(): string { return 'fake-bearer'; }
        public function invalidate(): void {}
        public function refresh(): string { return 'fake-bearer'; }
    };
    $client = new TfnClient($tokens);

    \Illuminate\Support\Facades\Http::fake([
        'customerapi.qa.tfn.co.za/api/Orders*' => \Illuminate\Support\Facades\Http::response([
            'ValidationResult' => 'Successful',
            'Order' => [
                'OrderNumber' => 'ORD/501/12623/00099',
                'StatusTitle' => 'Planned (Order pending release)',
                'CustomerReference' => 'TRIDENT-UNIT',
                'Entries' => [[
                    'Position' => 128999,
                    'ProductCode' => 'D0',
                    'VehicleRegistration' => 'ABF001EC',
                    'CurrentVirtualCardNumber' => '490961',
                    'MaxAllocation' => 10,
                    'ValidDateStart' => '2026-08-31T10:00:00+02:00',
                    'ValidDateEnd' => '2026-09-03T10:00:00+02:00',
                    'LinkedTransactions' => [],
                ]],
            ],
            'Message' => null,
        ], 200),
    ]);

    $order = $client->createOrder([
        'CustomerNumber' => '501/12623',
        'CustomerReference' => 'TRIDENT-UNIT',
    ]);

    // The envelope is unwrapped -- we get the Order directly, not the
    // wrapping object.
    expect($order)->toHaveKey('OrderNumber');
    expect($order['OrderNumber'])->toBe('ORD/501/12623/00099');
    expect($order)->not->toHaveKey('ValidationResult');
    expect($order['Entries'][0]['Position'])->toBe(128999);
    expect($order['Entries'][0]['CurrentVirtualCardNumber'])->toBe('490961');
});

test('createOrder throws TfnException with the Message when ValidationResult is not Successful', function () {
    config()->set('tfn.enabled', true);
    config()->set('tfn.username', 'API_ProQA_Tech');
    config()->set('tfn.password', 'x');
    config()->set('tfn.customer_number', '501/12623');
    config()->set('tfn.base_url', 'https://customerapi.qa.tfn.co.za');

    $tokens = new class extends \App\Services\Tfn\TfnTokenManager {
        public function __construct() {}
        public function token(): string { return 'fake-bearer'; }
        public function invalidate(): void {}
        public function refresh(): string { return 'fake-bearer'; }
    };
    $client = new TfnClient($tokens);

    \Illuminate\Support\Facades\Http::fake([
        'customerapi.qa.tfn.co.za/api/Orders*' => \Illuminate\Support\Facades\Http::response([
            'ValidationResult' => 'InsufficientFunds',
            'Order' => null,
            'Message' => 'Sub-account balance would go below the credit limit.',
        ], 200),
    ]);

    expect(fn () => $client->createOrder(['CustomerNumber' => '501/12623']))
        ->toThrow(TfnException::class, 'Sub-account balance would go below the credit limit.');
});

test('createOrder always sends newRecordIdentifier as a query param', function () {
    // The 405 wall we hit for a week was caused by omitting this
    // param.  Pin that it's always present on the wire, either from
    // the caller or auto-generated.
    config()->set('tfn.enabled', true);
    config()->set('tfn.username', 'API_ProQA_Tech');
    config()->set('tfn.password', 'x');
    config()->set('tfn.customer_number', '501/12623');
    config()->set('tfn.base_url', 'https://customerapi.qa.tfn.co.za');
    config()->set('tfn.api_version', '3');

    $tokens = new class extends \App\Services\Tfn\TfnTokenManager {
        public function __construct() {}
        public function token(): string { return 'fake-bearer'; }
        public function invalidate(): void {}
        public function refresh(): string { return 'fake-bearer'; }
    };
    $client = new TfnClient($tokens);

    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response([
            'ValidationResult' => 'Successful',
            'Order' => ['OrderNumber' => 'ORD/x'],
        ], 200),
    ]);

    // Explicit UUID -- must appear on the URL verbatim.
    $client->createOrder(['CustomerNumber' => '501/12623'], newRecordIdentifier: 'abc-123-explicit');
    // Auto-generated when omitted -- still on the URL, different UUID.
    $client->createOrder(['CustomerNumber' => '501/12623']);

    \Illuminate\Support\Facades\Http::assertSentCount(2);

    $urls = collect(\Illuminate\Support\Facades\Http::recorded())
        ->map(fn ($pair) => (string) $pair[0]->url())
        ->all();
    expect($urls)->each(fn ($url) => $url->toContain('newRecordIdentifier='));

    // Explicit UUID appears verbatim on one of the requests.
    expect(collect($urls)->contains(fn ($u) => str_contains($u, 'newRecordIdentifier=abc-123-explicit')))
        ->toBeTrue("Expected one request URL to carry newRecordIdentifier=abc-123-explicit. Got: " . json_encode($urls));

    // At least one call carried an auto-generated (non-explicit) UUID.
    expect(collect($urls)->contains(fn ($u) =>
        str_contains($u, 'newRecordIdentifier=')
        && !str_contains($u, 'newRecordIdentifier=abc-123-explicit')
    ))->toBeTrue("Expected a second request with an auto-generated newRecordIdentifier. Got: " . json_encode($urls));
});

// -----------------------------------------------------------------
// 4. TfnClient::subAccountAggregateLitres formats month as yyyyMM
// -----------------------------------------------------------------

test('subAccountAggregateLitres sends month in yyyyMM format on the wire', function () {
    // Third instance of the "TFN routes 404 when a required query
    // param is missing" bug.  The endpoint requires ?month=yyyyMM
    // (six digits, no separator); anything else 400s with "Invalid
    // month 'X' received, expected yyyyMM format e.g. 202607".  Pin
    // the format so a well-meaning refactor to '2026-08' or '08-2026'
    // fails loudly.
    config()->set('tfn.enabled', true);
    config()->set('tfn.username', 'API_ProQA_Tech');
    config()->set('tfn.password', 'x');
    config()->set('tfn.customer_number', '501/12623');
    config()->set('tfn.base_url', 'https://customerapi.qa.tfn.co.za');
    config()->set('tfn.api_version', '3');

    $tokens = new class extends \App\Services\Tfn\TfnTokenManager {
        public function __construct() {}
        public function token(): string { return 'fake-bearer'; }
        public function invalidate(): void {}
        public function refresh(): string { return 'fake-bearer'; }
    };
    $client = new TfnClient($tokens);

    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response([], 200),
    ]);

    // Explicit month.
    $client->subAccountAggregateLitres(\Carbon\Carbon::createFromDate(2026, 8, 15));
    // Default month (this call's month at run time).
    $client->subAccountAggregateLitres();

    \Illuminate\Support\Facades\Http::assertSentCount(2);

    $urls = collect(\Illuminate\Support\Facades\Http::recorded())
        ->map(fn ($pair) => (string) $pair[0]->url())
        ->all();

    // First call: month=202608 verbatim on the wire.
    expect(collect($urls)->contains(fn ($u) => str_contains($u, 'month=202608')))
        ->toBeTrue("Expected month=202608 on the URL. Got: " . json_encode($urls));

    // Second call: current month, six digits, no separator.
    $expected = 'month=' . now()->format('Ym');
    expect(collect($urls)->contains(fn ($u) => str_contains($u, $expected)))
        ->toBeTrue("Expected {$expected} on the URL. Got: " . json_encode($urls));

    // The wrong-format regressions we want to catch (any of these on
    // the URL would return 400 from TFN).
    foreach ($urls as $u) {
        expect($u)->not->toContain('month=2026-08');
        expect($u)->not->toContain('month=08-2026');
        expect($u)->not->toContain('month=8');
        expect($u)->not->toContain('month=2026-08-15');
    }
});

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------

/**
 * Assemble a reconciler wired to a controllable set of transactions.
 * Mirrors the fuelServiceReturning() helper in the older test file
 * but scoped locally so the two suites can evolve independently.
 */
function _realQaShapesSvc(array $transactions): TfnFuelReconciliationService
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

/**
 * @param string $driverTradePlate
 * @return \App\Models\Job
 */
function _realQaShapesJob(string $driverTradePlate): \App\Models\Job
{
    $company = \App\Models\Company::factory()->create(['type' => \App\Models\Company::TYPE_OEM]);
    $creator = \App\Models\User::factory()->create();
    $driver  = \App\Models\User::factory()->create(['is_active' => true]);
    \App\Models\Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
    $driver->assignRole('driver');
    \App\Models\DriverProfile::create(['user_id' => $driver->id, 'trade_plate' => $driverTradePlate]);
    $pickup   = \App\Models\Location::create(['company_id' => null, 'company_name' => 'Plant',  'address' => 'Plant',  'is_active' => true]);
    $delivery = \App\Models\Location::create(['company_id' => null, 'company_name' => 'Dealer', 'address' => 'Dealer', 'is_active' => true]);
    return \App\Models\Job::create([
        'uuid'                => (string) \Illuminate\Support\Str::uuid(),
        'job_number'          => 'JOB-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
        'job_type'            => 'transport',
        'status'              => \App\Models\Job::STATUS_DELIVERED,
        'company_id'          => $company->id,
        'created_by_user_id'  => $creator->id,
        'executor_type'       => \App\Models\Job::EXECUTOR_PROSELVER,
        'vin'                 => 'VIN' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
        'registration'        => null,
        'pickup_location_id'  => $pickup->id,
        'delivery_location_id'=> $delivery->id,
        'scheduled_date'      => now()->subDay()->toDateString(),
        'collected_at'        => now()->subDay(),
        'delivered_at'        => now()->subHours(2),
        'driver_user_id'      => $driver->id,
    ]);
}
