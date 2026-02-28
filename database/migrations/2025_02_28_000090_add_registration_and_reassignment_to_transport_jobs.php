<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->string('registration', 20)->nullable()->after('vin');
            $table->string('original_vin')->nullable()->after('registration');
            $table->timestamp('vehicle_reassigned_at')->nullable()->after('original_vin');
            $table->foreignId('vehicle_reassigned_by')->nullable()->after('vehicle_reassigned_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_reassigned_by');
            $table->dropColumn(['registration', 'original_vin', 'vehicle_reassigned_at']);
        });
    }
};
