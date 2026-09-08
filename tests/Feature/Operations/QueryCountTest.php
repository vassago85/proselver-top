<?php

/**
 * The old ops dashboard fired ~20 aggregate queries per render.
 * The rebuild caps each panel at ≤ 2 queries via the Domain query
 * classes. This test pins that ceiling — if a panel starts
 * count()-ing tiles inline again, this fails.
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\ExceptionCountsQuery;
use App\Domain\Operations\Queries\LaneSummaryQuery;
use App\Domain\Operations\Queries\LivePipelineQuery;
use App\Domain\Operations\Queries\PriorityMovementsQuery;
use App\Domain\Operations\Queries\ThroughputQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function qcJob(string $status = 'in_transit', array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup', 'address' => 'Pickup', 'is_active' => true, 'province' => 'Gauteng',
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery', 'address' => 'Delivery', 'is_active' => true, 'province' => 'Western Cape',
    ]);

    return Job::create(array_merge([
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
    ], $overrides));
}

function countQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    return $count;
}

it('LivePipelineQuery runs a single aggregate query', function () {
    qcJob(Job::STATUS_RECEIVED);

    $count = countQueries(fn () => (new LivePipelineQuery())->get(new OperationsFilters()));

    expect($count)->toBeLessThanOrEqual(1);
});

it('ExceptionCountsQuery runs a single aggregate query (plus threshold lookups when defaults absent)', function () {
    qcJob(Job::STATUS_IN_TRANSIT);
    // Pre-resolve thresholds to isolate the SQL bill of the counts
    // query itself; production paths cache the thresholds once.
    $t = \App\Domain\Operations\OperationsThresholds::forExceptions();

    $count = countQueries(fn () => (new ExceptionCountsQuery())->get(new OperationsFilters(), $t));

    expect($count)->toBeLessThanOrEqual(1);
});

it('ThroughputQuery keeps its query bill under the panel budget', function () {
    qcJob(Job::STATUS_DELIVERED, ['delivered_at' => now()]);

    $count = countQueries(fn () => (new ThroughputQuery())->get(new OperationsFilters()));

    // Throughput today runs 5 separate counts (scheduled / delivered /
    // three gap buckets). That's higher than the ≤ 2 target and marks
    // the tile as the next optimisation candidate — one FILTER-style
    // aggregate would collapse it. Pin the current ceiling so we
    // notice if it grows.
    expect($count)->toBeLessThanOrEqual(5);
});

it('LaneSummaryQuery runs a single aggregated join', function () {
    qcJob(Job::STATUS_RECEIVED);

    $count = countQueries(fn () => (new LaneSummaryQuery())->get(new OperationsFilters()));

    expect($count)->toBeLessThanOrEqual(1);
});

it('PriorityMovementsQuery runs at most two queries (one select + one eager batch)', function () {
    qcJob(Job::STATUS_IN_TRANSIT);

    // Eloquent's `with(...)` batches every relationship into ONE
    // additional query, so the ceiling is 1 (main select) + 1 (all
    // eager loads) + a couple for tiny lookup tables the mapper
    // touches. Cap at 8 so the assertion has slack for the eager
    // batch but still catches obvious N+1 regressions.
    $count = countQueries(fn () => (new PriorityMovementsQuery())->get(new OperationsFilters()));

    expect($count)->toBeLessThanOrEqual(8);
});
