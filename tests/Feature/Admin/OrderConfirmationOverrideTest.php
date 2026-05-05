<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Ops override of the FAW-style customer-confirmation gate.
 *
 * For external_confirmation customers we normally wait for the customer
 * portal tap before ops can plan the order. While that flow is still
 * being rolled out, ops needs an escape hatch: phone the customer, get
 * verbal sign-off, then push the order to "Collection Confirmed"
 * manually. Override must be audit-logged and stamp a clear
 * ops-attributed note on the job. Tests assert on the persisted state
 * directly — flash-message bag is a Livewire/Volt implementation
 * detail we deliberately don't couple to.
 */
beforeEach(function () {
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Operations Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
});

function makeFawOverrideScenario(string $status): array
{
    $faw = Company::factory()->create([
        'workflow_type' => 'external_confirmation',
        'type' => Company::TYPE_OEM,
    ]);
    $loc = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'FAW JHB',
        'address' => 'Isando',
    ]);
    $owner = User::factory()->create();
    $owner->assignRole('customer_owner');
    $faw->users()->attach($owner->id, ['location_id' => null]);

    $ops = User::factory()->create(['name' => 'Ops Operator']);
    $ops->assignRole('operations_controller');

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_type' => 'transport',
        'status' => $status,
        'company_id' => $faw->id,
        'created_by_user_id' => $ops->id,
        'pickup_location_id' => $loc->id,
        'delivery_location_id' => $loc->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);

    return compact('faw', 'loc', 'owner', 'ops', 'job');
}

it('lets ops override an order stuck in AWAITING_CUSTOMER_CONFIRMATION straight to CONFIRMED', function () {
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmOrderOverride');

    expect($job->fresh()->status)->toBe(Job::STATUS_CONFIRMED);
});

it('lets ops override straight from RECEIVED (skipping the customer step entirely)', function () {
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_RECEIVED);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmOrderOverride');

    expect($job->fresh()->status)->toBe(Job::STATUS_CONFIRMED);
});

it('rescues an order that the customer reported an issue against', function () {
    // CONFIRMATION_ISSUE means the customer tapped "Report Issue" but ops
    // wants to push it through anyway after talking to them. Override
    // should still work from this state.
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_CONFIRMATION_ISSUE);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmOrderOverride');

    expect($job->fresh()->status)->toBe(Job::STATUS_CONFIRMED);
});

it('stamps an ops-attributed note on the job so the override is visible later', function () {
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_RECEIVED);

    Volt::actingAs($ops)->test('admin.orders.show', ['job' => $job])->call('confirmOrderOverride');

    $job->refresh();
    expect($job->confirmation_note)
        ->toStartWith('Confirmed by ops on behalf of customer')
        ->toContain('Ops Operator');
    expect($job->confirmation_reason)->toBeNull();
});

it('writes an audit log entry attributing the override', function () {
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION);

    Volt::actingAs($ops)->test('admin.orders.show', ['job' => $job])->call('confirmOrderOverride');

    $log = AuditLog::query()
        ->where('action_type', 'order_confirmed_override')
        ->where('entity_type', 'job')
        ->where('entity_id', $job->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->actor_user_id)->toBe($ops->id);
    expect($log->before_json['status'] ?? null)->toBe(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION);
    expect($log->after_json['status'] ?? null)->toBe(Job::STATUS_CONFIRMED);
});

it('refuses to override an order that has already moved past confirmation', function () {
    ['ops' => $ops, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_PLANNED);

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmOrderOverride');

    // Status must NOT regress from PLANNED back to CONFIRMED.
    expect($job->fresh()->status)->toBe(Job::STATUS_PLANNED);
});

it('forbids customer users from triggering the override (it is ops-only)', function () {
    ['owner' => $owner, 'job' => $job] = makeFawOverrideScenario(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION);

    // The customer-side owner is a customer-tier user — must NOT be able
    // to call the ops override path even if they reach the Livewire
    // component (defence in depth; routing already guards admin pages).
    Volt::actingAs($owner)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmOrderOverride')
        ->assertStatus(403);

    expect($job->fresh()->status)->toBe(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION);
});
