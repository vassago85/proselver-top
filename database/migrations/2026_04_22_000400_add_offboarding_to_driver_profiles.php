<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver offboarding (retire / resign / dismiss / deceased / other).
 *
 * We do NOT soft-delete the user — they must remain referenceable from
 * historical audit logs, completed jobs, invoices, etc. Instead:
 *   - users.is_active flips to false (existing column)
 *   - driver_profiles records who / when / why it happened
 *   - trade_plate is cleared (returned to the business pool) or
 *     transferred to another active driver in the same transaction
 *
 * See App\Services\DriverOffboardingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestamp('off_roster_at')->nullable()->after('notes');
            $table->string('off_roster_reason', 32)->nullable()->after('off_roster_at');
            $table->text('off_roster_notes')->nullable()->after('off_roster_reason');
            $table->foreignId('off_roster_by_user_id')->nullable()
                ->after('off_roster_notes')
                ->constrained('users')->nullOnDelete();

            // Records the moment a plate was handed back to the business
            // pool (i.e. cleared from this profile during offboarding).
            // Useful for the plate-release audit trail.
            $table->timestamp('trade_plate_returned_at')->nullable()->after('trade_plate_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('off_roster_by_user_id');
            $table->dropColumn([
                'off_roster_at',
                'off_roster_reason',
                'off_roster_notes',
                'trade_plate_returned_at',
            ]);
        });
    }
};
