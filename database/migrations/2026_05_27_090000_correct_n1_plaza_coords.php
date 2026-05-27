<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct several N1 toll plaza coords to user-verified SANRAL values.
 *
 * The original seed put these plazas at points 3–40 km off the actual
 * booth, which is why PE → JHB runs were matching zero plazas even
 * with the 5 km haversine radius.  User pulled the coords from
 * satellite-view click on Google Maps for each booth canopy.
 *
 *   Grasmere       -26.3889, 27.9889  →  -26.4117, 27.8842
 *   Vaal           -27.0247, 28.0758  →  -26.8563, 27.6353
 *   Verkeerdevlei  -29.1000, 27.0167  →  -28.7988, 26.6905
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')->where('plaza_name', 'Grasmere')
            ->update(['latitude' => -26.41170000, 'longitude' => 27.88420000]);

        DB::table('toll_plazas')->where('plaza_name', 'Vaal')
            ->update(['latitude' => -26.85628065, 'longitude' => 27.63526156]);

        DB::table('toll_plazas')->where('plaza_name', 'Verkeerdevlei')
            ->update(['latitude' => -28.79880000, 'longitude' => 26.69050000]);
    }

    public function down(): void
    {
        // No reverse -- the previous values were wrong; rolling back
        // would put us back to the symptom.
    }
};
