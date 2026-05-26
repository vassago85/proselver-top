<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up to 2026_05_26_210000_fix_tugela_toll_plaza_coords:
 *
 * The previous migration was conservative -- it only updated Tugela
 * if the latitude was still on the exact known-bad seed value
 * (-28.58330000).  Decimal(10,8) vs float equality in the WHERE
 * clause may have left some installs untouched.  This migration
 * matches by name only and is unconditional, so it always corrects
 * Tugela to the actual SANRAL Toll Plaza coordinates at the top
 * of Van Reenen's Pass on the N3.
 *
 * Real coordinates from SANRAL/Google: -28.0833 lat, 29.4750 lng.
 *
 * If an ops person has already hand-fixed Tugela to something better,
 * this migration will overwrite that -- but Tugela has been wrong
 * since the original seed and there's been no UI for ops to edit
 * plaza coords, so that risk is essentially zero.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')
            ->where('plaza_name', 'Tugela')
            ->update([
                'latitude' => -28.08330000,
                'longitude' => 29.47500000,
            ]);
    }

    public function down(): void
    {
        // No reverse -- the down() of the original 210000 migration
        // still restores the broken seed value if rolled back, which
        // would put us back to the symptom.  Leaving this as a no-op
        // so a rollback doesn't make things worse.
    }
};
