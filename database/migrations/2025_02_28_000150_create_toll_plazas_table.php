<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_plazas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('road_name', 255);
            $table->string('plaza_name', 255);
            $table->string('plaza_type', 30)->default('mainline');
            $table->string('telephone', 50)->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('class_1_fee', 8, 2)->default(0);
            $table->decimal('class_2_fee', 8, 2)->default(0);
            $table->decimal('class_3_fee', 8, 2)->default(0);
            $table->decimal('class_4_fee', 8, 2)->default(0);
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plaza_name', 'plaza_type', 'road_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_plazas');
    }
};
