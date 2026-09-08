<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist every TFN fuel transaction we see so MTD spend / litres stops
 * silently truncating once the account exceeds ~100 fills in the month.
 *
 * TFN's /api/Transactions endpoint hard-caps at 100 rows per response
 * with only a `capturedDateAfter` filter (no offset / no page cursor),
 * and there is NO server-side aggregate for rand spend (litres has
 * SubAccountAggregateLitres, spend does not). The old Owner + Fuel Ops
 * MTD tiles summed abs(Amount) directly from the live tx feed, which
 * meant: month-start pull returned the oldest 100 rows, the last-24h
 * pull returned today's, and any fill in the middle of a heavy month
 * vanished from the tile — the number literally shrank as the month
 * went on. This table is the fix: a scheduled snapshotter upserts every
 * row it sees, and the tiles read SUM(amount) from here instead.
 *
 * TransactionID is treated as the natural upsert key. When TFN doesn't
 * supply one (rare — a few historical rows), the snapshotter falls
 * back to a deterministic hash of (CapturedDate + VehicleRegistration
 * + Amount + Litres) so retries don't duplicate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fuel_fills', function (Blueprint $table) {
            $table->id();

            // TFN row identity + owning account.
            $table->string('transaction_id', 64)->unique();
            $table->string('customer_number', 32)->nullable()->index();

            // What was pumped and when.
            $table->timestamp('captured_at')->index();
            $table->timestamp('transaction_at')->nullable();
            $table->string('product_code', 16)->index();
            $table->string('transaction_type_code', 16)->nullable();

            // The vehicle at the pump. Optional because a handful of
            // historical rows come back without a plate (workshop fills,
            // manual entries) and we still want to bank the spend.
            $table->string('vehicle_registration', 32)->nullable()->index();

            // Money in ZAR. Decimal so 1M+ fills a month don't hit
            // float rounding on the MTD sum.
            $table->decimal('litres', 10, 3)->default(0);
            $table->decimal('amount',  12, 2)->default(0);

            // Raw payload for forensic debugging — TFN adds columns
            // (site, driver ref, discount codes) over time and we don't
            // want to lose them. jsonb on Postgres, json elsewhere.
            $table->json('payload')->nullable();

            // When the snapshotter last touched this row. Cheap way to
            // spot rows that fell off the tx feed (should not happen).
            $table->timestamp('snapshotted_at')->useCurrent();

            $table->timestamps();

            // The one query the MTD tile actually runs.
            $table->index(['customer_number', 'captured_at'], 'fuel_fills_scope_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_fills');
    }
};
