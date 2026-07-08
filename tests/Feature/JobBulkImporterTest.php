<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\JobBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

uses(RefreshDatabase::class);

/**
 * Build a tiny FAW-style xlsx in /tmp and return its path. We construct
 * the file on disk (rather than feeding bytes straight to the importer)
 * so the parser exercises the same IOFactory path it uses in production.
 */
function makeFawWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('February 2026');
    $sheet->fromArray([
        ['Model', 'Chassis No.', 'From', "Movement \nOrder Date", 'To', 'Comments'],
        ['J5N 28.290FL', 'AAK2829FLSB121485', 'PE Plant', 46062, 'GB Bodies', 'Urgent'],
        ['8.140FL', 'AAK8140FLSB112025', 'PE Plant', 46062, 'East London', ''],
        ['', '', '', '', '', ''], // blank row — should be dropped
        ['JK6 15.220FD', 'AAK1522FDSB051097', 'PE Plant', 'ON HOLD', 'Tshwane Wheels', 'On hold'],
    ], null, 'A1', true);

    (new XlsxWriter($spreadsheet))->save($path);
}

function makeIsuzuWorkbook(string $path): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('MAY 2026');
    $sheet->fromArray([
        ['Date Order Received', 'Movement Date', 'Driver Name', 'Cell No', 'Model', 'Chassis Number', 'Departure', 'Destination', 'Collection Date', 'Delivery Date', 'Truck Status', 'Comment'],
        ['29-04-2026', '03-05-2026', 'THANDANANI DLAMINI', '078 859 2206', 'FTR850AMT', 'ACVFTR347TN211493', 'VCDC YARD', 'MOTUS ISUZU ZAMBEZI', '03-05-2026', '04-05-2026', 'DELIVERED', 'note here'],
    ], null, 'A1', true);

    (new XlsxWriter($spreadsheet))->save($path);
}

function setUpOemCompany(string $name = 'FAW SA', array $locationNames = []): Company
{
    $company = Company::factory()->create([
        'name' => $name,
        'type' => Company::TYPE_OEM,
    ]);
    foreach ($locationNames as $locName) {
        Location::create([
            'company_id' => $company->id,
            'company_name' => $locName,
            'address' => $locName,
            'type' => Location::TYPE_PLANT,
            // Seed real SA coordinates so these fixtures represent a
            // properly-geocoded address book. Without them every matched
            // row would now surface a "no map coordinates — tolls won't
            // calculate" warning and drop out of the "ready" status.
            'latitude' => -33.9608,
            'longitude' => 25.6022,
            'is_active' => true,
        ]);
    }
    return $company;
}

it('parse() flattens xlsx headers and rows, normalising whitespace and dropping blanks', function () {
    $path = tempnam(sys_get_temp_dir(), 'faw_').'.xlsx';
    makeFawWorkbook($path);

    $importer = app(JobBulkImporter::class);
    $result = $importer->parse($path);

    expect($result['headers'])->toContain('Model', 'Chassis No.', 'From', 'To', 'Comments');
    // Embedded newline in "Movement \nOrder Date" must be collapsed.
    expect($result['headers'])->toContain('Movement Order Date');

    // 3 data rows expected (the blank row is dropped, on-hold row is kept).
    expect($result['rows'])->toHaveCount(3);
    expect($result['rows'][0]['Chassis No.'])->toBe('AAK2829FLSB121485');
    expect($result['rows'][0]['_sheet'])->toBe('February 2026');
    expect($result['rows'][0]['_row'])->toBe(2);

    @unlink($path);
});

it('detectMapping() guesses the FAW headers correctly', function () {
    $importer = app(JobBulkImporter::class);
    $headers = ['Model', 'Chassis No.', 'From', 'To', 'Movement Order Date', 'Comments'];
    $mapping = $importer->detectMapping($headers);

    expect($mapping['vin'])->toBe('Chassis No.');
    expect($mapping['model'])->toBe('Model');
    expect($mapping['pickup'])->toBe('From');
    expect($mapping['delivery'])->toBe('To');
    expect($mapping['movement_date'])->toBe('Movement Order Date');
    expect($mapping['comments'])->toBe('Comments');
});

it('detectMapping() guesses the Isuzu headers correctly', function () {
    $importer = app(JobBulkImporter::class);
    $headers = ['Date Order Received', 'Movement Date', 'Driver Name', 'Cell No', 'Model', 'Chassis Number', 'Departure', 'Destination', 'Collection Date', 'Delivery Date', 'Truck Status', 'Comment'];
    $mapping = $importer->detectMapping($headers);

    expect($mapping['vin'])->toBe('Chassis Number');
    expect($mapping['model'])->toBe('Model');
    expect($mapping['pickup'])->toBe('Departure');
    expect($mapping['delivery'])->toBe('Destination');
    expect($mapping['movement_date'])->toBe('Movement Date');
    expect($mapping['comments'])->toBe('Comment');
});

it('detectMapping() prefers a previously-saved mapping over the heuristic guess', function () {
    $importer = app(JobBulkImporter::class);
    $headers = ['Model', 'Chassis No.', 'From', 'To', 'Movement Order Date', 'Comments', 'Internal Ref'];

    $company = Company::factory()->create([
        'movement_csv_mapping' => [
            'columns' => [
                'comments' => 'Internal Ref',
            ],
        ],
    ]);

    $mapping = $importer->detectMapping($headers, $company);

    expect($mapping['comments'])->toBe('Internal Ref');
    // Untouched fields still get heuristic-guessed:
    expect($mapping['vin'])->toBe('Chassis No.');
});

it('preview() flags rows with on-hold dates and surfaces missing locations as warnings', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    // Tomorrow's date in dd-mm-yyyy form — keeps the fixture forever-relative
    // instead of frozen against an Excel serial that drifts into the past.
    $tomorrow = now()->addDay()->format('d-m-Y');

    $importer = app(JobBulkImporter::class);
    $rows = [
        // ready row — both locations match exactly. Comments deliberately
        // does NOT contain "urgent" so the row is plain ready, not a
        // warning (urgent rows surface as warnings + emergency booking).
        [
            '_sheet' => 'February 2026', '_row' => 2,
            'Model' => 'J5N 28.290FL', 'Chassis No.' => 'AAK2829FLSB121485',
            'From' => 'PE Plant', 'To' => 'GB Bodies',
            'Movement Order Date' => $tomorrow, 'Comments' => 'standard',
        ],
        // delivery isn't in the address book — warn, don't error (auto-create on)
        [
            '_sheet' => 'February 2026', '_row' => 3,
            'Model' => '8.140FL', 'Chassis No.' => 'AAK8140FLSB112025',
            'From' => 'PE Plant', 'To' => 'East London',
            'Movement Order Date' => $tomorrow, 'Comments' => '',
        ],
        // on hold — should be marked skipped unless include_on_hold is set
        [
            '_sheet' => 'February 2026', '_row' => 4,
            'Model' => 'JK6', 'Chassis No.' => 'AAK1522FDSB051097',
            'From' => 'PE Plant', 'To' => 'GB Bodies',
            'Movement Order Date' => 'ON HOLD', 'Comments' => 'On hold',
        ],
    ];

    $mapping = $importer->detectMapping(['Model', 'Chassis No.', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['status'])->toBe('ready');
    expect($preview['rows'][1]['status'])->toBe('warning');
    expect($preview['rows'][1]['warnings'])->toContain('Delivery “East London” will be added to the address book — set a street address on it so route & tolls calculate');
    expect($preview['rows'][2]['status'])->toBe('skipped');
    expect($preview['stats']['ready'])->toBe(2);
    expect($preview['stats']['on_hold'])->toBe(1);
});

it('preview() honours include_on_hold and treats blank dates as warnings', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $importer = app(JobBulkImporter::class);
    $rows = [
        [
            '_sheet' => 'X', '_row' => 2,
            'Chassis No.' => 'AAA', 'From' => 'PE Plant', 'To' => 'GB Bodies',
            'Movement Order Date' => '',
        ],
    ];
    $mapping = $importer->detectMapping(['Chassis No.', 'From', 'To', 'Movement Order Date']);

    $preview = $importer->preview($company, $rows, $mapping, [
        'include_on_hold' => true,
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    // Even with on-hold rows included, a blank date is a warning, not an error.
    expect($preview['rows'][0]['status'])->toBe('warning');
    expect($preview['rows'][0]['warnings'])->toContain('No movement date — defaulted to today');
});

it('preview() flags rows that are missing a vehicle class as errors', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'AAA', 'Model' => 'unrecognisable',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => 46062,
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);

    // No default class, no vehicle classes catalogue → can't infer.
    $preview = $importer->preview($company, $rows, $mapping);

    expect($preview['rows'][0]['status'])->toBe('error');
    expect($preview['rows'][0]['errors'])->toContain('Vehicle class needed — set per row in the preview, or pick a default on the previous step');
});

it('preview() hard-errors rows with a blank origin or destination and commit() never creates them', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant']);
    $user = User::factory()->create();
    $tomorrow = now()->addDay()->format('d-m-Y');

    $importer = app(JobBulkImporter::class);
    $rows = [
        // Blank destination — must be blocked.
        [
            '_sheet' => 'X', '_row' => 2,
            'Chassis No.' => 'BLANKDEST0000001', 'Model' => '8.140FL',
            'From' => 'PE Plant', 'To' => '',
            'Movement Order Date' => $tomorrow, 'Comments' => '',
        ],
        // Whitespace-only origin — must be blocked too (trimmed to empty).
        [
            '_sheet' => 'X', '_row' => 3,
            'Chassis No.' => 'BLANKORIG0000002', 'Model' => '8.140FL',
            'From' => '   ', 'To' => 'GB Bodies',
            'Movement Order Date' => $tomorrow, 'Comments' => '',
        ],
    ];

    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['status'])->toBe('error');
    expect($preview['rows'][0]['errors'])->toContain('Delivery is missing');
    expect($preview['rows'][1]['status'])->toBe('error');
    expect($preview['rows'][1]['errors'])->toContain('Pickup is missing');

    $result = $importer->commit($company, $user->id, $preview['rows'], $brand->id, $vehicleClass->id);

    expect($result['created'])->toBe(0);
    expect(Job::count())->toBe(0);
});

it('links an existing shared body-builder location instead of spawning a city-less duplicate', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $oem = setUpOemCompany('Isuzu SA', ['VCDC Yard']);
    $user = User::factory()->create();

    // A real body builder that owns its own fully-addressed location.
    $bb = Company::factory()->create(['name' => 'Anchor Body Builders', 'type' => Company::TYPE_BODY_BUILDER]);
    $anchor = Location::create([
        'company_id' => $bb->id,
        'company_name' => 'Anchor Body Builders',
        'address' => '12 Fitment Road, Isando',
        'city' => 'Johannesburg',
        'province' => 'Gauteng',
        'latitude' => -26.1420,
        'longitude' => 28.2294,
        'type' => Location::TYPE_BODY_BUILDER,
        'is_active' => true,
    ]);

    $tomorrow = now()->addDay()->format('d-m-Y');
    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'SHAREDMATCH00001', 'Model' => '8.140FL',
        'From' => 'VCDC Yard', 'To' => 'Anchor Body Builders',
        'Movement Order Date' => $tomorrow, 'Comments' => '',
    ]];

    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($oem, $rows, $mapping, ['default_vehicle_class_id' => $vehicleClass->id]);

    // Resolved to the shared record → no "will be added to the address
    // book" warning, so the row is ready rather than a warning.
    expect($preview['rows'][0]['status'])->toBe('ready');
    expect($preview['rows'][0]['parsed']['delivery_match']?->id)->toBe($anchor->id);

    $result = $importer->commit($oem, $user->id, $preview['rows'], $brand->id, $vehicleClass->id);

    expect($result['created'])->toBe(1);
    // No duplicate Anchor location spawned under the importing OEM.
    expect(Location::where('company_name', 'Anchor Body Builders')->count())->toBe(1);

    $job = Job::first();
    expect($job->delivery_location_id)->toBe($anchor->id);
    expect($job->deliveryLocation->city)->toBe('Johannesburg');
});

it('warns (but still imports) when a matched location has no coordinates so tolls will not calculate', function () {
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant']); // PE Plant is geocoded by the helper

    // A book entry that exists but was never geocoded (address = name).
    Location::create([
        'company_id' => $company->id,
        'company_name' => 'GB Bodies',
        'address' => 'GB Bodies',
        'type' => Location::TYPE_BODY_BUILDER,
        'is_active' => true,
        // no latitude / longitude — this is the un-tollable case
    ]);

    $tomorrow = now()->addDay()->format('d-m-Y');
    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Model' => 'J5N 28.290FL', 'Chassis No.' => 'NOCOORDS00000001',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => $tomorrow, 'Comments' => 'standard',
    ]];

    $mapping = $importer->detectMapping(['Model', 'Chassis No.', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, ['default_vehicle_class_id' => $vehicleClass->id]);

    // Matched on both ends, so not an error — but the coordinate-less
    // delivery downgrades it to a warning rather than a clean "ready".
    expect($preview['rows'][0]['status'])->toBe('warning');
    expect($preview['rows'][0]['warnings'])->toContain('Delivery “GB Bodies” has no map coordinates — route & tolls won\'t calculate until its address is fixed');
    // Still counts toward the import ("ready" stat includes warnings).
    expect($preview['stats']['ready'])->toBe(1);
});

it('guessVehicleClassId() infers tonnage from FAW model strings', function () {
    $importer = app(JobBulkImporter::class);

    $classes = collect([
        (object) ['id' => 1, 'name' => '8t Rigid'],
        (object) ['id' => 2, 'name' => '13t Rigid'],
        (object) ['id' => 3, 'name' => '28t Rigid'],
    ]);

    expect($importer->guessVehicleClassId('J5N 28.290FL', $classes))->toBe(3);
    expect($importer->guessVehicleClassId('13.180FL', $classes))->toBe(2);
    expect($importer->guessVehicleClassId('8.140FL', $classes))->toBe(1);
    // Unknown tonnage → no guess (stay null so ops sees the row as needs-attention)
    expect($importer->guessVehicleClassId('99.999FL', $classes))->toBeNull();
    expect($importer->guessVehicleClassId(null, $classes))->toBeNull();
});

it('recalculateRow() flips a row to ready once a vehicle class is supplied', function () {
    $importer = app(JobBulkImporter::class);

    // Simulate the shape preview() emits for an unclassed but otherwise-valid row.
    $row = [
        'source_row' => 2,
        'source_sheet' => 'X',
        'on_hold' => false,
        'errors' => ['Vehicle class needed — set per row in the preview, or pick a default on the previous step'],
        'warnings' => [],
        'status' => 'error',
        'parsed' => [
            'vin' => 'AAA',
            'model' => 'J5N 28.290FL',
            'pickup_raw' => 'PE Plant',
            'delivery_raw' => 'GB Bodies',
            'pickup_match' => null,
            'delivery_match' => null,
            'scheduled_date' => '2026-02-01',
            'comments' => null,
            'vehicle_class_id' => null,
        ],
    ];

    // Initial state: error.
    expect($importer->recalculateRow($row)['status'])->toBe('error');

    $row['parsed']['vehicle_class_id'] = 7;

    $fixed = $importer->recalculateRow($row);
    expect($fixed['status'])->toBe('ready');
    expect($fixed['errors'])->toBe([]);
});

it('commit() creates transport jobs and auto-creates missing locations', function () {
    // Minimum dependencies for BookingService::createTransportBooking.
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $company = setUpOemCompany('FAW SA', ['PE Plant']);
    $user = User::factory()->create();

    $tomorrow = now()->addDay()->format('d-m-Y');

    $importer = app(JobBulkImporter::class);
    $rows = [
        [
            '_sheet' => 'February 2026', '_row' => 2,
            'Chassis No.' => 'AAK2829FLSB121485', 'Model' => 'J5N 28.290FL',
            'From' => 'PE Plant', 'To' => 'GB Bodies',
            'Movement Order Date' => $tomorrow, 'Comments' => 'Standard',
        ],
        // This row's pickup is missing — relies on auto-create
        [
            '_sheet' => 'February 2026', '_row' => 3,
            'Chassis No.' => 'AAK8140FLSB112025', 'Model' => '8.140FL',
            'From' => 'Brand New Yard', 'To' => 'PE Plant',
            'Movement Order Date' => $tomorrow, 'Comments' => '',
        ],
    ];

    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['stats']['ready'])->toBe(2);

    $result = $importer->commit(
        $company,
        $user->id,
        $preview['rows'],
        $brand->id,
        $vehicleClass->id,
    );

    expect($result['created'])->toBe(2);
    expect($result['created_locations'])->toBeGreaterThanOrEqual(2); // GB Bodies + Brand New Yard
    expect($result['errors'])->toBe([]);

    expect(Job::count())->toBe(2);
    $job = Job::first();
    expect($job->vin)->toBe('AAK2829FLSB121485');
    expect($job->company_id)->toBe($company->id);
    expect($job->brand_id)->toBe($brand->id);
    expect($job->vehicle_class_id)->toBe($vehicleClass->id);
    // Bulk-uploaded jobs follow BookingService's normal status logic — for
    // a standard workflow that means STATUS_RECEIVED (not the legacy
    // pending_verification gate, which is reserved for the FAW workflow).
    expect($job->status)->toBe(Job::STATUS_RECEIVED);
});

it('commit() reuses one address-book entry when the same new address repeats across rows', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant']);
    $user = User::factory()->create();
    $tomorrow = now()->addDay()->format('d-m-Y');

    $importer = app(JobBulkImporter::class);
    // Three rows all delivering to the SAME new address (the third with a
    // different case) — previously this created three duplicate locations.
    $rows = [
        ['_sheet' => 'X', '_row' => 2, 'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL', 'From' => 'PE Plant', 'To' => 'New Bodyshop CC', 'Movement Order Date' => $tomorrow],
        ['_sheet' => 'X', '_row' => 3, 'Chassis No.' => 'BBB', 'Model' => 'J5N 28.290FL', 'From' => 'PE Plant', 'To' => 'New Bodyshop CC', 'Movement Order Date' => $tomorrow],
        ['_sheet' => 'X', '_row' => 4, 'Chassis No.' => 'CCC', 'Model' => 'J5N 28.290FL', 'From' => 'PE Plant', 'To' => 'new bodyshop cc', 'Movement Order Date' => $tomorrow],
    ];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, ['default_vehicle_class_id' => $vehicleClass->id]);

    $result = $importer->commit($company, $user->id, $preview['rows'], $brand->id, $vehicleClass->id);

    expect($result['created'])->toBe(3);
    // Exactly ONE new location for the repeated address (not three), and the
    // existing "PE Plant" was reused, so created_locations is 1.
    expect(Location::where('company_id', $company->id)->where('company_name', 'New Bodyshop CC')->count())->toBe(1);
    expect(Location::where('company_id', $company->id)->where('company_name', 'PE Plant')->count())->toBe(1);
    expect($result['created_locations'])->toBe(1);
});

it('commit() reuses an existing book entry matched on its address field, not just its name', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant']);
    $user = User::factory()->create();

    // A book entry whose NAME differs from the uploaded string but whose
    // ADDRESS matches it exactly.
    Location::create([
        'company_id' => $company->id,
        'company_name' => 'Demo Motors',
        'address' => '55 Main Street, Bordeaux, Randburg',
        'type' => Location::TYPE_DEALER,
        'is_active' => true,
    ]);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2, 'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => '55 Main Street, Bordeaux, Randburg',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, ['default_vehicle_class_id' => $vehicleClass->id]);
    $result = $importer->commit($company, $user->id, $preview['rows'], $brand->id, $vehicleClass->id);

    expect($result['created'])->toBe(1);
    // No new location created — the address-field match reused "Demo Motors".
    expect($result['created_locations'])->toBe(0);
    expect(Location::where('company_id', $company->id)->count())->toBe(2); // PE Plant + Demo Motors
});

it('preview() rejects rows with past-dated movements', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        // Yesterday — a stale carry-over from last month's file.
        'Movement Order Date' => now()->subDay()->format('d-m-Y'),
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['status'])->toBe('error');
    expect(implode(' ', $preview['rows'][0]['errors']))
        ->toContain('is in the past');
});

it('preview() flags duplicate VINs within the same spreadsheet', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $importer = app(JobBulkImporter::class);
    $rows = [
        ['_sheet' => 'X', '_row' => 2, 'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL', 'From' => 'PE Plant', 'To' => 'GB Bodies', 'Movement Order Date' => now()->addDay()->format('d-m-Y')],
        ['_sheet' => 'X', '_row' => 5, 'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL', 'From' => 'PE Plant', 'To' => 'GB Bodies', 'Movement Order Date' => now()->addDay()->format('d-m-Y')],
    ];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['status'])->toBe('ready');
    expect($preview['rows'][1]['status'])->toBe('error');
    expect(implode(' ', $preview['rows'][1]['errors']))
        ->toContain('Duplicate VIN in this file');
});

it('preview() flags VINs on an active job as duplicates requiring an override before they import', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $user = User::factory()->create();

    // Seed an in-flight job for this VIN.
    $route = \App\Models\TransportRoute::firstOrCreate([
        'origin_location_id' => $company->locations()->first()->id,
        'destination_location_id' => $company->locations()->skip(1)->first()->id,
        'vehicle_class_id' => $vehicleClass->id,
    ], ['base_price' => 0]);
    Job::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'job_number' => 'TST-001',
        'job_type' => 'transport',
        'status' => Job::STATUS_RECEIVED,
        'company_id' => $company->id,
        'created_by_user_id' => $user->id,
        'transport_route_id' => $route->id,
        'pickup_location_id' => $company->locations()->first()->id,
        'delivery_location_id' => $company->locations()->skip(1)->first()->id,
        'vehicle_class_id' => $vehicleClass->id,
        'brand_id' => $brand->id,
        'vin' => 'IN-FLIGHT-VIN',
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'in-flight-vin',  // case-insensitive match
        'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    // VIN is on an ACTIVE job for the same customer → the row sits in
    // 'duplicate' status (a soft block).  The structured `duplicate_of`
    // payload carries the existing job number + status so the UI can
    // render an explicit override prompt without the operator having
    // to read a warning line.  The warning string is also there so the
    // notes column has something to render even with JS disabled.
    expect($preview['rows'][0]['status'])->toBe('duplicate');
    expect($preview['rows'][0]['requires_override'])->toBeTrue();
    expect($preview['rows'][0]['override_acknowledged'])->toBeFalse();
    expect($preview['rows'][0]['duplicate_of']['job_number'])->toBe('TST-001');
    expect($preview['rows'][0]['duplicate_of']['status'])->toBe(Job::STATUS_RECEIVED);
    expect(implode(' ', $preview['rows'][0]['warnings']))
        ->toContain('DUPLICATE OF ACTIVE ORDER TST-001');

    // Ticking the override flips the row into 'warning' (still imports,
    // still surfaces the warning in the notes column) and bumps the
    // override counter on the aggregate stats so the confirm dialog
    // can call out how many duplicates are about to be created.
    $importer = app(JobBulkImporter::class);
    $acked = $importer->setDuplicateOverride($preview['rows'][0], true);
    expect($acked['status'])->toBe('warning');
    expect($acked['override_acknowledged'])->toBeTrue();
    $stats = $importer->aggregateStats([$acked]);
    expect($stats['ready'])->toBe(1);
    expect($stats['duplicates_blocked'])->toBe(0);
    expect($stats['duplicates_override'])->toBe(1);
});

it('preview() does not block VINs that have already been delivered or completed', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $user = User::factory()->create();

    // Seed a completed job — historical movement, the same physical
    // vehicle is back for another move.
    $route = \App\Models\TransportRoute::firstOrCreate([
        'origin_location_id' => $company->locations()->first()->id,
        'destination_location_id' => $company->locations()->skip(1)->first()->id,
        'vehicle_class_id' => $vehicleClass->id,
    ], ['base_price' => 0]);
    Job::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'job_number' => 'TST-002',
        'job_type' => 'transport',
        'status' => Job::STATUS_COMPLETED,
        'company_id' => $company->id,
        'created_by_user_id' => $user->id,
        'transport_route_id' => $route->id,
        'pickup_location_id' => $company->locations()->first()->id,
        'delivery_location_id' => $company->locations()->skip(1)->first()->id,
        'vehicle_class_id' => $vehicleClass->id,
        'brand_id' => $brand->id,
        'vin' => 'OLD-DELIVERED-VIN',
        'scheduled_date' => now()->subMonth()->toDateString(),
    ]);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'OLD-DELIVERED-VIN',
        'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['status'])->toBe('ready');
});

it('preview() flags rows with "urgent" in the comments column', function () {
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
        'Comments' => 'URGENT please move asap',
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    expect($preview['rows'][0]['parsed']['is_urgent'])->toBeTrue();
    expect($preview['rows'][0]['warnings'])->toContain('Marked URGENT — will create as emergency booking');
});

it('commit() persists urgent rows as emergency bookings', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $vehicleClass = VehicleClass::create(['name' => 'Truck Class 4']);
    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $user = User::factory()->create();

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
        'Comments' => 'urgent — needed today',
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date', 'Comments']);
    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $vehicleClass->id,
    ]);

    $importer->commit($company, $user->id, $preview['rows'], $brand->id, $vehicleClass->id);

    $job = Job::first();
    expect($job->is_emergency)->toBeTrue();
    expect($job->emergency_reason)->toContain('urgent');
});

it('commit() honours per-row vehicle classes over the supplied default', function () {
    $brand = Brand::create(['name' => 'FAW']);
    $defaultClass = VehicleClass::create(['name' => 'Default 8t']);
    $heavyClass = VehicleClass::create(['name' => 'Heavy 28t']);

    $company = setUpOemCompany('FAW SA', ['PE Plant', 'GB Bodies']);
    $user = User::factory()->create();

    $importer = app(JobBulkImporter::class);
    $rows = [[
        '_sheet' => 'X', '_row' => 2,
        'Chassis No.' => 'AAA', 'Model' => 'J5N 28.290FL',
        'From' => 'PE Plant', 'To' => 'GB Bodies',
        'Movement Order Date' => now()->addDay()->format('d-m-Y'),
    ]];
    $mapping = $importer->detectMapping(['Chassis No.', 'Model', 'From', 'To', 'Movement Order Date']);

    $preview = $importer->preview($company, $rows, $mapping, [
        'default_vehicle_class_id' => $defaultClass->id,
    ]);

    // Operator overrides this single row to the heavy class on the
    // preview screen — commit() must persist the per-row choice instead
    // of falling back to the default.
    $preview['rows'][0]['parsed']['vehicle_class_id'] = $heavyClass->id;

    $importer->commit($company, $user->id, $preview['rows'], $brand->id, $defaultClass->id);

    expect(Job::first()->vehicle_class_id)->toBe($heavyClass->id);
});

it('rememberMapping() persists the user choices on the company for next time', function () {
    $company = Company::factory()->create();

    $importer = app(JobBulkImporter::class);
    $importer->rememberMapping(
        $company,
        columns: ['vin' => 'Chassis No.', 'pickup' => 'From', 'delivery' => 'To'],
        defaultBrandId: 7,
        defaultVehicleClassId: 2,
        autoCreateLocations: false,
    );

    $company->refresh();
    expect($company->movement_csv_mapping['columns']['vin'])->toBe('Chassis No.');
    expect($company->movement_csv_mapping['default_brand_id'])->toBe(7);
    expect($company->movement_csv_mapping['default_vehicle_class_id'])->toBe(2);
    expect($company->movement_csv_mapping['auto_create_locations'])->toBeFalse();
});
