<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('user_id')
                ->constrained('locations')->nullOnDelete();
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->string('confirmation_reason')->nullable()->after('cancellation_reason');
            $table->text('confirmation_note')->nullable()->after('confirmation_reason');
        });

        Schema::create('brand_company', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_company');

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn(['confirmation_reason', 'confirmation_note']);
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
