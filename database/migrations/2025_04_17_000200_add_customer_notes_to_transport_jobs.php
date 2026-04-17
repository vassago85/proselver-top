<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            // Optional free-text comment from the customer at order time
            // (e.g. "collect Friday"). Distinct from ops/driver notes.
            $table->text('customer_notes')->nullable()->after('emergency_reason');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('customer_notes');
        });
    }
};
