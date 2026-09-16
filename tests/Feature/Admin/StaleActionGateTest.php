<?php

/**
 * Ops login stale-action gate.
 *
 * Pins the five properties the plan calls out:
 *  1. A row I created that has sat in the same stage for 7+ days
 *     lands in `owned` and gates the dashboard.
 *  2. The same row created by someone else lands in `others` — I can
 *     see it but never get blocked by it.
 *  3. Rows fresher than 7 days never light up the gate.
 *  4. `snoozeStale()` writes a comment + horizon (~+7d) and drops
 *     the row off the list until the snooze expires.
 *  5. A real status transition (via `Job::transitionTo`) clears every
 *     snooze field so the next stage's 7-day clock runs fresh.
 */

use App\Domain\Operations\Queries\StaleActionJobsQuery;
use App\Enums\JobStatus;
use App\Livewire\Admin\Operations\OperationsDashboard;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'operations_controller' => 'Ops Controller',
        'dispatcher'            => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

/**
 * Ops-tier user; used both as the actor on the modal and as the
 * default job creator via `$creator` overrides.
 */
function opsActor(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');
    return $u->refresh();
}

/**
 * Build a queue-visible job at a specific status + dwell moment.
 * Small copy of `queueJob` from `OpsQueueTest`; kept local so a test
 * in `Operations/` cannot accidentally couple to it.
 */
function staleGateJob(User $creator, string $status, \Carbon\Carbon $enteredAt, array $overrides = []): Job
{
    $company = Company::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup',
        'address'      => 'Pickup addr',
        'is_active'    => true,
        'province'     => 'Gauteng',
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery',
        'address'      => 'Delivery addr',
        'is_active'    => true,
        'province'     => 'Western Cape',
    ]);

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

it('lists my own 8-day-old job in `owned` and lights up the gate', function () {
    $me = opsActor();
    $mine = staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(8));

    $data = (new StaleActionJobsQuery())->forUser($me);

    expect($data['total'])->toBe(1);
    expect($data['owned']->pluck('id')->all())->toBe([$mine->id]);
    expect($data['others'])->toBeEmpty();
    expect($data['worst_days'])->toBe(8);
});

it('puts jobs I did not create in `others` so they never block dismiss', function () {
    $me    = opsActor();
    $other = opsActor();

    $theirs = staleGateJob($other, JobStatus::Confirmed->value, now()->subDays(9));

    $data = (new StaleActionJobsQuery())->forUser($me);

    expect($data['owned'])->toBeEmpty();
    expect($data['others']->pluck('id')->all())->toBe([$theirs->id]);
});

it('ignores jobs younger than the stale-action threshold', function () {
    $me = opsActor();
    staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(3));

    $data = (new StaleActionJobsQuery())->forUser($me);

    expect($data['total'])->toBe(0);
});

it('snoozeStale writes a comment + ~7d horizon and removes the row from the list', function () {
    $me   = opsActor();
    $mine = staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(10));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true)
        ->set('staleComments.' . $mine->id, 'Customer confirmed collection Monday')
        ->call('snoozeStale', $mine->id);

    $mine->refresh();
    expect($mine->stale_action_snooze_comment)->toBe('Customer confirmed collection Monday');
    expect($mine->stale_action_snoozed_by_user_id)->toBe($me->id);
    expect($mine->stale_action_snoozed_at)->not->toBeNull();
    // Horizon is now() + 7d ± a couple of seconds for the round-trip.
    expect($mine->stale_action_snoozed_until->diffInSeconds(now()->addDays(7), false))
        ->toBeGreaterThan(-5)
        ->toBeLessThan(5);

    $data = (new StaleActionJobsQuery())->forUser($me);
    expect($data['owned'])->toBeEmpty();
});

it('re-includes a stale row after the snooze horizon has passed', function () {
    $me   = opsActor();
    $mine = staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(20), [
        'stale_action_snoozed_until' => now()->subHour(),
        'stale_action_snooze_comment' => 'previous reason',
        'stale_action_snoozed_by_user_id' => $me->id,
        'stale_action_snoozed_at' => now()->subDays(8),
    ]);

    $data = (new StaleActionJobsQuery())->forUser($me);

    expect($data['owned']->pluck('id')->all())->toBe([$mine->id]);
});

it('does not list a stale row while the snooze is still in the future', function () {
    $me = opsActor();
    staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(20), [
        'stale_action_snoozed_until' => now()->addDays(3),
        'stale_action_snooze_comment' => 'holiday',
        'stale_action_snoozed_by_user_id' => $me->id,
        'stale_action_snoozed_at' => now()->subDay(),
    ]);

    $data = (new StaleActionJobsQuery())->forUser($me);
    expect($data['total'])->toBe(0);
});

it('clears every snooze field on the next real status transition', function () {
    $me   = opsActor();
    $mine = staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(10), [
        'stale_action_snoozed_until'      => now()->addDays(5),
        'stale_action_snooze_comment'     => 'waiting on customer',
        'stale_action_snoozed_by_user_id' => $me->id,
        'stale_action_snoozed_at'         => now()->subDay(),
    ]);

    // Confirmed → Planned is a valid transition on the queue; the
    // actual next-status doesn't matter for this assertion — we're
    // pinning the booted() hook that wipes snooze fields whenever
    // `status` changes at all.
    $mine->transitionTo(JobStatus::Planned->value);
    $mine->refresh();

    expect($mine->stale_action_snoozed_until)->toBeNull();
    expect($mine->stale_action_snooze_comment)->toBeNull();
    expect($mine->stale_action_snoozed_by_user_id)->toBeNull();
    expect($mine->stale_action_snoozed_at)->toBeNull();
});

it('refuses to snooze without a comment (validation is per-row)', function () {
    $me   = opsActor();
    $mine = staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(10));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->set('staleComments.' . $mine->id, '   ')
        ->call('snoozeStale', $mine->id)
        ->assertHasErrors('staleComment.' . $mine->id);

    $mine->refresh();
    expect($mine->stale_action_snoozed_until)->toBeNull();
});

it('dismissStaleGate refuses to close while I still have unresolved owned rows', function () {
    $me   = opsActor();
    staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(10));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true)
        ->call('dismissStaleGate')
        ->assertSet('showStaleGate', true);
});

it('dismissStaleGate closes the modal when only non-owned rows remain', function () {
    $me    = opsActor();
    $other = opsActor();

    staleGateJob($other, JobStatus::Confirmed->value, now()->subDays(12));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true)
        ->call('dismissStaleGate')
        ->assertSet('showStaleGate', false);
});

it('does not re-open the gate on every ops dash visit after closing an others-only list', function () {
    $me    = opsActor();
    $other = opsActor();

    staleGateJob($other, JobStatus::Confirmed->value, now()->subDays(12));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true)
        ->call('dismissStaleGate')
        ->assertSet('showStaleGate', false);

    // Same session, fresh mount (navigate away → back to ops dash).
    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', false);
});

it('still re-opens the gate every visit while I have unresolved owned rows', function () {
    $me = opsActor();
    staleGateJob($me, JobStatus::Confirmed->value, now()->subDays(10));

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true);

    Livewire::actingAs($me)
        ->test(OperationsDashboard::class)
        ->assertSet('showStaleGate', true);
});
