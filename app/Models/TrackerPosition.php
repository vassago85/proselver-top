<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One sample from a vehicle GPS tracker (TrackSolid today; the column
 * names are vendor-neutral so a second integration can write the same
 * shape without a schema change).
 *
 * The wallboard only ever wants the LATEST row per tracker. Historical
 * rows are kept on disk for breadcrumb playback (out of scope for the
 * initial wallboard PR) and for debugging stale-position complaints.
 */
class TrackerPosition extends Model
{
    protected $fillable = [
        'tracker_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'heading_deg',
        'reported_at',
        'received_at',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'speed_kmh' => 'decimal:2',
            'heading_deg' => 'decimal:2',
            'reported_at' => 'datetime',
            'received_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * Relate this position to the DriverProfile that owns its tracker.
     * `tracker_id` on this row is matched against `driver_profiles.tracker_id`,
     * NOT against the `id` PK — hence the explicit local/foreign keys.
     */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class, 'tracker_id', 'tracker_id');
    }

    /**
     * Limit to positions reported within the last $minutes minutes. Used
     * by the wallboard to colour-code drivers (green = fresh, amber =
     * stale, red = offline / no fix in over an hour).
     */
    public function scopeFresh(Builder $query, int $minutes = 5): Builder
    {
        return $query->where('reported_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Window scope for the wallboard map: anything reported in the last
     * $minutes is renderable as a live driver pin. Defaults to 60 minutes
     * so the board stays useful even when a driver loses signal under a
     * gantry — the marker just goes amber instead of vanishing.
     */
    public function scopeRecent(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('reported_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Reduce the table to one (latest) row per tracker_id. Implemented
     * with a correlated sub-select rather than `groupBy` because MySQL's
     * ONLY_FULL_GROUP_BY mode rejects the latter for "give me the row
     * with the max timestamp for each group".
     */
    public static function latestPerTracker(): Builder
    {
        return static::query()
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('tracker_positions')
                    ->groupBy('tracker_id');
            });
    }
}
