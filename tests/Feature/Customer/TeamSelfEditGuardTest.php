<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The customer team management page must not allow a signed-in account
 * owner to edit their own row. The Edit button is hidden in the template,
 * but the Livewire wire payload is tamperable, so the component itself
 * has to refuse self-edits.
 *
 * This is the FAW-owner self-protection guard: a single rogue session
 * could otherwise rotate its own role away from customer_owner.
 */
beforeEach(function () {
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    Role::create(['name' => 'Customer Admin', 'slug' => 'customer_admin', 'tier' => 'customer']);
    Role::create(['name' => 'Customer User', 'slug' => 'customer_user', 'tier' => 'customer']);
    Role::create(['name' => 'Customer Dispatcher', 'slug' => 'customer_dispatcher', 'tier' => 'customer']);
});

function makeOwnerScenario(?string $companyType = null): array
{
    $company = Company::factory()->create([
        'type' => $companyType ?? Company::TYPE_CUSTOMER,
    ]);

    $owner = User::factory()->create(['name' => 'FAW Owner']);
    $owner->assignRole('customer_owner');
    $company->users()->attach($owner->id, ['location_id' => null]);

    $teammate = User::factory()->create(['name' => 'Teammate']);
    $teammate->assignRole('customer_user');
    $company->users()->attach($teammate->id, ['location_id' => null]);

    return compact('company', 'owner', 'teammate');
}

test('a customer owner cannot open their own row in edit mode', function () {
    ['owner' => $owner] = makeOwnerScenario();

    $this->actingAs($owner);

    Volt::test('customer.team.index')
        ->call('edit', $owner->id)
        ->assertSet('showForm', false)
        ->assertSet('editingId', null);
});

test('a customer owner can still edit a teammate', function () {
    ['owner' => $owner, 'teammate' => $teammate] = makeOwnerScenario();

    $this->actingAs($owner);

    Volt::test('customer.team.index')
        ->call('edit', $teammate->id)
        ->assertSet('showForm', true)
        ->assertSet('editingId', $teammate->id);
});

test('a tampered editingId pointing at self is refused on save', function () {
    ['owner' => $owner] = makeOwnerScenario();

    $this->actingAs($owner);

    // Simulate a tampered Livewire payload: editingId set to self via the
    // wire frame, then save() called directly without going through edit().
    Volt::test('customer.team.index')
        ->set('editingId', $owner->id)
        ->set('userName', 'Hacked Owner')
        ->set('userEmail', $owner->email)
        ->set('userRole', 'customer_user') // would-be self-demotion
        ->call('save');

    expect($owner->fresh()->name)->toBe('FAW Owner');
    expect($owner->fresh()->hasRole('customer_owner'))->toBeTrue();
    expect($owner->fresh()->hasRole('customer_user'))->toBeFalse();
});

test('OEM-typed company shows OEM role labels in the team page', function () {
    ['owner' => $owner] = makeOwnerScenario(Company::TYPE_OEM);

    $this->actingAs($owner);

    Volt::test('customer.team.index')
        ->assertSee('OEM Owner')
        ->assertDontSee('Customer Owner');
});

test('Customer-typed company keeps Customer role labels', function () {
    ['owner' => $owner] = makeOwnerScenario(Company::TYPE_CUSTOMER);

    $this->actingAs($owner);

    Volt::test('customer.team.index')
        ->assertSee('Customer Owner');
});
