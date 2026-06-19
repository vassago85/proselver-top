<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Optional finance-capture fields. After a movement is delivered,
 * accounts fills in the invoice number + amount, plus per-trip extras
 * and fuel.  Every column is nullable -- none are compulsory.  These
 * are the columns the FAW invoicing Excel export reads.
 *
 *  - invoice_amount: rand, INCLUDES VAT
 *  - extras_amount:  rand, INCLUDES VAT (truck stop fees, sundries)
 *  - fuel_litres:    volume
 *  - fuel_amount:    rand, EXCLUDES VAT (fuel is zero-rated for the
 *                    customer-facing invoice line)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->string('invoice_number', 50)->nullable()->after('invoiced_at');
            $table->decimal('invoice_amount', 12, 2)->nullable()->after('invoice_number');
            $table->decimal('extras_amount', 12, 2)->nullable()->after('invoice_amount');
            $table->decimal('fuel_litres', 8, 2)->nullable()->after('extras_amount');
            $table->decimal('fuel_amount', 12, 2)->nullable()->after('fuel_litres');

            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropIndex(['invoice_number']);
            $table->dropColumn(['invoice_number', 'invoice_amount', 'extras_amount', 'fuel_litres', 'fuel_amount']);
        });
    }
};
