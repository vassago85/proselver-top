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
            // Isuzu SA chassis-cab range (2026 catalogue).
            // Names use the official brochure format (model + GVM in 100kg),
            // grouped by range. Legacy short-form codes retained at the
            // bottom so historical orders with model_name = 'NQR500AC' etc.
            // continue to match cleanly.
            'Isuzu' => [
                // N-Series (Medium)
                'NLR 150',
                'NMR 250',
                'NMR 250 AMT',
                'NPR 275',
                'NPR 300',
                'NPR 300 AMT',
                'NPS 300 4x4',
                'NPR 400',
                'NPR 400 AMT',
                'NPR 400 Crew Cab',
                'NQR 500',
                'NQR 500 AMT',
                // F-Series (Heavy)
                'FRR 500',
                'FRR 500 AMT',
                'FRR 600',
                'FRR 600 AMT',
                'FSR 750',
                'FSR 750 AMT',
                'FSR 750 Crew Cab',
                'FSR 800',
                'FTS 750 4x4',
                'FTR 850',
                'FTR 850 AMT',
                'FTR 850 LWB',
                'FTR 850 Compactor',
                'FVR 900',
                'FVM 1200',
                'FVZ 1400',
                'FVZ 1400 SWB',
                'FVZ 1400 Compactor',
                // FX-Series (Extra Heavy)
                'FXR 17-360',
                'FXZ 26-360',
                'FXZ 26-360 Tipper',
                'FXZ 26-360 Mixer',
                'FYH 33-360',
                'FYH 35-360',
                // Legacy short-form codes (kept for historical compatibility
                // with bookings created before the formal SA list was seeded)
                'NQR500AC',
                'NQR500AMT',
                'FRR600AMT',
                'FTR850AMT',
                'FTR850 BUS',
                'FTS750SWA',
            ],
            // Powerstar SA current range (scraped from everstarindustries.com,
            // who are the local distributor). VX = heavy-duty rigid / off-road,
            // V3 = long-haul double-sleeper tractors, VL = AMT premium long-haul.
            // Names follow the official brochure format (range + GVM-and-power
            // code + axle configuration); SWB/LWB variants kept as distinct
            // models because their tare/payload differs for invoicing.
            'Powerstar' => [
                // VX Range (rigid / tipper / off-road)
                'VX 1627 4x2',
                'VX 1729 4x4',
                'VX 2628 6x4 SWB',
                'VX 2628 6x4 LWB',
                'VX 2635A 6x6',
                'VX 2642 6x4',
                'VX 3335 6x4 SWB',
                'VX 3335 6x4 LWB',
                'VX 4035 8x4',
                'VX 4042A 8x8',
                // V3 Range (long-haul tractors, double-sleeper cab)
                'V3 2646 6x4',
                'V3 2646S AMT 6x4',
                // VL Range (premium AMT long-haul)
                'VL 550 AMT 6x4',
                // Legacy short-form codes (kept for historical compatibility
                // with any test bookings created before the formal SA list).
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
