<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached Google Maps Directions response for a pickup→delivery pair.
 *
 * The TripCostEstimator looks up this row before calling Google.
 * A row exists per location pair (unique constraint in the migration),
 * so repeat orders on the same route reuse the polyline rather than
 * re-billing the API.  Tolls themselves aren't cached here — fees come
 * straight off `toll_plazas` so a fee revision is picked up immediately
 * on the next estimate.
 */
class RouteEstimate extends Model
{
    protected $fillable = [
        'pickup_location_id',
        'delivery_location_id',
        'distance_km',
        'duration_minutes',
        'polyline',
        'provider',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'duration_minutes' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'delivery_location_id');
    }
}
