<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * These tests exercise the merge / classification primitives that the
 * `locations:dedupe` command and the address-book "Clean up" UI both
 * depend on.  The command-level integration lives in
 * `LocationsDedupeTest`; here we hit the service directly so the UI
 * paths don't drift from the CLI behaviour.
 */

function makeMergeLoc(Company $company, string $name, string $address, array $extra = []): Location
{
    return Location::create(array_merge([
        'company_id' => $company->id,
        'company_name' => $name,
        'address' => $address,
        'is_active' => true,
    ], $extra));
}

function makeMergeJob(Company $company, Location $pickup, Location $delivery): Job
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

test('findDuplicateClusters groups by normalised (company + name + address)', function () {
    $company = Company::factory()->create();
    $keeper = makeMergeLoc($company, 'Anchor Auto', 'Anchor Auto');
    $dupe = makeMergeLoc($company, 'ANCHOR AUTO', 'anchor auto');
    // A different address should NOT cluster with the two above.
    $solo = makeMergeLoc($company, 'Anchor Auto', '12 Real Street');

    $clusters = app(LocationMergeService::class)->findDuplicateClusters($company->id);

    expect($clusters)->toHaveCount(1);
    $c = $clusters->first();
    expect($c['company_id'])->toBe($company->id);
    $allIds = array_merge([$c['keeper']['id']], collect($c['absorbed'])->pluck('id')->all());
    expect($allIds)->toContain($keeper->id, $dupe->id);
    expect($allIds)->not->toContain($solo->id);
});

test('mergeCluster re-points FKs onto the keeper and soft-deletes absorbed rows', function () {
    $company = Company::factory()->create();
    $keeper = makeMergeLoc($company, 'Keep Me', 'Keep Me');
    $absorb = makeMergeLoc($company, 'keep me', 'keep me');
    $partner = makeMergeLoc($company, 'Partner', 'Partner');

    // Two jobs against the absorbed row -- both must land on the keeper.
    $j1 = makeMergeJob($company, $partner, $absorb);
    $j2 = makeMergeJob($company, $absorb, $partner);

    $merged = app(LocationMergeService::class)->mergeCluster($keeper->id, [$absorb->id]);

    expect($merged)->toBe(1);
    expect(Location::find($absorb->id))->toBeNull();
    expect($j1->fresh()->delivery_location_id)->toBe($keeper->id);
    expect($j2->fresh()->pickup_location_id)->toBe($keeper->id);
});

test('mergeCluster ignores the keeper if it is passed in the absorbed list', function () {
    // Callers occasionally include the keeper by mistake; the service
    // must be tolerant so a UI misclick never soft-deletes the keeper.
    $company = Company::factory()->create();
    $keeper = makeMergeLoc($company, 'K', 'K');
    $abs = makeMergeLoc($company, 'k', 'k');

    $merged = app(LocationMergeService::class)->mergeCluster($keeper->id, [$keeper->id, $abs->id]);

    expect($merged)->toBe(1);
    expect(Location::find($keeper->id))->not->toBeNull();
    expect(Location::find($abs->id))->toBeNull();
});

test('findIncompleteLocations flags stubs missing coordinates or a real street', function () {
    $company = Company::factory()->create();

    // Classic bulk-import stub: address == name, no digit, no comma.
    $stub = makeMergeLoc($company, 'Anchor Auto', 'Anchor Auto');
    // Blank address.
    $blank = makeMergeLoc($company, 'Blank Row', '');
    // Address has a digit but no coordinates -- still incomplete because
    // routing / tolls can't run without lat+lng.
    $noCoords = makeMergeLoc($company, 'No Coords', '12 Real Street, Somewhere');
    // Fully populated -- must NOT be flagged.
    $ok = makeMergeLoc($company, 'Good', '55 Real Street, Randburg', [
        'city' => 'Randburg',
        'province' => 'Gauteng',
        'latitude' => -26.09,
        'longitude' => 28.00,
    ]);

    $service = app(LocationMergeService::class);
    $incomplete = $service->findIncompleteLocations($company->id)->pluck('id')->all();

    expect($incomplete)->toContain($stub->id);
    expect($incomplete)->toContain($blank->id);
    expect($incomplete)->toContain($noCoords->id);
    expect($incomplete)->not->toContain($ok->id);
});

test('findUnusedLocations returns rows with no FK references', function () {
    $company = Company::factory()->create();
    $stranded = makeMergeLoc($company, 'Stranded', 'Stranded');
    $used = makeMergeLoc($company, 'Used', 'Used');
    $partner = makeMergeLoc($company, 'Partner', 'Partner');

    // Partner used as pickup, Used as delivery -- both referenced by
    // the same job, so neither should appear in the unused set.
    makeMergeJob($company, $partner, $used);

    $unused = app(LocationMergeService::class)->findUnusedLocations($company->id)->pluck('id')->all();

    expect($unused)->toContain($stranded->id);
    expect($unused)->not->toContain($partner->id);
    expect($unused)->not->toContain($used->id);
});

test('isIncomplete recognises well-formed street addresses even when the name matches', function () {
    // Genuine "12 Sample Road" landing under the same string as the
    // company name is a valid setup for many small dealerships; we
    // must not flag it as an incomplete stub as long as coords are set.
    $loc = new Location([
        'company_name' => '12 Sample Road',
        'address' => '12 Sample Road',
        'city' => 'Randburg',
        'province' => 'Gauteng',
        'latitude' => -26.09,
        'longitude' => 28.00,
    ]);

    expect(app(LocationMergeService::class)->isIncomplete($loc))->toBeFalse();
});
