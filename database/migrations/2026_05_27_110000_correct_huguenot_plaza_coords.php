<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correct Huguenot toll plaza coords (N1 Worcester → Paarl tunnel).
 *   -33.7833, 19.0333  →  -33.7425, 19.0197   (~5 km off)
 *
 * Single-plaza section (N1 in the Western Cape). User-verified
 * satellite click on the actual booth at the Du Toitskloof tunnel
 * approach.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')->where('plaza_name', 'Huguenot')
            ->update(['latitude' => -33.74252111, 'longitude' => 19.01974617]);
    }

    public function down(): void {}
};
