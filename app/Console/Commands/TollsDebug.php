<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\TollPlaza;
use App\Models\VehicleClass;
use App\Services\RouteCalculationService;
use App\Services\TripCostEstimator;
use Illuminate\Console\Command;

/**
 * Per-job toll diagnostic.  Given an order id, prints the route detail
 * and every seeded plaza with its minimum distance to the polyline so
 * we can spot why a plaza missed: too far away (genuine off-route),
 * just over the match radius (bump radius / fix coord), or polyline
 * sparse around it (need finer-grained Google polyline).
 *
 * Usage:
 *   php artisan tolls:debug 1234
 *   docker exec proselver-app php artisan tolls:debug 1234
 */
class TollsDebug extends Command
{
    protected $signature = 'tolls:debug {job : Job ID or job_number (either works)}';
    protected $description = 'Diagnose why specific plazas are or are not matching on a job\'s route';

    public function handle(TripCostEstimator $estimator): int
    {
        $arg = (string) $this->argument('job');

        // Accept either the numeric DB id OR the job_number that shows
        // in the URL / on the order page header.  Numeric is tried as
        // an id first since that's the original signature.
        $job = null;
        if (ctype_digit($arg)) {
            $job = Job::with(['pickupLocation', 'deliveryLocation', 'vehicleClass'])->find((int) $arg);
        }
        if (!$job) {
            $job = Job::with(['pickupLocation', 'deliveryLocation', 'vehicleClass'])
                ->where('job_number', $arg)
                ->first();
        }
        if (!$job) {
            $this->error("Job '{$arg}' not found (tried as id and as job_number).");
            return self::FAILURE;
        }

        $this->info(sprintf(
            "Job #%d — %s → %s",
            $job->id,
            $job->pickupLocation?->company_name ?? '—',
            $job->deliveryLocation?->company_name ?? '—',
        ));
        $this->line(sprintf("  Pickup:   %s, %s", $job->pickupLocation?->latitude ?? '—', $job->pickupLocation?->longitude ?? '—'));
        $this->line(sprintf("  Delivery: %s, %s", $job->deliveryLocation?->latitude ?? '—', $job->deliveryLocation?->longitude ?? '—'));
        $this->newLine();

        $estimate = $estimator->estimateTolls($job);
        if ($estimate['status'] !== 'ok') {
            $this->error("Estimator returned status '{$estimate['status']}': {$estimate['message']}");
            return self::FAILURE;
        }

        // Re-fetch the cached route directly so we have the polyline to
        // measure against -- the estimator only returns the matched
        // plazas list, not the raw polyline.
        $cached = \App\Models\RouteEstimate::query()
            ->where('pickup_location_id', $job->pickup_location_id)
            ->where('delivery_location_id', $job->delivery_location_id)
            ->first();

        if (!$cached) {
            $this->error('Route_estimates cache row not found (estimator should have populated it).');
            return self::FAILURE;
        }

        $points = RouteCalculationService::decodePolyline($cached->polyline);
        $tollClass = $estimate['toll_class'];

        $this->line('Route polyline:');
        $this->line(sprintf("  Distance:      %s km", number_format((float) $cached->distance_km, 1)));
        $this->line(sprintf("  Duration (raw):  %d min", $cached->duration_minutes));
        $this->line(sprintf("  Polyline:      %d points decoded", count($points)));
        $this->line(sprintf("  Toll class:    %d (from %s)",
            $tollClass,
            $job->advance_toll_class_override ? 'per-trip override' : ('vehicle class ' . $job->vehicleClass?->name)
        ));
        $this->line(sprintf("  Match radius:  %.1f km (RouteCalculationService::TOLL_MATCH_RADIUS_KM)", RouteCalculationService::TOLL_MATCH_RADIUS_KM));
        $this->newLine();

        // For every active plaza, compute the minimum distance to any
        // polyline point.  Sorted ascending so the nearest plazas (most
        // likely matches or near-misses) appear first.
        $plazas = TollPlaza::active()->get();
        $rows = [];
        foreach ($plazas as $plaza) {
            $minDist = INF;
            foreach ($points as $point) {
                $d = $this->haversine((float) $point[0], (float) $point[1], (float) $plaza->latitude, (float) $plaza->longitude);
                if ($d < $minDist) $minDist = $d;
            }
            $matches = $minDist <= RouteCalculationService::TOLL_MATCH_RADIUS_KM;
            $rows[] = [
                'plaza' => $plaza->plaza_name,
                'road' => $plaza->road_name,
                'min_km' => round($minDist, 2),
                'match' => $matches ? '✓ HIT ' : '   miss',
                'fee' => $matches ? 'R ' . number_format($plaza->feeForClass($tollClass), 2) : '',
                '_sort' => $minDist,
            ];
        }
        usort($rows, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        $headers = ['Plaza', 'Road', 'Min dist (km)', 'Match?', 'Fee (Class ' . $tollClass . ')'];
        $tableRows = array_map(fn ($r) => [$r['plaza'], $r['road'], $r['min_km'], $r['match'], $r['fee']], $rows);
        $this->table($headers, $tableRows);

        $totalMatched = collect($rows)->filter(fn ($r) => str_contains($r['match'], 'HIT'))->count();
        $totalFee = array_sum(array_map(fn ($r) => str_contains($r['match'], 'HIT') ? (float) str_replace(['R ', ','], '', $r['fee']) : 0, $rows));
        $this->info(sprintf("Result: %d plaza%s matched, total R %s",
            $totalMatched,
            $totalMatched === 1 ? '' : 's',
            number_format($totalFee, 2),
        ));

        $nearMiss = array_filter($rows, fn ($r) => !str_contains($r['match'], 'HIT') && $r['min_km'] < RouteCalculationService::TOLL_MATCH_RADIUS_KM * 2);
        if (!empty($nearMiss)) {
            $this->newLine();
            $this->warn(sprintf(
                'Near-misses (>%.1fkm but <%.1fkm) — coords may be slightly off, or polyline-too-sparse around these plazas:',
                RouteCalculationService::TOLL_MATCH_RADIUS_KM,
                RouteCalculationService::TOLL_MATCH_RADIUS_KM * 2,
            ));
            foreach ($nearMiss as $r) {
                $this->line(sprintf("  · %-25s %s — closest point %.2f km", $r['plaza'], $r['road'], $r['min_km']));
            }
        }

        return self::SUCCESS;
    }

    /** Same haversine as RouteCalculationService -- duplicated here to keep this command self-contained. */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
