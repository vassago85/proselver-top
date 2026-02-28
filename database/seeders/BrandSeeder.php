<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            'Mercedes-Benz',
            'Volvo',
            'Powerstar',
            'Foton',
            'FAW',
            'Isuzu',
            'UD Trucks',
            'Hino',
            'Scania',
        ];

        foreach ($brands as $name) {
            Brand::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
