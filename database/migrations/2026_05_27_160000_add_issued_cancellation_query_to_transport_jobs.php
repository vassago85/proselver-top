<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Issued + later cancelled" reconciliation query.
 *
 * When a trip is cancelled after its advance has already been issued
 * (cash physically handed to the driver / EFT released), the money is
 * out of the till but the trip is dead. That mismatch needs to be
 * resolved — driver returned the cash, it was rolled into a swap trip,
 * it's being recovered from the driver's next slip, etc. — and the
 * resolution needs an audit trail.
 *
 * The query is *derived* (status=cancelled + advance_issued_at not null
 * + cleared_at is null), so no extra flag column is needed at cancel
 * time. We only persist the *clearance*:
 *
 *   - issued_cancellation_cleared_at        — when Accounts/Owner signed it off
 *   - issued_cancellation_cleared_by_user_id — who signed it off
 *   - issued_cancellation_cleared_note      — the mandatory explanation
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestampTz('issued_cancellation_cleared_at')->nullable()->after('advance_removal_reason');
            $table->foreignId('issued_cancellation_cleared_by_user_id')
                ->nullable()
                ->after('issued_cancellation_cleared_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('issued_cancellation_cleared_note')->nullable()->after('issued_cancellation_cleared_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_cancellation_cleared_by_user_id');
            $table->dropColumn([
                'issued_cancellation_cleared_at',
                'issued_cancellation_cleared_note',
            ]);
        });
    }
};
