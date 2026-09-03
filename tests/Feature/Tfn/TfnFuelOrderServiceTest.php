<?php

/**
 * TfnFuelOrderService payload contract.
 *
 * The service is the single code path both the fuel operations page
 * and the order-show fuel modal go through.  These tests pin the
 * payload shape TFN's /api/Orders sees on the wire -- especially the
 * DriverCellNumber (governs the voucher SMS) and SkipSMS (must stay
 * false so TFN actually delivers it).
 */

use App\Models\Role;
use App\Models\TfnFuelOrderPlacement;
use App\Models\User;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnFuelOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
});

/**
 * Build a live-mode TfnClient stub that captures the create-order
 * payload without hitting the network.
 */
function fuelOrderServiceCapturingClient(array &$captured, string $voucher = '812345'): TfnClient
{
    return new class ($captured, $voucher) extends TfnClient {
        /** @param array $captured Passed by reference so the test can inspect it. */
        public function __construct(private array &$captured, private string $voucher) {}
        public function isLive(): bool { return true; }
        public function createOrder(array $order, ?string $newRecordIdentifier = null): array
        {
            $this->captured = $order;
            // Mirror the real TFN v3 response shape: OrderNumber at
            // the top level, and each Entry echoes back with a
            // server-populated CurrentVirtualCardNumber (the driver's
            // pump voucher).
            return [
                'OrderNumber' => 'ORD/01/TEST/00042',
                'Entries'     => [['CurrentVirtualCardNumber' => $this->voucher]],
            ];
        }
    };
}

test('the payload includes DriverCellNumber verbatim when the caller supplies one', function () {
    $ops = User::factory()->create(['name' => 'Lize Ops', 'is_active' => true]);
    $ops->assignRole('operations_controller');
    $this->actingAs($ops);

    $captured = [];
    $service = new TfnFuelOrderService(fuelOrderServiceCapturingClient($captured));

    $service->place(
        posRegistration: 'TPJHB011',
        productCode: 'D0',
        allocation: 200.0,
        expiresAt: Carbon::now()->addDays(4)->endOfDay(),
        customerReference: 'JOB-ABC123',
        driverCellNumber: '0835551234',
    );

    // Top-level: SMS stays enabled so TFN actually delivers.
    expect($captured['SkipSMS'])->toBeFalse();
    // Per-entry: DriverCellNumber flows through untrimmed except for
    // whitespace.  TFN accepts local or E.164 -- we don't reformat.
    expect($captured['Entries'][0]['DriverCellNumber'])->toBe('0835551234');
    expect($captured['Entries'][0]['VehicleRegistration'])->toBe('TPJHB011');
    expect($captured['CustomerReference'])->toBe('JOB-ABC123');

    // Placement audit still gets written when live createOrder succeeds.
    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    expect($row->order_number)->toBe('ORD/01/TEST/00042');
    expect($row->user_id)->toBe($ops->id);
});

test('the service captures the TFN voucher number into the placement audit so ops can share it if SMS fails', function () {
    // TFN's response echoes each entry back with CurrentVirtualCardNumber
    // populated by the server -- that's the driver's pump code.  We
    // snapshot it locally so a failed SMS doesn't leave ops without a
    // way to read the code back to the driver on the phone.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $this->actingAs($ops);

    $captured = [];
    $service  = new TfnFuelOrderService(fuelOrderServiceCapturingClient($captured, '812345'));

    $result = $service->place(
        posRegistration: 'TPJHB011',
        productCode: 'D0',
        allocation: 200.0,
        expiresAt: Carbon::now()->addDays(4)->endOfDay(),
        customerReference: 'JOB-ABC123',
        driverCellNumber: '0835551234',
    );

    // Return tuple carries the voucher so callers can flash it.
    expect($result['voucher_number'])->toBe('812345');
    expect($result['order_number'])->toBe('ORD/01/TEST/00042');
    expect($result['demo'])->toBeFalse();

    // Audit row snapshots the voucher alongside the order number.
    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->voucher_number)->toBe('812345');
});

test('the service mints a demo voucher when the TFN client is not live so the demo UX matches production', function () {
    // The demo path is what runs on staging and in ops training.  If
    // it didn't produce a voucher, the flash/badge would look wildly
    // different from live -- so we synthesise a plausible 6-digit
    // number here too.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $this->actingAs($ops);

    // isLive() defaults to false when TFN_KEY isn't set in .env.testing.
    $service = app(TfnFuelOrderService::class);

    $result = $service->place(
        posRegistration: 'TPJHB011',
        productCode: 'D0',
        allocation: 200.0,
        expiresAt: Carbon::now()->addDays(4)->endOfDay(),
        customerReference: 'JOB-ABC123',
    );

    expect($result['demo'])->toBeTrue();
    expect($result['voucher_number'])->toMatch('/^\d{6}$/');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row->voucher_number)->toBe($result['voucher_number']);
});

test('the payload leaves DriverCellNumber blank when no phone is supplied', function () {
    // TFN treats an empty DriverCellNumber as "nothing to SMS" even
    // though SkipSMS is false -- ops falls back to reading the voucher
    // off the portal.  We deliberately pass empty (default arg) rather
    // than omitting the key so TFN's model binder doesn't complain.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $this->actingAs($ops);

    $captured = [];
    $service = new TfnFuelOrderService(fuelOrderServiceCapturingClient($captured));

    $service->place(
        posRegistration: 'TPJHB011',
        productCode: 'D0',
        allocation: 200.0,
        expiresAt: Carbon::now()->addDays(4)->endOfDay(),
        customerReference: 'JOB-ABC123',
    );

    expect($captured['Entries'][0]['DriverCellNumber'])->toBe('');
});

test('the payload passes the assigned driver phone even in odd formatting', function () {
    // Ops-typed "083 555 1234" should reach TFN as-is (minus outer
    // whitespace).  TFN handles the spaces on their end -- we don't
    // want to be clever with format because a bad regex could strip
    // an international prefix by accident.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $this->actingAs($ops);

    $captured = [];
    $service = new TfnFuelOrderService(fuelOrderServiceCapturingClient($captured));

    $service->place(
        posRegistration: 'TPJHB011',
        productCode: 'D0',
        allocation: 200.0,
        expiresAt: Carbon::now()->addDays(4)->endOfDay(),
        customerReference: 'JOB-ABC123',
        driverCellNumber: '  083 555 1234  ',
    );

    expect($captured['Entries'][0]['DriverCellNumber'])->toBe('083 555 1234');
});
