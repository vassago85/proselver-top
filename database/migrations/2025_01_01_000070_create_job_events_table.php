<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('transport_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('event_type', 30);
            $table->timestamp('event_at');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();

            $table->index(['job_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_events');
    }
};
