<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_hub_id')->constrained('hubs');
            $table->foreignId('destination_hub_id')->constrained('hubs');
            $table->foreignId('vehicle_class_id')->constrained();
            $table->decimal('base_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['origin_hub_id', 'destination_hub_id', 'vehicle_class_id'], 'route_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_routes');
    }
};
