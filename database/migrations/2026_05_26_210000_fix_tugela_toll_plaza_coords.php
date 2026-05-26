<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct the seeded Tugela Toll Plaza coordinates.
 *
 * Original seed placed Tugela at (-28.58, 29.42) which is actually
 * closer to where the discontinued Bergville plaza was, ~50 km south
 * of the real Tugela location.  The actual SANRAL Tugela Toll Plaza
 * sits at the top of Van Reenen's Pass on the N3 between Harrismith
 * and Bergville/Ladysmith.
 *
 * Symptom: on JHB-PMB / PMB-JHB N3 routes, Tugela was missed at ~17 km
 * off the polyline even though the truck physically passes through it.
 * `php artisan tolls:debug 318` shows the gap.  Fixed coords:
 *   -28.10, 29.48  (approximate centre of the toll plaza site)
 *
 * Conservative: only updates the row if its coord is still on the
 * known-bad seed value.  Ops edits or future corrections survive.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')
            ->where('plaza_name', 'Tugela')
            ->where('latitude', -28.58330000)
            ->update([
                'latitude' => -28.10000000,
                'longitude' => 29.48000000,
            ]);
    }

    public function down(): void
    {
        // Restore only rows still on the corrected value -- a manual
        // ops fix shouldn't be reverted by a rollback.
        DB::table('toll_plazas')
            ->where('plaza_name', 'Tugela')
            ->where('latitude', -28.10000000)
            ->update([
                'latitude' => -28.58330000,
                'longitude' => 29.41670000,
            ]);
    }
};
