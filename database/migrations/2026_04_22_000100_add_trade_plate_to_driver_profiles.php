<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('trade_plate', 20)->nullable()->after('base_location');
            $table->date('trade_plate_expiry')->nullable()->after('trade_plate');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['trade_plate', 'trade_plate_expiry']);
        });
    }
};
