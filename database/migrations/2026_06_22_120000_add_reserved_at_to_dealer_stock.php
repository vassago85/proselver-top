<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reserve workflow on dealer_stock.
 *
 * STATUS_RESERVED already existed on the model but the UI never
 * captured a "this vehicle is being held for X by Y" event.  Reusing
 * salesperson_user_id + sale_customer_* keeps the commercial fields
 * the same across reserved -> sold (no copy-on-sale), and a single
 * reserved_at timestamp records the lifecycle event so the timeline
 * panel can render "Reserved 12 Jun by Sarah → Sold 18 Jun".
 *
 * No index needed -- the dashboard does not surface a "reserved" card
 * (it stays on the stock list as a status filter).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            $table->timestamp('reserved_at')->nullable()->after('sold_at');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_stock', function (Blueprint $table) {
            $table->dropColumn('reserved_at');
        });
    }
};
