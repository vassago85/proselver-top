<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Fleet identifiers pulled from the ops master driver sheet.
            // Non-unique on purpose — sheet already has duplicates/blanks and
            // ops should be able to record what they have verbatim.
            $table->string('tracker_id', 50)->nullable()->after('trade_plate_expiry');
            $table->string('camera_id', 50)->nullable()->after('tracker_id');
            $table->string('toll_card_number', 50)->nullable()->after('camera_id');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['tracker_id', 'camera_id', 'toll_card_number']);
        });
    }
};
