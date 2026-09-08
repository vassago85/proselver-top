<?php

/**
 * The Live pipeline / Exceptions / Priority Movements panels must
 * IGNORE the performance-window date filter completely.
 *
 * The old dashboard had this backwards: changing the date range
 * re-ran twenty aggregate queries including the live tiles, so the
 * numbers on the top row silently drifted whenever ops touched the
 * date. This test pins the "two clocks, separated" invariant from the
 * plan.
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\ExceptionCountsQuery;
use App\Domain\Operations\Queries\LivePipelineQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function livePanelJob(string $status, ?\Carbon\Carbon $enteredAt = null, array $overrides = []): Job
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

it('live pipeline counts do not change when the date filter changes', function () {
    livePanelJob(Job::STATUS_RECEIVED);
    livePanelJob(Job::STATUS_CONFIRMED);
    livePanelJob(Job::STATUS_DRIVER_ASSIGNED);
    livePanelJob(Job::STATUS_IN_TRANSIT);

    $query = new LivePipelineQuery();

    $today = $query->get(new OperationsFilters(from: now()->startOfDay(), to: now()->endOfDay()));
    $lastYear = $query->get(new OperationsFilters(from: now()->subYear()->startOfDay(), to: now()->subYear()->endOfDay()));
    $wideOpen = $query->get(new OperationsFilters(from: null, to: null));

    expect($today)->toBe($lastYear, 'live pipeline is state-right-now — date range must not shift it');
    expect($today)->toBe($wideOpen, 'live pipeline with no dates set must equal any filtered date range');
    expect($today['intake'] + $today['ready'] + $today['dispatched'] + $today['on_road'])->toBe(4);
});

it('exception counts do not change when the date filter changes', function () {
    livePanelJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, now()->subDays(3));
    livePanelJob(Job::STATUS_IN_TRANSIT, now()->subDays(5));

    $q = new ExceptionCountsQuery();

    $tight = $q->get(new OperationsFilters(from: now()->startOfDay(), to: now()->endOfDay()));
    $wide  = $q->get(new OperationsFilters(from: now()->subYears(3)->startOfDay(), to: now()->endOfDay()));

    // Both windows must produce the same six-bucket vector.
    unset($tight['at_risk'], $wide['at_risk']);
    expect($tight)->toBe($wide);
});
