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
        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'za',
                'key' => $apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['results'][0]['geometry']['location'])) {
                    $loc = $data['results'][0]['geometry']['location'];
                    return ['lat' => $loc['lat'], 'lng' => $loc['lng']];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);
        }

        return null;
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

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $query,
                'region' => 'za',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Address suggestion failed', [
                'address' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $results = $data['results'] ?? [];
        if (empty($results)) {
            return [];
        }

        $out = [];
        foreach (array_slice($results, 0, max(1, $limit)) as $result) {
            $normalised = self::normaliseResult($result);
            if ($normalised) {
                $out[] = $normalised;
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
        $province = null;
        foreach ($components as $c) {
            $types = $c['types'] ?? [];
            if (!$city && (in_array('locality', $types, true) || in_array('sublocality_level_1', $types, true))) {
                $city = $c['long_name'] ?? null;
            }
            if (in_array('administrative_area_level_1', $types, true)) {
                $province = $c['long_name'] ?? null;
            }
        }

        return [
            'formatted_address' => $result['formatted_address'] ?? null,
            'city' => $city,
            'province' => $province,
            'lat' => $loc['lat'],
            'lng' => $loc['lng'],
            'place_id' => $result['place_id'] ?? null,
        ];
    }
}
