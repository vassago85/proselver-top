<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * login_history — persistent record of every authentication event.
 *
 * The app already has audit_logs, but that table only records what an
 * authenticated actor did to a row (created / updated / deleted).  It never
 * carried a "who signed in when, from where" trail, which meant the only
 * way to see recent logins on the server was to grep the nginx access log
 * inside the container — and that log doesn't survive `up -d --force-recreate`.
 *
 * We keep the shape narrow on purpose: one row per login attempt, one per
 * logout, one per failure.  No JSON blobs; anything richer belongs in
 * audit_logs.  The `event` column is the discriminator so the same table
 * covers all three cases without a second query.
 *
 * user_id is nullable because a failed attempt with an unknown identity
 * (typo, someone probing) still deserves to be logged — the identity string
 * they typed is captured separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();

            // Nullable: on 'failed' events we may not know who the user is.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Whatever the user typed into the "email / username / phone"
            // field.  Kept even on success so we can tell WHICH of the three
            // login identifiers they used (email vs username vs phone).
            $table->string('identity', 190)->nullable();

            // 'login' | 'failed' | 'logout'.  Left as a string rather than an
            // enum so adding a new event type (e.g. 'locked_out') later is a
            // one-line code change, not a migration.
            $table->string('event', 20);

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Correlates a 'login' row with the eventual 'logout' row.
            $table->string('session_id', 100)->nullable();

            // Single-column timestamp (immutable — same pattern as audit_logs).
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('event');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
