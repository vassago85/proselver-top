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
        // Every null-return path now logs a warning with enough context
        // to debug from the laravel.log without re-running the call.
        // Previously this was silent and the UI showed a generic "could
        // not calculate" message even when the underlying cause was
        // something concrete like REQUEST_DENIED on the API key.

        if (!$pickup->latitude || !$pickup->longitude || !$delivery->latitude || !$delivery->longitude) {
            Log::warning('Route calc: missing coordinates', [
                'pickup_id' => $pickup->id, 'delivery_id' => $delivery->id,
                'pickup_lat' => $pickup->latitude, 'pickup_lng' => $pickup->longitude,
                'delivery_lat' => $delivery->latitude, 'delivery_lng' => $delivery->longitude,
            ]);
            return null;
        }

        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            Log::warning('Route calc: no API key configured');
            return null;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$pickup->latitude},{$pickup->longitude}",
                'destination' => "{$delivery->latitude},{$delivery->longitude}",
                'key' => $apiKey,
            ]);

            if (!$response->successful()) {
                Log::warning('Route calc: HTTP failure', [
                    'status' => $response->status(),
                    'pickup_id' => $pickup->id, 'delivery_id' => $delivery->id,
                ]);
                return null;
            }

            $data = $response->json();

            // Google returns 200 OK even when there's no route -- the
            // actual outcome is in $data['status'].  Log it so we can
            // distinguish REQUEST_DENIED (API not enabled), OVER_QUERY_LIMIT
            // (quota), ZERO_RESULTS (no route exists), INVALID_REQUEST
            // (malformed coords), etc.
            $googleStatus = $data['status'] ?? 'UNKNOWN';
            if ($googleStatus !== 'OK' || empty($data['routes'][0])) {
                Log::warning('Route calc: Google returned no usable route', [
                    'google_status' => $googleStatus,
                    'error_message' => $data['error_message'] ?? null,
                    'pickup_id' => $pickup->id, 'delivery_id' => $delivery->id,
                ]);
                return null;
            }

            $route = $data['routes'][0];
            $leg = $route['legs'][0];

            $distanceKm = round($leg['distance']['value'] / 1000, 2);
            $durationMinutes = (int) ceil($leg['duration']['value'] / 60);

            // Build a denser polyline by concatenating each step's
            // polyline rather than relying on Google's heavily-
            // simplified overview_polyline.  Steps give 10-30x more
            // points so plaza matching is far more accurate.  Stored as
            // a JSON array of [lat, lng] pairs; decodePolyline detects
            // and handles both formats so cached overview polylines
            // keep working until they're refreshed.
            $allPoints = [];
            foreach ($leg['steps'] ?? [] as $step) {
                if (empty($step['polyline']['points'])) continue;
                $allPoints = array_merge($allPoints, self::decodeGooglePolyline($step['polyline']['points']));
            }

            $polyline = !empty($allPoints)
                ? json_encode($allPoints)
                : ($route['overview_polyline']['points'] ?? null);

            return [
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'polyline' => $polyline,
            ];
        } catch (\Throwable $e) {
            Log::warning('Route calc: exception', [
                'error' => $e->getMessage(),
                'pickup_id' => $pickup->id, 'delivery_id' => $delivery->id,
            ]);
            return null;
        }
    }

    /**
     * Match seeded toll_plazas against the route polyline.
     *
     * Threshold notes:
     * - Google's `overview_polyline` is ALREADY heavily simplified by
     *   their server (a 600km route is often <1500 points).  An extra
     *   sub-sampling step on top of that left big enough gaps that a
     *   plaza sitting between two polyline points was missed even
     *   though the road clearly passes through it.  We now match
     *   against every decoded point.
     * - 5km haversine threshold (was 2km).  Simplification approximates
     *   highway curves as straight lines, which can put a plaza 3-4km
     *   from the nearest polyline point even when the plaza is right
     *   on the road.  SA mainline plazas are spaced 30+km apart and
     *   parallel highways are 20+km apart, so a 5km window won't false-
     *   match across alternate routes.
     */
    public const TOLL_MATCH_RADIUS_KM = 5.0;

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
                if ($distance <= self::TOLL_MATCH_RADIUS_KM) {
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

    /**
     * Decode whatever's in the polyline column into a points array.
     * Supports two shapes:
     *   - JSON array (new) -- the per-step concatenated polyline we
     *     started storing after the sparse-overview_polyline bug.
     *   - Google encoded polyline (legacy) -- still valid for any
     *     cached route_estimates rows from before the switch.
     */
    public static function decodePolyline(string $encoded): array
    {
        $trimmed = ltrim($encoded);
        if ($trimmed === '') return [];

        if ($trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) return $decoded;
            // Fall through: malformed JSON, try as Google encoded.
        }

        return self::decodeGooglePolyline($encoded);
    }

    /**
     * Decode a single Google-encoded polyline string into [lat, lng] pairs.
     * Algorithm per https://developers.google.com/maps/documentation/utilities/polylinealgorithm
     */
    private static function decodeGooglePolyline(string $encoded): array
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

        return $points;
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
