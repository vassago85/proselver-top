<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Recall to planning" audit columns on transport_jobs.
 *
 * Ops needs the ability to pull a job back to STATUS_CONFIRMED at any
 * pre-delivery point (driver assigned, ready for collection, even
 * already collected / in transit if the truck is being recalled to
 * the yard).  These columns record the *last* recall -- who, when,
 * why -- so the order page can show an amber banner explaining why
 * a previously-planned job is back in the queue.  Full history is
 * still on audit_logs + job_events; these three columns just keep
 * the latest event one query away from the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestamp('recalled_at')->nullable()->after('cancellation_reason');
            $table->foreignId('recalled_by_user_id')
                ->nullable()
                ->after('recalled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('recall_reason', 500)->nullable()->after('recalled_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropForeign(['recalled_by_user_id']);
            $table->dropColumn(['recalled_at', 'recalled_by_user_id', 'recall_reason']);
        });
    }
};
