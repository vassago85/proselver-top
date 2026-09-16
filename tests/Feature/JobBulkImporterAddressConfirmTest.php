<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\JobBulkImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The bulk importer's new confirm-address flow: preview surfaces every
 * unmatched pickup / delivery cell so ops can pick a Google suggestion
 * (or type a custom one) before the row lands.  On commit, the
 * confirmed address writes structured city / province / lat / lng onto
 * the new location instead of the raw-name stub that used to break
 * route/toll estimation.
 */
function seedOemCompanyForConfirm(string $pickupName = 'PE Plant'): Company
{
    $company = Company::factory()->create([
        'name' => 'FAW SA Confirm Test',
        'type' => Company::TYPE_OEM,
    ]);
    Location::create([
        'company_id' => $company->id,
        'company_name' => $pickupName,
        'address' => $pickupName,
        'type' => Location::TYPE_PLANT,
        'latitude' => -33.96,
        'longitude' => 25.60,
        'is_active' => true,
    ]);
    return $company;
}

function buildFakePreviewRow(string $pickupRaw, string $deliveryRaw, Location $pickupMatch): array
{
    return [
        'source_row' => 2,
        'source_sheet' => 'February 2026',
        'on_hold' => false,
        'status' => 'warning',
        'errors' => [],
        'warnings' => ["Delivery “{$deliveryRaw}” will be added to the address book"],
        'requires_override' => false,
        'override_acknowledged' => false,
        'duplicate_of' => null,
        'parsed' => [
            'vin' => 'AAK2829FLSB121485',
            'registration' => null,
            'model' => 'J5N 28.290FL',
            'pickup_raw' => $pickupRaw,
            'delivery_raw' => $deliveryRaw,
            'pickup_location_id' => $pickupMatch->id,
            'delivery_location_id' => null,
            'pickup_match' => $pickupMatch,
            'delivery_match' => null,
            'scheduled_date' => now()->addDay()->toDateString(),
            'vehicle_class_id' => null, // filled below
            'is_urgent' => false,
            'executor_type' => Job::EXECUTOR_PROSELVER,
            'driver_user_id' => null,
            'driver_name_raw' => null,
            'third_party_courier_name' => null,
            'third_party_waybill' => null,
            'self_collect_name' => null,
            'self_collect_phone' => null,
            'comments' => null,
        ],
    ];
}

test('collectUnmatchedAddresses surfaces one entry per unique unmatched cell', function () {
    $company = seedOemCompanyForConfirm();
    $pickup = $company->locations()->first();

    $rows = [
        buildFakePreviewRow('PE Plant', 'ANCHOR AUTO BODY BUILDERS CC', $pickup),
        buildFakePreviewRow('PE Plant', 'anchor auto body builders cc', $pickup), // same, differently cased
        buildFakePreviewRow('PE Plant', 'GB Bodies', $pickup),
    ];

    $importer = app(JobBulkImporter::class);
    $out = $importer->collectUnmatchedAddresses($rows);

    // Two unique unmatched delivery names, but the two "ANCHOR AUTO"
    // variants must dedupe onto one key.
    expect($out)->toHaveCount(2);
    $anchorKey = JobBulkImporter::normaliseAddressKey('ANCHOR AUTO BODY BUILDERS CC');
    expect($out)->toHaveKey($anchorKey);
    expect($out[$anchorKey]['row_count'])->toBe(2);
    expect($out[$anchorKey]['sides'])->toBe(['delivery']);
});

test('suggestAddressesFor calls the geocoder once per unique key', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => '55 Sample Rd, Randburg, South Africa',
                'geometry' => ['location' => ['lat' => -26.09, 'lng' => 28.00]],
                'address_components' => [
                    ['long_name' => 'Randburg', 'types' => ['locality']],
                    ['long_name' => 'Gauteng',  'types' => ['administrative_area_level_1']],
                ],
            ]],
        ], 200),
    ]);

    $importer = app(JobBulkImporter::class);
    $out = $importer->suggestAddressesFor([
        'anchor' => ['raw' => 'ANCHOR AUTO', 'key' => 'anchor', 'sides' => ['delivery'], 'row_count' => 1],
        'gb'     => ['raw' => 'GB Bodies',   'key' => 'gb',     'sides' => ['delivery'], 'row_count' => 1],
    ], 3);

    expect($out)->toHaveCount(2);
    expect($out['anchor'][0]['formatted_address'])->toBe('55 Sample Rd, Randburg, South Africa');
    Http::assertSentCount(2);
});

test('commit uses the confirmed address instead of stubbing raw-name-as-address', function () {
    $company = seedOemCompanyForConfirm();
    $pickup = $company->locations()->first();
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);

    $row = buildFakePreviewRow('PE Plant', 'ANCHOR AUTO BODY BUILDERS CC', $pickup);
    $row['parsed']['vehicle_class_id'] = $vc->id;

    $confirmKey = JobBulkImporter::normaliseAddressKey('ANCHOR AUTO BODY BUILDERS CC');

    $importer = app(JobBulkImporter::class);
    $result = $importer->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        [
            'auto_create_locations' => true,
            'address_confirmations' => [
                $confirmKey => [
                    'address' => '12 Confirmed Street, Randburg, South Africa',
                    'city' => 'Randburg',
                    'province' => 'Gauteng',
                    'latitude' => -26.09,
                    'longitude' => 28.00,
                    'source' => 'suggestion',
                ],
            ],
        ],
    );

    expect($result['created'])->toBe(1);
    expect($result['created_locations'])->toBe(1);

    $created = Location::where('company_name', 'ANCHOR AUTO BODY BUILDERS CC')->first();
    expect($created)->not->toBeNull();
    expect($created->address)->toBe('12 Confirmed Street, Randburg, South Africa');
    expect($created->city)->toBe('Randburg');
    expect($created->province)->toBe('Gauteng');
    // The Location::saving hook would have re-geocoded on empty coords;
    // we passed real ones so it must NOT touch them.
    expect((float) $created->latitude)->toBe(-26.09);
    expect((float) $created->longitude)->toBe(28.00);
});

test('commit falls back to raw-name stub when no confirmation exists', function () {
    // Backwards compatibility: any existing caller that doesn't send
    // address_confirmations must keep behaving exactly like before --
    // stub with address = trim($rawName), no coords, saving hook may
    // then attempt to geocode it.
    $company = seedOemCompanyForConfirm();
    $pickup = $company->locations()->first();
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);

    $row = buildFakePreviewRow('PE Plant', 'STUBBED DEALER', $pickup);
    $row['parsed']['vehicle_class_id'] = $vc->id;

    // No API key -> no geocoding attempts.
    SystemSetting::set('google_maps_api_key', null, 'string', 'test');
    config(['services.google_maps.api_key' => null]);

    $importer = app(JobBulkImporter::class);
    $result = $importer->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        ['auto_create_locations' => true],
    );

    expect($result['created'])->toBe(1);
    $created = Location::where('company_name', 'STUBBED DEALER')->first();
    expect($created->address)->toBe('STUBBED DEALER');
    expect($created->latitude)->toBeNull();
});

test('normaliseAddressConfirmation converts panel selections into resolveLocation shape', function () {
    $importer = app(JobBulkImporter::class);
    $suggestions = [
        ['formatted_address' => '55 Sample Rd, Randburg', 'city' => 'Randburg', 'province' => 'Gauteng', 'lat' => -26.09, 'lng' => 28.00],
    ];

    expect($importer->normaliseAddressConfirmation(['choice' => 'kept'], $suggestions))->toBeNull();

    $picked = $importer->normaliseAddressConfirmation(['choice' => 'suggestion', 'index' => 0], $suggestions);
    expect($picked)->toMatchArray([
        'address' => '55 Sample Rd, Randburg',
        'city' => 'Randburg',
        'province' => 'Gauteng',
        'latitude' => -26.09,
        'longitude' => 28.00,
        'source' => 'suggestion',
    ]);

    $custom = $importer->normaliseAddressConfirmation([
        'choice' => 'custom',
        'address' => '99 Custom Rd, Sandton',
        'city' => 'Sandton',
        'province' => 'Gauteng',
        'latitude' => -26.10,
        'longitude' => 28.05,
    ], $suggestions);
    expect($custom['address'])->toBe('99 Custom Rd, Sandton');
    expect($custom['source'])->toBe('custom');

    // Empty custom address -> null (nothing to save).
    expect($importer->normaliseAddressConfirmation(['choice' => 'custom', 'address' => ''], $suggestions))->toBeNull();
});
