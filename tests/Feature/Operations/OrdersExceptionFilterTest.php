<?php

/**
 * The /admin/orders?exception=<bucket> deep link must return
 * exactly the rows the ops dashboard summed.
 *
 * Dashboard counts + orders queue rows must always agree — if they
 * drift, the "every number is a link" contract breaks and ops loses
 * confidence in the whole page.
 */

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'operations_controller' => 'Ops Controller',
        'dispatcher'            => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function ordersExUser(string $slug = 'operations_controller'): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function ordersExJob(string $status, ?\Carbon\Carbon $enteredAt = null, array $overrides = []): Job
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

it('orders page with ?exception=dispatched_not_collected returns only stuck-dispatched jobs', function () {
    // Fires.
    ordersExJob(Job::STATUS_DRIVER_ASSIGNED, now()->subDays(3));
    ordersExJob(Job::STATUS_READY_FOR_COLLECTION, now()->subDays(3));

    // Same status, but fresh -- does not fire.
    ordersExJob(Job::STATUS_DRIVER_ASSIGNED, now()->subMinutes(5));

    // Different status -- should never show up.
    ordersExJob(Job::STATUS_IN_TRANSIT, now()->subDays(3));

    $c = Volt::actingAs(ordersExUser())
        ->test('admin.orders.index', ['exceptionBucket' => 'dispatched_not_collected']);

    expect($c->viewData('jobs')->total())->toBe(2);
});

it('unknown exception values are ignored (no 500) rather than filtering the queue empty', function () {
    // Two live-status rows, no exceptions active.
    ordersExJob(Job::STATUS_CONFIRMED, now()->subMinutes(5));
    ordersExJob(Job::STATUS_IN_TRANSIT, now()->subMinutes(5));

    // Poison the URL with a value we haven't defined.
    $c = Volt::actingAs(ordersExUser())
        ->test('admin.orders.index', ['exceptionBucket' => 'not_a_real_bucket']);

    // Both rows still visible (predicate is a no-op).
    expect($c->viewData('jobs')->total())->toBe(2);
});

it('clearFilters resets the exception bucket', function () {
    ordersExJob(Job::STATUS_IN_TRANSIT, now()->subDays(4));

    $c = Volt::actingAs(ordersExUser())
        ->test('admin.orders.index', ['exceptionBucket' => 'long_in_transit']);

    expect($c->viewData('jobs')->total())->toBe(1);

    $c->call('clearFilters');

    expect($c->get('exceptionBucket'))->toBeNull();
    // Row is still there because it's a valid live status; the
    // exception filter is what narrowed the pill count, not the
    // list membership.
    expect($c->viewData('jobs')->total())->toBe(1);
});
