<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\PettyCashPlan;
use App\Models\RouteEstimate;
use App\Services\TripCostEstimator;
use Illuminate\Console\Command;

/**
 * Full forensic dump of one order: basic facts, locations + coord
 * health, driver + cellphone, vehicle class + toll class chosen,
 * cached route, computed toll plazas, advance state across the full
 * lifecycle (saved / on plan / approved / issued / removal pending),
 * petty-cash slips against it, and the recent audit log.
 *
 * One paste-back tells you everything you'd otherwise have to chase
 * across half a dozen artisan / tinker commands.
 *
 * Usage:
 *   php artisan order:audit 26050297
 *   php artisan order:audit 318          # by DB id
 */
class OrderAudit extends Command
{
    protected $signature = 'order:audit {job : Job number (e.g. 26050297) or DB id}';
    protected $description = 'Full audit dump of one order — facts, coords, route, advance state, slips, audit log';

    public function handle(TripCostEstimator $estimator): int
    {
        $arg = (string) $this->argument('job');
        $job = ctype_digit($arg) ? Job::find((int) $arg) : null;
        $job = $job ?? Job::where('job_number', $arg)->first();
        if (!$job) {
            $this->error("Job '{$arg}' not found.");
            return self::FAILURE;
        }

        $job->load([
            'company:id,name',
            'pickupLocation', 'deliveryLocation',
            'driver:id,name,phone',
            'driver.driverProfile:user_id,cellphone',
            'vehicleClass:id,name,toll_class',
            'advancePlan',
            'advanceAssignedBy:id,name',
            'advanceIssuedBy:id,name',
            'advanceRemovalRequestedBy:id,name',
        ]);

        $this->section('Order');
        $this->kv('Job number', $job->job_number);
        $this->kv('DB id', $job->id);
        $this->kv('Status', $job->status);
        $this->kv('Executor', $job->executor_type);
        $this->kv('Company', $job->company?->name ?? '—');
        $this->kv('VIN', $job->vin ?: '—');
        $this->kv('Make/model', trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—');
        $this->kv('Vehicle class', $job->vehicleClass?->name ?? '—');
        $this->kv('Toll class (vehicle default)', $job->vehicleClass?->toll_class ?? '—');
        $this->kv('Toll class override', $job->advance_toll_class_override ?? '—');
        $this->kv('Scheduled', $job->scheduled_date?->format('D d M Y') ?? '—');
        $this->kv('Created', $job->created_at?->format('D d M Y H:i'));

        $this->section('Pickup');
        $this->location($job->pickupLocation);
        $this->section('Delivery');
        $this->location($job->deliveryLocation);

        $this->section('Driver');
        if ($job->driver) {
            $this->kv('Name', $job->driver->name);
            $phone = $job->driver->phone ?: ($job->driver->driverProfile?->cellphone);
            $this->kv('Phone (bank-send key)', $phone ?: '— MISSING');
        } else {
            $this->line('  No driver assigned.');
        }

        $this->section('Route cache');
        $cache = RouteEstimate::query()
            ->where('pickup_location_id', $job->pickup_location_id)
            ->where('delivery_location_id', $job->delivery_location_id)
            ->first();
        if (!$cache) {
            $this->line('  No cached route. The estimator will fetch on next modal open.');
        } else {
            $this->kv('Distance', round((float) $cache->distance_km, 1) . ' km');
            $this->kv('Duration (raw)', $cache->duration_minutes . ' min');
            $this->kv('Polyline length', strlen((string) $cache->polyline) . ' chars (' . count(\App\Services\RouteCalculationService::decodePolyline($cache->polyline)) . ' points)');
            $this->kv('Calculated at', $cache->calculated_at?->format('D d M Y H:i'));
        }

        $this->section('Toll estimate (live)');
        $est = $estimator->estimateTolls($job, $job->advance_toll_class_override);
        $this->kv('Status', $est['status']);
        if ($est['status'] !== 'ok') {
            $this->warn('  ' . $est['message']);
        } else {
            $this->kv('Toll class used', $est['toll_class']);
            $this->kv('Toll total (live)', 'R ' . number_format((float) $est['toll_total'], 2));
            $this->kv('Suggested food', 'R ' . number_format((float) $est['suggested_food'], 2));
            $this->kv('Suggested taxi', 'R ' . number_format((float) $est['suggested_taxi'], 2));
            $this->line('  Plazas matched:');
            foreach ($est['plazas'] ?? [] as $p) {
                $this->line(sprintf('    · %-25s %s R %s', $p['plaza_name'], substr($p['road_name'], 0, 30), number_format((float) $p['fee'], 2)));
            }
        }

        $this->section('Advance — saved on the job');
        if ($job->advance_total === null) {
            $this->line('  No advance saved yet.');
        } else {
            $this->kv('Total saved', 'R ' . number_format((float) $job->advance_total, 2));
            $this->kv('  Tolls',         'R ' . number_format((float) $job->advance_tolls, 2));
            $this->kv('  Accommodation', 'R ' . number_format((float) $job->advance_accommodation, 2));
            $this->kv('  Taxi',          'R ' . number_format((float) $job->advance_taxi, 2) . ($job->advance_taxi_included ? ' (ticked)' : ' (shuttle / off)'));
            $this->kv('  Food',          'R ' . number_format((float) $job->advance_food, 2) . ($job->advance_food_waived ? ' (waived)' : ''));
            $custom = is_array($job->advance_custom_items) ? $job->advance_custom_items : [];
            if (!empty($custom)) {
                $this->line('  Custom items:');
                foreach ($custom as $ci) {
                    $this->line(sprintf('    · %-30s R %s%s', $ci['label'] ?? '', number_format((float) ($ci['amount'] ?? 0), 2), !empty($ci['needs_slip']) ? ' [slip]' : ''));
                }
            }
            $this->kv('Saved by',  $job->advanceAssignedBy?->name . ' at ' . $job->advance_assigned_at?->format('d M H:i'));
            $this->kv('Increase reason', $job->advance_increase_reason ?: '—');
            $this->kv('Override reason', $job->advance_override_reason ?: '—');
        }

        $this->section('Advance — plan / approval / issued / removal');
        if ($job->advance_plan_id && $job->advancePlan) {
            $p = $job->advancePlan;
            $this->kv('On plan', '#' . $p->id . ' "' . $p->label . '" (' . $p->status . ')');
        } else {
            $this->kv('On plan', '— not currently on any plan');
        }
        $this->kv('Approved at', $job->advance_approved_at?->format('d M Y H:i') ?: '— not approved');
        $this->kv('Issued at',   $job->advance_issued_at?->format('d M Y H:i') ?: '— not issued');
        $this->kv('Issued by',   $job->advanceIssuedBy?->name ?: '—');
        $this->kv('Issue ref',   $job->advance_issue_reference ?: '—');
        if ($job->advance_removal_pending) {
            $this->warn('  REMOVAL REQUEST PENDING');
            $this->kv('  Requested by', $job->advanceRemovalRequestedBy?->name);
            $this->kv('  Requested at', $job->advance_removal_requested_at?->format('d M Y H:i'));
            $this->kv('  Reason', $job->advance_removal_reason);
        }

        $this->section('Petty cash slips against this job');
        $slips = PettyCashEntry::where('job_id', $job->id)
            ->with('driver:id,name')
            ->orderBy('created_at')
            ->get();
        if ($slips->isEmpty()) {
            $this->line('  No slips submitted yet.');
        } else {
            $totals = ['approved' => 0, 'submitted' => 0, 'reimbursed' => 0, 'rejected' => 0];
            foreach ($slips as $s) {
                $totals[$s->status] = ($totals[$s->status] ?? 0) + $s->amount_cents;
                $this->line(sprintf(
                    '  · %-12s R %-8s %-15s %s',
                    $s->status,
                    number_format($s->amount_cents / 100, 2),
                    $s->categoryLabel(),
                    $s->merchant_name ?: ''
                ));
            }
            $this->newLine();
            foreach ($totals as $k => $cents) {
                if ($cents > 0) $this->kv('  ' . ucfirst($k), 'R ' . number_format($cents / 100, 2));
            }
        }

        $this->section('Audit log (most recent 15)');
        $logs = AuditLog::query()
            ->where('entity_type', 'job')
            ->where('entity_id', $job->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();
        if ($logs->isEmpty()) {
            $this->line('  No audit entries for this job.');
        } else {
            foreach ($logs as $l) {
                $this->line(sprintf(
                    '  %s · %-28s · %s%s',
                    $l->created_at?->format('d M H:i'),
                    $l->action_type,
                    $l->actor?->name ?? 'system',
                    $l->reason ? ' — ' . $l->reason : '',
                ));
            }
        }

        $this->newLine();
        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info('── ' . $title . ' ' . str_repeat('─', max(0, 60 - strlen($title))));
    }

    private function kv(string $key, ?string $value): void
    {
        $this->line(sprintf('  %-30s %s', $key . ':', $value ?? '—'));
    }

    private function location($location): void
    {
        if (!$location) {
            $this->line('  Location is null!');
            return;
        }
        $this->kv('Name', $location->company_name);
        $this->kv('Address', $location->address ?: '—');
        $this->kv('City / Province', trim(implode(', ', array_filter([$location->city, $location->province]))) ?: '—');
        $coords = ($location->latitude && $location->longitude)
            ? sprintf('%.6f, %.6f', $location->latitude, $location->longitude)
            : 'MISSING -- route can\'t calculate';
        $this->kv('Coords', $coords);
    }
}
