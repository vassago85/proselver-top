<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('transport_jobs');
            $table->foreignId('cancelled_by_user_id')->constrained('users');
            $table->text('reason');
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->boolean('penalty_overridden')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by_user_id')->nullable()->constrained('users');
            $table->boolean('is_late')->default(false);
            $table->timestamps();

            $table->index('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellations');
    }
};
