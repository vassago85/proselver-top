<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-lane (pickup, delivery) memory of toll plazas that the route
 * actually passes but Google's chosen polyline misses.  Mirrors the
 * read/write helpers on ModelTollClassHint -- single thin model, all
 * lookup/upsert via statics, no events.
 *
 * Written from saveAdvance() in the advance modal when ops adds a
 * gate; read from TripCostEstimator::estimateTolls() to merge the
 * remembered set with the polyline-detected set.
 */
class RouteTollPlazaHint extends Model
{
    protected $fillable = [
        'pickup_location_id',
        'delivery_location_id',
        'toll_plaza_id',
        'learned_by_user_id',
        'learned_at',
        'last_used_at',
        'use_count',
    ];

    protected function casts(): array
    {
        return [
            'pickup_location_id' => 'integer',
            'delivery_location_id' => 'integer',
            'toll_plaza_id' => 'integer',
            'use_count' => 'integer',
            'learned_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * All remembered plaza ids for a (pickup, delivery) lane.  Returns
     * an empty array when either id is missing or the lane has no
     * hints yet -- callers can union with auto-detected plazas without
     * a null check.
     */
    public static function plazaIdsForRoute(?int $pickupId, ?int $deliveryId): array
    {
        if (!$pickupId || !$deliveryId) return [];
        return self::query()
            ->where('pickup_location_id', $pickupId)
            ->where('delivery_location_id', $deliveryId)
            ->pluck('toll_plaza_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Add a plaza to a lane's memory.  Idempotent: re-adding the same
     * plaza reinforces (bumps use_count, refreshes last_used_at) rather
     * than creating a duplicate row.  Returns the row, or null if any
     * id is missing.
     */
    public static function remember(?int $pickupId, ?int $deliveryId, ?int $tollPlazaId, ?int $userId): ?self
    {
        if (!$pickupId || !$deliveryId || !$tollPlazaId) return null;

        $row = self::query()
            ->where('pickup_location_id', $pickupId)
            ->where('delivery_location_id', $deliveryId)
            ->where('toll_plaza_id', $tollPlazaId)
            ->first();

        if ($row) {
            $row->last_used_at = Carbon::now();
            $row->use_count = $row->use_count + 1;
            // Don't overwrite the original learner -- the audit trail is
            // most useful when it points at whoever first noticed the gap.
            $row->save();
            return $row;
        }

        return self::create([
            'pickup_location_id' => $pickupId,
            'delivery_location_id' => $deliveryId,
            'toll_plaza_id' => $tollPlazaId,
            'learned_by_user_id' => $userId,
            'learned_at' => Carbon::now(),
            'last_used_at' => Carbon::now(),
            'use_count' => 1,
        ]);
    }

    /**
     * Drop a plaza from a lane's memory.  Called when ops clicks the
     * remove (x) on a remembered row -- the gate shouldn't come back
     * on the next estimate for this lane.  No-op if there's no hint.
     */
    public static function forget(?int $pickupId, ?int $deliveryId, ?int $tollPlazaId): void
    {
        if (!$pickupId || !$deliveryId || !$tollPlazaId) return;
        self::query()
            ->where('pickup_location_id', $pickupId)
            ->where('delivery_location_id', $deliveryId)
            ->where('toll_plaza_id', $tollPlazaId)
            ->delete();
    }

    /**
     * Bump last_used_at on every hint for a lane.  Called on a
     * successful saveAdvance(), so use_count + last_used_at reflect
     * "actually used on a trip" rather than just "auto-suggested
     * during dropdown fiddling".  Use_count bumps stay attached to
     * remember() to avoid double-counting on every keystroke.
     */
    public static function markUsed(?int $pickupId, ?int $deliveryId): void
    {
        if (!$pickupId || !$deliveryId) return;
        self::query()
            ->where('pickup_location_id', $pickupId)
            ->where('delivery_location_id', $deliveryId)
            ->update(['last_used_at' => Carbon::now()]);
    }
}
