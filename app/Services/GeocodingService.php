<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Fields we pull back on every "structured" hit — city, province,
     * formatted address, coordinates.  Used by both `geocodeDetailed`
     * and `suggest` so the two paths return the same shape and any
     * caller can swap in `suggest()[0]` for `geocodeDetailed()`.
     */
    private const RESULT_KEYS = ['formatted_address', 'city', 'province', 'lat', 'lng', 'place_id'];

    public static function geocode(string $address): ?array
    {
        foreach (self::suggest($address, 5) as $hit) {
            // A city-centroid pin is not a usable route origin. Callers
            // that only want coordinates must get a street-level hit.
            if (!empty($hit['precise'])) {
                return ['lat' => $hit['lat'], 'lng' => $hit['lng']];
            }
        }

        return null;
    }

    /**
     * Best structured hit for a free-text address or dealer name.
     *
     * Prefers a street-level South African result (province + a pin we
     * can route from). When the Geocoding API only knows the suburb,
     * a name with no street number is retried through Places, which is
     * what actually resolves "ISUZU TRUCK CENTRE CITY DEEP" to the
     * workshop. A province-only hit is still returned (precise=false)
     * so the lane can be grouped, but callers must not treat that pin
     * as a street address.
     */
    public static function resolve(string $address): ?array
    {
        $query = trim($address);
        if ($query === '') {
            return null;
        }

        $candidates = self::suggest($query, 5);
        foreach ($candidates as $hit) {
            if (!empty($hit['precise'])) {
                return $hit;
            }
        }

        if (!preg_match('/\d/', $query)) {
            $fromPlace = self::resolveBusinessName($query);
            if ($fromPlace && !empty($fromPlace['precise'])) {
                return $fromPlace;
            }
            if ($fromPlace && $candidates === []) {
                return $fromPlace;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Geocode and extract city + province from address components.
     *
     * Thin wrapper over `suggest()` -- returns the top candidate in the
     * same shape callers have always seen (lat / lng / city / province /
     * formatted_address).  Preserving the old shape means existing call
     * sites (address-book lookup buttons, the auto-geocode hook on
     * Location::saving) keep working without a signature change.
     */
    public static function geocodeDetailed(string $address): ?array
    {
        $candidates = self::suggest($address, 1);
        return $candidates[0] ?? null;
    }

    /**
     * Return up to $limit ZA-region geocoding candidates for the given
     * free-text query.  Each candidate has:
     *
     *   [
     *     'formatted_address' => '12 Sample Rd, Randburg, 2194, South Africa',
     *     'city'              => 'Randburg',
     *     'province'          => 'Gauteng',
     *     'lat'               => -26.093,
     *     'lng'               =>  28.005,
     *     'place_id'          => 'ChIJ...',
     *   ]
     *
     * Returns [] on API failure, missing key, or zero results -- callers
     * are expected to fall back gracefully (e.g. keep the operator's
     * typed text) rather than treat "no suggestions" as an error.
     *
     * This is the single point in the app where address suggestions come
     * from.  Bulk import preview, address-book create/edit and the
     * cleanup UI all funnel through here so switching vendors later is
     * a one-file change.
     */
    public static function suggest(string $address, int $limit = 5): array
    {
        $query = trim($address);
        if ($query === '') {
            return [];
        }

        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            return [];
        }

        $results = self::geocodeResults([
            'address' => $query,
            'region' => 'za',
            // Bias (region=za) still returns overseas matches for a
            // generic name. Restricting to ZA is what keeps a lane
            // from being built on the wrong country.
            'components' => 'country:ZA',
        ]);
        if ($results === []) {
            return [];
        }

        $out = [];
        $limit = max(1, $limit);
        foreach ($results as $result) {
            $normalised = self::normaliseResult($result);
            if (!$normalised) {
                continue;
            }
            $out[] = $normalised;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Turn one Google Geocoding API result into our internal candidate
     * shape.  Returns null for results without coordinates -- a
     * suggestion the user can't act on ("here's a formatted string, but
     * no lat/lng") is worse than no suggestion at all because it looks
     * like a valid pick but breaks route/toll estimation later.
     */
    private static function normaliseResult(array $result): ?array
    {
        $loc = $result['geometry']['location'] ?? null;
        if (!$loc || !isset($loc['lat'], $loc['lng'])) {
            return null;
        }

        $components = $result['address_components'] ?? [];
        $city = null;
        $cityRank = 99;
        $province = null;
        $country = null;
        $cityRanks = [
            'locality' => 1,
            'postal_town' => 2,
            'sublocality_level_1' => 3,
            'sublocality' => 4,
        ];
        foreach ($components as $c) {
            $types = $c['types'] ?? [];
            foreach ($cityRanks as $type => $rank) {
                if ($rank < $cityRank && in_array($type, $types, true)) {
                    $city = $c['long_name'] ?? null;
                    $cityRank = $rank;
                }
            }
            if (in_array('administrative_area_level_1', $types, true)) {
                $province = $c['long_name'] ?? null;
            }
            if (in_array('country', $types, true)) {
                $country = $c['short_name'] ?? null;
            }
        }

        // Lane grouping and toll estimates both key off the province.
        // A pin with no province (or outside ZA) is not a usable hit.
        if ($province === null || trim((string) $province) === '') {
            return null;
        }
        if ($country !== null && strtoupper((string) $country) !== 'ZA') {
            return null;
        }

        return [
            'formatted_address' => $result['formatted_address'] ?? null,
            'city' => $city,
            'province' => $province,
            'lat' => $loc['lat'],
            'lng' => $loc['lng'],
            'place_id' => $result['place_id'] ?? null,
            'precise' => self::isPrecise($result),
        ];
    }

    /**
     * Street-level (or a named premises) rather than a suburb centroid.
     * Payloads that omit both `types` and `location_type` stay precise
     * so older fixtures that already carried a province still apply.
     */
    private static function isPrecise(array $result): bool
    {
        if (!empty($result['partial_match'])) {
            return false;
        }

        $locationType = $result['geometry']['location_type'] ?? null;
        if ($locationType === 'APPROXIMATE') {
            return false;
        }

        $types = $result['types'] ?? [];
        $streetTypes = ['street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest', 'intersection'];
        if (array_intersect($streetTypes, $types) !== []) {
            return true;
        }
        if ($locationType === 'ROOFTOP' || $locationType === 'RANGE_INTERPOLATED') {
            return true;
        }
        if ($locationType === null && $types === []) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private static function geocodeResults(array $params): array
    {
        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            return [];
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', $params + [
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Address suggestion failed', [
                'params' => array_diff_key($params, ['key' => true]),
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        return $response->json()['results'] ?? [];
    }

    /**
     * Places Find Place, then a place_id geocode so we still get
     * province and city components. Used only for digit-free names
     * the address geocoder could not pin to a street.
     */
    private static function resolveBusinessName(string $query): ?array
    {
        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            return null;
        }

        $input = $query;
        if (!str_contains(strtolower($input), 'south africa')) {
            $input .= ', South Africa';
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
                'input' => $input,
                'inputtype' => 'textquery',
                'fields' => 'place_id',
                'locationbias' => 'ipbias',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Places lookup failed', ['address' => $query, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $placeId = $response->json()['candidates'][0]['place_id'] ?? null;
        if (!$placeId) {
            return null;
        }

        $results = self::geocodeResults(['place_id' => $placeId]);
        foreach ($results as $result) {
            $normalised = self::normaliseResult($result);
            if ($normalised) {
                return $normalised;
            }
        }

        return null;
    }
}
