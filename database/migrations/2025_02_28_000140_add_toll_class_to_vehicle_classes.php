<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->tinyInteger('toll_class')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn('toll_class');
        });
    }
};
