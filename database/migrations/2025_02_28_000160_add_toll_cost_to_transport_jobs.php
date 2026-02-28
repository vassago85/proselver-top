<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->decimal('estimated_toll_cost', 8, 2)->nullable()->after('estimated_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('estimated_toll_cost');
        });
    }
};
