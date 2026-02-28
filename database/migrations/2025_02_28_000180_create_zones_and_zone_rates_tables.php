<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('zone_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('destination_zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('vehicle_class_id')->constrained('vehicle_classes')->cascadeOnDelete();
            $table->decimal('distance_km', 8, 2);
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['origin_zone_id', 'destination_zone_id', 'vehicle_class_id'], 'zone_rate_unique');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('company_id')->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });

        Schema::dropIfExists('zone_rates');
        Schema::dropIfExists('zones');
    }
};
