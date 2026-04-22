<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('chassis_number', 50);
            $table->string('vin', 50)->nullable();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_name')->nullable();
            $table->string('status', 20)->default('produced');
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_via_job_id')->nullable()->constrained('transport_jobs')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_company_id', 'chassis_number']);
            $table->index(['owner_company_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
