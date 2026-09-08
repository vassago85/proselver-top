<?php

/**
 * Ops queue behaviour — the collapsed panel that replaced Exceptions.
 *
 * Pins the four properties ops depends on:
 *  1. Rows are decorated with a Stage label (from StageGroup, not
 *     the raw JobStatus label).
 *  2. Rows are sorted by `overdue_by` desc — the loudest breach
 *     always floats to the top.
 *  3. Lanes with a missing province at either end collapse under a
 *     single "Lane not set" heading, pinned first.
 *  4. `PriorityMovementsQuery::overdueCount()` matches the count the
 *     queue itself renders as overdue, so the At-risk hero tile and
 *     the badges can never disagree.
 */

use App\Domain\Operations\Lane;
use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\PriorityMovementsQuery;
use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Small factory that puts a job in a specific status with a specific
 * status_entered_at, so we can dial in exact "hours in stage" values.
 */
function queueJob(string $status, \Carbon\Carbon $enteredAt, array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();

    // Use array_key_exists so an explicit `null` province in an
    // override is honoured (needed for the "Lane not set" test).
    $pickupProvince   = array_key_exists('pickup_province',   $overrides) ? $overrides['pickup_province']   : 'Gauteng';
    $deliveryProvince = array_key_exists('delivery_province', $overrides) ? $overrides['delivery_province'] : 'Western Cape';

    $pickup = Location::create([
        'company_name' => 'Pickup',
        'address'      => 'Pickup addr',
        'is_active'    => true,
        'province'     => $pickupProvince,
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery',
        'address'      => 'Delivery addr',
        'is_active'    => true,
        'province'     => $deliveryProvince,
    ]);

    unset($overrides['pickup_province'], $overrides['delivery_province']);

    return Job::create(array_merge([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => Job::TYPE_TRANSPORT,
        'status'               => $status,
        'status_entered_at'    => $enteredAt,
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
    ], $overrides));
}

it('decorates each row with a Stage label from StageGroup, not the raw JobStatus label', function () {
    // Confirmed maps to Ready → Stage label "Ready to dispatch",
    // while the raw JobStatus label would be "Collection Confirmed".
    // The queue must speak Stage.
    queueJob(JobStatus::Confirmed->value, now()->subHour());

    $data = (new PriorityMovementsQuery())->get(new OperationsFilters());
    $row = $data['jobs']->first();

    expect($row->stage_group)->toBe(StageGroup::Ready);
    expect($row->stage_label)->toBe('Ready to dispatch');
    expect($row->stage_label)->not->toBe($row->status);
});

it('sorts jobs so the most-overdue row floats to the top', function () {
    // Ready threshold is 8h. Two Confirmed jobs:
    //   - 2h in stage → overdue_by = -6
    //   - 20h in stage → overdue_by = +12
    // The 20h row must come first.
    $fresh   = queueJob(JobStatus::Confirmed->value, now()->subHours(2));
    $overdue = queueJob(JobStatus::Confirmed->value, now()->subHours(20));

    $data = (new PriorityMovementsQuery())->get(new OperationsFilters());
    $ids = $data['jobs']->pluck('id')->all();

    expect($ids[0])->toBe($overdue->id);
    expect($ids[1])->toBe($fresh->id);
});

it('flags is_overdue only when hours in stage exceed the stage threshold', function () {
    // Ready threshold is 8h. Right on the line is NOT overdue.
    $atLimit = queueJob(JobStatus::Confirmed->value, now()->subHours(8));
    $over    = queueJob(JobStatus::Confirmed->value, now()->subHours(9));

    $data = (new PriorityMovementsQuery())->get(new OperationsFilters());
    $byId = $data['jobs']->keyBy('id');

    expect($byId[$atLimit->id]->is_overdue)->toBeFalse();
    expect($byId[$over->id]->is_overdue)->toBeTrue();
    expect($byId[$over->id]->overdue_by)->toBe(1);
});

it('pins the "Lane not set" heading at the top of the lane list', function () {
    // One incomplete lane (missing pickup province) and two complete
    // lanes. Incomplete must come first regardless of overdue.
    queueJob(JobStatus::Confirmed->value, now()->subHours(100), [
        'pickup_province'   => null,
        'delivery_province' => 'Gauteng',
    ]);
    queueJob(JobStatus::Confirmed->value, now()->subHour());

    $data = (new PriorityMovementsQuery())->get(new OperationsFilters());
    $laneLabels = $data['lanes']->pluck('lane')->all();

    expect($laneLabels[0])->toBe(Lane::NOT_SET_LABEL);
});

it('includes Delivered (POD-pending) rows on the queue', function () {
    // Delivered maps to the POD-pending group; a 30h-old Delivered
    // row is overdue against the 24h threshold and MUST appear.
    queueJob(JobStatus::Delivered->value, now()->subHours(30), [
        'delivered_at' => now()->subHours(30),
    ]);

    $data = (new PriorityMovementsQuery())->get(new OperationsFilters());
    $row = $data['jobs']->first();

    expect($row->stage_group)->toBe(StageGroup::Delivered);
    expect($row->stage_label)->toBe('Delivered');
    expect($row->is_overdue)->toBeTrue();
    expect($row->overdue_by)->toBe(6);
});

it('exposes overdueCount() so the At-risk hero can share the same predicate as the badges', function () {
    // 1 overdue, 1 fresh, 1 completed (excluded). At-risk must be 1.
    queueJob(JobStatus::Confirmed->value, now()->subHours(20)); // overdue
    queueJob(JobStatus::Confirmed->value, now()->subHour());    // fresh
    queueJob(JobStatus::Completed->value, now()->subDay());     // out of scope

    $query = new PriorityMovementsQuery();
    $data = $query->get(new OperationsFilters());
    $overdueFromQueue = $data['jobs']->where('is_overdue', true)->count();

    expect($query->overdueCount(new OperationsFilters()))->toBe($overdueFromQueue);
    expect($query->overdueCount(new OperationsFilters()))->toBe(1);
});
