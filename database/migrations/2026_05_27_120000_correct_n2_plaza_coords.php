<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N2 plaza coord corrections — rolling batch as user verifies each.
 *
 *   Othongathi  -29.5672, 31.1172  →  -29.5881, 31.1410  (~3 km off)
 *   Mvoti       -29.3667, 31.2333  →  -29.4035, 31.2856  (~6 km off)
 *   Mtunzini    -28.9497, 31.7503  →  -28.9573, 31.7378  (~1.5 km off)
 *   Oribi       -30.7833, 30.3833  →  -30.7480, 30.4338  (~6 km off)
 *   Tsitsikamma -33.9700, 23.8900  →  -33.9502, 23.6233  (~25 km off)
 *
 * Full N2 section now verified.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')->where('plaza_name', 'Othongathi')
            ->update(['latitude' => -29.58810676, 'longitude' => 31.14100487]);

        DB::table('toll_plazas')->where('plaza_name', 'Mvoti')
            ->update(['latitude' => -29.40346000, 'longitude' => 31.28555000]);

        DB::table('toll_plazas')->where('plaza_name', 'Mtunzini')
            ->update(['latitude' => -28.95730000, 'longitude' => 31.73780000]);

        DB::table('toll_plazas')->where('plaza_name', 'Oribi')
            ->update(['latitude' => -30.74800000, 'longitude' => 30.43380000]);

        DB::table('toll_plazas')->where('plaza_name', 'Tsitsikamma')
            ->update(['latitude' => -33.95024090, 'longitude' => 23.62327456]);
    }

    public function down(): void {}
};
