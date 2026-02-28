<?php

namespace Database\Seeders;

use App\Models\BodyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BodyTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Flatbed', 'description' => 'Open flat platform for oversized or loose cargo'],
            ['name' => 'Curtain-side', 'description' => 'Sliding curtain walls for easy side loading'],
            ['name' => 'Box Body (Closed)', 'description' => 'Fully enclosed rigid box for general freight'],
            ['name' => 'Tautliner', 'description' => 'Flexible curtain-sided trailer for palletised loads'],
            ['name' => 'Tanker', 'description' => 'Cylindrical tank for liquid or gas transport'],
            ['name' => 'Tipper', 'description' => 'Hydraulic tipping body for bulk materials'],
            ['name' => 'Refrigerated', 'description' => 'Temperature-controlled body for perishable goods'],
            ['name' => 'Low-bed', 'description' => 'Low-height trailer for heavy/tall machinery'],
            ['name' => 'Skeletal (Container Chassis)', 'description' => 'Frame trailer for intermodal shipping containers'],
            ['name' => 'Cattle Body', 'description' => 'Ventilated body for livestock transport'],
            ['name' => 'Side Tipper', 'description' => 'Side-tipping body for bulk material offloading'],
            ['name' => 'Dropside', 'description' => 'Hinged side panels that fold down for access'],
            ['name' => 'Rollback / Car Carrier', 'description' => 'Tilting or multi-deck body for vehicle transport'],
        ];

        foreach ($types as $type) {
            BodyType::firstOrCreate(
                ['slug' => Str::slug($type['name'])],
                array_merge($type, ['is_active' => true])
            );
        }
    }
}
