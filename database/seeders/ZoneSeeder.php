<?php

namespace Database\Seeders;

use App\Models\Zone;
use App\Models\ZoneRate;
use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['name' => 'Pretoria', 'description' => 'Pretoria and surrounding areas'],
            ['name' => 'Centurion', 'description' => 'Centurion, Midstream, Irene'],
            ['name' => 'Midrand', 'description' => 'Midrand, Halfway House, Commercia'],
            ['name' => 'Johannesburg', 'description' => 'Johannesburg metro and surrounding areas'],
            ['name' => 'East Rand', 'description' => 'Springs, Benoni, Kempton Park, Boksburg'],
            ['name' => 'West Rand', 'description' => 'Roodepoort, Krugersdorp, Randfontein'],
            ['name' => 'Vaal Triangle', 'description' => 'Vereeniging, Vanderbijlpark, Sasolburg'],
            ['name' => 'Polokwane', 'description' => 'Polokwane and Limpopo surrounds'],
            ['name' => 'Nelspruit', 'description' => 'Mbombela / Nelspruit area'],
            ['name' => 'Rustenburg', 'description' => 'Rustenburg and surrounds'],
            ['name' => 'Durban', 'description' => 'Durban metro and surrounds'],
            ['name' => 'Pietermaritzburg', 'description' => 'Pietermaritzburg and surrounds'],
            ['name' => 'Cape Town', 'description' => 'Cape Town metro and surrounds'],
            ['name' => 'Bloemfontein', 'description' => 'Bloemfontein and Free State surrounds'],
            ['name' => 'Port Elizabeth', 'description' => 'Gqeberha / Port Elizabeth metro'],
            ['name' => 'East London', 'description' => 'East London / Buffalo City'],
            ['name' => 'George', 'description' => 'George and Garden Route'],
            ['name' => 'Kimberley', 'description' => 'Kimberley and Northern Cape surrounds'],
        ];

        foreach ($zones as $zone) {
            Zone::firstOrCreate(['name' => $zone['name']], $zone);
        }

        $hcv = VehicleClass::where('name', 'HCV')->first();
        if (!$hcv) {
            return;
        }

        $sampleRates = [
            // Intra-zone (within the same city)
            ['Pretoria', 'Pretoria', 30.0, 800.00],
            ['Johannesburg', 'Johannesburg', 35.0, 900.00],
            ['Midrand', 'Midrand', 15.0, 500.00],
            ['Durban', 'Durban', 30.0, 800.00],
            ['Cape Town', 'Cape Town', 35.0, 900.00],

            // Gauteng inter-city
            ['Pretoria', 'Centurion', 20.0, 650.00],
            ['Pretoria', 'Midrand', 45.0, 1200.00],
            ['Pretoria', 'Johannesburg', 60.0, 1600.00],
            ['Pretoria', 'East Rand', 70.0, 1800.00],
            ['Centurion', 'Midrand', 25.0, 750.00],
            ['Centurion', 'Johannesburg', 45.0, 1200.00],
            ['Midrand', 'Johannesburg', 30.0, 900.00],
            ['Johannesburg', 'East Rand', 35.0, 1000.00],
            ['Johannesburg', 'West Rand', 40.0, 1100.00],
            ['Johannesburg', 'Vaal Triangle', 70.0, 1800.00],

            // Long distance
            ['Johannesburg', 'Durban', 580.0, 12500.00],
            ['Johannesburg', 'Cape Town', 1400.0, 28000.00],
            ['Johannesburg', 'Bloemfontein', 400.0, 9500.00],
            ['Johannesburg', 'Port Elizabeth', 1050.0, 21000.00],
            ['Pretoria', 'Polokwane', 300.0, 7500.00],
            ['Pretoria', 'Nelspruit', 330.0, 8200.00],
            ['Pretoria', 'Rustenburg', 120.0, 3500.00],
            ['Durban', 'Pietermaritzburg', 80.0, 2200.00],
            ['Durban', 'Port Elizabeth', 680.0, 14000.00],
            ['Cape Town', 'Port Elizabeth', 770.0, 15500.00],
            ['Cape Town', 'George', 430.0, 10000.00],
        ];

        foreach ($sampleRates as [$origin, $destination, $distance, $price]) {
            $originZone = Zone::where('name', $origin)->first();
            $destZone = Zone::where('name', $destination)->first();

            if (!$originZone || !$destZone) {
                continue;
            }

            ZoneRate::firstOrCreate([
                'origin_zone_id' => $originZone->id,
                'destination_zone_id' => $destZone->id,
                'vehicle_class_id' => $hcv->id,
            ], [
                'distance_km' => $distance,
                'price' => $price,
            ]);
        }
    }
}
