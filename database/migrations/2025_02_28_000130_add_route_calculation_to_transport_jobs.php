<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->decimal('distance_km', 8, 2)->nullable()->after('registration');
            $table->integer('estimated_duration_minutes')->nullable()->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn(['distance_km', 'estimated_duration_minutes']);
        });
    }
};
