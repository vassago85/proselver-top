<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Set Tugela toll plaza to the ACTUAL SANRAL coordinates (user-
 * confirmed): 28.4623° S, 29.5616° E.  Replaces both the original
 * (-28.5833, 29.4167) seed value AND my previous bad guess at
 * Van Reenen's Pass (-28.0833, 29.4750).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('toll_plazas')
            ->where('plaza_name', 'Tugela')
            ->update([
                'latitude' => -28.46230000,
                'longitude' => 29.56160000,
            ]);
    }

    public function down(): void
    {
        // No reverse: any rollback target would be a wrong coord.
    }
};
