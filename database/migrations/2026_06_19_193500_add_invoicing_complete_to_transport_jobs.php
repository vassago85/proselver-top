<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Invoicing complete" flag for the customer-invoicing page.
 *
 * The page hydrates every delivered ProSelver movement in the window;
 * accounts wants a tick that says "I'm done with this row, hide it
 * from my working list" so they can keep filtering down to the rows
 * that still need an invoice number / amounts captured.  Not a status
 * transition (the job is already delivered/completed), just a finance
 * housekeeping marker.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestampTz('invoicing_completed_at')->nullable()->after('fuel_amount');
            $table->foreignId('invoicing_completed_by_user_id')
                ->nullable()
                ->after('invoicing_completed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoicing_completed_by_user_id');
            $table->dropColumn('invoicing_completed_at');
        });
    }
};
