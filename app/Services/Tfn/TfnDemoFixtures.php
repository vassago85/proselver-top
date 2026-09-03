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
    /**
     * Sub-account balance.
     *
     * Real TFN v3 /api/SubAccountBalance returns exactly two numeric
     * fields alongside the customer number: `AccountBalance` (SIGNED --
     * negative means the sub-account is in arrears) and
     * `AccountAvailableBalance` (available credit).  There is no
     * `CreditLimit` and no `AsOf` in the real payload; consumers must
     * handle their absence.
     *
     * The legacy demo keys (`Balance`, `CreditLimit`, `AvailableCredit`,
     * `AsOf`) are emitted alongside the real ones so any consumer that
     * still reads them keeps working while the migration lands.  The
     * fuel Volt page reads the real keys first with a fallback to the
     * legacy ones (see the KPI block).
     */
    public function balance(): array
    {
        $account = 148_732.55;      // positive: sub-account is in credit
        $available = 101_267.45;    // available credit
        return [
            'CustomerNumber'          => '01/6454',
            // Real v3 fields.
            'AccountBalance'          => $account,
            'AccountAvailableBalance' => $available,
            // Legacy aliases -- remove once no consumer reads them.
            'Balance'                 => $account,
            'AvailableCredit'         => $available,
            'CreditLimit'             => 250_000.00,
            'AsOf'                    => now()->toIso8601String(),
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
                'SubAccountName'   => 'FAW South Africa — drive-away trips',
                'ProductCode'      => 'D0',
                'Litres'           => 12_480,
                'PeriodStart'      => $monthStart->toIso8601String(),
                'PeriodEnd'        => now()->toIso8601String(),
            ],
            [
                'SubAccountNumber' => '10021/ISU',
                'SubAccountName'   => 'Isuzu Motors SA — drive-away trips',
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
     * Shape matches TFN v3 /api/Pricing?productCode=D0 exactly, per
     * the 2026-08-28 QA probe:
     *
     *   [
     *     {
     *       "SupplierName": "Agile Fuel Depot",
     *       "SupplierNumber": "0603",
     *       "Price": 4.90,                 // ex grid fee
     *       "PriceIncludingGrid": 27.90,   // driver-paid R/L
     *       "VolumeDiscount": 0.10,
     *       "PromotionalDiscount": 0.00,
     *       "HasSpecificPricing": false,
     *       "SpecificPricing": [],
     *       "CustomerExternalReference": "",
     *       "ParentExternalReference": ""
     *     },
     *     ...
     *   ]
     *
     * There is NO `ProductCode` field on the row itself -- the caller
     * knows the product because it's in the query string.  We emit
     * legacy aliases (`DepotTitle`, `PricePerLitre`) alongside so
     * consumers that haven't migrated to the real key names keep
     * working; new consumers should read `SupplierName` and
     * `PriceIncludingGrid` directly.
     */
    public function pricing(): array
    {
        // Slight per-request jitter so a Refresh cycle actually moves
        // the numbers -- the meeting audience should see the network
        // respond to a poll, not a static screenshot.
        $jitter = fn (float $base) => round($base + (mt_rand(-8, 8) / 100), 2);

        // Depot titles line up with depots() so the view can cross-
        // reference by SupplierName. If you add a depot there, add
        // it here (or the price list becomes shorter than the depot
        // picker).  Base is the driver-paid `PriceIncludingGrid`.
        $baseByDepot = [
            'Kroonstad Refuel2Save'         => 22.65,
            'Harrismith Truck Stop'         => 22.90,
            'Bloemfontein Ring'             => 23.15,
            'Kempton Park — Nimrod'         => 24.20,
            'Musina Depot'                  => 24.85,
            'Beitbridge Border'             => 25.10,
            'Fuel 1 - Kraaifontein'         => 28.62,
            'Kwa Nokeng Oil - Maun'         => 22.98,
            'Duze Petroleum - Bulawayo'     => 26.40,
            'Shell Benchicks - Palapye'     => 23.55,
        ];

        $rows = [];
        $depotIndex = 1;
        foreach ($baseByDepot as $title => $base) {
            $priceIncludingGrid = $jitter($base);
            // TFN reports the ex-grid product cost separately -- it's
            // typically ~R 23 lower than the pump price on 50ppm diesel
            // (the grid fee covers pump / depot margin).  Numbers here
            // are illustrative; the ex-grid figure isn't consumed by
            // any current view but we emit it for API-shape fidelity.
            $priceExGrid = round($priceIncludingGrid - 23.00, 2);

            $rows[] = [
                // Real TFN v3 fields.
                'SupplierName'              => $title,
                'SupplierNumber'            => sprintf('%04d', 600 + $depotIndex),
                'Price'                     => $priceExGrid,
                'PriceIncludingGrid'        => $priceIncludingGrid,
                'VolumeDiscount'            => 0.10,
                'PromotionalDiscount'       => 0.00,
                'HasSpecificPricing'        => false,
                'SpecificPricing'           => [],
                'CustomerExternalReference' => '',
                'ParentExternalReference'   => '',
                // Legacy aliases -- remove once no consumer reads them.
                'DepotID'                   => sprintf('11111111-0000-0000-0000-%012d', $depotIndex),
                'DepotTitle'                => $title,
                'ProductCode'               => 'D0',
                'Label'                     => 'Diesel (50ppm)',
                'PricePerLitre'             => $priceIncludingGrid,
                'AsOf'                      => now()->toIso8601String(),
            ];
            $depotIndex++;
        }

        // Sort cheapest-first so the ops screen naturally guides
        // planners toward the best-priced depot for the trip window.
        usort($rows, fn ($a, $b) => $a['PriceIncludingGrid'] <=> $b['PriceIncludingGrid']);
        return $rows;
    }

    /**
     * TFN v3 /api/Depots response.  Confirmed shape from the QA probe
     * (2026-08-28): DepotID (UUID), Number (int), Title, GPSLatitude /
     * GPSLongitude (fixed-precision decimal strings on the wire but
     * fine to serialise as float here), MarketingCategory (int), and
     * a nested Products[] array indicating which grades the depot
     * dispenses.  We support at least D0 everywhere; ULP93 / ULP95
     * are only at some depots.  MarketingCategory drives a chip
     * colour on the depot picker (0 = plain, 1 = highlight, 2 = premium).
     */
    public function depots(): array
    {
        $productsD0 = [['ProductCode' => 'D0']];
        $productsAll = [['ProductCode' => 'D0'], ['ProductCode' => 'ULP93'], ['ProductCode' => 'ULP95']];
        return [
            ['DepotID' => '11111111-0000-0000-0000-000000000001', 'Number' => 101, 'Title' => 'Kroonstad Refuel2Save',       'GPSLatitude' => -27.6482, 'GPSLongitude' => 27.2359, 'MarketingCategory' => 0, 'Products' => $productsD0],
            ['DepotID' => '11111111-0000-0000-0000-000000000002', 'Number' => 102, 'Title' => 'Harrismith Truck Stop',       'GPSLatitude' => -28.2734, 'GPSLongitude' => 29.1226, 'MarketingCategory' => 1, 'Products' => $productsAll],
            ['DepotID' => '11111111-0000-0000-0000-000000000003', 'Number' => 103, 'Title' => 'Beitbridge Border',           'GPSLatitude' => -22.2183, 'GPSLongitude' => 30.0016, 'MarketingCategory' => 2, 'Products' => $productsD0],
            ['DepotID' => '11111111-0000-0000-0000-000000000004', 'Number' => 104, 'Title' => 'Kempton Park — Nimrod',       'GPSLatitude' => -26.0964, 'GPSLongitude' => 28.2320, 'MarketingCategory' => 1, 'Products' => $productsAll],
            ['DepotID' => '11111111-0000-0000-0000-000000000005', 'Number' => 105, 'Title' => 'Musina Depot',                'GPSLatitude' => -22.3392, 'GPSLongitude' => 30.0330, 'MarketingCategory' => 0, 'Products' => $productsD0],
            ['DepotID' => '11111111-0000-0000-0000-000000000006', 'Number' => 106, 'Title' => 'Bloemfontein Ring',           'GPSLatitude' => -29.1211, 'GPSLongitude' => 26.2140, 'MarketingCategory' => 1, 'Products' => $productsAll],
            ['DepotID' => '11111111-0000-0000-0000-000000000007', 'Number' => 107, 'Title' => 'Fuel 1 - Kraaifontein',       'GPSLatitude' => -33.8410, 'GPSLongitude' => 18.7240, 'MarketingCategory' => 1, 'Products' => $productsD0],
            // Cross-border — GPS outside the SA box so the pricing
            // panel buckets them under "Out of country".
            ['DepotID' => '11111111-0000-0000-0000-000000000008', 'Number' => 201, 'Title' => 'Kwa Nokeng Oil - Maun',       'GPSLatitude' => -19.9831, 'GPSLongitude' => 23.4167, 'MarketingCategory' => 2, 'Products' => $productsD0],
            ['DepotID' => '11111111-0000-0000-0000-000000000009', 'Number' => 202, 'Title' => 'Duze Petroleum - Bulawayo',   'GPSLatitude' => -20.1500, 'GPSLongitude' => 28.5833, 'MarketingCategory' => 2, 'Products' => $productsD0],
            ['DepotID' => '11111111-0000-0000-0000-000000000010', 'Number' => 203, 'Title' => 'Shell Benchicks - Palapye',   'GPSLatitude' => -22.5500, 'GPSLongitude' => 27.1167, 'MarketingCategory' => 2, 'Products' => $productsD0],
        ];
    }

    /**
     * "Vehicles" here are the units ProSelver is moving drive-away
     * from plant to dealership -- Isuzu / FAW / Powerstar new trucks
     * en-route.
     *
     * IMPORTANT: TFN's real /api/Vehicle response is much thinner than
     * this fixture.  The 2026-08-28 QA probe returned only:
     *
     *   { Registration, FleetNumber, TankSize, Status, ExternalNumber }
     *
     * No VIN, no CustomerName, no Brand/Model.  So in production the
     * `vehicles()` list from TfnClient CANNOT power the fuel-order
     * picker on its own -- the VIN + customer + brand/model + driver
     * context has to come from OUR Job table, joined to TFN's list on
     * Registration (or the driver's trade plate for plateless units).
     * The fuel page's picker rewrite (planned follow-up) will source
     * from Jobs-in-transit for exactly this reason.
     *
     * This fixture keeps the synthetic extras -- VIN / brand / model /
     * customer / driver / trade plate / derived PosRegistration --
     * because the demo screen needs to LOOK like the eventual joined
     * shape.  Every row is authored so:
     *
     *   - `VIN` is the durable identifier a human recognises.
     *   - `Registration` is the vehicle's permanent plate, if any.
     *     Nullable: most new-from-plant units ship without one.
     *   - `DriverName` / `DriverTradePlate` describe the driver on
     *     the trip.  When the vehicle is plateless, TFN receives the
     *     driver's trade plate as VehicleRegistration on every
     *     transaction and order.
     *   - `PosRegistration` is the derived string TFN actually sees.
     *     Kept as a fixed field so tests can assert against it.
     *   - `TankSize` is optional: plant delivery notes don't always
     *     spec the tank, and ops shouldn't guess.
     */
    public function vehicles(): array
    {
        // Every fixture guarantees a POS registration (either the
        // vehicle's own plate or the driver's trade plate).  A truly
        // plateless-and-driverless vehicle is not a scenario TFN can
        // transact against, so we don't model one here -- the fuel
        // page's order-placement guard is what blocks that case in
        // production.
        $rows = [
            // Isuzu Motors SA -- NQR500 series, no permanent plate,
            // driver "Sipho Mahlangu" carries trade plate TPJHB011.
            ['VIN' => 'ACVWR75LTG213611',  'Registration' => null,      'DriverName' => 'Sipho Mahlangu', 'DriverTradePlate' => 'TPJHB011', 'CustomerName' => 'Isuzu Motors SA',  'Brand' => 'Isuzu',     'Model' => 'NQR500AC',       'TankSize' => 200,  'Status' => 3, 'ExternalNumber' => '26082501'],
            // Isuzu Motors SA -- FTR850, has its own permanent plate.
            ['VIN' => 'ACVFTR84T7N219536', 'Registration' => 'ND456GP', 'DriverName' => 'Tebogo Ndlovu',  'DriverTradePlate' => 'TPJHB014', 'CustomerName' => 'Isuzu Motors SA',  'Brand' => 'Isuzu',     'Model' => 'FTR850AMT',      'TankSize' => 200,  'Status' => 3, 'ExternalNumber' => '26082502'],
            // FAW South Africa -- 15.180FL, no plate, trade plate.
            ['VIN' => 'AK1522FLTB011743',  'Registration' => null,      'DriverName' => 'Kagiso Molefe',  'DriverTradePlate' => 'TPKZN005', 'CustomerName' => 'FAW South Africa', 'Brand' => 'FAW',       'Model' => '15.180FL',       'TankSize' => null, 'Status' => 3, 'ExternalNumber' => '26082503'],
            // FAW South Africa -- 15.220FL, no plate, trade plate.
            ['VIN' => 'AK1522FLTB011006',  'Registration' => null,      'DriverName' => 'Andile Zulu',    'DriverTradePlate' => 'TPKZN008', 'CustomerName' => 'FAW South Africa', 'Brand' => 'FAW',       'Model' => '15.220FL',       'TankSize' => 300,  'Status' => 3, 'ExternalNumber' => '26082504'],
            // FAW South Africa -- 8.140FL, has its own permanent plate.
            ['VIN' => 'AK8140FLTB001884',  'Registration' => 'SK12NXGP','DriverName' => 'Lerato Sithole', 'DriverTradePlate' => 'TPDBN007', 'CustomerName' => 'FAW South Africa', 'Brand' => 'FAW',       'Model' => '8.140FL',        'TankSize' => null, 'Status' => 3, 'ExternalNumber' => '26082505'],
            // Powerstar -- no plate, trade plate.
            ['VIN' => 'LGWEF67M4P0567890', 'Registration' => null,      'DriverName' => 'Mpho Dlamini',   'DriverTradePlate' => 'TPGP0023', 'CustomerName' => 'Powerstar',        'Brand' => 'Powerstar', 'Model' => 'FT7 6x4 tractor', 'TankSize' => null, 'Status' => 3, 'ExternalNumber' => '26082506'],
        ];

        // Derive the POS registration once so consumers don't repeat
        // the vehicle-plate-or-trade-plate rule.  The value TFN receives
        // on every order and transaction against this vehicle.
        return array_map(function (array $row) {
            $row['PosRegistration'] = $row['Registration'] ?: $row['DriverTradePlate'];
            return $row;
        }, $rows);
    }

    /**
     * One virtual card per in-transit vehicle. Card lifetime is set
     * to the trip window (2-4 days typical drive-away leg) rather
     * than the 30-day rotation you'd see on a permanent fleet card:
     * when the driver hands the keys over at the dealership, the
     * card is retired.
     *
     * Keyed by VIN so the fuel page can look up the current card for
     * a given vehicle without knowing which plate/trade-plate is
     * being used on this trip.  Each card's `VehicleRegistration`
     * mirrors the POS registration (permanent plate if present,
     * otherwise the driver's trade plate) -- the same string TFN
     * writes onto every transaction against this vehicle.
     *
     * `CurrentVirtualCardNumber` is a short numeric string in the real
     * TFN payload (see the sample Sikelela sent — 6 digits, e.g.
     * "876359").  The old fixture used a masked 16-digit card number;
     * that was wrong.  Kept both fields on the row for now so any
     * legacy consumer still finds a card number without a NPE, but
     * new code should read `CurrentVirtualCardNumber`.
     */
    public function virtualCards(): array
    {
        $now = now();
        $cards = [
            'ACVWR75LTG213611'  => ['card' => '876359', 'start' => $now->copy()->subDay(),      'end' => $now->copy()->addDays(2)],
            'ACVFTR84T7N219536' => ['card' => '876412', 'start' => $now->copy()->subHours(8),   'end' => $now->copy()->addDays(3)],
            'AK1522FLTB011743'  => ['card' => '876528', 'start' => $now->copy()->subDays(2),    'end' => $now->copy()->addDay()],
            'AK1522FLTB011006'  => ['card' => '876601', 'start' => $now->copy()->subDays(3),    'end' => $now->copy()->addHours(6)],
            'AK8140FLTB001884'  => ['card' => '876714', 'start' => $now->copy()->subHours(3),   'end' => $now->copy()->addDays(4)],
            'LGWEF67M4P0567890' => ['card' => '876832', 'start' => $now->copy()->subMinutes(45),'end' => $now->copy()->addDays(3)],
        ];
        $vehicles = collect($this->vehicles())->keyBy('VIN')->all();

        $out = [];
        foreach ($cards as $vin => $meta) {
            $v = $vehicles[$vin] ?? null;
            if (!$v) { continue; }
            $out[$vin] = [
                'CurrentVirtualCardNumber' => $meta['card'],
                // Legacy alias for consumers that still read
                // VirtualCardNumber; kept identical to the new field
                // so removing the alias later is a one-line change.
                'VirtualCardNumber'   => $meta['card'],
                'VIN'                 => $vin,
                'VehicleRegistration' => $v['PosRegistration'],
                'StartDate'           => $meta['start']->toIso8601String(),
                'ExpiryDate'          => $meta['end']->toIso8601String(),
                'IsOneUse'            => false,
            ];
        }
        return $out;
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
        // Vehicles being moved -- keyed by VIN so we can round-trip a
        // transaction back to its trip locally.  TFN's transaction rows
        // do NOT carry VIN; we synthesise one on demo rows so the fuel
        // page's "matched to VIN X" column has something to show, but
        // in production the reconciler only ever matches on the POS
        // registration (permanent plate OR driver trade plate).
        $inTransit = collect($this->vehicles())->keyBy('VIN')->all();
        $vins = array_keys($inTransit);
        // Mostly 50ppm because that's ProSelver's policy; sprinkle in
        // an OS (overnight stay) and W (truck wash) so the screen
        // shows what non-fuel spend looks like on the same feed.
        $products = ['D0', 'D0', 'D0', 'D0', 'D0', 'OS', 'W'];
        $now = now();

        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $product = $products[array_rand($products)];
            $depot = $depots[array_rand($depots)];
            $vin = $vins[array_rand($vins)];
            $veh = $inTransit[$vin] ?? [];
            // POS registration = the vehicle's permanent plate, or the
            // assigned driver's trade plate when the unit ships without
            // one.  Always populated; TFN rejects a transaction with a
            // blank registration.
            $reg = $veh['PosRegistration'] ?? '';
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
            $txId = self::syntheticTransactionId();
            $rows[] = [
                'TransactionID'               => $txId,
                'CustomerNumber'              => '501/12623',
                'CustomerExternalNumber'      => '',
                'ChildCustomerNumber'         => '',
                'ChildCustomerExternalNumber' => '',
                'ProductCode'                 => $product,
                // TFN v3 transactions use TransactionTypeCode "GP" for
                // general purchases; reversals do NOT carry a "REV"
                // code -- they are identified by ReversedTransaction
                // pointing at the original (confirmed 2026-08-31).
                'TransactionTypeCode'         => 'GP',
                'TransactionDate'             => $now->copy()->subMinutes(mt_rand(5, 60 * 20))->toIso8601String(),
                'CapturedDate'                => $now->copy()->subMinutes(mt_rand(5, 60 * 20))->toIso8601String(),
                'SupplierName'                => $depot,
                'SupplierNumber'              => sprintf('%04d', 600 + array_search($depot, $depots, true)),
                'VehicleRegistration'         => $reg,
                'VehicleFleetNumber'          => $veh['ExternalNumber'] ?? '',   // job number, not fleet #
                'VehicleExternalNumber'       => '',
                'Amount'                      => -$amount, // TFN convention: purchases decrease balance
                'VAT'                         => $isFuel ? 0.0 : round($amount * 0.15 / 1.15, 2),
                'ChildAccountAmount'          => 0.0,
                'ChildAccountVAT'             => 0.0,
                'Litres'                      => (float) $litres,
                'Odometer'                    => (float) mt_rand(120, 3_800),
                // Populated when a transaction has drawn against a
                // specific pre-auth Order entry.  Empty here because
                // our fixture orders aren't linked to fixture
                // transactions -- the real linkage is TFN-side.
                'UtilisedOrders'              => [],
                'TransactionReference'        => sprintf('%08d/%03d', mt_rand(1_000_000, 99_999_999), 600 + array_search($depot, $depots, true)),
                'Identifier'                  => sprintf('%06d (%s)', mt_rand(500_000, 999_999), $reg),
                'ReversedTransaction'         => [
                    'TransactionID'        => '00000000-0000-0000-0000-000000000000',
                    'IsFuel'               => false,
                    'TransactionReference' => '',
                ],
                // Synthetic-VIN kept for the fuel screen's "matched
                // trip" column; TFN's real payload does NOT include VIN.
                'VIN'                         => $veh['VIN'] ?? '',
                'CustomerName'                => $veh['CustomerName'] ?? '',
            ];
        }

        // Reversal exemplar.  Real shape: the reversal is a NEW
        // transaction row whose `ReversedTransaction.TransactionID`
        // points at the original (confirmed against the 2026-08-31
        // real QA payload).  When TransactionID there is the null
        // UUID, the row is a plain purchase; anything else marks the
        // row as a reversal.  We clone the last fuel row with negated
        // litres + amount so the reconciler nets it to zero.
        $lastFuel = collect($rows)->last(fn ($r) => (float) ($r['Litres'] ?? 0) > 0);
        if ($lastFuel) {
            $revId = self::syntheticTransactionId();
            $rows[] = array_merge($lastFuel, [
                'TransactionID'         => $revId,
                'TransactionDate'       => $now->copy()->subMinutes(mt_rand(2, 30))->toIso8601String(),
                'CapturedDate'          => $now->copy()->subMinutes(mt_rand(2, 30))->toIso8601String(),
                // Reversals invert the sign: purchase amount was
                // negative (reduced balance), reversal is positive
                // (restores balance).
                'Amount'                => -1 * (float) $lastFuel['Amount'],
                'VAT'                   => -1 * (float) $lastFuel['VAT'],
                'Litres'                => -1 * (float) $lastFuel['Litres'],
                'TransactionReference'  => 'REV/' . $lastFuel['TransactionReference'],
                'ReversedTransaction'   => [
                    'TransactionID'        => $lastFuel['TransactionID'],
                    'IsFuel'               => true,
                    'TransactionReference' => $lastFuel['TransactionReference'],
                ],
            ]);
        }

        // Account top-up (SubAccountPayment) — same feed as pump spend,
        // but no plate / depot / product / litres. Keeps the ops screen
        // honest about how payments look next to diesel fills.
        $rows[] = [
            'TransactionID'         => self::syntheticTransactionId(),
            'CustomerNumber'        => '10021',
            'ProductCode'           => '',
            'TransactionTypeCode'   => 'CC',
            'TransactionDate'       => $now->copy()->subHours(6)->toIso8601String(),
            'CapturedDate'          => $now->copy()->subHours(6)->toIso8601String(),
            'SupplierName'          => '',
            'VehicleRegistration'   => '',
            'VehicleFleetNumber'    => '',
            'VIN'                   => '',
            'CustomerName'          => '',
            'Amount'                => 200_000.00, // credit increases balance
            'VAT'                   => 0,
            'Litres'                => 0,
            'Odometer'              => 0,
            'TransactionReference'  => 'TE' . mt_rand(1000000, 9999999),
            'UtilisedOrders'        => [],
            'Identifier'            => '',
            'ReversedTransaction'   => [
                'TransactionID'        => '00000000-0000-0000-0000-000000000000',
                'IsFuel'               => false,
                'TransactionReference' => '',
            ],
        ];

        // Newest first, matching TFN's default ordering.
        usort($rows, fn ($a, $b) => strcmp($b['CapturedDate'], $a['CapturedDate']));
        return $rows;
    }

    /**
     * TFN transaction IDs are v1 UUIDs (time-based).  A synthetic v4
     * UUID would look wrong to anyone comparing demo screenshots to
     * real ones, but exact wire compatibility isn't required for
     * anything downstream -- we just need something UUID-shaped and
     * unique.  Uses PHP's mt_rand so multiple calls in the same second
     * still produce distinct ids.
     */
    private static function syntheticTransactionId(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            mt_rand(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(),
        );
    }

    /**
     * Pre-authorisations placed by ops for driver refuelling.
     *
     * Shape mirrors the real TFN v3 GET Orders payload (confirmed from
     * the QA sample Sikelela sent 2026-08-28): a list of Orders, each
     * carrying an `Entries[]` array of line items with `Position` (int
     * TFN entry id), `ProductCode`, `VehicleRegistration`, `MaxAllocation`
     * (litre cap), `ValidDateStart` / `ValidDateEnd` and
     * `LinkedTransactions[]` (transactions that have drawn against
     * this entry -- empty until a driver fuels up).
     *
     * The `Reference` on the parent order is the drive-away JOB number
     * so accounts can link the fuel authorisation straight back to the
     * customer trip in TRIDENT.  The fuel Volt page flattens one row
     * per entry for the table, so it works with either shape.
     */
    public function orders(): array
    {
        $now = now();
        $veh = collect($this->vehicles())->keyBy('VIN')->all();
        $customerNumber = '01/6454';

        // Helper: build one Order-with-Entries row using the fixture
        // vehicle's derived POS registration (permanent plate OR
        // driver trade plate).
        $order = function (string $vin, array $entryOverrides, array $orderOverrides = []) use ($customerNumber, $veh, $now) {
            $v = $veh[$vin] ?? [];
            $reg = $v['PosRegistration'] ?? null;
            return array_merge([
                'IsDeleted'                       => false,
                'Planned'                         => false,
                'PlannedReasons'                  => '',
                'OrderNumber'                     => $orderOverrides['OrderNumber'] ?? 'ORD/'.$customerNumber.'/'.random_int(10000, 99999),
                'CustomerNumber'                  => $customerNumber,
                'SubContractorCustomerNumber'     => '',
                'CustomerReference'               => $orderOverrides['CustomerReference'] ?? ($v['ExternalNumber'] ?? ''),
                'EntriesCompleteAfterFirstUse'    => true,
                'MaxAllocation'                   => 0,
                'SubContractorAccepted'           => false,
                'SubContractorDeclined'           => false,
                'StatusTitle'                     => $orderOverrides['StatusTitle'] ?? 'Active - Not started',
                'CustomerName'                    => $v['CustomerName'] ?? '',
                'VIN'                             => $vin,
                'Entries' => [
                    array_merge([
                        'IsDeleted'                 => false,
                        'Position'                  => random_int(10_000_000, 99_999_999),
                        'SupplierNumber'            => 6,
                        'ProductCode'               => 'D0',
                        'VehicleRegistration'       => $reg,
                        'CardNumber'                => '',
                        'DriverCellNumber'          => '',
                        'CurrentVirtualCardNumber'  => (string) random_int(800_000, 899_999),
                        'ValidDateStart'            => $now->copy()->startOfDay()->format('Y-m-d\TH:i:s'),
                        'ValidDateEnd'              => $now->copy()->addDays(2)->endOfDay()->format('Y-m-d\TH:i:s.v'),
                        'CustomerReference'         => '',
                        'LinkedTransactions'        => [],
                    ], $entryOverrides),
                ],
            ], $orderOverrides);
        };

        // A mix of open, driver-trade-plate and permanent-plate orders
        // so consumers can prove both branches of the "vehicle plate
        // OR driver trade plate" rule against realistic-looking rows.
        return [
            $order('ACVWR75LTG213611', [    // Isuzu NQR500 · driver trade plate
                'MaxAllocation' => 180,
            ], [
                'OrderNumber'      => 'ORD/'.$customerNumber.'/00056',
                'StatusTitle'      => 'Active - Not started',
                'CustomerReference'=> '26082501',
            ]),
            $order('AK1522FLTB011743', [    // FAW 15.180FL · driver trade plate
                'MaxAllocation' => 260,
            ], [
                'OrderNumber'      => 'ORD/'.$customerNumber.'/00057',
                'StatusTitle'      => 'Active - Not started',
                'CustomerReference'=> '26082503',
            ]),
            $order('AK8140FLTB001884', [    // FAW 8.140FL · permanent plate SK12NXGP
                'MaxAllocation' => 220,
            ], [
                'OrderNumber'      => 'ORD/'.$customerNumber.'/00058',
                'StatusTitle'      => 'Completed',
                'CustomerReference'=> '26082505',
            ]),
        ];
    }

    /**
     * Convenience for consumers that want one flat row per entry
     * (Order + Entry merged) instead of the nested Orders-with-Entries
     * shape.  Preserves the legacy field names the fuel page's Volt
     * template reads (`Litres`, `PlacedAt`, `ExpiresAt`, `Status`,
     * `EntryNumber`, `Reference`) alongside the real ones from
     * `orders()` so both existing and future consumers work.
     */
    public function ordersFlattened(): array
    {
        $out = [];
        foreach ($this->orders() as $order) {
            foreach ($order['Entries'] ?? [] as $entry) {
                $out[] = [
                    // Real TFN fields
                    'OrderNumber'               => $order['OrderNumber'],
                    'CustomerNumber'            => $order['CustomerNumber'],
                    'CustomerReference'         => $order['CustomerReference'],
                    'CustomerName'              => $order['CustomerName'] ?? '',
                    'StatusTitle'               => $order['StatusTitle'] ?? '',
                    'VIN'                       => $order['VIN'] ?? '',
                    'Position'                  => $entry['Position'],
                    'ProductCode'               => $entry['ProductCode'],
                    'VehicleRegistration'       => $entry['VehicleRegistration'],
                    'CurrentVirtualCardNumber'  => $entry['CurrentVirtualCardNumber'],
                    'MaxAllocation'             => $entry['MaxAllocation'],
                    'ValidDateStart'            => $entry['ValidDateStart'],
                    'ValidDateEnd'              => $entry['ValidDateEnd'],
                    'LinkedTransactions'        => $entry['LinkedTransactions'] ?? [],
                    // Legacy field aliases the current fuel template
                    // still reads.  Kept identical to the real fields
                    // so removing the alias is a one-line change once
                    // the Volt page has migrated across.
                    'EntryNumber'               => (string) $entry['Position'],
                    'Litres'                    => $entry['MaxAllocation'],
                    'PlacedAt'                  => $entry['ValidDateStart'],
                    'ExpiresAt'                 => $entry['ValidDateEnd'],
                    'Status'                    => str_starts_with($order['StatusTitle'] ?? '', 'Active') ? 'Open' : $order['StatusTitle'],
                    'Reference'                 => $order['CustomerReference'],
                    // Amount is intentionally NOT synthesised: TFN's
                    // order object doesn't carry a rand estimate --
                    // the finance figure only appears once transactions
                    // land against the entry.  The Volt column shows
                    // "—" when Amount is missing, which is the honest
                    // pre-utilisation state.
                    'DepotTitle'                => null,
                ];
            }
        }
        return $out;
    }
}
