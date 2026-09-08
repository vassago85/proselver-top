<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single TFN fuel fill, persisted so MTD tiles don't have to trust
 * the 100-row cap on /api/Transactions.
 *
 * WHY THIS EXISTS
 * ---------------
 * TFN's Transactions endpoint has no pagination and no server-side
 * rand-spend aggregate. The old dashboard called it live and summed
 * `abs(Amount)` — which meant the MTD tile QUIETLY UNDERSTATED as soon
 * as the account crossed ~100 fills in the month. This model is the
 * source of truth the tile reads now; a scheduled snapshotter keeps
 * it fresh (see `App\Console\Commands\SnapshotTfnFuelFills`).
 *
 * We keep the raw TFN payload in `payload` so any column TFN adds
 * later (site, driver ref, discount code) is not lost between
 * snapshot and use.
 */
class FuelFill extends Model
{
    /**
     * The product codes that count as liquid fuel — everything else
     * TFN returns on the same feed (OS for nights, WSH for washes,
     * EW for electronic wallets, etc.) is filtered out when the
     * caller wants MTD "fuel" spend.
     */
    public const LIQUID_PRODUCT_CODES = ['D0', 'D1', 'D3', 'ULP93', 'ULP95'];

    protected $fillable = [
        'transaction_id',
        'customer_number',
        'captured_at',
        'transaction_at',
        'product_code',
        'transaction_type_code',
        'vehicle_registration',
        'litres',
        'amount',
        'payload',
        'snapshotted_at',
    ];

    protected $casts = [
        'captured_at'     => 'datetime',
        'transaction_at'  => 'datetime',
        'snapshotted_at'  => 'datetime',
        'litres'          => 'decimal:3',
        'amount'          => 'decimal:2',
        'payload'         => 'array',
    ];

    /**
     * Only liquid fuel (diesel + petrol grades). Excludes overnight
     * accommodation, washes, wallet top-ups, etc. — every non-fuel
     * TFN product code the demo fixtures + prod feed have exposed.
     */
    public function scopeLiquid(Builder $query): Builder
    {
        return $query->whereIn('product_code', self::LIQUID_PRODUCT_CODES);
    }

    /**
     * Rows in the [from, to] window keyed off `captured_at` (TFN's
     * "when did the pump ring it up" timestamp). Inclusive on both
     * ends because the tile picks month-start / month-end boundaries.
     */
    public function scopeCapturedBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('captured_at', [$from, $to]);
    }
}
