<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Final batch of plaza coord corrections — the 4 plazas left un-
 * verified in the 2026_05_27_140000 batch.  User satellite-clicked each
 * booth and supplied the lat/lng directly.
 *
 *   Doornpoort  -25.6667, 28.1833  →  -25.6432, 28.2537   (~9 km off)
 *   Leandra     -26.3500, 29.0333  →  -26.3984, 28.9492   (~10 km off)
 *   Trichardt   -26.5000, 29.2833  →  -26.4835, 29.3261   (~4 km off)
 *   Ermelo      -26.5333, 29.9833  →  -26.5055, 29.8659   (~12 km off)
 *
 * Together with 2026_05_27_140000 this completes the post-import
 * audit -- every active toll plaza is now seated within ~0.05° of the
 * SANRAL/Bakwena/TRAC booth canopy.
 */
return new class extends Migration {
    public function up(): void
    {
        $fixes = [
            'Doornpoort' => [-25.64316519, 28.25368787],
            'Leandra'    => [-26.39839422, 28.94923681],
            'Trichardt'  => [-26.48354074, 29.32608244],
            'Ermelo'     => [-26.50546896, 29.86591572],
        ];

        foreach ($fixes as $plaza => [$lat, $lng]) {
            DB::table('toll_plazas')->where('plaza_name', $plaza)
                ->update(['latitude' => $lat, 'longitude' => $lng]);
        }
    }

    public function down(): void
    {
        // No reverse -- the previous values were inaccurate.
    }
};
