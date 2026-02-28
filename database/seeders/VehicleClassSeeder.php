<?php

namespace Database\Seeders;

use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class VehicleClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            ['name' => 'Sedan', 'description' => 'Standard sedan / saloon', 'toll_class' => 1],
            ['name' => 'Hatchback', 'description' => 'Compact hatchback', 'toll_class' => 1],
            ['name' => 'SUV', 'description' => 'Sport utility vehicle', 'toll_class' => 1],
            ['name' => 'Bakkie / Pickup', 'description' => 'Light commercial pickup truck', 'toll_class' => 1],
            ['name' => 'LCV', 'description' => 'Light commercial vehicle (panel van, etc.)', 'toll_class' => 1],
            ['name' => 'MCV', 'description' => 'Medium commercial vehicle (4-8 ton)', 'toll_class' => 2],
            ['name' => 'HCV', 'description' => 'Heavy commercial vehicle (8-16 ton)', 'toll_class' => 2],
            ['name' => 'Extra Heavy', 'description' => 'Extra heavy commercial vehicle (16+ ton)', 'toll_class' => 3],
            ['name' => 'Bus', 'description' => 'Passenger bus / minibus', 'toll_class' => 2],
            ['name' => 'Trailer', 'description' => 'Trailer unit', 'toll_class' => 4],
            ['name' => 'Other', 'description' => 'Other vehicle type', 'toll_class' => 1],
        ];

        foreach ($classes as $class) {
            VehicleClass::firstOrCreate(['name' => $class['name']], $class);
        }
    }
}
