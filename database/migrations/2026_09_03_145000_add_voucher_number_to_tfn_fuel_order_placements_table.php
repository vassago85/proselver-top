<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TFN's POST /api/Orders response includes `CurrentVirtualCardNumber`
 * on each Entry — that's the 6-digit voucher/redemption code the
 * driver punches into the pump.  TFN also SMSes it to
 * DriverCellNumber, but SMS delivery isn't guaranteed (roaming, dead
 * handset, wrong number on file) — so we snapshot the voucher into
 * our local placement audit at POST time.  Ops can then read it back
 * to the driver over the phone from the order-show page if the SMS
 * never arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tfn_fuel_order_placements', function (Blueprint $table) {
            // TFN vouchers are typically 6-digit numeric strings, but
            // keep it a plain string so future format changes on their
            // side (longer codes, alphanumeric) don't need a migration.
            $table->string('voucher_number')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('tfn_fuel_order_placements', function (Blueprint $table) {
            $table->dropColumn('voucher_number');
        });
    }
};
