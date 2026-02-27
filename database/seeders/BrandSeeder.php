<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            'Isuzu', 'UD Trucks', 'Hino', 'Fuso', 'Mercedes-Benz',
            'Volvo', 'Scania', 'MAN', 'Iveco', 'FAW', 'Powerstar',
            'Tata', 'Toyota', 'Ford', 'Volkswagen', 'Nissan',
            'Hyundai', 'Kia', 'BMW', 'Audi', 'Mazda',
            'Suzuki', 'Mitsubishi', 'Renault', 'Peugeot', 'Opel',
            'Jeep', 'Land Rover', 'Porsche', 'Jaguar', 'BAIC',
            'Chery', 'Haval', 'GWM', 'JAC', 'JMC',
            'Mahindra', 'DAF', 'Other',
        ];

        foreach ($brands as $brand) {
            Brand::firstOrCreate(['name' => $brand]);
        }
    }
}
