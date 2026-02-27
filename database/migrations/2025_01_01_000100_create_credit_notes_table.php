<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->string('credit_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
