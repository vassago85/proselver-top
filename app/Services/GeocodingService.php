<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
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
     */
    public static function geocodeDetailed(string $address): ?array
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

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (empty($data['results'][0])) {
                return null;
            }

            $result = $data['results'][0];
            $loc = $result['geometry']['location'] ?? null;
            $components = $result['address_components'] ?? [];

            $city = null;
            $province = null;
            foreach ($components as $c) {
                if (!$city && (in_array('locality', $c['types']) || in_array('sublocality_level_1', $c['types']))) {
                    $city = $c['long_name'];
                }
                if (in_array('administrative_area_level_1', $c['types'])) {
                    $province = $c['long_name'];
                }
            }

            return [
                'lat' => $loc['lat'] ?? null,
                'lng' => $loc['lng'] ?? null,
                'city' => $city,
                'province' => $province,
                'formatted_address' => $result['formatted_address'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Detailed geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
