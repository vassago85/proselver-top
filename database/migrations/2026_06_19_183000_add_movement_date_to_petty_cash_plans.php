<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The pay-run draft is usually built today for movements that roll
 * tomorrow (or later). movement_date lets the creator stamp a single
 * collection date on the whole draft at sign-off time; applying it
 * rewrites scheduled_date on every trip in the bundle.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('petty_cash_plans', function (Blueprint $table) {
            $table->date('movement_date')->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_plans', function (Blueprint $table) {
            $table->dropColumn('movement_date');
        });
    }
};
