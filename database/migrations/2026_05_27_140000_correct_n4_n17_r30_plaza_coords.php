<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N4, N17 and R30 plaza coord corrections — cross-referenced against
 * Bakwena's official GPS list (bakwena.co.za/gps-coordinates) for the
 * Magalies/Platinum plazas, TRAC + geoview/wikimapia for the Maputo
 * corridor plazas, and SANRAL plaza pages for N17 + R30.
 *
 *   Marikana     -25.7000, 27.4833  →  -25.7473, 27.3977  (~9 km off)
 *   Brits        -25.6167, 27.7833  →  -25.6500, 27.9220  (~15 km off)
 *   Swartruggens -25.8000, 26.6833  →  -25.6607, 26.6052  (~17 km off)
 *   Pelindaba    -25.7633, 27.9567  →  -25.7777, 27.9616  (~1.7 km off)
 *   Quagga       -25.7500, 28.0000  →  -25.7493, 28.1148  (~11.5 km off)
 *   Diamond Hill -25.4167, 28.5833  →  -25.7980, 28.5503  (~42 km off!)
 *   Middelburg   -25.7833, 29.4667  →  -25.8656, 29.3636  (~13 km off)
 *   Machado      -25.6667, 30.2500  →  -25.6279, 30.2583  (~4.4 km off)
 *   Nkomazi      -25.4333, 31.5833  →  -25.5365, 31.3446  (~28 km off)
 *   Gosforth     -26.2000, 28.4333  →  -26.2513, 28.1429  (~30 km off)
 *   Dalpark      -26.1833, 28.5333  →  -26.2563, 28.3275  (~22.5 km off)
 *   Brandfort    -28.7000, 26.4500  →  -28.7657, 26.4143  (~8 km off)
 *
 * Doornpoort, Leandra, Trichardt and Ermelo are NOT updated here — the
 * primary sources were either contradictory or absent.  Run
 * `php artisan tolls:verify-coords --road=N17` / `--road=N4`, satellite-
 * click the booth, and feed the corrected lat/lng back for a follow-up
 * migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $fixes = [
            'Marikana'     => [-25.74740600, 27.39740600],
            'Brits'        => [-25.65000000, 27.92215600],
            'Swartruggens' => [-25.66067000, 26.60517000],
            'Pelindaba'    => [-25.77765800, 27.96158400],
            'Quagga'       => [-25.74931000, 28.11481300],
            'Diamond Hill' => [-25.79797470, 28.55024500],
            'Middelburg'   => [-25.86594000, 29.36393900],
            'Machado'      => [-25.62788100, 30.25845600],
            'Nkomazi'      => [-25.53649100, 31.34457600],
            'Gosforth'     => [-26.25126900, 28.14290900],
            'Dalpark'      => [-26.25630500, 28.32753800],
            'Brandfort'    => [-28.76567000, 26.41426400],
        ];

        foreach ($fixes as $plaza => [$lat, $lng]) {
            DB::table('toll_plazas')->where('plaza_name', $plaza)
                ->update(['latitude' => $lat, 'longitude' => $lng]);
        }
    }

    public function down(): void
    {
        // No reverse -- the previous values were inaccurate; rolling
        // back would put us back to the symptom.
    }
};
