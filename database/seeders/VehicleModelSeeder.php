<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * Initial catalogue of vehicle models per brand.
 *
 * Not exhaustive — Owners/Super Admins can add, rename, and disable
 * models at any time from Admin → Settings → Brands & Models.
 * New brands just need their first model seeded here or added in the UI.
 */
class VehicleModelSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = [
            // FAW SA current model range (scraped from faw.co.za).
            // Mix of freight carriers (FL), truck tractors (FT), tippers (FD),
            // and mixers (FC). Legacy codes kept for historical orders.
            'FAW' => [
                // Freight carriers
                '4.110FL-MT',
                '6.130FL',
                '8.140FL',
                '8.140FL-AMT',
                '15.180FL',
                'JK6 15.220FL',
                'JK6 16.260FL',
                'JK6 16.260FL-AMT',
                'J5N 28.290FL',
                // Truck tractors
                '15.180FT',
                'JK6 15.220FD/FT',
                'J5N 28.380FT',
                'J5N 33.420FT',
                'JH6 28.500FT',
                'JH6 28.550FT',
                'JH6 33.420FT',
                'JH6 33.460FT-AMT',
                'J7 28.550FT/M',
                'J7 28.550FT/P',
                // Tippers
                '15.180FD',
                'JK6 16.240FD/FT',
                'J5N 28.290FD',
                'J5N 33.340FD',
                'J5N 35.340FD',
                // Mixers
                'J5N 33.340FC',
                'J5N 35.340FC',
                // Legacy (retained for historical order compatibility)
                'JK6 15.180FL',
            ],
            'Isuzu' => [
                'NQR500AC',
                'NQR500AMT',
                'FRR600AMT',
                'FTR850AMT',
                'FTR850 BUS',
                'FTS750SWA',
            ],
            'Powerstar' => [
                'VX2628',
                'VX3341',
                'VX2642',
            ],
            'Mercedes-Benz' => [
                'Actros 2645',
                'Axor 2628',
                'Atego 1523',
            ],
            'Volvo' => [
                'FH 440',
                'FH 500',
                'FM 440',
            ],
            'UD Trucks' => [
                'Quester',
                'Croner',
                'Quon',
            ],
            'Hino' => [
                '300 Series',
                '500 Series',
                '700 Series',
            ],
            'Scania' => [
                'R 460',
                'R 500',
                'G 460',
            ],
            'Foton' => [
                'Aumark',
                'Auman',
                'Tunland',
            ],
        ];

        foreach ($catalogue as $brandName => $models) {
            $brand = Brand::where('name', $brandName)->first();
            if (!$brand) {
                continue;
            }

            foreach ($models as $modelName) {
                VehicleModel::firstOrCreate(
                    ['brand_id' => $brand->id, 'name' => $modelName],
                    ['is_active' => true],
                );
            }
        }
    }
}
