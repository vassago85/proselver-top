<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remove advance" state on transport_jobs.
 *
 * Two flows ops asked for:
 *   1. Trip never approved -> wiping is a free action, no fields here
 *      are needed (we just null the existing advance_* columns).
 *   2. Trip ALREADY approved by owner -> wiping is sensitive; needs
 *      a second sign-off.  Ops files the removal request, owner
 *      accepts or rejects.  These columns hold that request state
 *      between the two clicks.
 *
 * On owner-accept: advance_* columns are nulled, advance_plan_id is
 *   cleared, advance_approved_at is cleared, audit row written, and
 *   these columns are cleared too.
 * On owner-reject: only these columns are cleared.  The advance
 *   itself stays as it was -- the owner's earlier sign-off still
 *   stands.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->boolean('advance_removal_pending')->default(false)->after('advance_issue_reference');
            $table->timestampTz('advance_removal_requested_at')->nullable()->after('advance_removal_pending');
            $table->foreignId('advance_removal_requested_by_user_id')
                ->nullable()
                ->after('advance_removal_requested_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('advance_removal_reason')->nullable()->after('advance_removal_requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_removal_requested_by_user_id');
            $table->dropColumn(['advance_removal_pending', 'advance_removal_requested_at', 'advance_removal_reason']);
        });
    }
};
