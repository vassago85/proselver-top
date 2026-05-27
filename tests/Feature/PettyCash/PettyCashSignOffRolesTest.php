<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\PettyCashPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Operations Controller', 'tier' => 'internal']);
});

function makePendingPlanScenario(): array
{
    $company = Company::factory()->create();

    $loc = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'Depot A',
        'address' => '123 Test Road',
        'city' => 'Pretoria',
        'province' => 'Gauteng',
    ]);

    $ops = User::factory()->create(['name' => 'Ops User']);
    $ops->assignRole('operations_controller');

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_PLANNED,
        'company_id' => $company->id,
        'created_by_user_id' => $ops->id,
        'pickup_location_id' => $loc->id,
        'delivery_location_id' => $loc->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);

    $plan = PettyCashPlan::create([
        'label' => 'Pay-run Test',
        'status' => PettyCashPlan::STATUS_PENDING,
        'total_amount' => 1250.00,
        'items_json' => [[
            'job_id' => $job->id,
            'job_number' => $job->job_number,
            'computed_total' => 1250.00,
        ]],
        'generated_by_user_id' => $ops->id,
        'generated_at' => now(),
    ]);

    $job->forceFill(['advance_plan_id' => $plan->id])->save();

    return compact('ops', 'job', 'plan');
}

function makePendingRemovalScenario(): array
{
    $company = Company::factory()->create();

    $loc = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'Depot B',
        'address' => '456 Test Avenue',
        'city' => 'Midrand',
        'province' => 'Gauteng',
    ]);

    $ops = User::factory()->create(['name' => 'Ops Requester']);
    $ops->assignRole('operations_controller');

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'company_id' => $company->id,
        'created_by_user_id' => $ops->id,
        'pickup_location_id' => $loc->id,
        'delivery_location_id' => $loc->id,
        'scheduled_date' => now()->toDateString(),
        'advance_total' => 900.00,
        'advance_tolls' => 300.00,
        'advance_accommodation' => 300.00,
        'advance_food' => 300.00,
        'advance_approved_at' => now()->subHour(),
        'advance_removal_pending' => true,
        'advance_removal_requested_at' => now()->subMinutes(10),
        'advance_removal_requested_by_user_id' => $ops->id,
        'advance_removal_reason' => 'Trip postponed',
    ]);

    return compact('ops', 'job');
}

it('allows accounts to approve pending petty-cash plans', function () {
    ['plan' => $plan] = makePendingPlanScenario();

    $accounts = User::factory()->create(['name' => 'Accounts User']);
    $accounts->assignRole('accounts');

    Volt::actingAs($accounts)
        ->test('admin.petty-cash.plans')
        ->call('approvePlan', $plan->id)
        ->assertHasNoErrors();

    $plan->refresh();
    expect($plan->status)->toBe(PettyCashPlan::STATUS_APPROVED);
    expect($plan->approved_by_user_id)->toBe($accounts->id);
});

it('keeps owner as a fallback approver for pending petty-cash plans', function () {
    ['plan' => $plan] = makePendingPlanScenario();

    $owner = User::factory()->create(['name' => 'Owner User']);
    $owner->assignRole('owner');

    Volt::actingAs($owner)
        ->test('admin.petty-cash.plans')
        ->call('approvePlan', $plan->id)
        ->assertHasNoErrors();

    expect($plan->fresh()->status)->toBe(PettyCashPlan::STATUS_APPROVED);
});

it('forbids ops from approving pending petty-cash plans', function () {
    ['ops' => $ops, 'plan' => $plan] = makePendingPlanScenario();

    Volt::actingAs($ops)
        ->test('admin.petty-cash.plans')
        ->call('approvePlan', $plan->id)
        ->assertStatus(403);

    expect($plan->fresh()->status)->toBe(PettyCashPlan::STATUS_PENDING);
});

it('allows accounts to confirm pending advance-removal requests', function () {
    ['job' => $job] = makePendingRemovalScenario();

    $accounts = User::factory()->create(['name' => 'Accounts User']);
    $accounts->assignRole('accounts');

    Volt::actingAs($accounts)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmRemoval')
        ->assertHasNoErrors();

    $job->refresh();
    expect($job->advance_removal_pending)->toBeFalse();
    expect($job->advance_total)->toBeNull();
});

it('forbids ops from confirming pending advance-removal requests', function () {
    ['ops' => $ops, 'job' => $job] = makePendingRemovalScenario();

    Volt::actingAs($ops)
        ->test('admin.orders.show', ['job' => $job])
        ->call('confirmRemoval')
        ->assertStatus(403);

    expect($job->fresh()->advance_removal_pending)->toBeTrue();
    expect((float) $job->fresh()->advance_total)->toBe(900.0);
});

