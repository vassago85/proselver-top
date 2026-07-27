<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Live Movements board coverage.
 *
 * This is the TV/wall board that replaced the old Operations Wallboard
 * when the TrackSolid position feed was dropped (see routes/admin.php).
 * One Volt component serves two routes, so the tests that matter are the
 * ones pinning down how it branches:
 *
 *   /admin/live-display  — internal, system-wide, ProSelver-executed work
 *                          only, with the extra "New Orders" lane.
 *   /customer/display    — tenant-scoped, every executor type, new-order
 *                          statuses folded back into Waiting.
 *
 * Lane membership is the whole point of the board, so each lane's status
 * bucket is asserted directly rather than through the rendered HTML.
 */
beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    Role::create(['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal']);
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    Role::create(['name' => 'Customer User', 'slug' => 'customer_user', 'tier' => 'customer']);
});

function ldInternal(string $slug = 'super_admin'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($slug);

    return $user;
}

function ldTenant(Company $company, string $slug = 'customer_owner', bool $withViewAll = true): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($slug);
    $company->users()->attach($user->id);

    if ($withViewAll) {
        $permission = Permission::firstOrCreate(
            ['slug' => 'view_all_bookings'],
            ['name' => 'View All Bookings', 'group' => 'Bookings']
        );
        $user->roles->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $user->unsetRelation('roles');
    }

    return $user;
}

function ldCompany(string $name = 'Acme Customer', string $type = Company::TYPE_DEALER): Company
{
    return Company::factory()->create(['name' => $name, 'type' => $type]);
}

function ldJob(Company $company, string $status, array $overrides = []): Job
{
    $vehicleClass = VehicleClass::firstOrCreate(['name' => 'Truck Class 4']);
    $pickup = Location::create([
        'company_id' => $company->id,
        'company_name' => 'Plant',
        'address' => 'Plant',
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => $company->id,
        'company_name' => 'Depot',
        'address' => 'Depot',
        'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-'.Str::upper(Str::random(6)),
        'job_type' => Job::TYPE_TRANSPORT,
        'status' => $status,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'company_id' => $company->id,
        'created_by_user_id' => User::query()->oldest()->first()?->id ?? User::factory()->create()->id,
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'vehicle_class_id' => $vehicleClass->id,
        'scheduled_date' => now()->addDay(),
    ], $overrides));
}

it('redirects guests to login', function () {
    $this->get('/admin/live-display')->assertRedirect('/login');
});

it('keeps the retired wallboard bookmark working by redirecting to the live display', function () {
    $this->actingAs(ldInternal('operations_controller'))
        ->get('/admin/wallboard')
        ->assertRedirect('/admin/live-display');
});

it('lets internal ops roles view the board', function (string $slug) {
    $this->actingAs(ldInternal($slug))
        ->get('/admin/live-display')
        ->assertOk();
})->with([
    'super admin' => ['super_admin'],
    'ops controller' => ['operations_controller'],
    'dispatcher' => ['dispatcher'],
]);

it('blocks tenant users from the internal board', function () {
    $this->actingAs(ldTenant(ldCompany()))
        ->get('/admin/live-display')
        ->assertForbidden();
});

it('needs view_all_bookings to open the tenant board', function () {
    $company = ldCompany();

    $this->actingAs(ldTenant($company, 'customer_user', withViewAll: false))
        ->get('/customer/display')
        ->assertForbidden();

    $this->actingAs(ldTenant($company, 'customer_owner'))
        ->get('/customer/display')
        ->assertOk();
});

it('sorts jobs into the waiting, in-transit and delivered lanes by status', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['job_number' => 'WAITING-1']);
    ldJob($company, Job::STATUS_IN_TRANSIT, ['job_number' => 'TRANSIT-1']);
    ldJob($company, Job::STATUS_DELIVERED, ['job_number' => 'DONE-1', 'scheduled_date' => today()]);

    $board = Volt::test('live-display');

    expect(collect($board->viewData('waiting'))->pluck('job_number')->all())->toBe(['WAITING-1']);
    expect(collect($board->viewData('inTransit'))->pluck('job_number')->all())->toBe(['TRANSIT-1']);
    expect(collect($board->viewData('deliveredToday'))->pluck('job_number')->all())->toBe(['DONE-1']);
});

it('only shows movements today in the delivered lane', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_DELIVERED, [
        'job_number' => 'TODAY-1',
        'scheduled_date' => today(),
    ]);
    // Delivered last week and not touched since, so it has dropped off
    // the board -- the lane is "Delivered Today", not "Delivered".
    $old = ldJob($company, Job::STATUS_COMPLETED, [
        'job_number' => 'LASTWEEK-1',
        'scheduled_date' => today()->subWeek(),
    ]);
    $old->timestamps = false;
    $old->updated_at = now()->subWeek();
    $old->save();

    $delivered = collect(Volt::test('live-display')->viewData('deliveredToday'));

    expect($delivered->pluck('job_number')->all())->toBe(['TODAY-1']);
});

// The 4th lane is gated on the route name, not just on the user's tier, so
// these two have to go through the real URL -- Volt::test() renders without
// route context and would report the lane hidden either way.
it('gives the internal board its own new orders lane', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_RECEIVED, ['job_number' => 'NEWORDER-1']);
    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['job_number' => 'WAITING-1']);

    // Matched against the lane heading markup rather than the bare words,
    // which also appear in a comment inside the board's inline script.
    $this->get('/admin/live-display')
        ->assertOk()
        ->assertSee('New Orders</h2>', false)
        ->assertSee('NEWORDER-1')
        ->assertSee('WAITING-1');
});

it('folds new orders back into waiting on the tenant board', function () {
    $company = ldCompany();
    $this->actingAs(ldTenant($company));

    ldJob($company, Job::STATUS_RECEIVED, ['job_number' => 'NEWORDER-1']);
    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['job_number' => 'WAITING-1']);

    $this->get('/customer/display')
        ->assertOk()
        ->assertDontSee('New Orders</h2>', false)
        ->assertSee('NEWORDER-1')
        ->assertSee('WAITING-1');
});

it('limits the internal board to movements ProSelver is actually executing', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_IN_TRANSIT, ['job_number' => 'OURS-1']);
    ldJob($company, Job::STATUS_IN_TRANSIT, [
        'job_number' => 'THEIRS-1',
        'executor_type' => Job::EXECUTOR_THIRD_PARTY,
    ]);

    $inTransit = collect(Volt::test('live-display')->viewData('inTransit'));

    expect($inTransit->pluck('job_number')->all())->toBe(['OURS-1']);
});

it('shows a tenant every executor type but only their own company', function () {
    $mine = ldCompany('My Dealership');
    $theirs = ldCompany('Rival Dealership');
    $this->actingAs(ldTenant($mine));

    ldJob($mine, Job::STATUS_IN_TRANSIT, ['job_number' => 'MINE-PROSELVER']);
    ldJob($mine, Job::STATUS_IN_TRANSIT, [
        'job_number' => 'MINE-OWN-DRIVER',
        'executor_type' => Job::EXECUTOR_INTERNAL,
    ]);
    ldJob($theirs, Job::STATUS_IN_TRANSIT, ['job_number' => 'THEIRS-1']);

    $inTransit = collect(Volt::test('live-display')->viewData('inTransit'));

    expect($inTransit->pluck('job_number')->sort()->values()->all())
        ->toBe(['MINE-OWN-DRIVER', 'MINE-PROSELVER']);
});

it('never shows archived movements', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_IN_TRANSIT, ['job_number' => 'LIVE-1']);
    ldJob($company, Job::STATUS_IN_TRANSIT, [
        'job_number' => 'ARCHIVED-1',
        'archived_at' => now(),
    ]);

    $inTransit = collect(Volt::test('live-display')->viewData('inTransit'));

    expect($inTransit->pluck('job_number')->all())->toBe(['LIVE-1']);
});

it('counts waiting movements that still have nobody to drive them', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    $driver = User::factory()->create(['is_active' => true]);

    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['driver_user_id' => $driver->id]);
    ldJob($company, Job::STATUS_CONFIRMED, ['driver_user_id' => null]);
    ldJob($company, Job::STATUS_PLANNED, ['driver_user_id' => null]);

    expect(Volt::test('live-display')->viewData('unassignedCount'))->toBe(2);
});

it('counts urgent movements across every lane that is still moving', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_RECEIVED, ['is_urgent' => true]);
    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['is_urgent' => true]);
    ldJob($company, Job::STATUS_IN_TRANSIT, ['is_urgent' => true]);
    ldJob($company, Job::STATUS_IN_TRANSIT, ['is_urgent' => false]);
    // Already delivered, so its urgency is moot.
    ldJob($company, Job::STATUS_DELIVERED, ['is_urgent' => true, 'scheduled_date' => today()]);

    expect(Volt::test('live-display')->viewData('urgentCount'))->toBe(3);
});

it('counts a waiting movement as delayed once its scheduled time has passed', function () {
    $this->actingAs(ldInternal());
    $company = ldCompany();

    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['scheduled_date' => today()->subDays(3)]);
    ldJob($company, Job::STATUS_DRIVER_ASSIGNED, ['scheduled_date' => today()->addWeek()]);

    expect(Volt::test('live-display')->viewData('delayedCount'))->toBe(1);
});
