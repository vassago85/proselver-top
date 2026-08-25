<?php

namespace App\Services\Tfn;

use Illuminate\Support\Carbon;

/**
 * Realistic-looking fixture data for the Fuel operations screen so it's
 * demonstrable before TFN QA credentials are issued.
 *
 * The shapes here mirror the TFN v3 swagger responses exactly (same
 * PascalCase keys, same product/transaction codes, same ISO 8601 dates)
 * so once we point at live QA we can swap `TfnDemoFixtures` calls for
 * `TfnClient` calls without touching the view.
 *
 * Everything is deterministic-with-drift: dates are anchored to "now" so
 * the screen always looks fresh, but the vehicle registrations, depot
 * names and driver names are stable so screenshots keep matching. Numbers
 * jitter by a small percentage each request so the screen doesn't look
 * like a static mockup during a live demo.
 */
class TfnDemoFixtures
{
    public function balance(): array
    {
        return [
            'CustomerNumber'  => '10021',
            'Balance'         => 148_732.55,
            'CreditLimit'     => 250_000.00,
            'AvailableCredit' => 101_267.45,
            'AsOf'            => now()->toIso8601String(),
        ];
    }

    public function aggregateLitres(): array
    {
        // Snap to the 1st of this month so the "month-to-date" tile
        // reads sensibly regardless of when the page is loaded. All D0
        // because Proselver runs 50ppm only.
        $monthStart = now()->startOfMonth();

        return [
            [
                'SubAccountNumber' => '10021/001',
                'SubAccountName'   => 'ProSelver Fleet — Line-Haul',
                'ProductCode'      => 'D0',
                'Litres'           => 12_480,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
            [
                'SubAccountNumber' => '10021/002',
                'SubAccountName'   => 'ProSelver Fleet — Dedicated',
                'ProductCode'      => 'D0',
                'Litres'           => 7_312,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
            [
                'SubAccountNumber' => '10021/003',
                'SubAccountName'   => 'ProSelver Fleet — Sub-Contract',
                'ProductCode'      => 'D0',
                'Litres'           => 2_940,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Per-depot diesel pricing across the TFN network. Prices vary by
     * station -- rural / high-throughput sites (Kroonstad, Harrismith,
     * Bloemfontein) tend to sit below the metros, while border and
     * remote depots (Beitbridge, Musina) trend higher because of the
     * logistics + smaller volume. Public TFN network page:
     *   https://tfn.co.za/our-network/
     *
     * Numbers below are illustrative but hold to a realistic ~R 22.50
     * to ~R 25.20/L spread for 50ppm at the time of writing.
     *
     * Shape is deliberately kept flat and PascalCase to mirror what
     * we expect from /api/Pricing/{code} once we go live -- the view
     * only cares about ProductCode, DepotTitle and PricePerLitre.
     */
    public function pricing(): array
    {
        // Slight per-request jitter so a Refresh cycle actually moves
        // the numbers -- the meeting audience should see the network
        // respond to a poll, not a static screenshot.
        $jitter = fn (float $base) => round($base + (mt_rand(-8, 8) / 100), 2);
        $asOf = now()->toIso8601String();

        $baseByDepot = [
            // depot title => base R/L for D0 (50ppm)
            'Kroonstad Refuel2Save'    => 22.65,
            'Harrismith Truck Stop'    => 22.90,
            'Bloemfontein Ring'        => 23.15,
            'Kempton Park — Nimrod'    => 24.20,
            'Musina Depot'             => 24.85,
            'Beitbridge Border'        => 25.10,
        ];

        // Depot titles line up with depots() so the view can cross-
        // reference by title. If you add a depot there, add it here
        // (or the price list becomes shorter than the depot picker).
        $rows = [];
        $depotIndex = 1;
        foreach ($baseByDepot as $title => $base) {
            $rows[] = [
                'ProductCode'   => 'D0',
                'Label'         => 'Diesel (50ppm)',
                'DepotID'       => sprintf('11111111-0000-0000-0000-%012d', $depotIndex++),
                'DepotTitle'    => $title,
                'PricePerLitre' => $jitter($base),
                'AsOf'          => $asOf,
            ];
        }

        // Sort cheapest-first so the ops screen naturally guides
        // planners toward the best-priced depot for the trip window.
        usort($rows, fn ($a, $b) => $a['PricePerLitre'] <=> $b['PricePerLitre']);
        return $rows;
    }

    public function depots(): array
    {
        return [
            ['DepotID' => '11111111-0000-0000-0000-000000000001', 'Number' => 101, 'Title' => 'Kroonstad Refuel2Save',       'GPSLatitude' => -27.6482, 'GPSLongitude' => 27.2359, 'MarketingCategory' => 0],
            ['DepotID' => '11111111-0000-0000-0000-000000000002', 'Number' => 102, 'Title' => 'Harrismith Truck Stop',       'GPSLatitude' => -28.2734, 'GPSLongitude' => 29.1226, 'MarketingCategory' => 1],
            ['DepotID' => '11111111-0000-0000-0000-000000000003', 'Number' => 103, 'Title' => 'Beitbridge Border',           'GPSLatitude' => -22.2183, 'GPSLongitude' => 30.0016, 'MarketingCategory' => 2],
            ['DepotID' => '11111111-0000-0000-0000-000000000004', 'Number' => 104, 'Title' => 'Kempton Park — Nimrod',       'GPSLatitude' => -26.0964, 'GPSLongitude' => 28.2320, 'MarketingCategory' => 1],
            ['DepotID' => '11111111-0000-0000-0000-000000000005', 'Number' => 105, 'Title' => 'Musina Depot',                'GPSLatitude' => -22.3392, 'GPSLongitude' => 30.0330, 'MarketingCategory' => 0],
            ['DepotID' => '11111111-0000-0000-0000-000000000006', 'Number' => 106, 'Title' => 'Bloemfontein Ring',           'GPSLatitude' => -29.1211, 'GPSLongitude' => 26.2140, 'MarketingCategory' => 1],
        ];
    }

    public function vehicles(): array
    {
        return [
            ['Registration' => 'ND 123 GP', 'FleetNumber' => 'PSL-045', 'TankSize' => 400, 'Status' => 3, 'ExternalNumber' => 'PSL-045'],
            ['Registration' => 'BX 987 GP', 'FleetNumber' => 'PSL-046', 'TankSize' => 400, 'Status' => 3, 'ExternalNumber' => 'PSL-046'],
            ['Registration' => 'CA 552 GP', 'FleetNumber' => 'PSL-047', 'TankSize' => 500, 'Status' => 3, 'ExternalNumber' => 'PSL-047'],
            ['Registration' => 'HP 774 GP', 'FleetNumber' => 'PSL-048', 'TankSize' => 500, 'Status' => 3, 'ExternalNumber' => 'PSL-048'],
            ['Registration' => 'JX 302 GP', 'FleetNumber' => 'PSL-049', 'TankSize' => 600, 'Status' => 3, 'ExternalNumber' => 'PSL-049'],
            ['Registration' => 'MT 118 GP', 'FleetNumber' => 'PSL-050', 'TankSize' => 400, 'Status' => 2, 'ExternalNumber' => 'PSL-050'],
        ];
    }

    /**
     * One virtual card per vehicle. All rotate on the same 30-day
     * cadence, staggered so the "expires in X days" column has variety.
     */
    public function virtualCards(): array
    {
        $now = now();
        return [
            'ND 123 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 1234', 'VehicleRegistration' => 'ND 123 GP', 'StartDate' => $now->copy()->subDays(3)->toIso8601String(),  'ExpiryDate' => $now->copy()->addDays(27)->toIso8601String(), 'IsOneUse' => false],
            'BX 987 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 5642', 'VehicleRegistration' => 'BX 987 GP', 'StartDate' => $now->copy()->subDays(8)->toIso8601String(),  'ExpiryDate' => $now->copy()->addDays(22)->toIso8601String(), 'IsOneUse' => false],
            'CA 552 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 8891', 'VehicleRegistration' => 'CA 552 GP', 'StartDate' => $now->copy()->subDays(14)->toIso8601String(), 'ExpiryDate' => $now->copy()->addDays(16)->toIso8601String(), 'IsOneUse' => false],
            'HP 774 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 2213', 'VehicleRegistration' => 'HP 774 GP', 'StartDate' => $now->copy()->subDays(20)->toIso8601String(), 'ExpiryDate' => $now->copy()->addDays(10)->toIso8601String(), 'IsOneUse' => false],
            'JX 302 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 7788', 'VehicleRegistration' => 'JX 302 GP', 'StartDate' => $now->copy()->subDays(26)->toIso8601String(), 'ExpiryDate' => $now->copy()->addDays(4)->toIso8601String(),  'IsOneUse' => false],
            'MT 118 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 3345', 'VehicleRegistration' => 'MT 118 GP', 'StartDate' => $now->copy()->subDays(1)->toIso8601String(),  'ExpiryDate' => $now->copy()->addDays(29)->toIso8601String(), 'IsOneUse' => false],
        ];
    }

    public function transactions(): array
    {
        // Pair depot => local price so transaction amounts stack up
        // consistently with the "Live pricing" panel (a driver who
        // filled up at Kroonstad shows a cheaper R/L than one at
        // Beitbridge, which matches what the ops screen advertises).
        $depotPrices = [
            'Kroonstad Refuel2Save'    => 22.65,
            'Harrismith Truck Stop'    => 22.90,
            'Bloemfontein Ring'        => 23.15,
            'Kempton Park — Nimrod'    => 24.20,
            'Musina Depot'             => 24.85,
            'Beitbridge Border'        => 25.10,
        ];
        $depots = array_keys($depotPrices);
        $regs = ['ND 123 GP', 'BX 987 GP', 'CA 552 GP', 'HP 774 GP', 'JX 302 GP'];
        // Mostly 50ppm because that's Proselver's policy; sprinkle in
        // an OS (overnight stay) and W (truck wash) so the screen
        // shows what non-fuel spend looks like on the same feed.
        $products = ['D0', 'D0', 'D0', 'D0', 'D0', 'OS', 'W'];
        $now = now();

        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $product = $products[array_rand($products)];
            $depot = $depots[array_rand($depots)];
            // Fuel = per-litre @ that depot's rate; non-fuel = flat.
            $isFuel = in_array($product, ['D0', 'D1', 'D3'], true);
            $litres = $isFuel ? mt_rand(180, 420) : 0;
            $price = match ($product) {
                'D0'    => $depotPrices[$depot],
                'OS'    => mt_rand(280, 420),
                'W'     => mt_rand(180, 320),
                default => $depotPrices[$depot],
            };
            $amount = $isFuel ? round($litres * $price, 2) : (float) $price;
            $rows[] = [
                'TransactionID'         => sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand()),
                'CustomerNumber'        => '10021',
                'ProductCode'           => $product,
                'TransactionTypeCode'   => '',
                'TransactionDate'       => $now->copy()->subMinutes(mt_rand(5, 60 * 20))->toIso8601String(),
                'CapturedDate'          => $now->copy()->subMinutes(mt_rand(5, 60 * 20))->toIso8601String(),
                'SupplierName'          => $depot,
                'VehicleRegistration'   => $regs[array_rand($regs)],
                'VehicleFleetNumber'    => 'PSL-' . mt_rand(45, 50),
                'Amount'                => -$amount, // TFN convention: purchases decrease balance
                'VAT'                   => round($amount * 0.15 / 1.15, 2),
                'Litres'                => $litres,
                'Odometer'              => mt_rand(180_000, 640_000),
                'TransactionReference'  => 'TFN' . mt_rand(1000000, 9999999),
            ];
        }

        // Newest first, matching TFN's default ordering.
        usort($rows, fn ($a, $b) => strcmp($b['CapturedDate'], $a['CapturedDate']));
        return $rows;
    }

    public function orders(): array
    {
        $now = now();
        return [
            [
                'OrderNumber'         => 'ORD-2026-8801',
                'EntryNumber'         => 'ENT-91201',
                'VehicleRegistration' => 'ND 123 GP',
                'ProductCode'         => 'D0',
                'Litres'              => 380,
                'Amount'              => 9348.00,
                'DepotTitle'          => 'Kroonstad Refuel2Save',
                'PlacedAt'            => $now->copy()->subHours(3)->toIso8601String(),
                'ExpiresAt'           => $now->copy()->addDay()->toIso8601String(),
                'Status'              => 'Open',
                'Reference'           => 'TRIP-JHB-DBN-0812',
            ],
            [
                'OrderNumber'         => 'ORD-2026-8800',
                'EntryNumber'         => 'ENT-91198',
                'VehicleRegistration' => 'BX 987 GP',
                'ProductCode'         => 'D0',
                'Litres'              => 300,
                'Amount'              => 7380.00,
                'DepotTitle'          => 'Harrismith Truck Stop',
                'PlacedAt'            => $now->copy()->subHours(6)->toIso8601String(),
                'ExpiresAt'           => $now->copy()->addHours(18)->toIso8601String(),
                'Status'              => 'Open',
                'Reference'           => 'TRIP-JHB-CT-0798',
            ],
            [
                'OrderNumber'         => 'ORD-2026-8799',
                'EntryNumber'         => 'ENT-91190',
                'VehicleRegistration' => 'JX 302 GP',
                'ProductCode'         => 'D0',
                'Litres'              => 500,
                'Amount'              => 12300.00,
                'DepotTitle'          => 'Musina Depot',
                'PlacedAt'            => $now->copy()->subDay()->toIso8601String(),
                'ExpiresAt'           => $now->copy()->subHours(3)->toIso8601String(),
                'Status'              => 'Utilised',
                'Reference'           => 'TRIP-JHB-HAR-0776',
            ],
        ];
    }
}
