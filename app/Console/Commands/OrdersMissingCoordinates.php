<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Job;
use Illuminate\Console\Command;

/**
 * Read-only diagnostic. Lists active (in-flight) jobs whose pickup or
 * delivery location has no map coordinates, because those orders can't
 * produce a route and therefore get NO toll estimate (see
 * RouteCalculationService::calculate, which bails the moment either
 * endpoint is missing lat/lng).
 *
 * The usual cause: the bulk importer seeded a new location's `address`
 * with the raw business name, which Google can't geocode, so lat/lng
 * stayed null. Fix the underlying address in the address book and run
 * `php artisan locations:geocode` to backfill the coordinates, then the
 * estimate will run on the next calculation.
 *
 * Usage:
 *   php artisan orders:missing-coordinates
 *   php artisan orders:missing-coordinates --company="FAW"
 *   php artisan orders:missing-coordinates --limit=100
 */
class OrdersMissingCoordinates extends Command
{
    protected $signature = 'orders:missing-coordinates
        {--company= : Limit to a company id or (partial) name}
        {--limit=50 : Max rows to print}';

    protected $description = 'List active jobs whose pickup/delivery has no coordinates (so tolls can\'t calculate).';

    /**
     * Anything not in a terminal state is still "in-flight" and expected
     * to route/toll — matches the importer's own live-VIN definition.
     */
    private const TERMINAL_STATUSES = [
        Job::STATUS_DELIVERED,
        Job::STATUS_COMPLETED,
        Job::STATUS_CANCELLED,
    ];

    public function handle(): int
    {
        $companyFilter = trim((string) $this->option('company'));
        $limit = max(1, (int) $this->option('limit'));

        $companyId = null;
        if ($companyFilter !== '') {
            $company = $this->resolveCompany($companyFilter);
            if (!$company) {
                return self::FAILURE;
            }
            $companyId = $company->id;
            $this->info("Scope: {$company->name} (#{$company->id})");
        } else {
            $this->info('Scope: every company');
        }

        $query = Job::query()
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where(function ($q) {
                // A NULL location FK, or a location row with a blank/zero
                // coordinate on either endpoint, both break routing.
                $q->whereNull('pickup_location_id')
                    ->orWhereNull('delivery_location_id')
                    ->orWhereHas('pickupLocation', fn ($l) => $l->whereNull('latitude')->orWhereNull('longitude'))
                    ->orWhereHas('deliveryLocation', fn ($l) => $l->whereNull('latitude')->orWhereNull('longitude'));
            })
            ->with([
                'pickupLocation:id,company_name,latitude,longitude',
                'deliveryLocation:id,company_name,latitude,longitude',
                'company:id,name',
            ])
            ->orderBy('company_id')
            ->orderBy('id');

        $total = (clone $query)->count();

        $this->info('');
        $this->info('Active jobs with no route / tolls');
        $this->info('---------------------------------');
        $this->line("  Total affected: {$total}");

        if ($total === 0) {
            $this->info('');
            $this->info('Done. Every active job has geocoded endpoints.');
            return self::SUCCESS;
        }

        $rows = $query->limit($limit)->get()->map(function (Job $job) {
            return [
                $job->job_number ?? ('JOB-' . $job->id),
                $this->truncate((string) ($job->company?->name ?? '—'), 24),
                $job->status,
                $this->endpointLabel($job->pickupLocation, $job->pickup_location_id),
                $this->endpointLabel($job->deliveryLocation, $job->delivery_location_id),
            ];
        })->all();

        $this->table(['Reference', 'Customer', 'Status', 'Pickup', 'Delivery'], $rows);

        if ($total > $limit) {
            $this->line('  (...and ' . ($total - $limit) . ' more — raise --limit to see them.)');
        }

        $this->info('');
        $this->line('Fix: set a real street address on the flagged locations, then run');
        $this->line('     php artisan locations:geocode');
        $this->info('Done. Read-only — nothing was changed.');

        return self::SUCCESS;
    }

    /**
     * "Name ✗" when the endpoint is missing coordinates, "Name ✓" when it
     * is fine (the other endpoint is the culprit), "(none)" when the FK
     * itself is null.
     */
    private function endpointLabel(mixed $location, ?int $fk): string
    {
        if (!$fk || !$location) {
            return '(none)';
        }
        $ok = !empty($location->latitude) && !empty($location->longitude);
        $name = $this->truncate((string) ($location->company_name ?? "#{$location->id}"), 26);
        return $name . ($ok ? ' ✓' : ' ✗ no coords');
    }

    private function resolveCompany(string $needle): ?Company
    {
        if (ctype_digit($needle)) {
            $c = Company::find((int) $needle);
            if (!$c) {
                $this->error("No company with id {$needle}.");
            }
            return $c;
        }

        $matches = Company::where('name', 'like', '%' . $needle . '%')->orderBy('name')->get();
        if ($matches->isEmpty()) {
            $this->error("No company matching '{$needle}'.");
            return null;
        }
        if ($matches->count() > 1) {
            $this->error("'{$needle}' matches " . $matches->count() . ' companies — be more specific or pass the id:');
            foreach ($matches as $m) {
                $this->line("  #{$m->id}  {$m->name}");
            }
            return null;
        }
        return $matches->first();
    }

    private function truncate(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
