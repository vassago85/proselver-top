<?php

/**
 * Operations headline + inline driver-assign coverage.
 *
 * The audit asked for two changes on the ops surfaces:
 *   1. Ready-to-dispatch dwell must be the operations headline —
 *      "N jobs ready · p50 Xh · p90 Yh" with a per-status split.
 *   2. Planning "Awaiting Driver" rows and the ops-queue rows must
 *      let the dispatcher pick + assign a driver inline instead of
 *      opening every order.
 *
 * These tests lock those behaviours so the follow-up week's tidy-up
 * on operations UX can't accidentally regress them.
 */

use App\Livewire\Admin\Operations\Panels\PriorityMovementsPanel;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        ['slug' => 'operations_controller', 'name' => 'Ops Controller', 'tier' => 'internal'],
        ['slug' => 'driver',                'name' => 'Driver',         'tier' => 'driver'],
    ] as $r) {
        Role::firstOrCreate(['slug' => $r['slug']], $r);
    }
});

function assignScenarioCompany(): Company
{
    return Company::factory()->create();
}

function assignScenarioJob(string $status, ?int $driverId = null, array $extra = []): Job
{
    $company = assignScenarioCompany();
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup',
        'address'      => 'Pickup',
        'is_active'    => true,
        'province'     => 'Gauteng',
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery',
        'address'      => 'Delivery',
        'is_active'    => true,
        'province'     => 'Western Cape',
    ]);

    return Job::create(array_merge([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => Job::TYPE_TRANSPORT,
        'status'               => $status,
        'status_entered_at'    => now()->subHours(20),
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date'       => now()->addDay()->toDateString(),
        'driver_user_id'       => $driverId,
    ], $extra));
}

function assignScenarioDriver(string $name = 'Sipho Driver'): User
{
    $u = User::factory()->create(['name' => $name, 'is_active' => true]);
    $u->assignRole('driver');
    return $u->fresh();
}

// -----------------------------------------------------------------
// 1. Ops dashboard headline
// -----------------------------------------------------------------

test('the operations dashboard splits Ready-to-dispatch into ready-to-plan and planned counts', function () {
    // Two confirmed (waiting to be planned) + one planned
    // (waiting for a driver) = 3 ready to dispatch.
    assignScenarioJob(Job::STATUS_CONFIRMED);
    assignScenarioJob(Job::STATUS_CONFIRMED);
    assignScenarioJob(Job::STATUS_PLANNED);
    // A dispatched row must NOT be counted -- it's past the Ready
    // stage.  If the headline swept it in the split totals would
    // silently disagree with Planning + Dispatch.
    assignScenarioJob(Job::STATUS_IN_TRANSIT);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $response = $this->actingAs($u)->get(route('admin.dashboard.ops'))->assertOk();

    // Headline copy names the totals and the split verbatim so a
    // future edit can't quietly relocate them.
    $response->assertSee('3 jobs ready to dispatch');
    $response->assertSee('Ready to plan');
    $response->assertSee('Planned');
    // Deep links to the two clearing surfaces are present.
    $response->assertSee(route('admin.planning'), escape: false);
    $response->assertSee(route('admin.dispatch'), escape: false);
});

test('the operations dashboard threshold copy matches the trident config value', function () {
    // The threshold has to come from config so the headline can't
    // silently drift from the ops-queue overdue predicate that uses
    // the same source.
    assignScenarioJob(Job::STATUS_CONFIRMED);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $expected = (int) config('trident.stage_thresholds.ready_to_dispatch', 8);

    $this->actingAs($u)
        ->get(route('admin.dashboard.ops'))
        ->assertOk()
        ->assertSee("Threshold {$expected}h");
});

// -----------------------------------------------------------------
// 2. Planning inline driver assign
// -----------------------------------------------------------------

test('planning::assignDriverInline attaches a driver and advances the status', function () {
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $driver = assignScenarioDriver();
    $job = assignScenarioJob(Job::STATUS_PLANNED);

    Volt::actingAs($ops)
        ->test('admin.planning')
        ->set("driverSelections.{$job->id}", (string) $driver->id)
        ->call('assignDriverInline', $job->id);

    $job->refresh();

    expect($job->driver_user_id)->toBe($driver->id);
    expect($job->status)->toBe(Job::STATUS_DRIVER_ASSIGNED);
});

test('planning::assignDriverInline refuses when no driver is picked', function () {
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $job = assignScenarioJob(Job::STATUS_PLANNED);

    Volt::actingAs($ops)
        ->test('admin.planning')
        ->call('assignDriverInline', $job->id);

    // Guard must leave the job unchanged when no picker value was
    // submitted -- the dispatcher hitting Assign with the picker
    // still on the placeholder should not silently break state.
    // (The user-facing flash text is exercised in browser QA; here
    // we pin the invariant that matters: state stays clean.)
    expect($job->fresh()->status)->toBe(Job::STATUS_PLANNED);
    expect($job->fresh()->driver_user_id)->toBeNull();
});

// -----------------------------------------------------------------
// 3. Ops-queue inline driver assign
// -----------------------------------------------------------------

test('priority-movements panel skips the driver pool when no planned-no-driver row is on screen', function () {
    // Only in-transit rows (i.e. already have drivers): no picker
    // is rendered, and the driver-pool query must NOT run.  This
    // half of the pair pins the cheap-render path.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $driver = assignScenarioDriver('Other Driver');
    assignScenarioJob(Job::STATUS_IN_TRANSIT, driverId: $driver->id);

    $panel = Livewire::actingAs($ops)
        ->test(PriorityMovementsPanel::class)
        ->call('$refresh');

    expect($panel->viewData('driverOptions'))->toBe([]);
});

test('priority-movements panel loads the driver pool when a planned-no-driver row is on screen', function () {
    // The other half of the pair: a single planned-no-driver row is
    // enough to switch the panel into "load the driver pool" mode
    // so the inline picker can render.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    assignScenarioDriver();
    assignScenarioJob(Job::STATUS_PLANNED);

    $panel = Livewire::actingAs($ops)
        ->test(PriorityMovementsPanel::class)
        ->call('$refresh');

    expect(count($panel->viewData('driverOptions')))->toBeGreaterThan(0);
});

test('priority-movements::assignDriverInline attaches a driver from the ops queue', function () {
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $driver = assignScenarioDriver();
    $job = assignScenarioJob(Job::STATUS_PLANNED);

    Livewire::actingAs($ops)
        ->test(PriorityMovementsPanel::class)
        ->set("driverSelections.{$job->id}", (string) $driver->id)
        ->call('assignDriverInline', $job->id);

    $job->refresh();

    expect($job->driver_user_id)->toBe($driver->id);
    expect($job->status)->toBe(Job::STATUS_DRIVER_ASSIGNED);
});

test('priority-movements::assignDriverInline refuses when the row is not planned', function () {
    // The guard uses Job::canTransitionTo() so an in-transit row can't
    // be pulled back to driver-assigned via the inline action.
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');
    $driver = assignScenarioDriver();
    $job = assignScenarioJob(Job::STATUS_IN_TRANSIT, driverId: $driver->id);

    Livewire::actingAs($ops)
        ->test(PriorityMovementsPanel::class)
        ->set("driverSelections.{$job->id}", (string) $driver->id)
        ->call('assignDriverInline', $job->id);

    // Row must be untouched: same status, same driver.  The user-
    // facing flash text is exercised in browser QA; here we pin the
    // invariant that matters -- an in-transit row cannot be pulled
    // back to driver-assigned via the ops-queue inline action.
    expect($job->fresh()->status)->toBe(Job::STATUS_IN_TRANSIT);
    expect($job->fresh()->driver_user_id)->toBe($driver->id);
});
