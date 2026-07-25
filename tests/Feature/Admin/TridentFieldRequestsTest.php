<?php

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\PettyCashEntry;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------
// Shared setup
// -----------------------------------------------------------------

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'developer'], ['name' => 'Developer', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'dispatcher'], ['name' => 'Dispatcher', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'ops_manager'], ['name' => 'Ops Manager', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
});

function tfrUserWithRole(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function tfrPlatformCompany(): Company
{
    return Company::factory()->create([
        'name' => 'ProSelver',
        'type' => Company::TYPE_OEM,
        'is_platform_owner' => true,
    ]);
}

function tfrDriver(?Company $platform = null, ?int $rateCents = null): User
{
    $platform ??= tfrPlatformCompany();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('driver');
    $user->companies()->syncWithoutDetaching([$platform->id]);
    DriverProfile::create([
        'user_id' => $user->id,
        'rate_per_movement_cents' => $rateCents,
    ]);
    return $user;
}

function tfrProselverJob(array $extras = []): Job
{
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_id' => null,
        'company_name' => 'Plant',
        'address' => 'Plant',
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null,
        'company_name' => 'Dealer',
        'address' => 'Dealer',
        'is_active' => true,
    ]);
    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $oem->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
        'delivered_at' => now(),
    ], $extras));
}

// -----------------------------------------------------------------
// 1. Customer Invoicing performance fixes
// -----------------------------------------------------------------

test('customer invoicing page paginates instead of loading every job at once', function () {
    $accounts = tfrUserWithRole('accounts');
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    $pickup = Location::create(['company_name' => 'Plant', 'address' => 'p', 'is_active' => true]);
    $delivery = Location::create(['company_name' => 'Dealer', 'address' => 'd', 'is_active' => true]);

    for ($i = 0; $i < 55; $i++) {
        Job::create([
            'uuid' => (string) Str::uuid(),
            'job_number' => 'JOB-' . Str::upper(Str::random(6)),
            'job_type' => 'transport',
            'status' => Job::STATUS_DELIVERED,
            'company_id' => $oem->id,
            'created_by_user_id' => $creator->id,
            'executor_type' => Job::EXECUTOR_PROSELVER,
            'vin' => 'VIN' . Str::upper(Str::random(8)),
            'pickup_location_id' => $pickup->id,
            'delivery_location_id' => $delivery->id,
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => now()->subDays(2),
        ]);
    }

    $this->actingAs($accounts);

    Volt::test('admin.invoices.index')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(5)->toDateString())
        ->set('dateTo', now()->toDateString())
        ->set('completion', 'all')
        ->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 55 && count($jobs->items()) === 50);
});

test('window totals come from a DB aggregate and match the underlying filter', function () {
    $accounts = tfrUserWithRole('accounts');
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);

    tfrProselverJob(['company_id' => $oem->id, 'delivered_at' => now(), 'invoice_amount' => 1200.50]);
    tfrProselverJob(['company_id' => $oem->id, 'delivered_at' => now(), 'invoice_amount' => 800.00, 'invoicing_completed_at' => now()]);
    tfrProselverJob(['company_id' => $oem->id, 'delivered_at' => now(), 'invoice_amount' => 500.00, 'invoicing_excluded_at' => now()]);

    $this->actingAs($accounts);

    $c = Volt::test('admin.invoices.index')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(1)->toDateString())
        ->set('dateTo', now()->addDay()->toDateString())
        ->set('completion', 'all');

    $totals = $c->viewData('totals');
    expect($totals['window_total'])->toBe(3)
        ->and($totals['window_excluded'])->toBe(1)
        ->and($totals['window_billable'])->toBe(2)
        ->and($totals['window_complete'])->toBe(1)
        ->and($totals['invoice'])->toBe(2500.50);
});

test('switching completion filter clears the per-row inputs and resets the page', function () {
    $accounts = tfrUserWithRole('accounts');
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);

    $j1 = tfrProselverJob(['company_id' => $oem->id, 'delivered_at' => now(), 'invoicing_completed_at' => now()]);
    $j2 = tfrProselverJob(['company_id' => $oem->id, 'delivered_at' => now()]);

    $this->actingAs($accounts);

    $c = Volt::test('admin.invoices.index')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDay()->toDateString())
        ->set('dateTo', now()->addDay()->toDateString())
        ->set('completion', 'incomplete');

    // On 'incomplete', only j2 should be present in $rows.
    $rows = $c->get('rows');
    expect($rows)->toHaveKey($j2->id)->and($rows)->not->toHaveKey($j1->id);

    $c->call('setCompletion', 'complete');
    $rows = $c->get('rows');
    expect($rows)->toHaveKey($j1->id)->and($rows)->not->toHaveKey($j2->id);
});

// -----------------------------------------------------------------
// 2. Petty cash Overview access + monthly reporting
// -----------------------------------------------------------------

test('accounts can now open the petty cash overview page', function () {
    $accounts = tfrUserWithRole('accounts');

    $this->actingAs($accounts)
        ->get(route('admin.overview'))
        ->assertOk();
});

test('operations controller can now open the petty cash overview page', function () {
    $ops = tfrUserWithRole('operations_controller');

    $this->actingAs($ops)
        ->get(route('admin.overview'))
        ->assertOk();
});

test('dispatcher (internal but not accounts/ops/owner/dev) still 403s on the overview page', function () {
    $dispatcher = tfrUserWithRole('dispatcher');

    $this->actingAs($dispatcher)
        ->get(route('admin.overview'))
        ->assertForbidden();
});

test('overview month range picks the requested calendar month', function () {
    $accounts = tfrUserWithRole('accounts');
    $this->actingAs($accounts);

    $picked = now()->subMonthsNoOverflow(3);

    $c = Volt::test('admin.overview')
        ->set('monthPick', $picked->format('Y-m'));

    // updatedMonthPick() flips range to 'month' when a value is entered.
    expect($c->get('range'))->toBe('month');

    $win = $c->viewData('win');
    expect($win['from']->format('Y-m-d'))->toBe($picked->copy()->startOfMonth()->format('Y-m-d'))
        ->and($win['to']->format('Y-m-d'))->toBe($picked->copy()->endOfMonth()->format('Y-m-d'));
});

// -----------------------------------------------------------------
// 3. Driver rate + pay report
// -----------------------------------------------------------------

test('driver_profiles.rate_per_movement_cents is fillable and cast as integer', function () {
    $user = User::factory()->create();
    $profile = DriverProfile::create([
        'user_id' => $user->id,
        'rate_per_movement_cents' => 35000,
    ]);
    expect($profile->rate_per_movement_cents)->toBe(35000)
        ->and($profile->ratePerMovementRand())->toBe(350.0);
});

test('driver create form saves rate_per_movement_cents when submitted by accounts', function () {
    $accounts = tfrUserWithRole('accounts');
    tfrPlatformCompany();

    $this->actingAs($accounts);

    Volt::test('admin.drivers.create')
        ->set('name', 'Test Driver')
        ->set('username', 'test.driver')
        ->set('password', 'password123')
        ->set('ratePerMovement', '425.50')
        ->call('save');

    $driver = User::where('username', 'test.driver')->firstOrFail();
    expect($driver->driverProfile)->not->toBeNull()
        ->and($driver->driverProfile->rate_per_movement_cents)->toBe(42550);
});

test('driver create form does NOT save rate when submitted by a role without pay access', function () {
    $dispatcher = tfrUserWithRole('dispatcher');
    tfrPlatformCompany();

    $this->actingAs($dispatcher);

    Volt::test('admin.drivers.create')
        ->set('name', 'Test Driver')
        ->set('username', 'test.driver')
        ->set('password', 'password123')
        // Even though the UI hides it, a crafted payload sets it.
        ->set('ratePerMovement', '999.99')
        ->call('save');

    $driver = User::where('username', 'test.driver')->firstOrFail();
    expect($driver->driverProfile->rate_per_movement_cents)->toBeNull();
});

test('driver pay report 403s a dispatcher and 200s for accounts', function () {
    $accounts = tfrUserWithRole('accounts');
    $dispatcher = tfrUserWithRole('dispatcher');

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers.pay'))
        ->assertForbidden();

    $this->actingAs($accounts)
        ->get(route('admin.drivers.pay'))
        ->assertOk();
});

test('driver pay report computes movements, earnings, cost, advances and petty cash for the month', function () {
    $accounts = tfrUserWithRole('accounts');
    $platform = tfrPlatformCompany();
    $driver = tfrDriver($platform, 30000); // R300 / move

    $inMonth = now()->startOfMonth()->addDays(10);
    $prevMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);

    // Two completed movements in-month, one not-in-month.
    tfrProselverJob([
        'driver_user_id' => $driver->id,
        'delivered_at' => $inMonth,
        'status' => Job::STATUS_DELIVERED,
        'total_cost' => 1500,
    ]);
    tfrProselverJob([
        'driver_user_id' => $driver->id,
        'delivered_at' => $inMonth->copy()->addDay(),
        'status' => Job::STATUS_COMPLETED,
        'total_cost' => 2000,
    ]);
    tfrProselverJob([
        'driver_user_id' => $driver->id,
        'delivered_at' => $prevMonth,
        'status' => Job::STATUS_DELIVERED,
        'total_cost' => 4000,
    ]);

    // Advance issued in-month.  Create it on an existing job so it
    // doesn't add another "delivered" row and inflate the moves count.
    Job::whereKey(Job::query()->latest('id')->value('id'))->update([
        'advance_total' => 800,
        'advance_assigned_at' => $inMonth,
    ]);

    // Petty cash slips.
    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_FUEL,
        'amount_cents' => 25000,
        'status' => PettyCashEntry::STATUS_APPROVED,
        'created_at' => $inMonth,
        'updated_at' => $inMonth,
    ]);
    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_TOLL,
        'amount_cents' => 15000,
        'status' => PettyCashEntry::STATUS_REIMBURSED,
        'created_at' => $inMonth,
        'updated_at' => $inMonth,
    ]);
    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_FOOD,
        'amount_cents' => 99999,
        'status' => PettyCashEntry::STATUS_REJECTED,
        'created_at' => $inMonth,
        'updated_at' => $inMonth,
    ]);

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.pay')->set('month', now()->format('Y-m'));

    $rows = $c->viewData('rows');
    $row = $rows->firstWhere('id', $driver->id);

    expect($row['moves'])->toBe(2)
        ->and($row['rate'])->toBe(300.0)
        ->and($row['earnings'])->toBe(600.0)
        ->and($row['cost'])->toBe(3500.0)
        ->and($row['advances'])->toBe(800.0)
        ->and($row['spend'])->toBe(400.0);   // 25000 + 15000 cents, rejected excluded
});

// -----------------------------------------------------------------
// 4. Orders driver filter
// -----------------------------------------------------------------

test('orders index filters by driver via the ?driver=<id> URL parameter', function () {
    $ops = tfrUserWithRole('operations_controller');
    $platform = tfrPlatformCompany();
    $d1 = tfrDriver($platform);
    $d2 = tfrDriver($platform);

    $j1 = tfrProselverJob(['driver_user_id' => $d1->id, 'status' => Job::STATUS_DRIVER_ASSIGNED]);
    $j2 = tfrProselverJob(['driver_user_id' => $d2->id, 'status' => Job::STATUS_DRIVER_ASSIGNED]);

    $this->actingAs($ops);

    $c = Volt::test('admin.orders.index')->set('driverId', $d1->id);

    $jobs = $c->viewData('jobs');
    expect($jobs->pluck('id')->all())->toContain($j1->id)->not->toContain($j2->id);
});

// -----------------------------------------------------------------
// 5. Trips driver-aware totals
// -----------------------------------------------------------------

test('trips KPI strip returns driver-scoped totals when a driver is selected', function () {
    $ops = tfrUserWithRole('operations_controller');
    $platform = tfrPlatformCompany();
    $driver = tfrDriver($platform);
    $company = Company::factory()->create();

    // Two completed trips for our driver, one in-range one out.
    Trip::create([
        'company_id' => $company->id,
        'driver_user_id' => $driver->id,
        'trip_date' => now()->subDay()->toDateString(),
        'status' => Trip::STATUS_COMPLETED,
    ]);
    Trip::create([
        'company_id' => $company->id,
        'driver_user_id' => $driver->id,
        'trip_date' => now()->subDays(30)->toDateString(),
        'status' => Trip::STATUS_COMPLETED,
    ]);

    tfrProselverJob([
        'driver_user_id' => $driver->id,
        'delivered_at' => now()->subHours(2),
        'total_cost' => 2500,
    ]);

    $this->actingAs($ops);

    $c = Volt::test('admin.trips.index')
        ->set('driverFilter', $driver->id)
        ->set('dateFrom', now()->subDays(3)->toDateString())
        ->set('dateTo', now()->addDay()->toDateString());

    $kpis = $c->viewData('driverKpis');

    expect($kpis)->not->toBeNull()
        ->and($kpis['completed_trips'])->toBe(1)
        ->and($kpis['moves'])->toBe(1)
        ->and($kpis['cost'])->toBe(2500.0);
});

// -----------------------------------------------------------------
// 6. Order edit fields (VIN, pickup, delivery)
// -----------------------------------------------------------------

test('dispatcher (has JobPolicy::update) can correct VIN, and original_vin is preserved', function () {
    $dispatcher = tfrUserWithRole('dispatcher');
    $job = tfrProselverJob(['status' => Job::STATUS_PLANNED, 'vin' => 'OLDVIN1234']);

    $this->actingAs($dispatcher);

    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openEditBookingDetails')
        ->set('newVin', 'NEWVIN9999')
        ->set('bookingEditReason', 'Corrected typo from booking form')
        ->call('saveBookingDetails')
        ->assertHasNoErrors();

    $job->refresh();
    expect($job->vin)->toBe('NEWVIN9999')
        ->and($job->original_vin)->toBe('OLDVIN1234')
        ->and($job->vehicle_reassigned_by)->toBe($dispatcher->id)
        ->and($job->vehicle_reassigned_at)->not->toBeNull();
});

test('a customer_owner cannot open the edit panel (JobPolicy::update denies)', function () {
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    $customer = User::factory()->create(['is_active' => true]);
    $customer->assignRole('customer_owner');
    $job = tfrProselverJob(['status' => Job::STATUS_PLANNED]);

    $this->actingAs($customer);

    expect(Volt::test('admin.orders.show', ['job' => $job])->instance()->canEditBookingDetails())->toBeFalse();
});

test('edit blocks pickup/delivery changes while the job is on an active trip', function () {
    $dispatcher = tfrUserWithRole('dispatcher');
    $platform = tfrPlatformCompany();
    $driver = tfrDriver($platform);
    $company = Company::factory()->create();

    $trip = Trip::create([
        'company_id' => $company->id,
        'driver_user_id' => $driver->id,
        'trip_date' => now()->toDateString(),
        'status' => Trip::STATUS_IN_PROGRESS,
    ]);

    $job = tfrProselverJob([
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'trip_id' => $trip->id,
    ]);

    $newPickup = Location::create([
        'company_name' => 'Other pickup', 'address' => 'x', 'is_active' => true,
    ]);
    $newDelivery = Location::create([
        'company_name' => 'Other delivery', 'address' => 'y', 'is_active' => true,
    ]);

    $this->actingAs($dispatcher);

    $originalPickup = $job->pickup_location_id;

    // We can still edit VIN (locations only require validation when
    // NOT locked; a change here should not write pickup/delivery).
    Volt::test('admin.orders.show', ['job' => $job])
        ->call('openEditBookingDetails')
        ->set('newVin', 'STILLEDITABLE')
        ->set('newPickupLocationId', $newPickup->id)
        ->set('newDeliveryLocationId', $newDelivery->id)
        ->set('bookingEditReason', 'On trip so only VIN should change')
        ->call('saveBookingDetails')
        ->assertHasNoErrors();

    $job->refresh();
    expect($job->vin)->toBe('STILLEDITABLE')
        ->and($job->pickup_location_id)->toBe($originalPickup);
});
