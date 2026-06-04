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
            // alternatives=true asks Google for up to 3 routes.  Drivers
            // are expected to take the main highway (N-road) network
            // rather than the literal shortest path, so we score each
            // returned alternative on how much of its distance is on N-
            // roads and pick the heaviest-N route.  Falls back to the
            // first (shortest) result if scoring finds nothing or only
            // one route comes back.
            $response = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$pickup->latitude},{$pickup->longitude}",
                'destination' => "{$delivery->latitude},{$delivery->longitude}",
                'alternatives' => 'true',
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

            $route = self::pickMainHighwayRoute($data['routes'], $pickup->id, $delivery->id);
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
     * National-route tokens we treat as "main highways" for scoring.
     * Listed in alphanumeric order; the regex below matches any of them
     * as a whole word in Google's step instructions (e.g. "N3", "N12").
     * If SANRAL adds another national route, append it here.
     */
    private const NATIONAL_ROUTES = ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7', 'N8', 'N9', 'N10', 'N11', 'N12', 'N14', 'N17', 'N18'];

    /**
     * From a list of Google route alternatives, pick the one that spends
     * the most distance on N-roads.  Ties (or all-zero scores) fall back
     * to the first route, which is Google's recommended/shortest pick.
     *
     * Scoring is "step distance × is-on-N-road", summed across the leg.
     * A step is considered N-road if its html_instructions mentions any
     * token from NATIONAL_ROUTES, or if the route's overall `summary`
     * lists one and the step has no instructions of its own.  We log
     * the chosen route so the laravel.log shows which alternative won.
     */
    private static function pickMainHighwayRoute(array $routes, ?int $pickupId = null, ?int $deliveryId = null): array
    {
        if (count($routes) === 1) {
            return $routes[0];
        }

        $pattern = '/\b(?:' . implode('|', self::NATIONAL_ROUTES) . ')\b/i';
        $bestIndex = 0;
        $bestScore = -1.0;
        $scores = [];

        foreach ($routes as $idx => $route) {
            $leg = $route['legs'][0] ?? null;
            if (!$leg) continue;

            $summary = (string) ($route['summary'] ?? '');
            $summaryMentionsN = (bool) preg_match($pattern, $summary);

            $nMetres = 0;
            foreach ($leg['steps'] ?? [] as $step) {
                $instructions = (string) ($step['html_instructions'] ?? '');
                $isN = (bool) preg_match($pattern, $instructions);
                if (!$isN && $instructions === '' && $summaryMentionsN) {
                    $isN = true;
                }
                if ($isN) {
                    $nMetres += (int) ($step['distance']['value'] ?? 0);
                }
            }

            $scores[$idx] = $nMetres;
            if ($nMetres > $bestScore) {
                $bestScore = (float) $nMetres;
                $bestIndex = $idx;
            }
        }

        Log::info('Route calc: picked main-highway alternative', [
            'pickup_id' => $pickupId,
            'delivery_id' => $deliveryId,
            'alternatives' => count($routes),
            'picked_index' => $bestIndex,
            'picked_n_road_km' => round($bestScore / 1000, 1),
            'summary' => $routes[$bestIndex]['summary'] ?? null,
            'all_scores_km' => array_map(fn ($m) => round($m / 1000, 1), $scores),
        ]);

        return $routes[$bestIndex];
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
     * - Distance is measured plaza→nearest line SEGMENT, not plaza→
     *   nearest vertex.  On a long straight stretch (e.g. the ~73km
     *   Trichardt→Ermelo leg on the N17) Google's polyline keeps only
     *   a handful of widely-spaced vertices, so a booth sitting between
     *   two of them can be >5km from every *vertex* while being right
     *   on the road (≈0km from the *segment* joining them).  Measuring
     *   to the segment fixes those drops and is robust to sparse or
     *   legacy cached polylines.  It only ever *adds* matches (segment
     *   distance ≤ vertex distance), never removes them.
     */
    public const TOLL_MATCH_RADIUS_KM = 5.0;

    public static function detectTolls(string $polyline, int $tollClass): array
    {
        $points = self::decodePolyline($polyline);
        if (empty($points)) {
            return ['plazas' => [], 'total_cost' => 0];
        }

        // Bounding-box prefilter.  Per-step polylines run 5-10k points;
        // multiplying that by 36+ plazas = hundreds of thousands of
        // haversine calcs per modal recalc, which lands as visible UI
        // latency.  Computing the polyline's bbox once and DB-filtering
        // plazas to the same bbox + buffer drops the candidate set to
        // single digits for most routes -- haversines we actually do
        // run drop by ~80%.
        //
        // 1 degree of latitude ≈ 111 km on Earth; longitude varies but
        // at SA latitudes is ~95 km/deg, so 111 is a safe over-estimate.
        // We use 111 for both axes which makes the bbox slightly bigger
        // than the true 5km radius -- false positives are then filtered
        // out by the precise haversine in the inner loop.
        $minLat = $maxLat = $points[0][0];
        $minLng = $maxLng = $points[0][1];
        foreach ($points as $p) {
            if ($p[0] < $minLat) $minLat = $p[0];
            if ($p[0] > $maxLat) $maxLat = $p[0];
            if ($p[1] < $minLng) $minLng = $p[1];
            if ($p[1] > $maxLng) $maxLng = $p[1];
        }
        $bboxBuf = self::TOLL_MATCH_RADIUS_KM / 111.0;

        $plazas = TollPlaza::active()
            ->whereBetween('latitude', [$minLat - $bboxBuf, $maxLat + $bboxBuf])
            ->whereBetween('longitude', [$minLng - $bboxBuf, $maxLng + $bboxBuf])
            ->get();

        $matched = [];
        $totalCost = 0;
        $pointCount = count($points);

        foreach ($plazas as $plaza) {
            $plat = (float) $plaza->latitude;
            $plng = (float) $plaza->longitude;

            // Single-point polyline can't form a segment -- fall back to
            // a straight point distance so a degenerate route still works.
            if ($pointCount === 1) {
                if (self::pointToSegmentKm($plat, $plng, $points[0][0], $points[0][1], $points[0][0], $points[0][1]) <= self::TOLL_MATCH_RADIUS_KM) {
                    $fee = $plaza->feeForClass($tollClass);
                    $matched[] = ['plaza' => $plaza, 'fee' => $fee];
                    $totalCost += $fee;
                }
                continue;
            }

            for ($i = 1; $i < $pointCount; $i++) {
                $distance = self::pointToSegmentKm(
                    $plat, $plng,
                    $points[$i - 1][0], $points[$i - 1][1],
                    $points[$i][0], $points[$i][1],
                );
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
     * Minimum distance (km) from a plaza to a route polyline, measured to
     * the nearest line *segment* between consecutive points (not just the
     * nearest vertex).  Exposed for the tolls:debug diagnostic so it
     * reports the same number detectTolls() actually matches on.
     */
    public static function distanceToPolylineKm(array $points, float $lat, float $lng): float
    {
        $count = count($points);
        if ($count === 0) {
            return INF;
        }
        if ($count === 1) {
            return self::pointToSegmentKm($lat, $lng, $points[0][0], $points[0][1], $points[0][0], $points[0][1]);
        }

        $min = INF;
        for ($i = 1; $i < $count; $i++) {
            $d = self::pointToSegmentKm($lat, $lng, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
            if ($d < $min) {
                $min = $d;
                if ($min === 0.0) break;
            }
        }
        return $min;
    }

    /**
     * Distance (km) from point P to the line segment A→B.
     *
     * Uses a local equirectangular projection (degrees → km on a plane
     * centred near the points) so we can do plain planar point-to-segment
     * geometry.  Over the few-km spans between adjacent polyline points at
     * SA latitudes the projection error is well under 1% — far tighter
     * than the 5km match window — and it's much cheaper than running
     * haversine at every clamp step.
     */
    private static function pointToSegmentKm(
        float $plat, float $plng,
        float $alat, float $alng,
        float $blat, float $blng,
    ): float {
        $kmPerDegLat = 111.32;
        $kmPerDegLng = 111.32 * cos(deg2rad($plat));

        // Translate so the plaza sits at the origin.
        $ax = ($alng - $plng) * $kmPerDegLng;
        $ay = ($alat - $plat) * $kmPerDegLat;
        $bx = ($blng - $plng) * $kmPerDegLng;
        $by = ($blat - $plat) * $kmPerDegLat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $segLenSq = $dx * $dx + $dy * $dy;

        if ($segLenSq <= 1e-12) {
            // A and B coincide -- segment degenerates to a point.
            return sqrt($ax * $ax + $ay * $ay);
        }

        // Parameter of the origin's projection onto AB, clamped to the
        // segment so we never measure past either endpoint.
        $t = -($ax * $dx + $ay * $dy) / $segLenSq;
        $t = max(0.0, min(1.0, $t));

        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;
        return sqrt($cx * $cx + $cy * $cy);
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
