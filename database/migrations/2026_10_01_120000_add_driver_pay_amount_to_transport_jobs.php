<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-trip override of the driver's rate_per_movement_cents.  When
 * set, this is what the month-end payslip pays the driver for THIS
 * one job -- it replaces (does not add to) the profile rate x 1.
 *
 * Nullable throughout: if accounts never touches it, nothing changes
 * and the profile rate is used as before.  Captured inline on the new
 * /admin/drivers/{user}/payslip page.  Set_by + set_at exist so the
 * audit log and the payslip itself can show who overrode the rate and
 * when, which matters when a dispute lands six weeks later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->decimal('driver_pay_amount', 12, 2)->nullable()->after('cost_driver');
            $table->string('driver_pay_note', 255)->nullable()->after('driver_pay_amount');
            $table->foreignId('driver_pay_set_by_user_id')
                ->nullable()
                ->after('driver_pay_note')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('driver_pay_set_at')->nullable()->after('driver_pay_set_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropForeign(['driver_pay_set_by_user_id']);
            $table->dropColumn([
                'driver_pay_amount',
                'driver_pay_note',
                'driver_pay_set_by_user_id',
                'driver_pay_set_at',
            ]);
        });
    }
};
