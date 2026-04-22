<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Not a true enum so we can extend later without a schema migration.
            // Expected values: 'sa_id', 'passport', 'other'.
            $table->string('id_type', 20)->default('sa_id')->after('id_number');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('id_type');
        });
    }
};
