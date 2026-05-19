<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urgent-collection flag on transport_jobs.
 *
 * Either ops or the booking customer can mark a job urgent (with an
 * optional reason). The flag drives:
 *   - URGENT badge + glow on the Live Display wallboard.
 *   - Top-of-queue sort on the Waiting / New Orders lanes.
 *   - Headline "Urgent" counter on the admin summary strip.
 *   - (Future) Notification + Planning Queue priority.
 *
 * Audit fields capture who marked it and when, so disputes about
 * "why was this prioritised" have a clear paper trail. Clearing the
 * flag wipes all four columns; the marking event itself is also
 * written to audit_logs by the Job::markUrgent() / clearUrgent()
 * helpers, so history isn't lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('status');
            $table->string('urgent_reason', 500)->nullable()->after('is_urgent');
            $table->foreignId('urgent_marked_by_user_id')
                ->nullable()
                ->after('urgent_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('urgent_marked_at')->nullable()->after('urgent_marked_by_user_id');
            $table->index(['is_urgent', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropForeign(['urgent_marked_by_user_id']);
            $table->dropIndex(['is_urgent', 'status']);
            $table->dropColumn(['is_urgent', 'urgent_reason', 'urgent_marked_by_user_id', 'urgent_marked_at']);
        });
    }
};
