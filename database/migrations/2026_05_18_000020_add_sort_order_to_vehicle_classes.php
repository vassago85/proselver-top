<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `sort_order` to vehicle_classes so commercial vehicles appear at
 * the top of every selector instead of falling out in alphabetical
 * order (which buries LCV / MCV / HCV under Bakkie / Bus / Hatchback).
 *
 * The default for existing rows is 100, the same as "Other"; the
 * standard catalogue (LCV, MCV, HCV, Extra Heavy, Bus, etc.) is
 * explicitly placed below. Anything ops adds later defaults to 100
 * and slots in alongside the passenger classes — ops can edit it from
 * the settings page.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_classes', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(100)->after('name')->index();
            }
        });

        // Seed the canonical order. Commercial classes first (this is a
        // logistics platform — the vast majority of bookings sit here),
        // then passenger classes, then catch-all/Other at the very end.
        // Keyed on lower-cased name so seeder spelling variations
        // ("Extra Heavy" vs "XHCV") don't have to perfectly match.
        $order = [
            'lcv'                => 10,
            'mcv'                => 20,
            'hcv'                => 30,
            'extra heavy'        => 40,
            'xhcv'               => 40,
            'bus'                => 50,
            'trailer'            => 60,
            'bakkie / pickup'    => 70,
            'bakkie'             => 70,
            'pickup'             => 70,
            'suv'                => 200,
            'sedan'              => 210,
            'hatchback'          => 220,
            'other'              => 900,
        ];

        foreach ($order as $name => $sort) {
            DB::table('vehicle_classes')
                ->whereRaw('LOWER(name) = ?', [$name])
                ->update(['sort_order' => $sort]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_classes', 'sort_order')) {
                $table->dropIndex(['sort_order']);
                $table->dropColumn('sort_order');
            }
        });
    }
};
