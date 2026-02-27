<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('job_number')->unique()->nullable();
            $table->string('job_type', 20); // transport, yard_work
            $table->string('status', 30)->default('pending_verification');

            $table->foreignId('company_id')->constrained();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('driver_user_id')->nullable()->constrained('users');
            $table->foreignId('transport_route_id')->nullable()->constrained();

            // Transport fields
            $table->foreignId('from_hub_id')->nullable()->constrained('hubs');
            $table->foreignId('to_hub_id')->nullable()->constrained('hubs');
            $table->foreignId('vehicle_class_id')->nullable()->constrained();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->string('model_name')->nullable();
            $table->string('vin', 50)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('scheduled_ready_time')->nullable();
            $table->timestamp('actual_ready_time')->nullable();

            // PO fields
            $table->string('po_number', 50)->nullable();
            $table->decimal('po_amount', 12, 2)->nullable();
            $table->boolean('po_verified')->default(false);
            $table->timestamp('po_verified_at')->nullable();
            $table->foreignId('po_verified_by')->nullable()->constrained('users');

            // Yard work fields
            $table->foreignId('yard_hub_id')->nullable()->constrained('hubs');
            $table->integer('drivers_required')->nullable();
            $table->decimal('hours_required', 8, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();

            // Sell price (customer visible)
            $table->decimal('base_transport_price', 12, 2)->default(0);
            $table->decimal('delivery_fuel_price', 12, 2)->default(0);
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_sell_price', 12, 2)->default(0);

            // Internal cost (admin only)
            $table->decimal('cost_fuel', 12, 2)->default(0);
            $table->decimal('cost_tolls', 12, 2)->default(0);
            $table->decimal('cost_driver', 12, 2)->default(0);
            $table->decimal('cost_accommodation', 12, 2)->default(0);
            $table->decimal('cost_other', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('gross_profit', 12, 2)->default(0);
            $table->decimal('margin_percent', 8, 2)->default(0);

            // Emergency
            $table->boolean('is_emergency')->default(false);
            $table->text('emergency_reason')->nullable();

            // Delay tracking
            $table->integer('delay_minutes')->nullable();
            $table->text('delay_reason')->nullable();
            $table->string('delay_reason_type', 20)->nullable(); // client, proselver, other

            // Status timestamps
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('job_type');
            $table->index('scheduled_date');
            $table->index(['company_id', 'status']);
            $table->index(['driver_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_jobs');
    }
};
