<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N3 plaza coord corrections — rolling batch as user verifies each.
 *
 *   De Hoek  -26.6833, 28.3667  →  -26.6636, 28.3897  (~3 km off)
 *   Wilge    -27.1833, 28.6667  →  -27.0404, 28.6261  (~15 km off)
 *   Mooi        -29.2000, 30.0167  →  -29.2208, 30.0035  (~2.5 km off)
 *   Mariannhill -29.8500, 30.8167  →  -29.8229, 30.8027  (~3 km off)
 *
 * Full N3 section now verified (Tugela already done in
 * 2026_05_26_230000).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')->where('plaza_name', 'De Hoek')
            ->update(['latitude' => -26.66360000, 'longitude' => 28.38970000]);

        DB::table('toll_plazas')->where('plaza_name', 'Wilge')
            ->update(['latitude' => -27.04043933, 'longitude' => 28.62612647]);

        DB::table('toll_plazas')->where('plaza_name', 'Mooi')
            ->update(['latitude' => -29.22080000, 'longitude' => 30.00350000]);

        DB::table('toll_plazas')->where('plaza_name', 'Mariannhill')
            ->update(['latitude' => -29.82289872, 'longitude' => 30.80274328]);
    }

    public function down(): void {}
};
