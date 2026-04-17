<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * FAW-style external-confirmation scoping.
 *
 * A dispatcher pinned to a depot (JHB) must not be able to confirm — or even
 * see — an order that collects from a different depot (Coega / CPT). Owners
 * and admins (no location on the pivot) stay account-wide.
 */
beforeEach(function () {
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    Role::create(['name' => 'Customer Dispatcher', 'slug' => 'customer_dispatcher', 'tier' => 'customer']);
});

function makeFawScenario(): array
{
    $faw = Company::factory()->create(['workflow_type' => 'external_confirmation']);

    $jhb = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'FAW JHB',
        'address' => 'Isando',
    ]);
    $cpt = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'FAW Coega',
        'address' => 'Coega',
    ]);

    $jhbDispatcher = User::factory()->create(['name' => 'JHB Dispatcher']);
    $jhbDispatcher->assignRole('customer_dispatcher');
    $faw->users()->attach($jhbDispatcher->id, ['location_id' => $jhb->id]);

    $cptDispatcher = User::factory()->create(['name' => 'CPT Dispatcher']);
    $cptDispatcher->assignRole('customer_dispatcher');
    $faw->users()->attach($cptDispatcher->id, ['location_id' => $cpt->id]);

    $owner = User::factory()->create(['name' => 'FAW Owner']);
    $owner->assignRole('customer_owner');
    $faw->users()->attach($owner->id, ['location_id' => null]);

    // Order collects from Cape Town, awaiting the CPT depot's confirmation.
    $cptJob = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_type' => 'transport',
        'status' => Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        'company_id' => $faw->id,
        'created_by_user_id' => $owner->id,
        'pickup_location_id' => $cpt->id,
        'delivery_location_id' => $jhb->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);

    return compact('faw', 'jhb', 'cpt', 'jhbDispatcher', 'cptDispatcher', 'owner', 'cptJob');
}

test('a JHB-pinned dispatcher cannot confirm a Cape Town pickup', function () {
    ['jhbDispatcher' => $jhb, 'cptJob' => $job] = makeFawScenario();

    expect($jhb->can('confirmCustomerOrder', $job))->toBeFalse();
});

test('the Cape Town dispatcher can confirm their own pickup', function () {
    ['cptDispatcher' => $cpt, 'cptJob' => $job] = makeFawScenario();

    expect($cpt->can('confirmCustomerOrder', $job))->toBeTrue();
});

test('the account owner (no location) can confirm any pickup', function () {
    ['owner' => $owner, 'cptJob' => $job] = makeFawScenario();

    expect($owner->can('confirmCustomerOrder', $job))->toBeTrue();
});

test('a JHB dispatcher 404s on a Cape Town order detail page', function () {
    ['jhbDispatcher' => $jhb, 'cptJob' => $job] = makeFawScenario();

    // Pickup=CPT and Delivery=JHB — delivery matches JHB so the list rule
    // would let them *see* it. Swap delivery out to make it a pure CPT order
    // the JHB dispatcher should not touch.
    $job->delivery_location_id = $job->pickup_location_id;
    $job->save();

    $this->actingAs($jhb)
        ->get(route('customer.orders.show', $job))
        ->assertStatus(404);
});

test('a JHB dispatcher can open an order being delivered to JHB', function () {
    ['jhbDispatcher' => $jhb, 'cptJob' => $job] = makeFawScenario();

    // Delivery is JHB by default in the scenario — they should see it.
    $this->actingAs($jhb)
        ->get(route('customer.orders.show', $job))
        ->assertStatus(200);
});

test('the policy still blocks confirmation even if the JHB dispatcher can view a delivery-bound order', function () {
    ['jhbDispatcher' => $jhb, 'cptJob' => $job] = makeFawScenario();

    // Delivery is JHB so they can see the page, but pickup is still CPT —
    // confirmation is about "truck is here" and must stay with CPT.
    expect($jhb->can('confirmCustomerOrder', $job))->toBeFalse();
});
