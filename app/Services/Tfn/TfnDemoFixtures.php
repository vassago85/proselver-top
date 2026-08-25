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
        // reads sensibly regardless of when the page is loaded. All
        // D0 because Proselver runs 50ppm only.
        //
        // Sub-accounts are organised BY CUSTOMER because that's how
        // Proselver's drive-away work is contracted -- litres are
        // split out per OEM so month-end billing can allocate diesel
        // to the right customer trip pool.
        $monthStart = now()->startOfMonth();

        return [
            [
                'SubAccountNumber' => '10021/FAW',
                'SubAccountName'   => 'FAW — drive-away trips',
                'ProductCode'      => 'D0',
                'Litres'           => 12_480,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
            [
                'SubAccountNumber' => '10021/ISU',
                'SubAccountName'   => 'Isuzu — drive-away trips',
                'ProductCode'      => 'D0',
                'Litres'           => 7_312,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
            [
                'SubAccountNumber' => '10021/PWR',
                'SubAccountName'   => 'Powerstar — drive-away trips',
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

    /**
     * "Vehicles" here are the units Proselver is moving drive-away
     * from plant to dealership -- Isuzu / FAW / Powerstar new trucks
     * on trip. TFN issues a virtual card per trip, so the customer,
     * VIN and job number matter more than any Proselver-internal
     * fleet numbering. Registration is the temp/trade plate applied
     * for the drive-away leg; VIN is the durable identifier.
     */
    public function vehicles(): array
    {
        return [
            ['VIN' => 'LFAGH1245P0234567', 'Registration' => 'ND 123 GP', 'CustomerName' => 'FAW',       'Brand' => 'FAW',       'Model' => 'J5 28.290FT',      'TankSize' => 400, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8801'],
            ['VIN' => 'JAANKR85LP0456789', 'Registration' => 'BX 987 GP', 'CustomerName' => 'Isuzu',     'Brand' => 'Isuzu',     'Model' => 'FVR 900 AMT',      'TankSize' => 400, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8802'],
            ['VIN' => 'LGWEF67M4P0567890', 'Registration' => 'CA 552 GP', 'CustomerName' => 'Powerstar', 'Brand' => 'Powerstar', 'Model' => 'FT7 6x4 tractor',  'TankSize' => 500, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8803'],
            ['VIN' => 'LFAJH6360P0234789', 'Registration' => 'HP 774 GP', 'CustomerName' => 'FAW',       'Brand' => 'FAW',       'Model' => 'JH6 P360 6x4',     'TankSize' => 500, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8804'],
            ['VIN' => 'JAAFTR85LP0789012', 'Registration' => 'JX 302 GP', 'CustomerName' => 'Isuzu',     'Brand' => 'Isuzu',     'Model' => 'FTR 850 AMT',      'TankSize' => 600, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8805'],
            ['VIN' => 'LGWEF95L4P0567234', 'Registration' => 'MT 118 GP', 'CustomerName' => 'Powerstar', 'Brand' => 'Powerstar', 'Model' => 'FT9 8x4 rigid',    'TankSize' => 400, 'Status' => 3, 'ExternalNumber' => 'JOB-2026-8806'],
        ];
    }

    /**
     * One virtual card per in-transit vehicle. Card lifetime is set
     * to the trip window (2-4 days typical drive-away leg) rather
     * than the 30-day rotation you'd see on a permanent fleet card:
     * when the driver hands the keys over at the dealership, the
     * card is retired.
     */
    public function virtualCards(): array
    {
        $now = now();
        return [
            'ND 123 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 1234', 'VehicleRegistration' => 'ND 123 GP', 'StartDate' => $now->copy()->subDay()->toIso8601String(),      'ExpiryDate' => $now->copy()->addDays(2)->toIso8601String(),  'IsOneUse' => false],
            'BX 987 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 5642', 'VehicleRegistration' => 'BX 987 GP', 'StartDate' => $now->copy()->subHours(8)->toIso8601String(),   'ExpiryDate' => $now->copy()->addDays(3)->toIso8601String(),  'IsOneUse' => false],
            'CA 552 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 8891', 'VehicleRegistration' => 'CA 552 GP', 'StartDate' => $now->copy()->subDays(2)->toIso8601String(),    'ExpiryDate' => $now->copy()->addDay()->toIso8601String(),    'IsOneUse' => false],
            'HP 774 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 2213', 'VehicleRegistration' => 'HP 774 GP', 'StartDate' => $now->copy()->subDays(3)->toIso8601String(),    'ExpiryDate' => $now->copy()->addHours(6)->toIso8601String(), 'IsOneUse' => false],
            'JX 302 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 7788', 'VehicleRegistration' => 'JX 302 GP', 'StartDate' => $now->copy()->subHours(3)->toIso8601String(),   'ExpiryDate' => $now->copy()->addDays(4)->toIso8601String(),  'IsOneUse' => false],
            'MT 118 GP' => ['VirtualCardNumber' => '5432 09XX XXXX 3345', 'VehicleRegistration' => 'MT 118 GP', 'StartDate' => $now->copy()->subMinutes(45)->toIso8601String(),'ExpiryDate' => $now->copy()->addDays(3)->toIso8601String(),  'IsOneUse' => false],
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
        // Vehicles being moved -- indexed by reg so we can attach VIN
        // and customer name to each transaction row (drive-away model:
        // the vehicle refuelling IS the customer's new truck).
        $inTransit = collect($this->vehicles())->keyBy('Registration')->all();
        $regs = array_keys($inTransit);
        // Mostly 50ppm because that's Proselver's policy; sprinkle in
        // an OS (overnight stay) and W (truck wash) so the screen
        // shows what non-fuel spend looks like on the same feed.
        $products = ['D0', 'D0', 'D0', 'D0', 'D0', 'OS', 'W'];
        $now = now();

        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $product = $products[array_rand($products)];
            $depot = $depots[array_rand($depots)];
            $reg = $regs[array_rand($regs)];
            $veh = $inTransit[$reg] ?? [];
            // Fuel = per-litre @ that depot's rate; non-fuel = flat.
            $isFuel = in_array($product, ['D0', 'D1', 'D3'], true);
            // New in-transit trucks are large, low-odometer -- most
            // are being ferried straight from the plant so mileage
            // is trip-scale, not fleet-scale.
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
                'VehicleRegistration'   => $reg,
                'VehicleFleetNumber'    => $veh['ExternalNumber'] ?? '',   // job number, not fleet #
                'VIN'                   => $veh['VIN'] ?? '',
                'CustomerName'          => $veh['CustomerName'] ?? '',
                'Amount'                => -$amount, // TFN convention: purchases decrease balance
                'VAT'                   => round($amount * 0.15 / 1.15, 2),
                'Litres'                => $litres,
                'Odometer'              => mt_rand(120, 3_800),  // drive-away trucks are new -- odometer is trip-scale
                'TransactionReference'  => 'TFN' . mt_rand(1000000, 9999999),
            ];
        }

        // Newest first, matching TFN's default ordering.
        usort($rows, fn ($a, $b) => strcmp($b['CapturedDate'], $a['CapturedDate']));
        return $rows;
    }

    /**
     * Pre-authorisations placed by ops for driver refuelling.
     * `Reference` is the drive-away JOB number so accounts can link
     * the fuel authorisation straight back to the customer trip in
     * TRIDENT (this is exactly what the "Order fuel" quick-action
     * on the vehicles page pre-fills when ops clicks through).
     */
    public function orders(): array
    {
        $now = now();
        $veh = collect($this->vehicles())->keyBy('Registration')->all();
        $decorate = fn (string $reg, array $extra) => array_merge([
            'VehicleRegistration' => $reg,
            'VIN'                 => $veh[$reg]['VIN'] ?? '',
            'CustomerName'        => $veh[$reg]['CustomerName'] ?? '',
            'ProductCode'         => 'D0',
        ], $extra);

        return [
            $decorate('ND 123 GP', [
                'OrderNumber'  => 'ORD-2026-8801',
                'EntryNumber'  => 'ENT-91201',
                'Litres'       => 380,
                'Amount'       => 8607.00,
                'DepotTitle'   => 'Kroonstad Refuel2Save',
                'PlacedAt'     => $now->copy()->subHours(3)->toIso8601String(),
                'ExpiresAt'    => $now->copy()->addDay()->toIso8601String(),
                'Status'       => 'Open',
                'Reference'    => 'JOB-2026-8801',
            ]),
            $decorate('BX 987 GP', [
                'OrderNumber'  => 'ORD-2026-8800',
                'EntryNumber'  => 'ENT-91198',
                'Litres'       => 300,
                'Amount'       => 6870.00,
                'DepotTitle'   => 'Harrismith Truck Stop',
                'PlacedAt'     => $now->copy()->subHours(6)->toIso8601String(),
                'ExpiresAt'    => $now->copy()->addHours(18)->toIso8601String(),
                'Status'       => 'Open',
                'Reference'    => 'JOB-2026-8802',
            ]),
            $decorate('JX 302 GP', [
                'OrderNumber'  => 'ORD-2026-8799',
                'EntryNumber'  => 'ENT-91190',
                'Litres'       => 500,
                'Amount'       => 12425.00,
                'DepotTitle'   => 'Musina Depot',
                'PlacedAt'     => $now->copy()->subDay()->toIso8601String(),
                'ExpiresAt'    => $now->copy()->subHours(3)->toIso8601String(),
                'Status'       => 'Utilised',
                'Reference'    => 'JOB-2026-8805',
            ]),
        ];
    }
}
