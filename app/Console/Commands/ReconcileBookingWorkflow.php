<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Job;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nudge bookings that landed in the legacy STATUS_PENDING_VERIFICATION
 * gate but belong to a standard-workflow company (dealers like Demo
 * Motors, most OEMs) onto the Phase 1 chain at STATUS_RECEIVED.
 *
 * BookingService historically pushed every booking into
 * pending_verification regardless of workflow; standard-workflow jobs
 * then sat invisibly waiting for an ops PO-verification step that
 * doesn't apply to them. This command clears that backlog without
 * touching FAW bookings, which legitimately belong in pending_verification.
 *
 *   php artisan bookings:reconcile-workflow              # dry-run
 *   php artisan bookings:reconcile-workflow --apply      # actually migrate
 *   php artisan bookings:reconcile-workflow --apply --company=5
 */
class ReconcileBookingWorkflow extends Command
{
    protected $signature = 'bookings:reconcile-workflow
                            {--apply : Actually apply the status change (default is a dry-run)}
                            {--company= : Only touch bookings for this company id}';

    protected $description = 'Move pending_verification bookings belonging to standard-workflow companies to received';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $companyId = $this->option('company');

        $strictCompanyIds = Company::where('workflow_type', 'faw')->pluck('id')->all();

        $query = Job::query()
            ->where('status', Job::STATUS_PENDING_VERIFICATION)
            ->when(
                !empty($strictCompanyIds),
                fn($q) => $q->whereNotIn('company_id', $strictCompanyIds),
            )
            ->when(
                $companyId,
                fn($q) => $q->where('company_id', $companyId),
            )
            ->with('company:id,name,workflow_type')
            ->orderBy('id');

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No bookings need reconciliation.');
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d booking%s that would move pending_verification → received:',
            $apply ? 'Migrating' : 'Found',
            $count,
            $count === 1 ? '' : 's',
        ));

        $rows = $query->get()->map(fn(Job $j) => [
            $j->id,
            $j->job_number,
            $j->company?->name,
            $j->company?->workflow_type,
            $j->created_at?->format('Y-m-d H:i'),
        ])->all();

        $this->table(['id', 'job_number', 'company', 'workflow_type', 'created_at'], $rows);

        if (!$apply) {
            $this->comment('Dry-run only. Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($query) {
            $query->getQuery()->update([
                'status' => Job::STATUS_RECEIVED,
                'updated_at' => now(),
            ]);
        });

        $this->info("Done. {$count} booking(s) moved to received.");
        return self::SUCCESS;
    }
}
