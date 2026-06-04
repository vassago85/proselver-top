<?php

namespace App\Services;

use App\Models\Job;
use App\Models\RouteEstimate;
use App\Models\RouteTollPlazaHint;
use App\Models\SystemSetting;
use App\Models\TollPlaza;
use App\Models\VehicleClass;
use Illuminate\Support\Carbon;

/**
 * Trip-cost / driver-advance estimator.
 *
 *  1. Looks up (or calculates and caches) the route polyline for the
 *     pickup→delivery pair via RouteCalculationService.  Subsequent
 *     orders on the same route reuse the cached polyline so we don't
 *     re-bill Google Maps.
 *  2. Walks the polyline against `toll_plazas` and returns the matched
 *     plaza list with each plaza's fee for the job's vehicle toll class.
 *  3. The accommodation / taxi / food numbers are ops-typed in v1 — the
 *     estimator only handles tolls.  The caller assembles the final
 *     advance breakdown.
 *
 * Returns a structured breakdown rather than mutating the job, so the
 * UI can show the result before ops commits.  Persisting the advance
 * onto the job is the caller's responsibility (see admin.orders.show).
 */
class TripCostEstimator
{
    /**
     * Compute the toll breakdown for a job.  Returns a structured array;
     * does NOT mutate the job.
     *
     * Shape:
     *  [
     *    'status' => 'ok' | 'no_route' | 'no_coordinates' | 'missing_coords' | 'no_api_key' | 'no_toll_class',
     *    'message' => string|null,                  // human-readable explanation when status !== 'ok'
     *    'missing_pickup_coords' => bool,           // true when status is 'missing_coords' and pickup is the culprit
     *    'missing_delivery_coords' => bool,         // true when status is 'missing_coords' and delivery is the culprit
     *    'pickup_location_id' => ?int,              // surfaced so the UI can deep-link to the address book
     *    'delivery_location_id' => ?int,
     *    'cached' => bool,                          // true when we hit the route_estimates cache
     *    'distance_km' => float|null,
     *    'duration_minutes' => int|null,
     *    'toll_class' => int|null,
     *    'plazas' => [                              // ordered roughly by route position
     *      [
     *        'plaza_name' => string,
     *        'road_name' => string,
     *        'plaza_type' => string,
     *        'fee' => float,                        // rand
     *      ],
     *    ],
     *    'toll_total' => float,                     // rand
     *    'days_count' => int,                       // 0 = under minimum, 1 = single-day, 2 = duration ≥ threshold
     *    'suggested_food' => float,                 // rand. days_count × food_rate_per_day
     *    'food_rate_per_day' => float,              // rand. Configured daily rate
     *    'food_minimum_hours' => int,               // hours below which food = R0
     *    'food_threshold_hours' => int,             // hours at or above which food doubles
     *    'suggested_taxi' => float,                 // rand. Standard per-trip taxi allowance
     *  ]
     */
    /**
     * Compute the toll breakdown.  $overrideTollClass wins over any
     * per-job column.  Passing it explicitly is the robust path
     * because Livewire dehydrates Eloquent models and reloads them
     * from the DB on the next request -- any in-memory mutation we
     * make to $job->advance_toll_class_override is lost across the
     * boundary.  Always pass the actor's intended class here.
     */
    public function estimateTolls(Job $job, ?int $overrideTollClass = null): array
    {
        $pickup = $job->pickupLocation;
        $delivery = $job->deliveryLocation;

        // Food allowance config — pulled up front so single-line returns
        // through emptyResult() still carry the configured rate / threshold
        // back to the UI for display.  Owner sets these in system_settings.
        $foodRate = (float) SystemSetting::get('food_allowance_per_day', 150);
        $foodMinHours = (int) SystemSetting::get('food_minimum_hours', 4);
        $foodThresholdHours = (int) SystemSetting::get('food_two_day_threshold_hours', 9);
        $taxiRate = (float) SystemSetting::get('taxi_allowance_per_trip', 50);

        // Cheap guard before either DB or API work.
        if (!$pickup || !$delivery) {
            return $this->emptyResult('no_coordinates', 'Pickup or delivery location is not set on this order.', $foodRate, $foodMinHours, $foodThresholdHours, $taxiRate);
        }

        // Specific check: address looks fine but lat/lng never got
        // populated.  Most common cause of "couldn't calculate a route"
        // -- bulk-imported locations whose `address` was the dealer
        // name at first, so geocoding silently failed, and the saving
        // hook only retries when BOTH coords are null.  Surface the
        // specific location so ops can fix it from the address book.
        $missingPickup = !$pickup->latitude || !$pickup->longitude;
        $missingDelivery = !$delivery->latitude || !$delivery->longitude;
        if ($missingPickup || $missingDelivery) {
            $which = [];
            if ($missingPickup) $which[] = "pickup '" . ($pickup->company_name ?: '#' . $pickup->id) . "'";
            if ($missingDelivery) $which[] = "delivery '" . ($delivery->company_name ?: '#' . $delivery->id) . "'";
            $msg = ucfirst(implode(' and ', $which)) . ' ' . (count($which) === 1 ? 'has' : 'have') . ' no coordinates yet. Run `php artisan locations:geocode` to backfill, or edit the address in Settings → Locations.';

            $result = $this->emptyResult('missing_coords', $msg, $foodRate, $foodMinHours, $foodThresholdHours, $taxiRate);
            $result['missing_pickup_coords'] = $missingPickup;
            $result['missing_delivery_coords'] = $missingDelivery;
            $result['pickup_location_id'] = $pickup->id;
            $result['delivery_location_id'] = $delivery->id;
            return $result;
        }

        // The vehicle class drives which fee column we read from
        // toll_plazas (class_1_fee..class_4_fee).  Without a class we
        // can't read a fee; fail fast so ops sees a clear message
        // rather than a list of plazas at R 0.00.
        $vehicleClass = $job->vehicleClass ?? ($job->vehicle_class_id ? VehicleClass::find($job->vehicle_class_id) : null);

        // Per-trip override wins.  Three sources, in priority order:
        //  1. $overrideTollClass parameter (live UI state, survives the
        //     Livewire request boundary)
        //  2. $job->advance_toll_class_override column (last issued)
        //  3. $vehicleClass->toll_class (system default for the class)
        // Override is clamped to the SANRAL bands 1-4.
        $param = ($overrideTollClass && $overrideTollClass >= 1 && $overrideTollClass <= 4) ? (int) $overrideTollClass : null;
        $stored = $job->advance_toll_class_override;
        $tollClass = $param
            ?? (($stored && $stored >= 1 && $stored <= 4) ? (int) $stored : null)
            ?? $vehicleClass?->toll_class;

        if (!$tollClass) {
            return $this->emptyResult(
                'no_toll_class',
                $vehicleClass
                    ? "Vehicle class '{$vehicleClass->name}' has no toll class configured (see Settings → Vehicle Classes), and no per-trip override is set."
                    : 'No vehicle class is set on this order.',
                $foodRate,
                $foodMinHours,
                $foodThresholdHours,
                $taxiRate,
            );
        }

        // Try the cache first.  The pair-unique constraint guarantees
        // at most one row, so firstWhere is safe.
        $cached = RouteEstimate::query()
            ->where('pickup_location_id', $pickup->id)
            ->where('delivery_location_id', $delivery->id)
            ->first();

        $route = $cached
            ? [
                'distance_km' => (float) $cached->distance_km,
                'duration_minutes' => (int) $cached->duration_minutes,
                'polyline' => $cached->polyline,
            ]
            : RouteCalculationService::calculate($pickup, $delivery);

        if (!$route || empty($route['polyline'])) {
            // The underlying service returns null when either the API
            // key is missing or Google returns no route.  We can't
            // distinguish here without re-querying, so the message is
            // soft.  Ops can still type accommodation/taxi/food.
            return $this->emptyResult('no_route', 'Could not calculate a route between pickup and delivery. Tolls will need to be entered manually.', $foodRate, $foodMinHours, $foodThresholdHours, $taxiRate);
        }

        // Cache miss → persist for next time. Upsert by the unique pair
        // so a race where two ops open the same order simultaneously
        // doesn't blow up on the unique index.
        if (!$cached) {
            RouteEstimate::updateOrCreate(
                [
                    'pickup_location_id' => $pickup->id,
                    'delivery_location_id' => $delivery->id,
                ],
                [
                    'distance_km' => $route['distance_km'],
                    'duration_minutes' => $route['duration_minutes'],
                    'polyline' => $route['polyline'],
                    'provider' => 'google_maps',
                    'calculated_at' => Carbon::now(),
                ],
            );
        }

        // Tolls are always recomputed from the *current* toll_plazas
        // rows (not cached), so a fee revision lands immediately on the
        // next estimate without manual cache invalidation.
        $detected = RouteCalculationService::detectTolls($route['polyline'], (int) $tollClass);

        // Merge in any plazas ops has manually attached to this exact
        // lane (pickup, delivery).  These are gates whose booth sits too
        // far from Google's chosen polyline to auto-match -- typically
        // because Google only offers a bypass alternative for the lane.
        // De-dupe by toll_plaza_id so the same plaza never appears twice
        // if a later route recalc happens to start including it.
        $detectedIds = collect($detected['plazas'] ?? [])
            ->pluck('toll_plaza_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $rememberedIds = array_values(array_diff(
            RouteTollPlazaHint::plazaIdsForRoute($pickup->id, $delivery->id),
            $detectedIds,
        ));

        $rememberedEntries = [];
        $rememberedTotal = 0.0;
        if (!empty($rememberedIds)) {
            $rememberedPlazas = TollPlaza::active()
                ->whereIn('id', $rememberedIds)
                ->get();
            foreach ($rememberedPlazas as $plaza) {
                $fee = (float) $plaza->feeForClass((int) $tollClass);
                $rememberedEntries[] = [
                    'plaza' => $plaza,
                    'fee' => $fee,
                    'toll_plaza_id' => (int) $plaza->id,
                    'source' => 'remembered',
                ];
                $rememberedTotal += $fee;
            }
        }

        $mergedEntries = array_merge($detected['plazas'] ?? [], $rememberedEntries);

        $plazas = collect($mergedEntries)
            ->map(fn ($entry) => [
                'plaza_name' => $entry['plaza']->plaza_name,
                'road_name' => $entry['plaza']->road_name,
                'plaza_type' => $entry['plaza']->plaza_type,
                'fee' => (float) $entry['fee'],
                'toll_plaza_id' => (int) ($entry['toll_plaza_id'] ?? $entry['plaza']->id),
                'source' => (string) ($entry['source'] ?? 'auto'),
            ])
            ->values()
            ->all();

        // Truck-speed adjustment: Google's duration is for car traffic
        // (max ~120 km/h on freeway), but SA legal limits are MCV 100,
        // HCV/EHCV 80.  We multiply the raw Google figure by the
        // configured per-class factor so the food band lands on the
        // right side of the 4h/9h thresholds for the *actual* truck
        // taking the trip.  Multiplier defaults are 1.00/1.10/1.25/1.30
        // for classes 1-4; owner-tunable in system_settings.
        $rawDurationMinutes = (int) $route['duration_minutes'];
        $durationMultiplier = (float) SystemSetting::get(
            'route_duration_multiplier_class_' . $tollClass,
            $tollClass >= 3 ? 1.25 : ($tollClass == 2 ? 1.10 : 1.00),
        );
        $durationMinutes = (int) round($rawDurationMinutes * $durationMultiplier);

        // Food-allowance ladder (owner-stated, 2026-05-26):
        //   - Under food_minimum_hours        → 0 days → R 0
        //   - food_minimum_hours .. threshold → 1 day  → rate
        //   - At or above two-day threshold   → 2 days → 2 × rate
        // Ops can also waive food per trip via the modal -- handled by
        // the caller (job->advance_food_waived).  All thresholds and
        // the rate live in system_settings.
        $minimumMinutes = $foodMinHours * 60;
        $thresholdMinutes = $foodThresholdHours * 60;

        if ($durationMinutes < $minimumMinutes) {
            $daysCount = 0;
        } elseif ($durationMinutes < $thresholdMinutes) {
            $daysCount = 1;
        } else {
            $daysCount = 2;
        }
        $suggestedFood = round($daysCount * $foodRate, 2);

        return [
            'status' => 'ok',
            'message' => null,
            'cached' => (bool) $cached,
            'distance_km' => (float) $route['distance_km'],
            'duration_minutes' => $durationMinutes,
            'toll_class' => (int) $tollClass,
            'plazas' => $plazas,
            'toll_total' => round((float) ($detected['total_cost'] ?? 0) + $rememberedTotal, 2),
            'days_count' => $daysCount,
            'suggested_food' => $suggestedFood,
            'food_rate_per_day' => $foodRate,
            'food_minimum_hours' => $foodMinHours,
            'food_threshold_hours' => $foodThresholdHours,
            'suggested_taxi' => round($taxiRate, 2),
        ];
    }

    /**
     * Force a re-fetch of the route by deleting the cached row.  Called
     * from the UI's "Recalculate route" button — handy when ops knows
     * the polyline is stale (road work, plaza added) and wants a fresh
     * pull from Google.
     */
    public function invalidateRoute(Job $job): void
    {
        if (!$job->pickup_location_id || !$job->delivery_location_id) {
            return;
        }
        RouteEstimate::query()
            ->where('pickup_location_id', $job->pickup_location_id)
            ->where('delivery_location_id', $job->delivery_location_id)
            ->delete();
    }

    private function emptyResult(
        string $status,
        string $message,
        float $foodRate = 150.0,
        int $foodMinHours = 4,
        int $foodThresholdHours = 9,
        float $taxiRate = 50.0,
    ): array {
        // Without a route we can't decide which food band applies, so
        // we don't preset food; ops can type a number once they know
        // the actual trip duration.  Taxi still defaults to its
        // configured flat amount because that allowance is
        // duration-independent.
        return [
            'status' => $status,
            'message' => $message,
            'cached' => false,
            'distance_km' => null,
            'duration_minutes' => null,
            'toll_class' => null,
            'plazas' => [],
            'toll_total' => 0.0,
            'days_count' => 0,
            'suggested_food' => 0.0,
            'food_rate_per_day' => $foodRate,
            'food_minimum_hours' => $foodMinHours,
            'food_threshold_hours' => $foodThresholdHours,
            'suggested_taxi' => round($taxiRate, 2),
        ];
    }
}
