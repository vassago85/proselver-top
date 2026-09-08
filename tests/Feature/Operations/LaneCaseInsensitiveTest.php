<?php

/**
 * Top waiting lanes must collapse province casing variants onto a
 * single row. Real dispatchers were seeing "Eastern Cape -> Gauteng"
 * and "Eastern Cape -> GAUTENG" as two separate lanes because a
 * legacy import left uppercase province strings in the locations
 * table.
 *
 * This test pins the invariant: same normalised corridor -> one row,
 * even if the raw `province` casing differs between rows.
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\LaneSummaryQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function laneJob(string $pickupProvince, string $deliveryProvince, string $status = 'received'): Job
{
    // Force unique company names to sidestep the Faker duplicate that
    // trips `companies.normalized_name` when a test creates a lot of
    // rows in a single database run.
    $company = Company::factory()->create(['name' => 'LaneCo ' . Str::upper(Str::random(10))]);
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup ' . $pickupProvince,
        'address'      => 'somewhere',
        'is_active'    => true,
        'province'     => $pickupProvince,
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery ' . $deliveryProvince,
        'address'      => 'somewhere',
        'is_active'    => true,
        'province'     => $deliveryProvince,
    ]);

    return Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => Job::TYPE_TRANSPORT,
        'status'               => $status,
        'status_entered_at'    => now(),
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
    ]);
}

it('collapses casing variants of the same corridor into one lane', function () {
    // Six rows heading Eastern Cape -> Gauteng, but the province
    // strings on the locations rows disagree on casing.
    laneJob('Eastern Cape', 'Gauteng');
    laneJob('EASTERN CAPE', 'GAUTENG');
    laneJob('eastern cape', 'gauteng');
    laneJob('Eastern Cape', 'GAUTENG');
    laneJob('EASTERN CAPE', 'Gauteng');
    laneJob('Eastern Cape', 'Gauteng');

    // Two rows on a genuinely different corridor.
    laneJob('Eastern Cape', 'Western Cape');
    laneJob('Eastern Cape', 'Western Cape');

    $lanes = (new LaneSummaryQuery())->get(new OperationsFilters());

    // Two lanes total — not four.
    expect($lanes)->toHaveCount(2);

    // The Gauteng lane collapses all six rows and displays in the
    // canonical casing.
    $gauteng = $lanes->firstWhere('destination_province', 'Gauteng');
    expect($gauteng)->not->toBeNull();
    expect((int) $gauteng->jobs)->toBe(6);
    expect($gauteng->origin_province)->toBe('Eastern Cape');

    $wc = $lanes->firstWhere('destination_province', 'Western Cape');
    expect($wc)->not->toBeNull();
    expect((int) $wc->jobs)->toBe(2);
});

it('keeps unrelated lanes separate even after canonicalisation', function () {
    laneJob('Gauteng', 'Western Cape');
    laneJob('Western Cape', 'Gauteng');

    $lanes = (new LaneSummaryQuery())->get(new OperationsFilters());

    expect($lanes)->toHaveCount(2);

    // Reverse direction is a different dispatch decision — must not
    // collapse with the forward direction.
    $forward  = $lanes->first(fn ($l) => $l->origin_province === 'Gauteng' && $l->destination_province === 'Western Cape');
    $backward = $lanes->first(fn ($l) => $l->origin_province === 'Western Cape' && $l->destination_province === 'Gauteng');

    expect($forward)->not->toBeNull();
    expect($backward)->not->toBeNull();
});

it('splits the lane row into "awaiting dispatch" and "in flight" so it matches the ops queue lane totals', function () {
    // Three jobs awaiting dispatch (Intake + Ready) on EC -> GP.
    laneJob('Eastern Cape', 'Gauteng', 'received');   // Intake
    laneJob('Eastern Cape', 'Gauteng', 'received');   // Intake
    laneJob('Eastern Cape', 'Gauteng', 'confirmed');  // Ready

    // Ten jobs in flight (Dispatched -> POD-pending) on the same lane.
    // Uses the actual JobStatus values the ops queue counts (see
    // JobStatus::group() for the Ready -> OnRoad mapping).
    for ($i = 0; $i < 3; $i++) {
        laneJob('Eastern Cape', 'Gauteng', 'driver_assigned');   // Dispatched
    }
    for ($i = 0; $i < 5; $i++) {
        laneJob('Eastern Cape', 'Gauteng', 'in_transit');        // OnRoad
    }
    laneJob('Eastern Cape', 'Gauteng', 'delivered');             // POD-pending
    laneJob('Eastern Cape', 'Gauteng', 'delivered');

    // Cancelled + completed must NOT count on either side.
    laneJob('Eastern Cape', 'Gauteng', 'cancelled');
    laneJob('Eastern Cape', 'Gauteng', 'completed');

    $lanes = (new LaneSummaryQuery())->get(new OperationsFilters());
    $ecgp  = $lanes->firstWhere('destination_province', 'Gauteng');

    expect($ecgp)->not->toBeNull();
    expect($ecgp->jobs)->toBe(3);
    expect($ecgp->in_flight)->toBe(10);
    // Widget total must match what the ops queue's lane header shows
    // for the same corridor — the whole point of returning both.
    expect($ecgp->total)->toBe(13);
});

it('still ranks lanes by awaiting-dispatch count, not by in-flight', function () {
    // Lane A: 4 waiting, 0 in flight.
    for ($i = 0; $i < 4; $i++) {
        laneJob('Eastern Cape', 'Gauteng', 'received');
    }
    // Lane B: 1 waiting, 20 in flight — huge in-flight number but
    // only one consolidation opportunity right now. Must NOT displace
    // the busier consolidation lane at the top of the widget.
    laneJob('Eastern Cape', 'Western Cape', 'received');
    for ($i = 0; $i < 20; $i++) {
        laneJob('Eastern Cape', 'Western Cape', 'in_transit');
    }

    $lanes = (new LaneSummaryQuery())->get(new OperationsFilters());

    expect($lanes->first()->destination_province)->toBe('Gauteng');
});
