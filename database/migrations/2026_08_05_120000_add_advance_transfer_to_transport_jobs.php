<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty-cash / advance TRANSFER between vehicles.
 *
 * When a trip is cancelled after the cash has already left the till, the
 * ops-desk shortcut in the field is to hand the same cash to the driver
 * of the replacement vehicle rather than have the driver bank it, ops
 * bank-send a fresh advance, and produce two paper trails for one physical
 * pile of notes. Historically that was reconciled by typing the target VIN
 * into a free-text explanation on the source order -- searchable by no-one.
 *
 * These four columns give the transfer a proper structured link:
 *
 *   - advance_transferred_to_job_id    on the CANCELLED source: which
 *                                      trip absorbed this advance.
 *   - advance_transferred_from_job_id  on the RECEIVING replacement:
 *                                      where the money originally came
 *                                      from. Used by the money-report
 *                                      scopes so a transferred-in advance
 *                                      is not double-counted alongside
 *                                      the original.
 *   - advance_transferred_at + _by     when and who executed the transfer;
 *                                      distinct from the assignment /
 *                                      issue / removal actors so the
 *                                      audit trail stays honest.
 *
 * The transfer itself is done by App\Services\PettyCashTransferService,
 * which copies the breakdown, moves the receipt slips, and clears the
 * source's reconciliation query in one transaction.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            // Self-referencing. nullOnDelete keeps the counterpart row
            // usable if the other side of the pair is hard-deleted --
            // the audit log still carries the paper trail.
            $table->foreignId('advance_transferred_to_job_id')
                ->nullable()
                ->after('issued_cancellation_cleared_note')
                ->constrained('transport_jobs')
                ->nullOnDelete();

            $table->foreignId('advance_transferred_from_job_id')
                ->nullable()
                ->after('advance_transferred_to_job_id')
                ->constrained('transport_jobs')
                ->nullOnDelete();

            $table->timestampTz('advance_transferred_at')
                ->nullable()
                ->after('advance_transferred_from_job_id');

            $table->foreignId('advance_transferred_by_user_id')
                ->nullable()
                ->after('advance_transferred_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_transferred_by_user_id');
            $table->dropConstrainedForeignId('advance_transferred_from_job_id');
            $table->dropConstrainedForeignId('advance_transferred_to_job_id');
            $table->dropColumn('advance_transferred_at');
        });
    }
};
