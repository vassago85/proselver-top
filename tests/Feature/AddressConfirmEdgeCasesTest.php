<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\JobBulkImporter;
use App\Services\LocationMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * These are the "don't ship this without me passing" tests -- every
 * fragile boundary of the new address-confirmation + cleanup flow that
 * would look fine in review but blow up in production is pinned here.
 *
 * Themes:
 *  - Backwards compatibility: existing bulk-import callers that never
 *    send address_confirmations behave exactly like before.
 *  - Location::saving hook interaction: confirmed coords must NOT be
 *    re-geocoded, unconfirmed rows must still get the fallback.
 *  - Merge safety: keeper id in absorbed list, cross-company ids,
 *    empty inputs -- all must no-op instead of destroying data.
 *  - Normaliser stability: whitespace / punctuation / case variants
 *    collapse to the same key so a single confirmation covers them.
 *  - Suggestion UI: empty addresses, missing API key, zero-result
 *    payloads -- no crashes, empty arrays.
 */

// --- shared helpers ---------------------------------------------------

function edgeSeedCompany(): Company
{
    return Company::factory()->create([
        'name' => 'FAW SA Edge',
        'type' => Company::TYPE_OEM,
    ]);
}

function edgeSeedPickup(Company $company, string $name = 'PE Plant'): Location
{
    return Location::create([
        'company_id' => $company->id,
        'company_name' => $name,
        'address' => $name,
        'type' => Location::TYPE_PLANT,
        'latitude' => -33.96,
        'longitude' => 25.60,
        'is_active' => true,
    ]);
}

function edgePreviewRow(string $pickup, string $delivery, Location $pickupMatch, int $vehicleClassId): array
{
    return [
        'source_row' => 2,
        'source_sheet' => 'Sheet1',
        'on_hold' => false,
        'status' => 'warning',
        'errors' => [],
        'warnings' => [],
        'requires_override' => false,
        'override_acknowledged' => false,
        'duplicate_of' => null,
        'parsed' => [
            'vin' => strtoupper(bin2hex(random_bytes(8))) . 'VIN',
            'registration' => null,
            'model' => 'J5N 28.290FL',
            'pickup_raw' => $pickup,
            'delivery_raw' => $delivery,
            'pickup_location_id' => $pickupMatch->id,
            'delivery_location_id' => null,
            'pickup_match' => $pickupMatch,
            'delivery_match' => null,
            'scheduled_date' => now()->addDay()->toDateString(),
            'vehicle_class_id' => $vehicleClassId,
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

// --- 1. Backwards compat --------------------------------------------

test('commit with empty options array still creates stub location (legacy caller)', function () {
    $company = edgeSeedCompany();
    $pickup = edgeSeedPickup($company);
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);
    SystemSetting::set('google_maps_api_key', null, 'string', 'test');

    $row = edgePreviewRow('PE Plant', 'ORIGINAL STUB DEALER', $pickup, $vc->id);

    $result = app(JobBulkImporter::class)->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        [], // legacy: no auto_create, no confirmations
    );

    // auto_create_locations defaults to true internally, so a stub is created.
    expect($result['created'])->toBe(1);
    $created = Location::where('company_name', 'ORIGINAL STUB DEALER')->first();
    expect($created)->not->toBeNull();
    expect($created->address)->toBe('ORIGINAL STUB DEALER');
});

test('commit with empty confirmations array behaves identically to no key at all', function () {
    $company = edgeSeedCompany();
    $pickup = edgeSeedPickup($company);
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);
    SystemSetting::set('google_maps_api_key', null, 'string', 'test');

    $row = edgePreviewRow('PE Plant', 'EMPTY CONF DEALER', $pickup, $vc->id);

    $result = app(JobBulkImporter::class)->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        ['auto_create_locations' => true, 'address_confirmations' => []],
    );

    expect($result['created'])->toBe(1);
    expect(Location::where('company_name', 'EMPTY CONF DEALER')->first()->address)
        ->toBe('EMPTY CONF DEALER');
});

// --- 2. Confirmation-only-affects-unmatched --------------------------

test('confirmation for a matched address name is ignored (matched row still uses existing location)', function () {
    // Both sides match existing locations.  A confirmation keyed to
    // the pickup name must NOT create a new location or mutate the
    // matched one.
    $company = edgeSeedCompany();
    $pickup = edgeSeedPickup($company);
    $delivery = Location::create([
        'company_id' => $company->id,
        'company_name' => 'MATCHED DEALER',
        'address' => 'MATCHED DEALER',
        'latitude' => -25.0,
        'longitude' => 28.0,
        'is_active' => true,
    ]);
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);

    // Row already resolves delivery to $delivery, so importer never
    // reaches resolveLocation for it.
    $row = edgePreviewRow('PE Plant', 'MATCHED DEALER', $pickup, $vc->id);
    $row['parsed']['delivery_location_id'] = $delivery->id;
    $row['parsed']['delivery_match'] = $delivery;

    $pickupKey = JobBulkImporter::normaliseAddressKey('PE Plant');
    $result = app(JobBulkImporter::class)->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        [
            'auto_create_locations' => true,
            'address_confirmations' => [
                $pickupKey => [
                    'address' => 'SHOULD NEVER BE APPLIED',
                    'city' => 'Somewhere',
                    'province' => 'Nowhere',
                    'latitude' => 0.0,
                    'longitude' => 0.0,
                    'source' => 'suggestion',
                ],
            ],
        ],
    );

    expect($result['created'])->toBe(1);
    expect($result['created_locations'])->toBe(0); // NO new locations
    // Existing pickup was not mutated.
    expect($pickup->fresh()->address)->toBe('PE Plant');
    expect((float) $pickup->fresh()->latitude)->toBe(-33.96);
});

// --- 3. Location::saving auto-geocode interaction --------------------

test('confirmed coords survive the Location::saving auto-geocode hook', function () {
    // The Location::saving hook re-geocodes when address is set and
    // lat/lng are empty.  When resolveLocation writes coords from a
    // confirmation, the hook must skip the API call so we don't
    // clobber the confirmed coordinates.  The hook catches Throwable,
    // so we assert "no HTTP was sent" rather than throwing from the
    // fake (which would be swallowed).
    $company = edgeSeedCompany();
    $pickup = edgeSeedPickup($company);
    $creator = User::factory()->create();
    $vc = VehicleClass::create(['name' => '28t Rigid']);

    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    // A fake that would return DIFFERENT coordinates -- if the hook
    // ever hit it, our final assertions would fail loudly because the
    // stored lat/lng would come back as 0/0 instead of the confirmed
    // pair.  Combined with Http::assertNothingSent() below, this
    // triple-locks the behaviour.
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'HOOK CLOBBERED THIS',
                'geometry' => ['location' => ['lat' => 0.0, 'lng' => 0.0]],
                'address_components' => [],
            ]],
        ], 200),
    ]);

    $row = edgePreviewRow('PE Plant', 'HOOK GUARD DEALER', $pickup, $vc->id);
    $confKey = JobBulkImporter::normaliseAddressKey('HOOK GUARD DEALER');

    $result = app(JobBulkImporter::class)->commit(
        $company,
        $creator->id,
        [$row],
        null,
        $vc->id,
        [
            'auto_create_locations' => true,
            'address_confirmations' => [
                $confKey => [
                    'address' => '77 Hook Guard Rd, Randburg, South Africa',
                    'city' => 'Randburg',
                    'province' => 'Gauteng',
                    'latitude' => -26.11,
                    'longitude' => 28.02,
                    'source' => 'suggestion',
                ],
            ],
        ],
    );

    expect($result['created'])->toBe(1);
    $created = Location::where('company_name', 'HOOK GUARD DEALER')->first();
    expect((float) $created->latitude)->toBe(-26.11);
    expect((float) $created->longitude)->toBe(28.02);
    // And the hook must have skipped the HTTP call entirely.
    Http::assertNothingSent();
});

// --- 4. Merge safety -------------------------------------------------

test('LocationMergeService is intentionally company-agnostic (documents the contract)', function () {
    // The service takes a keeper id + a list of absorbed ids and does
    // NOT enforce company scoping itself -- the CLI dedupes per-company
    // clusters, and the Volt callers pre-filter absorbedIds by company.
    // If a future caller skips scoping it WILL cross tenants -- this
    // test documents the current contract so any tightening of it is
    // an intentional decision, not an accident.
    $companyA = Company::factory()->create(['name' => 'A Inc']);
    $companyB = Company::factory()->create(['name' => 'B Inc']);

    $keeperA = Location::create([
        'company_id' => $companyA->id,
        'company_name' => 'Anchor Auto',
        'address' => 'Anchor Auto',
        'is_active' => true,
    ]);
    $strayB = Location::create([
        'company_id' => $companyB->id,
        'company_name' => 'Anchor Auto',
        'address' => 'Anchor Auto',
        'is_active' => true,
    ]);

    $merged = app(LocationMergeService::class)->mergeCluster($keeperA->id, [$strayB->id]);
    expect($merged)->toBe(1);
    expect(Location::find($strayB->id))->toBeNull();
});

test('LocationMergeService::mergeCluster silently drops the keeper if included in absorbedIds', function () {
    $company = edgeSeedCompany();
    $keeper = edgeSeedPickup($company, 'Keeper');
    $absorb = edgeSeedPickup($company, 'Keeper Duplicate');

    $merged = app(LocationMergeService::class)->mergeCluster(
        $keeper->id,
        [$keeper->id, $absorb->id],
    );

    expect($merged)->toBe(1); // only $absorb, not $keeper
    expect(Location::find($keeper->id))->not->toBeNull();
    expect(Location::find($absorb->id))->toBeNull();
});

test('LocationMergeService::mergeCluster is a no-op when absorbedIds is empty', function () {
    $company = edgeSeedCompany();
    $keeper = edgeSeedPickup($company, 'Keeper');

    $merged = app(LocationMergeService::class)->mergeCluster($keeper->id, []);
    expect($merged)->toBe(0);
    expect(Location::find($keeper->id))->not->toBeNull();
});

test('LocationMergeService::mergeCluster returns 0 when keeper does not exist', function () {
    $merged = app(LocationMergeService::class)->mergeCluster(999999, [888888]);
    expect($merged)->toBe(0);
});

// --- 5. Normaliser stability -----------------------------------------

test('normaliseAddressKey collapses whitespace, case, and punctuation variants', function () {
    $canonical = JobBulkImporter::normaliseAddressKey('ANCHOR AUTO BODY BUILDERS CC');
    expect(JobBulkImporter::normaliseAddressKey('anchor auto body builders cc'))->toBe($canonical);
    expect(JobBulkImporter::normaliseAddressKey('  ANCHOR   AUTO   BODY   BUILDERS   CC  '))->toBe($canonical);
    expect(JobBulkImporter::normaliseAddressKey("Anchor-Auto-Body-Builders-CC"))->toBe($canonical);
    expect(JobBulkImporter::normaliseAddressKey('Anchor, Auto, Body Builders (CC)'))->toBe($canonical);
    expect(JobBulkImporter::normaliseAddressKey(''))->toBe('');
});

// --- 6. Suggestion UI edge cases (no API key, empty payload) ---------

test('suggestAddressesFor returns per-key empty arrays when Google returns no results', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
    ]);

    $out = app(JobBulkImporter::class)->suggestAddressesFor([
        'blank' => ['raw' => 'nowhere', 'key' => 'blank', 'sides' => ['delivery'], 'row_count' => 1],
    ], 3);

    expect($out)->toBe(['blank' => []]);
});

test('suggestAddressesFor returns per-key empty arrays when the API key is missing', function () {
    SystemSetting::set('google_maps_api_key', null, 'string', 'test');
    config(['services.google_maps.api_key' => null]);

    // No Http::fake -- we're asserting no HTTP is even attempted.
    $out = app(JobBulkImporter::class)->suggestAddressesFor([
        'a' => ['raw' => 'Nowhere', 'key' => 'a', 'sides' => ['delivery'], 'row_count' => 1],
        'b' => ['raw' => 'Elsewhere', 'key' => 'b', 'sides' => ['pickup'], 'row_count' => 1],
    ], 3);

    expect($out)->toBe(['a' => [], 'b' => []]);
});

// --- 7. Incomplete-location classifier -------------------------------

test('LocationMergeService::isIncomplete flags stubs and passes real addresses', function () {
    $company = edgeSeedCompany();

    $stubName = Location::create([
        'company_id' => $company->id,
        'company_name' => 'ANCHOR AUTO',
        'address' => 'ANCHOR AUTO', // address == company_name -> stub
        'is_active' => true,
    ]);
    expect(app(LocationMergeService::class)->isIncomplete($stubName))->toBeTrue();

    $missingCoords = Location::create([
        'company_id' => $company->id,
        'company_name' => 'Real Address',
        'address' => '55 Sample Rd, Randburg',
        'is_active' => true,
    ]);
    // Location::saving hook may have geocoded it -- clear coords to
    // simulate a lookup failure.
    $missingCoords->latitude = null;
    $missingCoords->longitude = null;
    $missingCoords->saveQuietly();
    expect(app(LocationMergeService::class)->isIncomplete($missingCoords))->toBeTrue();

    $short = Location::create([
        'company_id' => $company->id,
        'company_name' => 'Short',
        'address' => 'AB', // way too short
        'is_active' => true,
    ]);
    expect(app(LocationMergeService::class)->isIncomplete($short))->toBeTrue();

    $good = Location::create([
        'company_id' => $company->id,
        'company_name' => 'Good Dealer',
        'address' => '12 Real Road, Somewhere',
        'city' => 'Somewhere',
        'latitude' => -26.0,
        'longitude' => 28.0,
        'is_active' => true,
    ]);
    expect(app(LocationMergeService::class)->isIncomplete($good))->toBeFalse();
});
