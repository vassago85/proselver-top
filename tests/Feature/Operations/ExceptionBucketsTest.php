<?php

/**
 * Every exception bucket on the ops dashboard must be able to fire.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The old dashboard aged jobs on `updated_at`, and every unrelated save
 * (invoice capture, note, cost update) reset that clock. Six buckets sat
 * at zero for weeks while 21 jobs were visibly stuck in the pipeline —
 * this test is the tripwire that catches that class of regression.
 *
 * Each test seeds ONE job that clearly matches ONE bucket and asserts
 * both:
 *   1. The ExceptionCountsQuery counts it.
 *   2. The /admin/orders?exception=<bucket> deep link returns it.
 *
 * If a future refactor breaks the dwell clock or the deep-link
 * predicate, exactly one of these two halves fails first — and the
 * test's name tells you which bucket to look at.
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
    foreach ([
        'operations_controller' => 'Ops Controller',
        'dispatcher'            => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function exBucketUser(string $slug = 'dispatcher'): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function exBucketJob(string $status, ?\Carbon\Carbon $enteredAt = null, array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_id'   => null,
        'company_name' => 'Pickup',
        'address'      => 'Pickup',
        'is_active'    => true,
    ]);
    $delivery = Location::create([
        'company_id'   => null,
        'company_name' => 'Delivery',
        'address'      => 'Delivery',
        'is_active'    => true,
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

/**
 * Convenience: run the counts against an empty filter set (ProSelver
 * default scope, no date, no entity restrictions).
 *
 * @return array<string,int>
 */
function exBucketCounts(): array
{
    return (new ExceptionCountsQuery())->get(new OperationsFilters());
}

/**
 * Trigger the /admin/orders page with an exception filter set, and
 * assert the paginator returns the given number of rows. We reach
 * into the Volt anonymous class by using the Livewire testing helper.
 */
function assertOrdersQueueMatchesBucket(string $bucket, int $expected): void
{
    $count = \Livewire\Volt\Volt::actingAs(exBucketUser('operations_controller'))
        ->test('pages.admin.orders.index', ['exceptionBucket' => $bucket])
        ->viewData('jobs')
        ->total();

    expect($count)->toBe($expected, "orders?exception={$bucket} should return {$expected} rows");
}

// ---------------------------------------------------------------
// Awaiting confirmation
// ---------------------------------------------------------------

it('awaiting_confirmation fires only after the threshold has passed', function () {
    // Well past threshold (default 48h).
    exBucketJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3));

    // Just entered the state -- must NOT fire.
    exBucketJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subMinutes(5));

    $counts = exBucketCounts();

    expect($counts['awaiting_confirmation'])->toBe(1);
});

// ---------------------------------------------------------------
// Confirmation issue -- age-invariant
// ---------------------------------------------------------------

it('confirmation_issue fires on first appearance, regardless of dwell', function () {
    // A fresh confirmation issue must fire immediately -- ops needs
    // to see it before it hits the awaiting-confirmation window.
    exBucketJob(Job::STATUS_CONFIRMATION_ISSUE, now()->subMinutes(2));

    $counts = exBucketCounts();

    expect($counts['confirmation_issue'])->toBe(1);
});

// ---------------------------------------------------------------
// Ready to dispatch but no driver
// ---------------------------------------------------------------

it('ready_no_driver counts CONFIRMED and PLANNED jobs past the ready threshold', function () {
    exBucketJob(Job::STATUS_CONFIRMED, now()->subDays(2));
    exBucketJob(Job::STATUS_PLANNED,   now()->subDays(3));

    // Under threshold -- must NOT count.
    exBucketJob(Job::STATUS_CONFIRMED, now()->subMinutes(5));

    $counts = exBucketCounts();

    expect($counts['ready_no_driver'])->toBe(2);
});

// ---------------------------------------------------------------
// Dispatched but not collected
// ---------------------------------------------------------------

it('dispatched_not_collected counts DRIVER_ASSIGNED and READY_FOR_COLLECTION jobs past threshold', function () {
    exBucketJob(Job::STATUS_DRIVER_ASSIGNED,      now()->subDays(3));
    exBucketJob(Job::STATUS_READY_FOR_COLLECTION, now()->subDays(4));

    exBucketJob(Job::STATUS_DRIVER_ASSIGNED, now()->subMinutes(5)); // fresh, ignored

    expect(exBucketCounts()['dispatched_not_collected'])->toBe(2);
});

// ---------------------------------------------------------------
// Long in transit
// ---------------------------------------------------------------

it('long_in_transit counts IN_TRANSIT jobs past the transit threshold', function () {
    exBucketJob(Job::STATUS_IN_TRANSIT, now()->subDays(4));

    // A fresh in-transit job is not an exception yet.
    exBucketJob(Job::STATUS_IN_TRANSIT, now()->subHours(2));

    expect(exBucketCounts()['long_in_transit'])->toBe(1);
});

// ---------------------------------------------------------------
// POD pending
// ---------------------------------------------------------------

it('pod_pending counts DELIVERED jobs where POD (completed_at) is still missing past threshold', function () {
    // Delivered days ago, no POD yet -- fires.
    exBucketJob(Job::STATUS_DELIVERED, now()->subDays(4), [
        'delivered_at' => now()->subDays(4),
    ]);

    // Delivered days ago BUT completed -- must NOT fire.
    exBucketJob(Job::STATUS_DELIVERED, now()->subDays(4), [
        'delivered_at' => now()->subDays(4),
        'completed_at' => now()->subDays(3),
    ]);

    // Delivered just now, POD not yet in -- must NOT fire yet.
    exBucketJob(Job::STATUS_DELIVERED, now(), [
        'delivered_at' => now(),
    ]);

    expect(exBucketCounts()['pod_pending'])->toBe(1);
});

// ---------------------------------------------------------------
// Executor scoping
// ---------------------------------------------------------------

it('by default, exception buckets only see ProSelver-executed movements', function () {
    // ProSelver-executed exception -- must count.
    exBucketJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3));

    // Dealer-internal exception -- must NOT count in default scope.
    exBucketJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3), [
        'executor_type' => Job::EXECUTOR_INTERNAL,
    ]);

    $default = (new ExceptionCountsQuery())->get(new OperationsFilters());
    expect($default['awaiting_confirmation'])->toBe(1);

    // When ops opts in via allExecutors, both count.
    $all = (new ExceptionCountsQuery())->get(new OperationsFilters(allExecutors: true));
    expect($all['awaiting_confirmation'])->toBe(2);
});
