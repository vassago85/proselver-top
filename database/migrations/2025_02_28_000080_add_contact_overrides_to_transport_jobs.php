<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->string('pickup_contact_name')->nullable()->after('pickup_location_id');
            $table->string('pickup_contact_phone')->nullable()->after('pickup_contact_name');
            $table->string('delivery_contact_name')->nullable()->after('delivery_location_id');
            $table->string('delivery_contact_phone')->nullable()->after('delivery_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn(['pickup_contact_name', 'pickup_contact_phone', 'delivery_contact_name', 'delivery_contact_phone']);
        });
    }
};
