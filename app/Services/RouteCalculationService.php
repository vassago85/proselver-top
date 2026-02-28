<?php

namespace App\Services;

use App\Models\Location;
use App\Models\SystemSetting;
use App\Models\TollPlaza;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RouteCalculationService
{
    public static function calculate(Location $pickup, Location $delivery): ?array
    {
        if (!$pickup->latitude || !$pickup->longitude || !$delivery->latitude || !$delivery->longitude) {
            return null;
        }

        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$pickup->latitude},{$pickup->longitude}",
                'destination' => "{$delivery->latitude},{$delivery->longitude}",
                'key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (empty($data['routes'][0])) {
                return null;
            }

            $route = $data['routes'][0];
            $leg = $route['legs'][0];

            $distanceKm = round($leg['distance']['value'] / 1000, 2);
            $durationMinutes = (int) ceil($leg['duration']['value'] / 60);
            $polyline = $route['overview_polyline']['points'] ?? null;

            return [
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'polyline' => $polyline,
            ];
        } catch (\Throwable $e) {
            Log::warning('Route calculation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function detectTolls(string $polyline, int $tollClass): array
    {
        $points = self::decodePolyline($polyline);
        if (empty($points)) {
            return ['plazas' => [], 'total_cost' => 0];
        }

        $plazas = TollPlaza::active()->get();
        $matched = [];
        $totalCost = 0;

        foreach ($plazas as $plaza) {
            foreach ($points as $point) {
                $distance = self::haversine($point[0], $point[1], (float) $plaza->latitude, (float) $plaza->longitude);
                if ($distance <= 2.0) {
                    $fee = $plaza->feeForClass($tollClass);
                    $matched[] = [
                        'plaza' => $plaza,
                        'fee' => $fee,
                    ];
                    $totalCost += $fee;
                    break;
                }
            }
        }

        return ['plazas' => $matched, 'total_cost' => round($totalCost, 2)];
    }

    public static function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lng = 0;
        $len = strlen($encoded);

        while ($index < $len) {
            $shift = 0;
            $result = 0;
            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlat = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $lat += $dlat;

            $shift = 0;
            $result = 0;
            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlng = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            $lng += $dlng;

            $points[] = [$lat / 1e5, $lng / 1e5];
        }

        // Sample every 10th point for performance
        $sampled = [];
        foreach ($points as $i => $point) {
            if ($i % 10 === 0) {
                $sampled[] = $point;
            }
        }

        return $sampled ?: $points;
    }

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
