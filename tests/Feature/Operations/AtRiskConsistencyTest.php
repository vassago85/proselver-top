<?php

/**
 * At risk / delayed must always equal the distinct union of the six
 * exception buckets.
 *
 * The old dashboard fired a seventh independent count() for the "At
 * risk" tile, which drifted from the sum of the six as soon as one of
 * the sub-queries used a slightly different predicate. Ops would see
 * "At risk: 3" and then click through to a bucket list that added up
 * to 7. This test pins the invariant so a future refactor cannot
 * reintroduce the drift.
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\ExceptionCountsQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'dispatcher'], ['name' => 'Dispatcher', 'tier' => 'internal']);
});

function atRiskJob(string $status, ?\Carbon\Carbon $enteredAt = null, array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup', 'address' => 'Pickup', 'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery', 'address' => 'Delivery', 'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => Job::TYPE_TRANSPORT,
        'status'               => $status,
        'status_entered_at'    => $enteredAt ?? now(),
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
    ], $overrides));
}

it('at_risk equals the sum of all six buckets when they are populated', function () {
    // 2 awaiting, 1 confirmation_issue, 1 ready_no_driver, 1 dispatched_not_collected,
    // 1 long_in_transit, 1 pod_pending. Total => 7.
    atRiskJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3));
    atRiskJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3));
    atRiskJob(Job::STATUS_CONFIRMATION_ISSUE, now()->subMinutes(1));
    atRiskJob(Job::STATUS_CONFIRMED, now()->subDays(2));
    atRiskJob(Job::STATUS_DRIVER_ASSIGNED, now()->subDays(3));
    atRiskJob(Job::STATUS_IN_TRANSIT, now()->subDays(4));
    atRiskJob(Job::STATUS_DELIVERED, now()->subDays(4), [
        'delivered_at' => now()->subDays(4),
    ]);

    $counts = (new ExceptionCountsQuery())->get(new OperationsFilters());

    $bucketKeys = [
        'awaiting_confirmation',
        'confirmation_issue',
        'ready_no_driver',
        'dispatched_not_collected',
        'long_in_transit',
        'pod_pending',
    ];

    $sum = array_sum(array_intersect_key($counts, array_flip($bucketKeys)));

    expect($counts['at_risk'])->toBe($sum);
    expect($counts['at_risk'])->toBe(7);
});

it('at_risk is zero when nothing has crossed a threshold', function () {
    // All fresh — nothing is at risk yet.
    atRiskJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subMinutes(5));
    atRiskJob(Job::STATUS_CONFIRMED, now()->subMinutes(5));
    atRiskJob(Job::STATUS_DRIVER_ASSIGNED, now()->subMinutes(5));
    atRiskJob(Job::STATUS_IN_TRANSIT, now()->subMinutes(5));

    $counts = (new ExceptionCountsQuery())->get(new OperationsFilters());

    expect($counts['at_risk'])->toBe(0);
});

it('a job appears in exactly one exception bucket, never two', function () {
    // A single stuck-in-transit job. It must appear in long_in_transit
    // and nowhere else, and at_risk must be 1 -- not 2 or 6.
    atRiskJob(Job::STATUS_IN_TRANSIT, now()->subDays(5));

    $counts = (new ExceptionCountsQuery())->get(new OperationsFilters());

    expect($counts['long_in_transit'])->toBe(1);
    expect($counts['at_risk'])->toBe(1);
    expect($counts['awaiting_confirmation'])->toBe(0);
    expect($counts['confirmation_issue'])->toBe(0);
    expect($counts['ready_no_driver'])->toBe(0);
    expect($counts['dispatched_not_collected'])->toBe(0);
    expect($counts['pod_pending'])->toBe(0);
});
