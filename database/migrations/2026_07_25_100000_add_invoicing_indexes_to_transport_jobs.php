<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes to unstick the Customer Invoicing page.
 *
 * The page filters on
 *   executor_type = 'proselver'
 *   AND status IN (delivered, completed, invoiced)
 *   AND delivered_at BETWEEN ...
 * plus optional company_id, and toggles on invoicing_completed_at /
 * invoicing_excluded_at nullability.  None of those three columns
 * (invoicing_completed_at, invoicing_excluded_at, delivered_at)
 * were indexed, which is why accounts saw the page "jam" for busy
 * OEMs over month-long windows.
 *
 * The composite (executor_type, status, delivered_at) covers the
 * primary lookup and is well ordered for the ORDER BY delivered_at
 * that follows it in the same query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->index(
                ['executor_type', 'status', 'delivered_at'],
                'transport_jobs_invoicing_lookup_idx',
            );
            $table->index('invoicing_completed_at', 'transport_jobs_invoicing_completed_idx');
            $table->index('invoicing_excluded_at', 'transport_jobs_invoicing_excluded_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropIndex('transport_jobs_invoicing_lookup_idx');
            $table->dropIndex('transport_jobs_invoicing_completed_idx');
            $table->dropIndex('transport_jobs_invoicing_excluded_idx');
        });
    }
};
