<?php

return [

    /*
    |---------------------------------------------------------------------
    | TFN (TruckFuelNet) Customer API
    |---------------------------------------------------------------------
    |
    | Integration with the diesel network we transact against. Powers the
    | /admin/fuel operations screen (balances, live pricing, pre-auth
    | order placement, recent transactions, virtual card lookup).
    |
    | Docs:
    |   https://app.tfn.co.za/api/
    |   https://api.tfn.co.za/v3/swagger              (production)
    |   https://customerapi.qa.tfn.co.za/v3/swagger   (QA)
    |
    | Credentials are issued by TFN per customer. Until QA credentials are
    | in `.env`, the operations screen runs in demo mode (see
    | App\Services\Tfn\TfnDemoFixtures) so the UI is demonstrable to
    | non-technical stakeholders without touching production.
    |
    */

    // Master switch. If false, the screen still loads but shows a
    // "not configured" banner and no outbound calls are made. Flip to
    // true only after credentials + customer_number are populated.
    'enabled' => env('TFN_ENABLED', false),

    // Prod: https://api.tfn.co.za
    // QA:   https://customerapi.qa.tfn.co.za
    // No trailing slash. Point QA and Prod at different .env files.
    'base_url' => env('TFN_BASE_URL', 'https://api.tfn.co.za'),

    // Every request pins the API version. If we ever need to move to v4
    // this is the single knob to flip -- do NOT hardcode 3 in the client.
    'api_version' => env('TFN_API_VERSION', '3'),

    // OAuth password-grant credentials. `client_ID` is a literal (TFN
    // require it to be "customerAPI") but exposed here in case they
    // ever segregate it per integrator.
    'client_id' => env('TFN_CLIENT_ID', 'customerAPI'),
    'username'  => env('TFN_USERNAME'),
    'password'  => env('TFN_PASSWORD'),

    // The parent customer number under which drivers / vehicles are
    // sub-accounted. Displayed at the top-right of the ops screen so
    // whoever is on shift can confirm they're looking at the right
    // account.
    'customer_number' => env('TFN_CUSTOMER_NUMBER'),

    // Outbound HTTP timeout in seconds. TFN's endpoints are generally
    // fast but /api/Transactions can be slow on large windows.
    'timeout' => (int) env('TFN_TIMEOUT', 15),

    // Refresh the access token this many seconds BEFORE its actual
    // expiry. Buys us head-room so a token doesn't expire mid-request.
    'token_refresh_buffer' => (int) env('TFN_TOKEN_REFRESH_BUFFER', 60),

    // Cache key prefix so TFN entries are easy to isolate in Redis (for
    // debugging / forced invalidation via `redis-cli KEYS tfn:*`).
    'cache_prefix' => env('TFN_CACHE_PREFIX', 'tfn:'),

    // While true (or while credentials are absent), the screen renders
    // realistic fixture data instead of hitting TFN. Kept as an env flag
    // rather than tying it to `enabled` so we can dry-run against QA:
    //   TFN_ENABLED=true + TFN_DEMO_MODE=false = live QA
    //   TFN_ENABLED=false                       = demo fixtures
    'demo_mode' => (bool) env('TFN_DEMO_MODE', true),

    // Product codes ops may pre-authorise on the fuel page.
    // D0 = diesel (litres). OS = overnight stay (nights as MaxAllocation).
    // Petrol (ULP93/ULP95) stays omitted until TFN Director approval.
    'orderable_products' => [
        'D0' => 'Diesel (50ppm)',
        'OS' => 'Overnight stay',
    ],

    // Wider set of product codes that count as "diesel" during
    // reconciliation, in case operational reality diverges from the
    // 50ppm-only policy (a driver refuels 500ppm at a depot that's
    // out of 50ppm, for example) and we still want that spend on the
    // fuel line rather than dropped. Kept as a separate knob from
    // `orderable_products` so ORDERING stays disciplined even when
    // RECONCILIATION is tolerant.
    'reconciliation_products' => [
        'D0',
        'D1',
        'D3',
    ],

    // Full product code -> label map for display (used when rendering
    // transaction rows). Sourced from the TFN v3 swagger. Anything not
    // in here falls through to the raw code.
    'product_labels' => [
        'D0'    => 'Diesel (50ppm)',
        'D1'    => 'Diesel (500ppm)',
        'D3'    => 'Diesel (10ppm)',
        'ULP93' => 'Petrol (ULP93)',
        'ULP95' => 'Petrol (ULP95)',
        'F2'    => 'AdBlue',
        'F'     => 'Oil',
        'PAR'   => 'Parts',
        'WKS'   => 'Workshop',
        'W'     => 'Truck wash',
        'SHO'   => 'Shower',
        'OS'    => 'Overnight stay',
        'L1'    => 'Laundry',
        'CAN'   => 'Canteen',
        'SHP'   => 'Shop',
        'WB'    => 'Weighbridge',
        'IPB'   => 'IP (Bulk)',
        'EW'    => 'eWallet allocation',
    ],
];
