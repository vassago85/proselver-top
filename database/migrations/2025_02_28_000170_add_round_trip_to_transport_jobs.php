<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->boolean('is_round_trip')->default(false)->after('estimated_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('is_round_trip');
        });
    }
};
