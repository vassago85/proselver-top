<?php

namespace Database\Seeders;

use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class VehicleClassSeeder extends Seeder
{
    public function run(): void
    {
        // Commercial classes first — this is a logistics platform and
        // 95%+ of bookings are commercial. The sort_order numbers leave
        // gaps so ops can later wedge a new class in without renumbering
        // the whole list (e.g. an "MCV+" between MCV and HCV at 25).
        $classes = [
            ['name' => 'LCV',             'description' => 'Light commercial vehicle (panel van, etc.)', 'toll_class' => 1, 'sort_order' => 10],
            ['name' => 'MCV',             'description' => 'Medium commercial vehicle (4-8 ton)',         'toll_class' => 2, 'sort_order' => 20],
            ['name' => 'HCV',             'description' => 'Heavy commercial vehicle (8-16 ton)',         'toll_class' => 2, 'sort_order' => 30],
            ['name' => 'Extra Heavy',     'description' => 'Extra heavy commercial vehicle (16+ ton)',    'toll_class' => 3, 'sort_order' => 40],
            ['name' => 'Bus',             'description' => 'Passenger bus / minibus',                     'toll_class' => 2, 'sort_order' => 50],
            ['name' => 'Trailer',         'description' => 'Trailer unit',                                'toll_class' => 4, 'sort_order' => 60],
            ['name' => 'Bakkie / Pickup', 'description' => 'Light commercial pickup truck',               'toll_class' => 1, 'sort_order' => 70],
            ['name' => 'SUV',             'description' => 'Sport utility vehicle',                       'toll_class' => 1, 'sort_order' => 200],
            ['name' => 'Sedan',           'description' => 'Standard sedan / saloon',                     'toll_class' => 1, 'sort_order' => 210],
            ['name' => 'Hatchback',       'description' => 'Compact hatchback',                           'toll_class' => 1, 'sort_order' => 220],
            ['name' => 'Other',           'description' => 'Other vehicle type',                          'toll_class' => 1, 'sort_order' => 900],
        ];

        foreach ($classes as $class) {
            // Stay with firstOrCreate so we never clobber ops edits to
            // description / toll_class / sort_order on already-seeded
            // rows. The companion migration
            // 2026_05_18_000020_add_sort_order_to_vehicle_classes is
            // what back-fills sort_order on existing installs; this
            // seeder only matters for fresh installs and tests, where
            // there are no rows to clobber.
            VehicleClass::firstOrCreate(['name' => $class['name']], $class);
        }
    }
}
