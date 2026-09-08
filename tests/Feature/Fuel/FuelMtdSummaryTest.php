<?php

/**
 * Read-side contract for the Owner Fuel MTD tile.
 *
 * The tile used to sum abs(Amount) from the live TFN transactions
 * feed, which caps at 100 rows / month and has no rand-spend
 * aggregate — so a heavy month silently shrank the tile. FuelMtdSummary
 * puts that behaviour behind a value object with a `source` flag:
 *
 *   1. DB          — SUM(amount) from fuel_fills — authoritative
 *   2. estimated   — aggregate litres × avg R/L from the tx feed
 *   3. live_tx     — raw sum of the tx feed (may be truncated)
 *   4. unavailable — nothing to render
 *
 * These tests pin the resolution order so a refactor cannot silently
 * fall back to the truncated live-tx path when DB rows are present.
 */

use App\Models\FuelFill;
use App\Services\Tfn\FuelMtdSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function fuelFillRow(array $overrides = []): FuelFill
{
    return FuelFill::create(array_merge([
        'transaction_id'       => 'tx-' . uniqid('', true),
        'customer_number'      => '10021',
        'captured_at'          => now()->startOfMonth()->addDays(2),
        'transaction_at'       => now()->startOfMonth()->addDays(2),
        'product_code'         => 'D0',
        'transaction_type_code'=> 'FUEL',
        'vehicle_registration' => 'CA123GP',
        'litres'               => 100,
        'amount'               => 2500,
        'payload'              => [],
        'snapshotted_at'       => now(),
    ], $overrides));
}

it('sources spend from fuel_fills when the table is populated', function () {
    $anchor = now();
    fuelFillRow(['amount' => 1500, 'litres' => 60]);
    fuelFillRow(['amount' => 2200, 'litres' => 90]);

    // Live-tx feed is intentionally NOT passed — the DB path must win
    // even when the caller has stale live rows.
    $summary = FuelMtdSummary::forMonth($anchor, '10021');

    expect($summary->source)->toBe(FuelMtdSummary::SOURCE_DB);
    expect((float) $summary->spend)->toBe(3700.0);
    expect((float) $summary->litres)->toBe(150.0);
    expect($summary->fillCount)->toBe(2);
    expect($summary->isAuthoritative())->toBeTrue();
});

it('excludes non-liquid product codes from the DB SUM', function () {
    fuelFillRow(['amount' => 1000, 'litres' => 40, 'product_code' => 'D0']);
    fuelFillRow(['amount' => 400,  'litres' => 0,  'product_code' => 'OS']);  // overnight, not fuel
    fuelFillRow(['amount' => 200,  'litres' => 0,  'product_code' => 'WSH']); // wash, not fuel

    $summary = FuelMtdSummary::forMonth(now(), '10021');

    expect((float) $summary->spend)->toBe(1000.0);
    expect($summary->fillCount)->toBe(1);
});

it('scopes fuel_fills to the anchor month', function () {
    // Same customer, one fill inside the month and one outside.
    fuelFillRow(['amount' => 5000, 'captured_at' => now()->startOfMonth()->addDays(1)]);
    fuelFillRow(['amount' => 9999, 'captured_at' => now()->startOfMonth()->subDays(10)]);

    $summary = FuelMtdSummary::forMonth(now(), '10021');

    expect((float) $summary->spend)->toBe(5000.0);
});

it('scopes fuel_fills to the requested customer_number', function () {
    fuelFillRow(['amount' => 1000, 'customer_number' => '10021']);
    fuelFillRow(['amount' => 9999, 'customer_number' => '99999']);

    $summary = FuelMtdSummary::forMonth(now(), '10021');

    expect((float) $summary->spend)->toBe(1000.0);
});

it('estimates spend from aggregate litres × avg R/L when DB is empty and tx feed is truncated', function () {
    // The live tx feed only shows 100 L @ R25/L = R2500 for this month,
    // but TFN's server-side rollup says the account actually consumed
    // 400 L. The summary should scale up: 400 × 25 = R10 000, tagged
    // as an estimate.
    $txRows = [
        [
            'ProductCode'  => 'D0',
            'CapturedDate' => now()->startOfMonth()->addDays(1)->toIso8601String(),
            'Litres'       => 100,
            'Amount'       => -2500,   // TFN sends purchases negative
        ],
    ];

    $summary = FuelMtdSummary::forMonth(
        now(),
        '10021',
        liveTxRows: $txRows,
        aggregateLitres: 400.0,
    );

    expect($summary->source)->toBe(FuelMtdSummary::SOURCE_ESTIMATED);
    expect((float) $summary->litres)->toBe(400.0);
    expect((float) $summary->spend)->toBe(10000.0);
    expect($summary->isEstimated())->toBeTrue();
});

it('falls back to the live tx sum when DB is empty and aggregate agrees with the feed', function () {
    // No truncation: aggregate rollup matches the tx feed exactly, so
    // the raw sum is safe.
    $txRows = [
        [
            'ProductCode'  => 'D0',
            'CapturedDate' => now()->startOfMonth()->addDays(1)->toIso8601String(),
            'Litres'       => 50,
            'Amount'       => -1200,
        ],
        [
            'ProductCode'  => 'D0',
            'CapturedDate' => now()->startOfMonth()->addDays(2)->toIso8601String(),
            'Litres'       => 30,
            'Amount'       => -700,
        ],
    ];

    $summary = FuelMtdSummary::forMonth(
        now(),
        '10021',
        liveTxRows: $txRows,
        aggregateLitres: 80.0,
    );

    expect($summary->source)->toBe(FuelMtdSummary::SOURCE_LIVE_TX);
    expect((float) $summary->spend)->toBe(1900.0);
    expect((float) $summary->litres)->toBe(80.0);
    expect($summary->fillCount)->toBe(2);
});

it('returns unavailable when there is nothing anywhere', function () {
    $summary = FuelMtdSummary::forMonth(now(), '10021');

    expect($summary->source)->toBe(FuelMtdSummary::SOURCE_UNAVAILABLE);
    expect((float) $summary->spend)->toBe(0.0);
    expect($summary->fillCount)->toBe(0);
});

it('drops account payments and non-fuel product codes from the live tx sum', function () {
    // These rows would corrupt spend if summed naively — CC/CD/EW etc.
    // are payments and electronic-wallet top-ups on the same feed.
    $txRows = [
        ['ProductCode' => 'D0', 'CapturedDate' => now()->startOfMonth()->addDay()->toIso8601String(), 'Litres' => 100, 'Amount' => -2500],
        ['ProductCode' => 'D0', 'CapturedDate' => now()->startOfMonth()->addDay()->toIso8601String(), 'Litres' => 0,   'Amount' => -1_000_000, 'TransactionTypeCode' => 'CC'],
        ['ProductCode' => 'EW', 'CapturedDate' => now()->startOfMonth()->addDay()->toIso8601String(), 'Litres' => 0,   'Amount' => -999_999],
    ];

    $summary = FuelMtdSummary::forMonth(now(), '10021', $txRows, 100.0);

    expect($summary->source)->toBe(FuelMtdSummary::SOURCE_LIVE_TX);
    expect((float) $summary->spend)->toBe(2500.0);
});
