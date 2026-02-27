<?php

namespace Database\Seeders;

use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class VehicleClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            ['name' => 'Sedan', 'description' => 'Standard sedan / saloon'],
            ['name' => 'Hatchback', 'description' => 'Compact hatchback'],
            ['name' => 'SUV', 'description' => 'Sport utility vehicle'],
            ['name' => 'Bakkie / Pickup', 'description' => 'Light commercial pickup truck'],
            ['name' => 'LCV', 'description' => 'Light commercial vehicle (panel van, etc.)'],
            ['name' => 'MCV', 'description' => 'Medium commercial vehicle (4-8 ton)'],
            ['name' => 'HCV', 'description' => 'Heavy commercial vehicle (8-16 ton)'],
            ['name' => 'Extra Heavy', 'description' => 'Extra heavy commercial vehicle (16+ ton)'],
            ['name' => 'Bus', 'description' => 'Passenger bus / minibus'],
            ['name' => 'Trailer', 'description' => 'Trailer unit'],
            ['name' => 'Other', 'description' => 'Other vehicle type'],
        ];

        foreach ($classes as $class) {
            VehicleClass::firstOrCreate(['name' => $class['name']], $class);
        }
    }
}
