<?php

/**
 * Snapshot command contract:
 *
 *   1. Skips silently when TFN is not live (no crash, no writes).
 *   2. Upserts by TransactionID — a re-run does not duplicate.
 *   3. Skips CC/CD/CX/PAYMENT and product code EW so payments never
 *      leak into MTD spend.
 *   4. Synthesises a stable transaction_id when TFN doesn't send one.
 */

use App\Models\FuelFill;
use App\Services\Tfn\TfnClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Bind a fake TfnClient that returns the given rows for every
 * transactions() call. Reports isLive() as true so the command runs.
 */
function bindFakeTfnClient(array $rows, bool $live = true): void
{
    // Ensure the command can construct because config validates. Set
    // a customer_number so the persist path uses it.
    config()->set('tfn.enabled', true);
    config()->set('tfn.demo_mode', false);
    config()->set('tfn.customer_number', '10021');
    config()->set('tfn.username', 'x');
    config()->set('tfn.password', 'x');

    app()->instance(TfnClient::class, new class($rows, $live) extends TfnClient {
        public function __construct(private array $rows, private bool $live)
        {
            // Skip the parent constructor: it wants a TfnTokenManager
            // which we don't need for a stubbed transactions() feed.
        }

        public function isLive(): bool
        {
            return $this->live;
        }

        public function transactions(?\DateTimeInterface $capturedDateAfter = null): array
        {
            return $this->rows;
        }
    });
}

function tfnRow(array $overrides = []): array
{
    return array_merge([
        'TransactionID'        => 'TX-' . uniqid('', true),
        'CapturedDate'         => now()->subHours(1)->toIso8601String(),
        'TransactionDate'      => now()->subHours(1)->toIso8601String(),
        'ProductCode'          => 'D0',
        'TransactionTypeCode'  => 'FUEL',
        'VehicleRegistration'  => 'CA123GP',
        'Litres'               => 100.0,
        'Amount'               => -2500.0,
    ], $overrides);
}

it('no-ops cleanly when TFN is not live', function () {
    bindFakeTfnClient([tfnRow()], live: false);

    $this->artisan('tfn:snapshot-fills --days=1')
        ->expectsOutputToContain('not live')
        ->assertSuccessful();

    expect(FuelFill::count())->toBe(0);
});

it('persists incoming rows and is idempotent on re-run', function () {
    $rows = [
        tfnRow(['TransactionID' => 'A', 'Amount' => -1500, 'Litres' => 60]),
        tfnRow(['TransactionID' => 'B', 'Amount' => -2100, 'Litres' => 84]),
    ];
    bindFakeTfnClient($rows);

    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();
    expect(FuelFill::count())->toBe(2);

    // Second run must not duplicate.
    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();
    expect(FuelFill::count())->toBe(2);

    $a = FuelFill::where('transaction_id', 'A')->first();
    expect((float) $a->amount)->toBe(1500.0);
    expect((float) $a->litres)->toBe(60.0);
    expect($a->customer_number)->toBe('10021');
});

it('updates an existing row when the tx re-emits with a new amount', function () {
    bindFakeTfnClient([tfnRow(['TransactionID' => 'X', 'Amount' => -1000])]);
    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();
    expect((float) FuelFill::where('transaction_id', 'X')->value('amount'))->toBe(1000.0);

    bindFakeTfnClient([tfnRow(['TransactionID' => 'X', 'Amount' => -1234.56])]);
    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();
    expect((float) FuelFill::where('transaction_id', 'X')->value('amount'))->toBe(1234.56);
    expect(FuelFill::count())->toBe(1);
});

it('drops payment / credit rows and the EW product code', function () {
    $rows = [
        tfnRow(['TransactionID' => 'FUEL-1']),
        tfnRow(['TransactionID' => 'PAY-1',   'TransactionTypeCode' => 'CC', 'Amount' => -1_000_000]),
        tfnRow(['TransactionID' => 'PAY-2',   'TransactionTypeCode' => 'CD']),
        tfnRow(['TransactionID' => 'WALLET',  'ProductCode' => 'EW']),
    ];
    bindFakeTfnClient($rows);

    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();

    expect(FuelFill::count())->toBe(1);
    expect(FuelFill::first()->transaction_id)->toBe('FUEL-1');
});

it('synthesises a deterministic transaction_id when TFN omits one', function () {
    $row = tfnRow(['TransactionID' => null, 'Amount' => -777, 'Litres' => 30]);
    bindFakeTfnClient([$row, $row]);   // same row twice, no TXID

    $this->artisan('tfn:snapshot-fills --days=1')->assertSuccessful();

    // Two identical rows without a TXID must collapse into one on the
    // synthetic key — otherwise a retry duplicates them.
    expect(FuelFill::count())->toBe(1);
    expect(FuelFill::first()->transaction_id)->toStartWith('syn:');
});
