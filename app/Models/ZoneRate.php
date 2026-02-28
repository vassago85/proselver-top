<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZoneRate extends Model
{
    protected $fillable = [
        'origin_zone_id',
        'destination_zone_id',
        'vehicle_class_id',
        'distance_km',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'origin_zone_id');
    }

    public function destinationZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'destination_zone_id');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find a rate for a given origin zone, destination zone, and vehicle class.
     */
    public static function findRate(int $originZoneId, int $destinationZoneId, int $vehicleClassId): ?self
    {
        return static::where('origin_zone_id', $originZoneId)
            ->where('destination_zone_id', $destinationZoneId)
            ->where('vehicle_class_id', $vehicleClassId)
            ->where('is_active', true)
            ->first();
    }
}
