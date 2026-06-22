<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Privacy gate: the "Driver advance & tolls" block on the dealer's
 * customer/orders/show page must NEVER render when ProSelver is the
 * executor of the job.  That block surfaces:
 *
 *   - advance_total / advance_food / advance_tolls / advance_taxi /
 *     advance_accommodation -- ProSelver's internal driver-cash plan,
 *   - estimated_toll_cost -- ProSelver's route-budget estimate,
 *   - the assigned driver's personal cellphone -- as a copy-to-clipboard
 *     pill for "allocate cash to <driver> <phone>".
 *
 * All of that is ProSelver-internal operational data; the dealer paid
 * a quoted line haul and has no business with the petty-cash slip.
 *
 * For executor=internal the dealer pays their own driver, so the same
 * block IS shown -- it's the dealer's own internal info.
 */

function pcGateDealer(): Company
{
    return Company::factory()->create([
        'name' => 'Petty Cash Gate Dealer',
        'type' => Company::TYPE_DEALER,
    ]);
}

function pcGateDealerUser(Company $dealer): User
{
    Role::firstOrCreate(['slug' => 'stock_controller'], ['name' => 'Stock Controller', 'tier' => 'dealer']);
    $role = Role::where('slug', 'stock_controller')->first();
    foreach ([
        'view_all_bookings' => 'view',
        'view_own_bookings' => 'view',
    ] as $slug => $name) {
        $p = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => 'test']);
        $role->permissions()->syncWithoutDetaching([$p->id]);
    }
    $u = User::factory()->create();
    $u->assignRole('stock_controller');
    $dealer->users()->attach($u->id);
    return $u;
}

function pcGateProselverDriver(): User
{
    return User::factory()->create([
        'name'  => 'Francis Mpheteng',
        'phone' => '072 614 1617',
    ]);
}

function pcGateMakeJob(Company $dealer, string $executor, ?User $driver = null): Job
{
    return Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_type'             => 'transport',
        'status'               => Job::STATUS_IN_TRANSIT,
        'company_id'           => $dealer->id,
        'created_by_user_id'   => $dealer->users()->first()?->id ?? User::factory()->create()->id,
        'executor_type'        => $executor,
        'driver_user_id'       => $driver?->id,
        'vin'                  => 'PETTYGATE001',
        'registration'         => 'ABC123GP',
        'scheduled_date'       => now()->addDay()->toDateString(),
        // ProSelver-internal advance plan -- attached to demonstrate
        // that values present on the row never leak when executor is
        // proselver but DO render for executor=internal.
        'advance_total'         => 840.00,
        'advance_tolls'         => 232.50,
        'advance_food'          => 150.00,
        'advance_taxi'          => 450.00,
        'estimated_toll_cost'   => 232.50,
        'advance_issued_at'     => now(),
    ]);
}

test('the dealer customer.orders.show page hides the Driver advance & tolls block when ProSelver moved the vehicle', function () {
    $dealer = pcGateDealer();
    $user   = pcGateDealerUser($dealer);
    $driver = pcGateProselverDriver();
    $job    = pcGateMakeJob($dealer, Job::EXECUTOR_PROSELVER, $driver);

    $this->actingAs($user);

    // The petty-cash widget ("Driver advance & tolls") must not render
    // for executor=proselver -- advance amounts, tolls, food, taxi and
    // the "Allocate cash to <driver>" pill are all ProSelver-internal
    // operational data and must stay off the dealer's screen.
    //
    // The driver's name + cellphone may still appear elsewhere on the
    // page (in the Executor / Driver card) as legitimate dispatch info
    // -- the dealer needs a number to call about gate access / ETA.
    // The assertions below intentionally target only the petty-cash
    // widget content, not the driver-contact card.
    Volt::test('customer.orders.show', ['job' => $job])
        ->assertDontSee('Driver advance &amp; tolls', false)
        ->assertDontSee('Driver advance & tolls')
        ->assertDontSee('Advance total')
        ->assertDontSee('R 840.00')
        ->assertDontSee('R 150.00')
        ->assertDontSee('R 450.00')
        ->assertDontSee('Allocate cash to');
});

test('the dealer customer.orders.show page shows the Driver advance & tolls block when the dealer used their own driver (executor=internal)', function () {
    $dealer = pcGateDealer();
    $user   = pcGateDealerUser($dealer);
    $driver = User::factory()->create(['name' => 'In-House Driver', 'phone' => '083 000 0000']);
    $job    = pcGateMakeJob($dealer, Job::EXECUTOR_INTERNAL, $driver);

    $this->actingAs($user);

    Volt::test('customer.orders.show', ['job' => $job])
        ->assertSee('Advance total')
        ->assertSee('R 840.00')
        ->assertSee('Allocate cash to')
        ->assertSee('083 000 0000');
});
