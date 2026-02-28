<?php

namespace Database\Seeders;

use App\Models\TollPlaza;
use Illuminate\Database\Seeder;

class TollPlazaSeeder extends Seeder
{
    public function run(): void
    {
        $plazas = [
            // N1: Beit Bridge – Pretoria
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Baobab', 'plaza_type' => 'mainline', 'latitude' => -23.1068, 'longitude' => 29.5378, 'class_1_fee' => 60.00, 'class_2_fee' => 163.00, 'class_3_fee' => 224.00, 'class_4_fee' => 269.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Capricorn', 'plaza_type' => 'mainline', 'latitude' => -23.4465, 'longitude' => 29.4550, 'class_1_fee' => 62.00, 'class_2_fee' => 170.00, 'class_3_fee' => 198.00, 'class_4_fee' => 248.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Nyl', 'plaza_type' => 'mainline', 'latitude' => -24.1889, 'longitude' => 28.6969, 'class_1_fee' => 77.00, 'class_2_fee' => 144.00, 'class_3_fee' => 174.00, 'class_4_fee' => 233.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Kranskop', 'plaza_type' => 'mainline', 'latitude' => -24.7558, 'longitude' => 28.3750, 'class_1_fee' => 60.00, 'class_2_fee' => 152.00, 'class_3_fee' => 203.00, 'class_4_fee' => 249.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Carousel', 'plaza_type' => 'mainline', 'latitude' => -25.3347, 'longitude' => 28.2583, 'class_1_fee' => 73.00, 'class_2_fee' => 196.00, 'class_3_fee' => 216.00, 'class_4_fee' => 249.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Beit Bridge - Pretoria', 'plaza_name' => 'Pumulani', 'plaza_type' => 'mainline', 'latitude' => -25.5914, 'longitude' => 28.2169, 'class_1_fee' => 16.00, 'class_2_fee' => 40.00, 'class_3_fee' => 46.00, 'class_4_fee' => 55.00, 'effective_from' => '2025-03-01'],
            // N1: Johannesburg – Bloemfontein
            ['road_name' => 'N1: Johannesburg - Bloemfontein', 'plaza_name' => 'Grasmere', 'plaza_type' => 'mainline', 'latitude' => -26.3889, 'longitude' => 27.9889, 'class_1_fee' => 27.00, 'class_2_fee' => 80.00, 'class_3_fee' => 92.00, 'class_4_fee' => 122.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Johannesburg - Bloemfontein', 'plaza_name' => 'Vaal', 'plaza_type' => 'mainline', 'latitude' => -27.0247, 'longitude' => 28.0758, 'class_1_fee' => 89.00, 'class_2_fee' => 167.00, 'class_3_fee' => 200.00, 'class_4_fee' => 267.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N1: Johannesburg - Bloemfontein', 'plaza_name' => 'Verkeerdevlei', 'plaza_type' => 'mainline', 'latitude' => -29.1000, 'longitude' => 27.0167, 'class_1_fee' => 76.00, 'class_2_fee' => 152.00, 'class_3_fee' => 229.00, 'class_4_fee' => 321.00, 'effective_from' => '2025-03-01'],
            // N1: Worcester – Paarl
            ['road_name' => 'N1: Worcester - Paarl', 'plaza_name' => 'Huguenot', 'plaza_type' => 'mainline', 'latitude' => -33.7833, 'longitude' => 19.0333, 'class_1_fee' => 53.00, 'class_2_fee' => 146.00, 'class_3_fee' => 229.00, 'class_4_fee' => 371.00, 'effective_from' => '2025-03-01'],
            // N2: Empangeni – Durban
            ['road_name' => 'N2: Empangeni - Durban', 'plaza_name' => 'Mtunzini', 'plaza_type' => 'mainline', 'latitude' => -28.9497, 'longitude' => 31.7503, 'class_1_fee' => 62.00, 'class_2_fee' => 118.00, 'class_3_fee' => 141.00, 'class_4_fee' => 210.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N2: Empangeni - Durban', 'plaza_name' => 'Mvoti', 'plaza_type' => 'mainline', 'latitude' => -29.3667, 'longitude' => 31.2333, 'class_1_fee' => 18.00, 'class_2_fee' => 50.00, 'class_3_fee' => 67.00, 'class_4_fee' => 101.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N2: Empangeni - Durban', 'plaza_name' => 'Othongathi', 'plaza_type' => 'mainline', 'latitude' => -29.5672, 'longitude' => 31.1172, 'class_1_fee' => 15.00, 'class_2_fee' => 31.00, 'class_3_fee' => 41.00, 'class_4_fee' => 59.00, 'effective_from' => '2025-03-01'],
            // N2: Port Shepstone – Margate
            ['road_name' => 'N2: Port Shepstone - Margate', 'plaza_name' => 'Oribi', 'plaza_type' => 'mainline', 'latitude' => -30.7833, 'longitude' => 30.3833, 'class_1_fee' => 40.00, 'class_2_fee' => 70.00, 'class_3_fee' => 96.00, 'class_4_fee' => 157.00, 'effective_from' => '2025-03-01'],
            // N2: Tsitsikamma
            ['road_name' => 'N2: Tsitsikamma', 'plaza_name' => 'Tsitsikamma', 'plaza_type' => 'mainline', 'latitude' => -33.9700, 'longitude' => 23.8900, 'class_1_fee' => 71.00, 'class_2_fee' => 178.00, 'class_3_fee' => 424.00, 'class_4_fee' => 600.00, 'effective_from' => '2025-03-01'],
            // N3: Heidelberg – Pietermaritzburg
            ['road_name' => 'N3: Heidelberg - Pietermaritzburg', 'plaza_name' => 'De Hoek', 'plaza_type' => 'mainline', 'latitude' => -26.6833, 'longitude' => 28.3667, 'class_1_fee' => 65.00, 'class_2_fee' => 101.00, 'class_3_fee' => 154.00, 'class_4_fee' => 222.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N3: Heidelberg - Pietermaritzburg', 'plaza_name' => 'Wilge', 'plaza_type' => 'mainline', 'latitude' => -27.1833, 'longitude' => 28.6667, 'class_1_fee' => 90.00, 'class_2_fee' => 155.00, 'class_3_fee' => 207.00, 'class_4_fee' => 294.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N3: Heidelberg - Pietermaritzburg', 'plaza_name' => 'Tugela', 'plaza_type' => 'mainline', 'latitude' => -28.5833, 'longitude' => 29.4167, 'class_1_fee' => 96.00, 'class_2_fee' => 159.00, 'class_3_fee' => 251.00, 'class_4_fee' => 347.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N3: Heidelberg - Pietermaritzburg', 'plaza_name' => 'Mooi', 'plaza_type' => 'mainline', 'latitude' => -29.2000, 'longitude' => 30.0167, 'class_1_fee' => 67.00, 'class_2_fee' => 165.00, 'class_3_fee' => 231.00, 'class_4_fee' => 313.00, 'effective_from' => '2025-03-01'],
            // N3: Mariannhill
            ['road_name' => 'N3: Mariannhill', 'plaza_name' => 'Mariannhill', 'plaza_type' => 'mainline', 'latitude' => -29.8500, 'longitude' => 30.8167, 'class_1_fee' => 16.00, 'class_2_fee' => 29.00, 'class_3_fee' => 35.00, 'class_4_fee' => 55.00, 'effective_from' => '2025-03-01'],
            // N4: Lobatse – Pretoria
            ['road_name' => 'N4: Lobatse - Pretoria', 'plaza_name' => 'Swartruggens', 'plaza_type' => 'mainline', 'latitude' => -25.8000, 'longitude' => 26.6833, 'class_1_fee' => 99.00, 'class_2_fee' => 249.00, 'class_3_fee' => 302.00, 'class_4_fee' => 355.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Lobatse - Pretoria', 'plaza_name' => 'Marikana', 'plaza_type' => 'mainline', 'latitude' => -25.7000, 'longitude' => 27.4833, 'class_1_fee' => 29.00, 'class_2_fee' => 70.00, 'class_3_fee' => 79.00, 'class_4_fee' => 93.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Lobatse - Pretoria', 'plaza_name' => 'Brits', 'plaza_type' => 'mainline', 'latitude' => -25.6167, 'longitude' => 27.7833, 'class_1_fee' => 19.50, 'class_2_fee' => 68.00, 'class_3_fee' => 74.00, 'class_4_fee' => 87.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Lobatse - Pretoria', 'plaza_name' => 'Doornpoort', 'plaza_type' => 'mainline', 'latitude' => -25.6667, 'longitude' => 28.1833, 'class_1_fee' => 19.50, 'class_2_fee' => 49.00, 'class_3_fee' => 56.00, 'class_4_fee' => 68.00, 'effective_from' => '2025-03-01'],
            // N4: Hartbeespoort – Pretoria
            ['road_name' => 'N4: Hartbeespoort - Pretoria', 'plaza_name' => 'Pelindaba', 'plaza_type' => 'mainline', 'latitude' => -25.7633, 'longitude' => 27.9567, 'class_1_fee' => 8.00, 'class_2_fee' => 15.00, 'class_3_fee' => 20.00, 'class_4_fee' => 26.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Hartbeespoort - Pretoria', 'plaza_name' => 'Quagga', 'plaza_type' => 'mainline', 'latitude' => -25.7500, 'longitude' => 28.0000, 'class_1_fee' => 6.00, 'class_2_fee' => 11.00, 'class_3_fee' => 15.00, 'class_4_fee' => 20.00, 'effective_from' => '2025-03-01'],
            // N4: Pretoria – Maputo
            ['road_name' => 'N4: Pretoria - Maputo', 'plaza_name' => 'Diamond Hill', 'plaza_type' => 'mainline', 'latitude' => -25.4167, 'longitude' => 28.5833, 'class_1_fee' => 49.00, 'class_2_fee' => 68.00, 'class_3_fee' => 128.00, 'class_4_fee' => 213.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Pretoria - Maputo', 'plaza_name' => 'Middelburg', 'plaza_type' => 'mainline', 'latitude' => -25.7833, 'longitude' => 29.4667, 'class_1_fee' => 81.00, 'class_2_fee' => 176.00, 'class_3_fee' => 268.00, 'class_4_fee' => 352.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Pretoria - Maputo', 'plaza_name' => 'Machado', 'plaza_type' => 'mainline', 'latitude' => -25.6667, 'longitude' => 30.2500, 'class_1_fee' => 122.00, 'class_2_fee' => 338.00, 'class_3_fee' => 493.00, 'class_4_fee' => 704.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N4: Pretoria - Maputo', 'plaza_name' => 'Nkomazi', 'plaza_type' => 'mainline', 'latitude' => -25.4333, 'longitude' => 31.5833, 'class_1_fee' => 92.00, 'class_2_fee' => 187.00, 'class_3_fee' => 271.00, 'class_4_fee' => 391.00, 'effective_from' => '2025-03-01'],
            // N17: Springs – Ermelo
            ['road_name' => 'N17: Springs - Ermelo', 'plaza_name' => 'Gosforth', 'plaza_type' => 'mainline', 'latitude' => -26.2000, 'longitude' => 28.4333, 'class_1_fee' => 16.00, 'class_2_fee' => 44.00, 'class_3_fee' => 48.00, 'class_4_fee' => 67.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N17: Springs - Ermelo', 'plaza_name' => 'Dalpark', 'plaza_type' => 'mainline', 'latitude' => -26.1833, 'longitude' => 28.5333, 'class_1_fee' => 15.00, 'class_2_fee' => 31.00, 'class_3_fee' => 41.00, 'class_4_fee' => 56.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N17: Springs - Ermelo', 'plaza_name' => 'Leandra', 'plaza_type' => 'mainline', 'latitude' => -26.3500, 'longitude' => 29.0333, 'class_1_fee' => 49.00, 'class_2_fee' => 123.00, 'class_3_fee' => 184.00, 'class_4_fee' => 244.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N17: Springs - Ermelo', 'plaza_name' => 'Trichardt', 'plaza_type' => 'mainline', 'latitude' => -26.5000, 'longitude' => 29.2833, 'class_1_fee' => 24.00, 'class_2_fee' => 61.00, 'class_3_fee' => 93.00, 'class_4_fee' => 122.00, 'effective_from' => '2025-03-01'],
            ['road_name' => 'N17: Springs - Ermelo', 'plaza_name' => 'Ermelo', 'plaza_type' => 'mainline', 'latitude' => -26.5333, 'longitude' => 29.9833, 'class_1_fee' => 44.00, 'class_2_fee' => 110.00, 'class_3_fee' => 164.00, 'class_4_fee' => 219.00, 'effective_from' => '2025-03-01'],
            // R30: Kroonstad – Bloemfontein
            ['road_name' => 'R30: Kroonstad - Bloemfontein', 'plaza_name' => 'Brandfort', 'plaza_type' => 'mainline', 'latitude' => -28.7000, 'longitude' => 26.4500, 'class_1_fee' => 61.00, 'class_2_fee' => 121.00, 'class_3_fee' => 182.00, 'class_4_fee' => 256.00, 'effective_from' => '2025-03-01'],
        ];

        foreach ($plazas as $plaza) {
            TollPlaza::firstOrCreate(
                ['plaza_name' => $plaza['plaza_name'], 'plaza_type' => $plaza['plaza_type'], 'road_name' => $plaza['road_name']],
                $plaza
            );
        }
    }
}
