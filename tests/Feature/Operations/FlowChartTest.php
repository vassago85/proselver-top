<?php

/**
 * The flow chart plots daily *inflow* per pipeline stage, using the
 * per-stage entry timestamp columns (created_at, customer_confirmed_at,
 * assigned_at, collected_at).
 *
 * INVARIANTS
 * ----------
 *   1. A row that flowed all four pipeline stages inside the window
 *      contributes ONE entry to each of the four lines, on the day it
 *      entered that stage — not just to whichever stage it happens to
 *      sit in today.
 *   2. Current status has NO effect on whether a row appears on a
 *      stage's line — only the entry timestamp matters.
 *   3. Rows with a null entry timestamp for a stage don't appear on
 *      that line (they never entered it).
 *
 * These are the invariants that were broken by the initial
 * `whereIn('status', ...)` + `status_entered_at` implementation, so
 * this test locks them in explicitly.
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\FlowChartQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function flowJob(array $overrides = []): Job
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
        'status'               => Job::STATUS_RECEIVED,
        'status_entered_at'    => now(),
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
    ], $overrides));
}

it('a job that flowed all four stages appears on all four lines', function () {
    // The row is currently at "delivered" — the old implementation
    // dropped it from Intake / Ready / Dispatched / OnRoad because
    // its current status is none of those. The new one puts it on
    // every stage line on its transition day.
    $job = flowJob([
        'status'                => Job::STATUS_DELIVERED,
        // Entry timestamps across four consecutive days in the window.
        'created_at'            => Carbon::parse('2026-09-03 08:00:00'),
        'customer_confirmed_at' => Carbon::parse('2026-09-04 08:00:00'),
        'assigned_at'           => Carbon::parse('2026-09-05 08:00:00'),
        'collected_at'          => Carbon::parse('2026-09-06 08:00:00'),
        'delivered_at'          => Carbon::parse('2026-09-07 08:00:00'),
    ]);

    $filters = new OperationsFilters(
        from: Carbon::parse('2026-09-02')->startOfDay(),
        to:   Carbon::parse('2026-09-08')->endOfDay(),
    );

    $data = (new FlowChartQuery())->get($filters);

    // Each stage line has exactly 1 entry — this one row on the day
    // it entered that stage.
    expect($data['totals']['intake'])->toBe(1);
    expect($data['totals']['ready'])->toBe(1);
    expect($data['totals']['dispatched'])->toBe(1);
    expect($data['totals']['on_road'])->toBe(1);
});

it('a job stuck in intake only appears on the intake line', function () {
    flowJob([
        'status'                => Job::STATUS_RECEIVED,
        'created_at'            => Carbon::parse('2026-09-05 08:00:00'),
        'customer_confirmed_at' => null,
        'assigned_at'           => null,
        'collected_at'          => null,
    ]);

    $filters = new OperationsFilters(
        from: Carbon::parse('2026-09-02')->startOfDay(),
        to:   Carbon::parse('2026-09-08')->endOfDay(),
    );

    $data = (new FlowChartQuery())->get($filters);

    expect($data['totals']['intake'])->toBe(1);
    expect($data['totals']['ready'])->toBe(0);
    expect($data['totals']['dispatched'])->toBe(0);
    expect($data['totals']['on_road'])->toBe(0);
});

it('does not double-count rows whose entry timestamp falls outside the window', function () {
    flowJob([
        'status'                => Job::STATUS_DELIVERED,
        // Confirmed before the window opens — should not show on Ready.
        'created_at'            => Carbon::parse('2026-09-04 08:00:00'),
        'customer_confirmed_at' => Carbon::parse('2026-08-30 08:00:00'),
        // Everything else is inside.
        'assigned_at'           => Carbon::parse('2026-09-05 08:00:00'),
        'collected_at'          => Carbon::parse('2026-09-06 08:00:00'),
        'delivered_at'          => Carbon::parse('2026-09-07 08:00:00'),
    ]);

    $filters = new OperationsFilters(
        from: Carbon::parse('2026-09-02')->startOfDay(),
        to:   Carbon::parse('2026-09-08')->endOfDay(),
    );

    $data = (new FlowChartQuery())->get($filters);

    expect($data['totals']['intake'])->toBe(1);
    expect($data['totals']['ready'])->toBe(0); // confirmed before window
    expect($data['totals']['dispatched'])->toBe(1);
    expect($data['totals']['on_road'])->toBe(1);
});

it('rejects legacy behaviour: current status must not filter the chart', function () {
    // If somebody reintroduces `whereIn('status', $group->statusValues())`,
    // this row disappears from the Intake line — because its current
    // status is `driver_assigned`, not `received`. Locking the invariant.
    flowJob([
        'status'      => Job::STATUS_DRIVER_ASSIGNED,
        'created_at'  => Carbon::parse('2026-09-04 08:00:00'),
        'assigned_at' => Carbon::parse('2026-09-05 08:00:00'),
    ]);

    $filters = new OperationsFilters(
        from: Carbon::parse('2026-09-02')->startOfDay(),
        to:   Carbon::parse('2026-09-08')->endOfDay(),
    );

    $data = (new FlowChartQuery())->get($filters);

    expect($data['totals']['intake'])->toBe(1);      // <- would be 0 under old code
    expect($data['totals']['dispatched'])->toBe(1);
});
