<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Location;
use App\Models\VehicleClass;
use Illuminate\Console\Command;

/**
 * Cross-system audit of why route-based toll detection fails (or
 * succeeds) on jobs.  No DB writes, no API calls -- this is a pure
 * scan that surfaces the *reason* each job is blocked, so the owner
 * sees the pattern across the whole live system instead of clicking
 * orders one at a time.
 *
 * Failure buckets, in priority order:
 *   1. Pickup or delivery location is missing entirely (data error).
 *   2. Pickup has no lat/lng (needs locations:geocode or address fix).
 *   3. Delivery has no lat/lng.
 *   4. Vehicle class has no toll_class configured.
 *   5. Has a per-trip toll class override that's out of band.
 *   6. Everything looks fine -- route should compute (or has computed).
 *
 * Usage:
 *   php artisan tolls:audit
 *   php artisan tolls:audit --status=planned,confirmed,in_transit
 *   php artisan tolls:audit --limit-locations=20   # show top N affected locations
 */
class TollsAudit extends Command
{
    protected $signature = 'tolls:audit
        {--status= : Comma-separated job statuses to scan. Defaults to all active (non-terminal).}
        {--limit-locations=10 : Top N affected locations to print per bucket}';

    protected $description = 'Survey every active job and report why route/toll detection succeeds or fails';

    /** Statuses considered "active" -- the ops-actionable set. */
    private const DEFAULT_ACTIVE = [
        Job::STATUS_CONFIRMED,
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_IN_PROGRESS,
    ];

    public function handle(): int
    {
        $statuses = $this->option('status')
            ? array_map('trim', explode(',', $this->option('status')))
            : self::DEFAULT_ACTIVE;

        $limitLocations = max(1, (int) $this->option('limit-locations'));

        $jobs = Job::query()
            ->whereIn('status', $statuses)
            ->with(['pickupLocation:id,company_name,latitude,longitude', 'deliveryLocation:id,company_name,latitude,longitude', 'vehicleClass:id,name,toll_class'])
            ->get([
                'id', 'job_number', 'status', 'executor_type',
                'pickup_location_id', 'delivery_location_id', 'vehicle_class_id',
                'advance_toll_class_override',
            ]);

        $total = $jobs->count();
        if ($total === 0) {
            $this->info('No active jobs to audit.');
            return self::SUCCESS;
        }

        $buckets = [
            'pickup_missing'        => collect(),
            'delivery_missing'      => collect(),
            'pickup_no_coords'      => collect(),
            'delivery_no_coords'    => collect(),
            'no_toll_class'         => collect(),
            'ok'                    => collect(),
        ];

        $locationCounter = [
            'pickup_no_coords' => [],
            'delivery_no_coords' => [],
        ];

        foreach ($jobs as $job) {
            $p = $job->pickupLocation;
            $d = $job->deliveryLocation;

            if (!$p) {
                $buckets['pickup_missing']->push($job);
                continue;
            }
            if (!$d) {
                $buckets['delivery_missing']->push($job);
                continue;
            }
            if (!$p->latitude || !$p->longitude) {
                $buckets['pickup_no_coords']->push($job);
                $locationCounter['pickup_no_coords'][$p->id] = ($locationCounter['pickup_no_coords'][$p->id] ?? ['name' => $p->company_name, 'count' => 0]);
                $locationCounter['pickup_no_coords'][$p->id]['count']++;
                continue;
            }
            if (!$d->latitude || !$d->longitude) {
                $buckets['delivery_no_coords']->push($job);
                $locationCounter['delivery_no_coords'][$d->id] = ($locationCounter['delivery_no_coords'][$d->id] ?? ['name' => $d->company_name, 'count' => 0]);
                $locationCounter['delivery_no_coords'][$d->id]['count']++;
                continue;
            }

            // Toll class: override wins, else vehicle class default.
            $tollClass = $job->advance_toll_class_override
                ?: $job->vehicleClass?->toll_class;
            if (!$tollClass) {
                $buckets['no_toll_class']->push($job);
                continue;
            }

            $buckets['ok']->push($job);
        }

        // -- Summary --
        $this->newLine();
        $this->info(str_repeat('=', 60));
        $this->info(' Route / toll audit — ' . $total . ' active jobs');
        $this->info(str_repeat('=', 60));

        $okPct = $total > 0 ? round(($buckets['ok']->count() / $total) * 100, 1) : 0;
        $this->line(sprintf("  ✓ Should compute (coords + class OK):  %4d  (%s%%)", $buckets['ok']->count(), $okPct));

        if ($buckets['pickup_no_coords']->count() > 0) {
            $this->line('');
            $this->warn(sprintf("  ✗ Blocked — pickup has no coords:      %4d  (run `locations:geocode` to fix)", $buckets['pickup_no_coords']->count()));
        }
        if ($buckets['delivery_no_coords']->count() > 0) {
            $this->warn(sprintf("  ✗ Blocked — delivery has no coords:    %4d  (run `locations:geocode` to fix)", $buckets['delivery_no_coords']->count()));
        }
        if ($buckets['no_toll_class']->count() > 0) {
            $this->warn(sprintf("  ✗ Blocked — vehicle class has no toll_class: %4d  (fix in Settings → Vehicle Classes)", $buckets['no_toll_class']->count()));
        }
        if ($buckets['pickup_missing']->count() > 0) {
            $this->error(sprintf("  ✗ DATA ERROR — pickup location null:   %4d  (manual fix on each order)", $buckets['pickup_missing']->count()));
        }
        if ($buckets['delivery_missing']->count() > 0) {
            $this->error(sprintf("  ✗ DATA ERROR — delivery location null: %4d  (manual fix on each order)", $buckets['delivery_missing']->count()));
        }

        // -- Top offending locations --
        foreach (['pickup_no_coords', 'delivery_no_coords'] as $key) {
            if (empty($locationCounter[$key])) continue;
            $this->newLine();
            $kind = $key === 'pickup_no_coords' ? 'PICKUPS' : 'DELIVERIES';
            $this->line("Top $kind without coords (orders affected):");
            $rows = collect($locationCounter[$key])
                ->sortByDesc('count')
                ->take($limitLocations)
                ->values();
            foreach ($rows as $r) {
                $this->line(sprintf("  · %4d jobs  →  %s", $r['count'], $r['name']));
            }
            if (count($locationCounter[$key]) > $limitLocations) {
                $this->line(sprintf("  ... and %d more locations (use --limit-locations=N to see more)", count($locationCounter[$key]) - $limitLocations));
            }
        }

        // -- Vehicle classes missing toll_class --
        if ($buckets['no_toll_class']->count() > 0) {
            $this->newLine();
            $missingClasses = VehicleClass::whereNull('toll_class')->get();
            $this->line('Vehicle classes with no toll_class set:');
            foreach ($missingClasses as $vc) {
                $this->line(sprintf("  · %s (%d jobs affected)", $vc->name, $buckets['no_toll_class']->where('vehicle_class_id', $vc->id)->count()));
            }
        }

        // -- Next-step hint --
        $this->newLine();
        $nextSteps = [];
        if ($buckets['pickup_no_coords']->count() + $buckets['delivery_no_coords']->count() > 0) {
            $nextSteps[] = "  1. `php artisan locations:geocode --dry-run`  then  `php artisan locations:geocode`";
        }
        if ($buckets['no_toll_class']->count() > 0) {
            $nextSteps[] = "  2. Go to Settings → Vehicle Classes, set toll_class (1-4) on each row";
        }
        if (empty($nextSteps)) {
            $this->info('Everything that can be calculated, can be calculated. If a specific order still shows "no toll plazas detected", use `php artisan tolls:debug {job_id}` to see per-plaza distance.');
        } else {
            $this->info('Next steps to unblock route calculation:');
            foreach ($nextSteps as $s) $this->line($s);
        }

        return self::SUCCESS;
    }
}
