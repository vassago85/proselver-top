<?php

namespace App\Models;

use App\Domain\Operations\ProvinceLabel;
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

    public const TYPE_PLANT = 'plant';
    public const TYPE_YARD = 'yard';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_DEALER = 'dealer';
    public const TYPE_BODY_BUILDER = 'body_builder';

    public const TYPES = [
        self::TYPE_PLANT,
        self::TYPE_YARD,
        self::TYPE_STORAGE,
        self::TYPE_DEALER,
        self::TYPE_BODY_BUILDER,
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'zone_id',
        'company_name',
        'type',
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
            }
            if ($rawLng === '' || $rawLng === null) {
                $location->longitude = null;
            }

            // Coords alone do not make a lane. A blank province (the
            // usual bulk-import stub) is resolved here so every job
            // pointing at this row picks up the same correction.
            if ($location->needsStructuredGeocode()) {
                try {
                    $query = trim((string) $location->address);
                    if ($query === '') {
                        $query = trim((string) $location->company_name);
                    }
                    $hit = $query !== '' ? GeocodingService::resolve($query) : null;
                    if ($hit) {
                        $location->applyGeocodeHit($hit);
                    }
                } catch (\Throwable $e) {
                    // Geocoding unavailable -- save without coordinates
                }
            }
        });
    }

    /**
     * True when a save should ask Google for a structured ZA address.
     * Skipped once both a province and a pin are already stored, so a
     * confirmed address is never re-fetched.
     */
    public function needsStructuredGeocode(): bool
    {
        $hasQuery = trim((string) $this->address) !== '' || trim((string) $this->company_name) !== '';
        if (!$hasQuery) {
            return false;
        }

        $missingCoords = $this->latitude === null || $this->longitude === null;
        $missingProvince = trim((string) $this->province) === '';

        return $missingCoords || $missingProvince;
    }

    /**
     * Dealer name written into the address column, with no street number
     * and no comma. Google can replace this; a typed street must not.
     */
    public function addressIsNameStub(): bool
    {
        $address = trim((string) $this->address);
        $name = trim((string) $this->company_name);
        if ($address === '' || $name === '' || strcasecmp($address, $name) !== 0) {
            return false;
        }

        return preg_match('/\d/', $address) !== 1 && !str_contains($address, ',');
    }

    /**
     * Copy a GeocodingService hit onto this model.
     *
     * Province and city fill only when blank. Coordinates and the
     * formatted street replace a name stub only when the hit is
     * street-level (`precise`). A suburb centroid still supplies the
     * province for the lane, and leaves the pin alone.
     */
    public function applyGeocodeHit(array $hit): bool
    {
        $changed = false;

        $province = ProvinceLabel::canonicalise(isset($hit['province']) ? (string) $hit['province'] : null);
        if ($province !== null && trim((string) $this->province) === '') {
            $this->province = $province;
            $changed = true;
        }

        $city = trim((string) ($hit['city'] ?? ''));
        if ($city !== '' && trim((string) $this->city) === '') {
            $this->city = $city;
            $changed = true;
        }

        $precise = (bool) ($hit['precise'] ?? false);
        $stub = $this->addressIsNameStub();
        $missingCoords = $this->latitude === null || $this->longitude === null;
        // A name stub may already carry a suburb-centroid pin from the
        // old geocoder. A street-level hit replaces that pin. A typed
        // street address keeps whatever coordinates were confirmed.
        if ($precise && ($missingCoords || $stub) && isset($hit['lat'], $hit['lng'])) {
            $this->latitude = $hit['lat'];
            $this->longitude = $hit['lng'];
            $changed = true;
        }

        $formatted = trim((string) ($hit['formatted_address'] ?? ''));
        if ($precise && $formatted !== '' && $stub) {
            $this->address = $formatted;
            $changed = true;
        }

        return $changed;
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

    /**
     * Single-line label safe for compact card / table cells.
     *
     * Falls through: company_name -> street address -> "city, province"
     * -> em-dash. Locations with only a company name look like
     * "Acme Motors"; locations with only an address look like
     * "12 Sample Rd, Randburg"; locations with only geo hints look
     * like "Randburg, Gauteng". Never returns an empty string.
     *
     * Used in place of `$job->pickup_address` fallback strings that
     * referenced non-existent columns on `transport_jobs`, which was
     * the root cause of the "pickup shows as -" bug on /vehicles.
     */
    public function displayLabel(): string
    {
        $name = trim((string) $this->company_name);
        if ($name !== '') {
            return $name;
        }

        $addr = trim((string) $this->address);
        if ($addr !== '') {
            return $addr;
        }

        $tail = trim(implode(', ', array_filter([
            trim((string) $this->city),
            trim((string) $this->province),
        ])));

        return $tail !== '' ? $tail : '—';
    }

    public function shortDisplay(): string
    {
        $parts = [$this->company_name];
        if ($this->city) {
            $parts[] = $this->city;
        }
        return implode(', ', $parts);
    }

    /**
     * Full multi-line display for the orders list / collection notes etc.
     * Returns "<company>\n<street address>\n<city, province>" with the
     * blank pieces dropped so a half-populated location still renders
     * sensibly.  Lines are joined with " — " when collapsed for tooltips.
     */
    public function fullDisplay(string $sep = "\n"): string
    {
        $lines = [trim((string) $this->company_name)];

        $addr = trim((string) $this->address);
        if ($addr !== '' && strcasecmp($addr, (string) $this->company_name) !== 0) {
            $lines[] = $addr;
        }

        $tail = trim(implode(', ', array_filter([
            trim((string) $this->city),
            trim((string) $this->province),
        ])));
        if ($tail !== '') {
            $lines[] = $tail;
        }

        return implode($sep, array_filter($lines));
    }
}
