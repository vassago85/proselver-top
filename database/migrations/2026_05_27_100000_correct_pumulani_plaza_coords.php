<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batch N1 north plaza coord corrections from user satellite-view
 * verification.  Each plaza was 8-50 km off the booth.
 *
 *   Pumulani   -25.5914, 28.2169  →  -25.6397, 28.2754
 *   Carousel   -25.3347, 28.2583  →  -25.3250, 28.2978
 *   Kranskop   -24.7558, 28.3750  →  -24.7817, 28.4716
 *   Nyl        -24.1889, 28.6969  →  -24.2898, 28.9796
 *   Capricorn  -23.4465, 29.4550  →  -22.9743, 30.4559
 *   Baobab     -23.1068, 29.5378  →  -22.6471, 29.9181
 *
 * Full N1 Pretoria → Beit Bridge section now verified.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')->where('plaza_name', 'Pumulani')
            ->update(['latitude' => -25.63970000, 'longitude' => 28.27540000]);

        DB::table('toll_plazas')->where('plaza_name', 'Carousel')
            ->update(['latitude' => -25.32500000, 'longitude' => 28.29780000]);

        DB::table('toll_plazas')->where('plaza_name', 'Kranskop')
            ->update(['latitude' => -24.78170000, 'longitude' => 28.47160000]);

        DB::table('toll_plazas')->where('plaza_name', 'Nyl')
            ->update(['latitude' => -24.28980000, 'longitude' => 28.97960000]);

        DB::table('toll_plazas')->where('plaza_name', 'Capricorn')
            ->update(['latitude' => -22.97430000, 'longitude' => 30.45590000]);

        DB::table('toll_plazas')->where('plaza_name', 'Baobab')
            ->update(['latitude' => -22.64710000, 'longitude' => 29.91810000]);
    }

    public function down(): void {}
};
