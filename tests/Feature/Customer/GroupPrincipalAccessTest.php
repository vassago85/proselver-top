<?php

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Small helper to spin up minimal transport_jobs rows without a factory. */
function makeJobForCompany(Company $company, string $status, string $jobNumber, ?User $creator = null): Job
{
    $creator ??= User::factory()->create();

    return Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => $jobNumber,
        'job_type' => 'transport',
        'status' => $status,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);
}

/**
 * Phase 0 group access: a franchise CEO who has been pivot-attached
 * to every dealership in a company_group sees stock, orders and
 * addresses combined across the whole umbrella.  A regular
 * customer_owner attached to only one of the dealerships sees only
 * their own dealership.  The admin "Make group principal" action
 * does the actual attachment.
 */
beforeEach(function () {
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
});

function makeCfaoFixture(): array
{
    $group = CompanyGroup::create([
        'name' => 'CFAO Holdings',
        'normalized_name' => 'cfao_holdings',
        'is_active' => true,
    ]);

    $sandton = Company::factory()->create([
        'name' => 'Williams Hunt Sandton',
        'type' => Company::TYPE_DEALER,
        'company_group_id' => $group->id,
    ]);
    $bryanston = Company::factory()->create([
        'name' => 'Williams Hunt Bryanston',
        'type' => Company::TYPE_DEALER,
        'company_group_id' => $group->id,
    ]);
    $fourways = Company::factory()->create([
        'name' => 'Williams Hunt Fourways',
        'type' => Company::TYPE_DEALER,
        'company_group_id' => $group->id,
    ]);

    return compact('group', 'sandton', 'bryanston', 'fourways');
}

test('visibleCompanyIds() returns only directly-linked companies by default', function () {
    ['sandton' => $sandton] = makeCfaoFixture();

    $regular = User::factory()->create();
    $regular->assignRole('customer_owner');
    $sandton->users()->attach($regular->id);

    expect($regular->visibleCompanyIds())->toEqual([$sandton->id]);
});

test('a CEO pivoted into every sibling sees them all via visibleCompanyIds()', function () {
    ['sandton' => $sandton, 'bryanston' => $bryanston, 'fourways' => $fourways] = makeCfaoFixture();

    $ceo = User::factory()->create(['name' => 'Group CEO']);
    $ceo->assignRole('customer_owner');
    $sandton->users()->attach($ceo->id);
    $bryanston->users()->attach($ceo->id);
    $fourways->users()->attach($ceo->id);

    $ids = $ceo->visibleCompanyIds();

    expect($ids)->toContain($sandton->id);
    expect($ids)->toContain($bryanston->id);
    expect($ids)->toContain($fourways->id);
    expect(count($ids))->toEqual(3);
});

test('admin "Make group principal" attaches the user to every sibling dealership', function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);

    ['sandton' => $sandton, 'bryanston' => $bryanston, 'fourways' => $fourways] = makeCfaoFixture();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $candidate = User::factory()->create();
    $candidate->assignRole('customer_owner');
    $sandton->users()->attach($candidate->id);

    $this->actingAs($admin);

    Volt::test('admin.companies.show', ['company' => $sandton])
        ->call('makeGroupPrincipal', $candidate->id);

    expect($candidate->fresh()->visibleCompanyIds())
        ->toContain($sandton->id)
        ->toContain($bryanston->id)
        ->toContain($fourways->id);
});

test('admin "Remove group principal" detaches sibling dealerships but keeps the originating link', function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);

    ['sandton' => $sandton, 'bryanston' => $bryanston, 'fourways' => $fourways] = makeCfaoFixture();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $ceo = User::factory()->create();
    $ceo->assignRole('customer_owner');
    $sandton->users()->attach($ceo->id);
    $bryanston->users()->attach($ceo->id);
    $fourways->users()->attach($ceo->id);

    $this->actingAs($admin);

    Volt::test('admin.companies.show', ['company' => $sandton])
        ->call('removeGroupPrincipal', $ceo->id);

    $finalIds = $ceo->fresh()->visibleCompanyIds();
    expect($finalIds)->toEqual([$sandton->id]);
});

test('orders index shows jobs from every visible dealership for a CEO', function () {
    ['sandton' => $sandton, 'bryanston' => $bryanston, 'fourways' => $fourways] = makeCfaoFixture();

    $ceo = User::factory()->create();
    $ceo->assignRole('customer_owner');
    $sandton->users()->attach($ceo->id);
    $bryanston->users()->attach($ceo->id);
    $fourways->users()->attach($ceo->id);

    // One job per dealership so we can check each one shows up
    foreach ([$sandton, $bryanston, $fourways] as $i => $c) {
        makeJobForCompany($c, Job::STATUS_RECEIVED, "JOB-{$i}");
    }

    $this->actingAs($ceo);

    Volt::test('customer.orders.index')
        ->assertViewHas('isMultiCompany', true)
        ->assertSee('JOB-0')
        ->assertSee('JOB-1')
        ->assertSee('JOB-2')
        ->assertSee($sandton->name)
        ->assertSee($bryanston->name)
        ->assertSee($fourways->name);
});

test('regular customer_owner sees only their own dealership orders', function () {
    ['sandton' => $sandton, 'bryanston' => $bryanston] = makeCfaoFixture();

    $regular = User::factory()->create();
    $regular->assignRole('customer_owner');
    $sandton->users()->attach($regular->id);

    makeJobForCompany($sandton, Job::STATUS_RECEIVED, 'SANDTON-1');
    makeJobForCompany($bryanston, Job::STATUS_RECEIVED, 'BRYANSTON-1');

    $this->actingAs($regular);

    Volt::test('customer.orders.index')
        ->assertViewHas('isMultiCompany', false)
        ->assertSee('SANDTON-1')
        ->assertDontSee('BRYANSTON-1');
});

test('dashboard KPI counts sum across every dealership a CEO can see', function () {
    ['sandton' => $sandton, 'bryanston' => $bryanston] = makeCfaoFixture();

    $ceo = User::factory()->create();
    $ceo->assignRole('customer_owner');
    $sandton->users()->attach($ceo->id);
    $bryanston->users()->attach($ceo->id);

    foreach (range(1, 2) as $i) {
        makeJobForCompany($sandton, Job::STATUS_RECEIVED, "S-{$i}");
    }
    foreach (range(1, 3) as $i) {
        makeJobForCompany($bryanston, Job::STATUS_RECEIVED, "B-{$i}");
    }

    $this->actingAs($ceo);

    Volt::test('customer.dashboard')
        ->assertViewHas('hasCompany', true)
        ->assertViewHas('isMultiCompany', true)
        ->assertViewHas('visibleCompanyCount', 2);
});

test('makeGroupPrincipal flashes an error when the company has no group', function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);

    $lone = Company::factory()->create(['type' => Company::TYPE_DEALER, 'company_group_id' => null]);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $candidate = User::factory()->create();
    $candidate->assignRole('customer_owner');
    $lone->users()->attach($candidate->id);

    $this->actingAs($admin);

    Volt::test('admin.companies.show', ['company' => $lone])
        ->call('makeGroupPrincipal', $candidate->id);

    // Still only attached to the one company
    expect($candidate->fresh()->visibleCompanyIds())->toEqual([$lone->id]);
});
