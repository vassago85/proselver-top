<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobDocument;
use App\Models\PettyCashEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a driver's cash advance from a cancelled trip onto its replacement.
 *
 * Ops does this in the field constantly: driver arrives at the depot with
 * the FAW cash still in hand, the trip is cancelled, dispatch swaps them
 * onto the next available vehicle (an Isuzu, say), the driver goes with
 * the same pile of notes. Historically the only record was a free-text
 * "moved to VIN xxx" note on the source; nothing linked the two orders
 * and the receipts the driver later submitted stayed pinned to the
 * cancelled job where nobody looked for them.
 *
 * The transfer collapses that into one atomic move:
 *   - the breakdown, total, plan link and issued timestamp/actor migrate
 *     to the target vehicle so it looks like the advance was issued
 *     against that trip in the first place;
 *   - any petty-cash slips already logged against the source (fuel bought
 *     between confirmation and cancellation, for example) plus their
 *     receipt photos follow;
 *   - the source's reconciliation query is auto-cleared with a structured
 *     note pointing at the target so the paper trail is intact;
 *   - both sides get audit rows with the counterpart's id + amount.
 *
 * @see database/migrations/2026_08_05_120000_add_advance_transfer_to_transport_jobs.php
 */
class PettyCashTransferService
{
    /**
     * Fields that describe the advance itself and travel with it when a
     * transfer happens. Timestamps and the actor columns are handled
     * separately (see below) because they need discrete decisions -- the
     * issued_at date, for example, must NOT be replaced by "now", since
     * the cash left the till on the original issue date.
     */
    private const ADVANCE_BREAKDOWN_FIELDS = [
        'advance_toll_breakdown',
        'advance_toll_class_override',
        'advance_tolls',
        'advance_accommodation',
        'advance_taxi',
        'advance_taxi_included',
        'advance_food',
        'advance_food_waived',
        'advance_custom_items',
        'advance_total',
        'advance_increase_reason',
        'advance_plan_id',
        'advance_approved_at',
        'advance_override_reason',
    ];

    /**
     * @throws RuntimeException  when either side of the pair is invalid.
     *                           The caller (Livewire component) converts
     *                           the message into a flash error.
     */
    public function transfer(Job $source, Job $target, User $actor, string $note = ''): void
    {
        $this->guard($source, $target);

        // Refresh from the database so DB defaults (e.g. the boolean NOT NULL
        // columns like advance_food_waived) are present on the model. When
        // the caller passes a freshly-created row, the in-memory instance
        // only carries fields the caller supplied, so an unqualified copy
        // would replay NULL over columns that must not be NULL.
        $source = $source->fresh() ?? $source;
        $target = $target->fresh() ?? $target;

        DB::transaction(function () use ($source, $target, $actor, $note) {
            // Snapshot the source so the audit-log "before" is honest.
            $sourceBefore = $source->only([
                'issued_cancellation_cleared_at',
                'issued_cancellation_cleared_by_user_id',
                'issued_cancellation_cleared_note',
                'advance_transferred_to_job_id',
                'advance_transferred_at',
                'advance_transferred_by_user_id',
            ]);
            $targetBefore = $target->only([
                'advance_total',
                'advance_assigned_at',
                'advance_issued_at',
                'advance_issued_by_user_id',
                'advance_transferred_from_job_id',
            ]);

            $amount = (float) ($source->advance_total ?? 0);

            // ── Copy the breakdown onto the target ────────────────────
            $payload = [];
            foreach (self::ADVANCE_BREAKDOWN_FIELDS as $field) {
                $payload[$field] = $source->getAttribute($field);
            }

            // The cash physically left the till on the source's issue
            // date. Preserve those timestamps on the target so trend
            // reports keep the right week / month.
            $payload['advance_assigned_at'] = $source->advance_assigned_at;
            $payload['advance_assigned_by_user_id'] = $source->advance_assigned_by_user_id;
            $payload['advance_issued_at'] = $source->advance_issued_at;
            $payload['advance_issued_by_user_id'] = $source->advance_issued_by_user_id;

            // A machine-readable breadcrumb replaces the old free-text
            // "Transferred from JOB-xxx" note. The reference column is
            // still populated for people scanning the audit trail.
            $payload['advance_issue_reference'] = 'Transferred from ' . $this->label($source);

            // Structured link back to the source.
            $payload['advance_transferred_from_job_id'] = $source->id;

            $target->forceFill($payload)->save();

            // ── Move receipt slips + their documents ──────────────────
            // Ops cares about "show me every receipt for this trip".
            // Leaving them on the cancelled source would mean re-tagging
            // them manually or losing them to the archive.
            $movedEntryIds = PettyCashEntry::where('job_id', $source->id)
                ->pluck('id')
                ->all();

            if (!empty($movedEntryIds)) {
                $documentIds = PettyCashEntry::whereIn('id', $movedEntryIds)
                    ->whereNotNull('document_id')
                    ->pluck('document_id')
                    ->all();

                PettyCashEntry::whereIn('id', $movedEntryIds)
                    ->update(['job_id' => $target->id]);

                if (!empty($documentIds)) {
                    JobDocument::whereIn('id', $documentIds)
                        ->update(['job_id' => $target->id]);
                }
            }

            // ── Stamp + clear the source ──────────────────────────────
            $opsNote = trim($note);
            $clearanceNote = 'Advance of R ' . number_format($amount, 2)
                . ' transferred to ' . $this->label($target)
                . ($target->vehicle_identifier ? ' · VIN ' . $target->vehicle_identifier : '')
                . ($opsNote !== '' ? '. ' . $opsNote : '.');

            $source->forceFill([
                'advance_transferred_to_job_id' => $target->id,
                'advance_transferred_at' => now(),
                'advance_transferred_by_user_id' => $actor->id,
                'issued_cancellation_cleared_at' => now(),
                'issued_cancellation_cleared_by_user_id' => $actor->id,
                'issued_cancellation_cleared_note' => $clearanceNote,
            ])->save();

            // ── Audit both sides ──────────────────────────────────────
            $auditContext = [
                'amount' => $amount,
                'moved_petty_cash_entry_ids' => $movedEntryIds,
                'note' => $opsNote !== '' ? $opsNote : null,
                'clearance_note' => $clearanceNote,
                'actor_roles' => $actor->relationLoaded('roles')
                    ? $actor->roles->pluck('slug')->values()->all()
                    : $actor->roles()->pluck('slug')->values()->all(),
            ];

            AuditService::log(
                'advance_transferred_out',
                'job',
                $source->id,
                $sourceBefore,
                array_merge($auditContext, [
                    'to_job_id' => $target->id,
                    'to_job_number' => $target->job_number,
                    'to_vehicle_identifier' => $target->vehicle_identifier,
                ]),
            );

            AuditService::log(
                'advance_transferred_in',
                'job',
                $target->id,
                $targetBefore,
                array_merge($auditContext, [
                    'from_job_id' => $source->id,
                    'from_job_number' => $source->job_number,
                    'from_vehicle_identifier' => $source->vehicle_identifier,
                ]),
            );
        });
    }

    /**
     * Preconditions with human-readable messages so the Livewire caller
     * can surface them without translating exception codes.
     */
    private function guard(Job $source, Job $target): void
    {
        if ($source->id === $target->id) {
            throw new RuntimeException('The source and target must be different orders.');
        }

        if (!$source->hasOpenIssuedCancellationQuery()) {
            throw new RuntimeException(
                'This trip has no open reconciliation query -- the advance can only '
                . 'be transferred while the source is cancelled and the query is unresolved.'
            );
        }

        if ((float) ($source->advance_total ?? 0) <= 0) {
            throw new RuntimeException('The source trip has no advance amount to transfer.');
        }

        if (!$target->canReceiveTransferredAdvance()) {
            throw new RuntimeException(
                'The chosen replacement cannot receive the advance. It must be a live '
                . 'trip with a driver assigned and no existing advance of its own.'
            );
        }
    }

    /** Best-available label for a job when explaining the transfer. */
    private function label(Job $job): string
    {
        return $job->job_number ?: ('JOB-' . $job->id);
    }
}
