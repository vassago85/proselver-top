<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Smoke tests for the locations:dedupe command -- the bulk-importer
 * dedupe fix only stops new dupes; this command cleans up the ones
 * that landed before the fix.  These tests assert the merge picks the
 * right keeper and re-points the FKs without losing data.
 */

function makeLocFor(Company $company, string $name, string $address): Location
{
    return Location::create([
        'company_id' => $company->id,
        'company_name' => $name,
        'address' => $address,
        'is_active' => true,
    ]);
}

function makeJobAt(Company $company, Location $pickup, Location $delivery): Job
{
    $creator = User::factory()->create();
    return Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_RECEIVED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
    ]);
}

test('dedupe merges duplicate locations into the keeper (most-referenced) and soft-deletes the rest', function () {
    $company = Company::factory()->create(['name' => 'WH Midrand']);
    $other   = Company::factory()->create(['name' => 'Other Pickup']);
    $otherLoc = makeLocFor($other, 'Origin', 'Origin');

    // Three identical rows (same normalised key); $a gets 2 refs,
    // $b gets 1 ref, $c gets 0.
    $a = makeLocFor($company, 'WILLIAM HUNT MIDRAND', 'WILLIAM HUNT MIDRAND');
    $b = makeLocFor($company, 'William Hunt Midrand', 'William Hunt Midrand');
    $c = makeLocFor($company, 'william hunt midrand', 'william hunt midrand');

    makeJobAt($company, $otherLoc, $a);
    makeJobAt($company, $otherLoc, $a);
    makeJobAt($company, $otherLoc, $b);

    $this->artisan('locations:dedupe', ['--company' => 'WH Midrand'])
        ->assertExitCode(0);

    // $a should survive (highest refs); $b and $c soft-deleted.
    expect(Location::find($a->id))->not->toBeNull();
    expect(Location::find($b->id))->toBeNull();
    expect(Location::find($c->id))->toBeNull();

    // All three jobs should now point at the keeper.
    expect(Job::where('delivery_location_id', $a->id)->count())->toBe(3);
});

test('dedupe handles composite-unique tables without crashing', function () {
    $company = Company::factory()->create(['name' => 'Comp Co']);
    $other = makeLocFor($company, 'Other', 'Other');

    $a = makeLocFor($company, 'DUP', 'DUP');
    $b = makeLocFor($company, 'dup', 'dup');

    // Both $a and $b have a route_estimates row to the same destination --
    // re-pointing $b's row to $a would collide with $a's row on the
    // composite unique (pickup, delivery).  Merge-or-delete should
    // drop $b's row rather than crash.
    \DB::table('route_estimates')->insert([
        ['pickup_location_id' => $a->id, 'delivery_location_id' => $other->id, 'distance_km' => 50, 'duration_minutes' => 30, 'polyline' => '', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['pickup_location_id' => $b->id, 'delivery_location_id' => $other->id, 'distance_km' => 51, 'duration_minutes' => 31, 'polyline' => '', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('locations:dedupe', ['--company' => 'Comp Co'])
        ->assertExitCode(0);

    expect(\DB::table('route_estimates')->count())->toBe(1);
    expect(\DB::table('route_estimates')->where('pickup_location_id', $a->id)->count())->toBe(1);
});

test('dedupe re-points transport_jobs off absorbed transport_routes before deleting them', function () {
    // Production failure: deleting a colliding transport_routes row while
    // transport_jobs.transport_route_id still pointed at it (FK 23503).
    $company = Company::factory()->create(['name' => 'Route Co']);
    $origin = makeLocFor($company, 'Origin', 'Origin');
    $keeper = makeLocFor($company, 'DEST', 'DEST');
    $dupe = makeLocFor($company, 'dest', 'dest');
    $vehicleClass = \App\Models\VehicleClass::create(['name' => 'Class A']);

    $keeperRouteId = \DB::table('transport_routes')->insertGetId([
        'origin_location_id' => $origin->id,
        'destination_location_id' => $keeper->id,
        'vehicle_class_id' => $vehicleClass->id,
        'base_price' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dupeRouteId = \DB::table('transport_routes')->insertGetId([
        'origin_location_id' => $origin->id,
        'destination_location_id' => $dupe->id,
        'vehicle_class_id' => $vehicleClass->id,
        'base_price' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = makeJobAt($company, $origin, $dupe);
    $job->update(['transport_route_id' => $dupeRouteId]);

    // Give keeper more refs so it wins.
    makeJobAt($company, $origin, $keeper);
    makeJobAt($company, $origin, $keeper);

    $this->artisan('locations:dedupe', ['--company' => 'Route Co'])
        ->assertExitCode(0);

    expect(Location::find($dupe->id))->toBeNull();
    expect(\DB::table('transport_routes')->where('id', $dupeRouteId)->exists())->toBeFalse();
    expect(\DB::table('transport_routes')->where('id', $keeperRouteId)->exists())->toBeTrue();
    expect(Job::find($job->id)->transport_route_id)->toBe($keeperRouteId);
    expect(Job::find($job->id)->delivery_location_id)->toBe($keeper->id);
});

test('dry-run does not change anything', function () {
    $company = Company::factory()->create(['name' => 'Dry Run Co']);
    $a = makeLocFor($company, 'X', 'X');
    $b = makeLocFor($company, 'X', 'X');

    $this->artisan('locations:dedupe', ['--company' => 'Dry Run Co', '--dry-run' => true])
        ->assertExitCode(0);

    expect(Location::whereIn('id', [$a->id, $b->id])->count())->toBe(2);
});

test('purge-unused soft-deletes locations with no FK references', function () {
    $company = Company::factory()->create(['name' => 'Purge Co']);
    $stranded = makeLocFor($company, 'Stranded', 'Stranded');
    $used = makeLocFor($company, 'Used', 'Used');

    makeJobAt($company, $used, $used);

    $this->artisan('locations:dedupe', ['--company' => 'Purge Co', '--purge-unused' => true])
        ->assertExitCode(0);

    expect(Location::find($stranded->id))->toBeNull();
    expect(Location::find($used->id))->not->toBeNull();
});
