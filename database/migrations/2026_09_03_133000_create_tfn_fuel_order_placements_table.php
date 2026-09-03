<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local audit of who placed each TFN pre-authorisation.  TFN's
 * /api/Orders payload has no "placed by" field — CustomerReference
 * is the job / delivery ref — so we record the TRIDENT user at
 * POST time and join back onto the open/closed orders tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tfn_fuel_order_placements', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->index();
            $table->string('vehicle_registration')->nullable();
            $table->string('product_code', 16)->nullable();
            $table->decimal('litres', 10, 2)->nullable();
            $table->string('customer_reference')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot so historical rows still name the placer after
            // a user is renamed or soft-deleted.
            $table->string('placed_by_name');
            $table->timestamp('placed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tfn_fuel_order_placements');
    }
};
