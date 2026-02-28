<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_change_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('job_id')->constrained('transport_jobs')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->string('request_type', 50)->default('collection_date_change');
            $table->json('current_value');
            $table->json('requested_value');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_change_requests');
    }
};
