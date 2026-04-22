<?php

namespace App\Models;

use App\Services\GeocodingService;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Location extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'company_id',
        'zone_id',
        'company_name',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'customer_name',
        'customer_phone',
        'customer_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            if (empty($location->uuid)) {
                $location->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (Location $location) {
            // Read raw attributes so the decimal:8 cast doesn't choke on ''
            // (BigNumber::of('') throws). Normalise blanks/zero-ish to null.
            $rawLat = $location->getAttributes()['latitude'] ?? null;
            $rawLng = $location->getAttributes()['longitude'] ?? null;

            if ($rawLat === '' || $rawLat === null) {
                $location->latitude = null;
                $rawLat = null;
            }
            if ($rawLng === '' || $rawLng === null) {
                $location->longitude = null;
                $rawLng = null;
            }

            if ($location->address && $rawLat === null && $rawLng === null) {
                try {
                    $coords = GeocodingService::geocode($location->address);
                    if ($coords) {
                        $location->latitude = $coords['lat'];
                        $location->longitude = $coords['lng'];
                    }
                } catch (\Throwable $e) {
                    // Geocoding unavailable -- save without coordinates
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function scopeVisibleTo(Builder $query, Company $company): Builder
    {
        return $query->where('company_id', $company->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->company_name;
    }

    public function shortDisplay(): string
    {
        $parts = [$this->company_name];
        if ($this->city) {
            $parts[] = $this->city;
        }
        return implode(', ', $parts);
    }
}
