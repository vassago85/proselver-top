<?php

/**
 * Petty Cash / Driver Advance -- address gate on save.
 *
 * Covers the four paths of the gate added to encourage correct data
 * entry when a pickup or delivery Location has no coordinates:
 *
 *   1. Save is blocked when the estimator reports missing_coords and
 *      the operator has not ticked the override checkbox.  The whole
 *      point of the gate: no silent save with unusable route data.
 *
 *   2. Save is blocked when the override is ticked but the reason is
 *      too short (< 15 chars).  Short reasons are usually "n/a" style
 *      lazy justifications that make the audit log worthless.
 *
 *   3. Save is blocked when the reason matches the junk regex
 *      (n/a, none, ., ---, test).  Regex catches the "typed 15 dashes
 *      to defeat the length check" evasion.
 *
 *   4. Save succeeds when the override is ticked with a substantive
 *      reason, AND an audit row of type 'advance_address_override_used'
 *      lands with the reason + lookup_attempts payload so the owner
 *      can spot patterns.
 *
 *   5. Picking a Google suggestion writes lat/lng back onto the shared
 *      Location model (fixing every future trip using that address),
 *      re-runs the estimator, and unblocks the save because the status
 *      is no longer 'missing_coords'.  Also emits an
 *      'advance_address_geocoded' audit row.
 */

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner' => 'Owner',
        'operations_controller' => 'Ops Controller',
        'driver' => 'Driver',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $slug === 'driver' ? 'driver' : 'internal']);
    }

    // API key must exist for the in-modal lookup (openAdvancePanel
    // auto-prime -> GeocodingService::suggest -> Http::get to Google).
    // The Location::saving hook ALSO auto-geocodes on write, but
    // gateJobWithMissingCoords() saves via Model::withoutEvents() so
    // no HTTP call fires during setup -- keeping Http::fake stubs
    // reserved for what we actually want the modal to see.
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
});

function gateInternalUser(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');
    return $u;
}

/**
 * Build a job with pickup + delivery Locations that both have empty
 * address text so the Location::saving auto-geocode hook doesn't fire.
 * Both locations start WITHOUT lat/lng -- the exact shape the estimator
 * reports as 'missing_coords'.
 */
function gateJobWithMissingCoords(): Job
{
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $vehicleClass = VehicleClass::create([
        'name' => 'Rigid MCV', 'code' => 'MCV', 'toll_class' => 2, 'is_active' => true,
    ]);

    // Address text is set so the auto-prime in openAdvancePanel has
    // something to feed to Google.  We save via Location::withoutEvents
    // (with a manual uuid because we're also bypassing the creating
    // hook that would normally set it) so the saving auto-geocode does
    // NOT fire -- otherwise it would consume an Http::fake stub in an
    // unpredictable order, since Http::fake stacks callbacks rather
    // than replacing them.
    $pickup = Location::withoutEvents(fn () => Location::create([
        'uuid' => (string) Str::uuid(),
        'company_id' => null,
        'company_name' => 'Sample Plant',
        'address' => 'Sample Plant, Randburg',
        'is_active' => true,
    ]));
    $delivery = Location::withoutEvents(fn () => Location::create([
        'uuid' => (string) Str::uuid(),
        'company_id' => null,
        'company_name' => 'Sample Dealership',
        'address' => 'Sample Dealership, Sandton',
        'is_active' => true,
    ]));

    $creator = User::factory()->create();
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    return Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-GATE-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'driver_user_id' => $driver->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vehicle_class_id' => $vehicleClass->id,
        'vin' => 'GATEVIN' . Str::upper(Str::random(6)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);
}

test('saveAdvance is blocked when addresses are missing coords and no override is ticked', function () {
    $ops = gateInternalUser();
    $job = gateJobWithMissingCoords();
    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openAdvancePanel')
        // Estimator will have reported missing_coords -- assert we
        // recognised that and that the modal opened at all.
        ->assertSet('showAdvancePanel', true)
        // Type an advance total so the rest of the form is otherwise
        // valid -- we want the failure reason to be the address gate,
        // not some unrelated numeric field.
        ->set('advanceAccommodation', 300)
        ->call('saveAdvance')
        ->assertHasErrors(['advanceAddressOverrideReason'])
        // Modal must stay open so ops can react to the error.
        ->assertSet('showAdvancePanel', true);

    // No audit row for the override should exist because the save failed.
    expect(AuditLog::where('action_type', 'advance_address_override_used')->count())->toBe(0);
});

test('saveAdvance is blocked when override is ticked but reason is too short', function () {
    $ops = gateInternalUser();
    $job = gateJobWithMissingCoords();
    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openAdvancePanel')
        ->set('advanceForceAddressOverride', true)
        ->set('advanceAddressOverrideReason', 'nope')  // 4 chars
        ->call('saveAdvance')
        ->assertHasErrors(['advanceAddressOverrideReason'])
        ->assertSet('showAdvancePanel', true);

    expect(AuditLog::where('action_type', 'advance_address_override_used')->count())->toBe(0);
});

test('saveAdvance is blocked when reason is junk (matches banned regex) even at min length', function () {
    // "n/a" alone is 3 chars so also fails the length gate, but a
    // string of 15+ dashes ("---------------") passes length and only
    // the junk-regex catches it -- that's the case worth asserting.
    $ops = gateInternalUser();
    $job = gateJobWithMissingCoords();
    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openAdvancePanel')
        ->set('advanceForceAddressOverride', true)
        ->set('advanceAddressOverrideReason', '---------------')  // 15 dashes
        ->call('saveAdvance')
        ->assertHasErrors(['advanceAddressOverrideReason'])
        ->assertSet('showAdvancePanel', true);
});

test('saveAdvance succeeds with a substantive override reason and writes the audit row', function () {
    $ops = gateInternalUser();
    $job = gateJobWithMissingCoords();
    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openAdvancePanel')
        ->set('advanceAccommodation', 500)   // give the total something concrete
        ->set('advanceForceAddressOverride', true)
        ->set('advanceAddressOverrideReason', 'Customer only supplied a GPS pin, no street address exists yet.')
        ->call('saveAdvance')
        ->assertHasNoErrors()
        ->assertSet('showAdvancePanel', false);

    // The override audit row must exist with the reason preserved.
    $audit = AuditLog::where('action_type', 'advance_address_override_used')
        ->where('entity_type', 'job')
        ->where('entity_id', $job->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->reason)->toBe('Customer only supplied a GPS pin, no street address exists yet.');
    // Payload carries the missing-side flags + attempt count so the
    // owner can filter "always 0 attempts" from "genuinely tried".
    expect($audit->after_json)->toMatchArray([
        'missing_pickup' => true,
        'missing_delivery' => true,
        'pickup_location_id' => $job->pickup_location_id,
        'delivery_location_id' => $job->delivery_location_id,
    ]);
    // lookup_attempts should be >= 2 because openAdvancePanel auto-primes
    // one lookup per missing side.
    expect($audit->after_json['lookup_attempts'])->toBeGreaterThanOrEqual(2);

    // The main advance row also lands as an issued advance.
    expect($job->fresh()->advance_total)->not->toBeNull();
});

test('picking a Google suggestion writes coords onto the shared Location and unblocks save', function () {
    $ops = gateInternalUser();
    $job = gateJobWithMissingCoords();
    $this->actingAs($ops);

    // Override the beforeEach ZERO_RESULTS fake with a real payload so
    // the auto-prime + our follow-up typed lookup return something.
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => '12 Sample Road, Randburg, 2194, South Africa',
                    'place_id' => 'ChIJ_test',
                    'geometry' => ['location' => ['lat' => -26.093, 'lng' => 28.005]],
                    'address_components' => [
                        ['long_name' => 'Randburg', 'short_name' => 'Randburg', 'types' => ['locality']],
                        ['long_name' => 'Gauteng', 'short_name' => 'GP', 'types' => ['administrative_area_level_1']],
                    ],
                ],
            ],
        ], 200),
    ]);

    // Note the pickup's id BEFORE the pick so we can check the DB row
    // was updated (not a fresh Location created).
    $pickupId = $job->pickup_location_id;
    $deliveryId = $job->delivery_location_id;

    $component = Volt::test('admin.orders.show', ['job' => $job])
        ->call('openAdvancePanel');

    // Auto-prime should have populated both suggestion arrays -- the
    // whole "encourage correct data entry" premise is that ops opens
    // the modal and already sees a Google pin waiting to be clicked.
    expect($component->get('advanceAddressPickupSuggestions'))->not->toBeEmpty()
        ->and($component->get('advanceAddressDeliverySuggestions'))->not->toBeEmpty();

    $component->call('pickPickupAddressSuggestion', 0)
              ->call('pickDeliveryAddressSuggestion', 0)
              // Both locations now have coords -> estimator status
              // flips off missing_coords -> save proceeds without any
              // override.
              ->set('advanceAccommodation', 250)
              ->call('saveAdvance')
              ->assertHasNoErrors()
              ->assertSet('showAdvancePanel', false);

    // Coords landed on the shared Location rows (so every future trip
    // using them benefits, which is the whole point of the "fix at
    // source" behaviour).
    $pickup = Location::find($pickupId);
    expect((float) $pickup->latitude)->toBe(-26.093);
    expect((float) $pickup->longitude)->toBe(28.005);
    expect($pickup->address)->toBe('12 Sample Road, Randburg, 2194, South Africa');

    $delivery = Location::find($deliveryId);
    expect((float) $delivery->latitude)->toBe(-26.093);
    expect((float) $delivery->longitude)->toBe(28.005);

    // Two 'advance_address_geocoded' audit rows -- one per side --
    // must have been written so the "who fixed this address" trail is
    // preserved for future forensics.
    expect(AuditLog::where('action_type', 'advance_address_geocoded')->count())->toBe(2);

    // Belt-and-braces: no override audit row was written because we
    // didn't use the override path.
    expect(AuditLog::where('action_type', 'advance_address_override_used')->count())->toBe(0);
});
