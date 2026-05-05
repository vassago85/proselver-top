<?php

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\JobEvent;
use App\Models\Location;
use App\Models\Role;
use App\Models\TrackerPosition;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Wallboard feature coverage. The wallboard renders three panels driven
 * entirely from the database (drivers, events, map). These tests pin
 * down (a) the auth surface, (b) that ops can actually see meaningful
 * data, and (c) that driver freshness colour-coding follows the
 * documented buckets — the latter is what tells dispatch at a glance
 * whether a tracker has dropped offline.
 */
beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    Role::create(['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal']);
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);
});

function asInternal(string $slug = 'super_admin'): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function makeWallboardDriver(string $name, ?string $trackerId): User
{
    $driver = User::factory()->create(['name' => $name, 'is_active' => true]);
    $driver->assignRole('driver');
    DriverProfile::create([
        'user_id' => $driver->id,
        'tracker_id' => $trackerId,
    ]);
    return $driver;
}

function wbCompanyAndLocation(string $companyName = 'Acme Customer', string $locName = 'Main Plant'): array
{
    $company = Company::factory()->create([
        'name' => $companyName,
        'type' => Company::TYPE_CUSTOMER,
    ]);
    $location = Location::create([
        'company_id' => $company->id,
        'company_name' => $locName,
        'address' => $locName,
        'type' => Location::TYPE_PLANT,
        'is_active' => true,
        'latitude' => -26.2041,
        'longitude' => 28.0473,
    ]);
    return [$company, $location];
}

function wbJob(int $companyId, ?int $driverUserId = null, ?int $pickupId = null, ?int $deliveryId = null, array $overrides = []): Job
{
    $vc = VehicleClass::firstOrCreate(['name' => 'Truck Class 4']);
    $createdBy = User::query()->oldest()->first()?->id ?? User::factory()->create()->id;

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => Job::TYPE_TRANSPORT,
        'status' => Job::STATUS_RECEIVED,
        'company_id' => $companyId,
        'created_by_user_id' => $createdBy,
        'driver_user_id' => $driverUserId,
        'pickup_location_id' => $pickupId,
        'delivery_location_id' => $deliveryId,
        'vehicle_class_id' => $vc->id,
        'scheduled_date' => now()->addDay(),
    ], $overrides));
}

it('blocks customers from the wallboard', function () {
    $customer = User::factory()->create(['is_active' => true]);
    $customer->assignRole('customer_owner');

    $this->actingAs($customer)
        ->get('/admin/wallboard')
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get('/admin/wallboard')->assertRedirect('/login');
});

it('lets ops_controller view the wallboard', function () {
    $this->actingAs(asInternal('operations_controller'))
        ->get('/admin/wallboard')
        ->assertOk();
});

it('lets dispatchers view the wallboard', function () {
    $this->actingAs(asInternal('dispatcher'))
        ->get('/admin/wallboard')
        ->assertOk();
});

it('classifies drivers into freshness buckets based on last-fix age', function () {
    asInternal();

    // Driver with a fix < 5 minutes old → idle (no job)
    makeWallboardDriver('Fresh Driver', 'IMEI-FRESH');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-FRESH',
        'latitude' => -26.20, 'longitude' => 28.04,
        'reported_at' => now()->subMinutes(2),
        'received_at' => now()->subMinutes(2),
    ]);

    makeWallboardDriver('Stale Driver', 'IMEI-STALE');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-STALE',
        'latitude' => -26.30, 'longitude' => 28.10,
        'reported_at' => now()->subMinutes(30),
        'received_at' => now()->subMinutes(30),
    ]);

    makeWallboardDriver('Cold Driver', 'IMEI-COLD');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-COLD',
        'latitude' => -26.40, 'longitude' => 28.20,
        'reported_at' => now()->subHours(3),
        'received_at' => now()->subHours(3),
    ]);

    makeWallboardDriver('No-Tracker Driver', null);

    $component = Volt::test('admin.wallboard.index');

    $rows = collect($component->viewData('rows'))->keyBy('name');

    expect($rows->get('Fresh Driver')->bucket)->toBe('idle');
    expect($rows->get('Stale Driver')->bucket)->toBe('stale');
    expect($rows->get('Cold Driver')->bucket)->toBe('offline');
    expect($rows->get('No-Tracker Driver')->bucket)->toBe('offline');

    $counts = $component->viewData('counts');
    expect($counts['total'])->toBe(4);
    expect($counts['idle'])->toBe(1);
    expect($counts['stale'])->toBe(1);
    expect($counts['offline'])->toBe(2);
});

it('flags a driver as on_job when they have an active assignment', function () {
    asInternal();

    $driver = makeWallboardDriver('Working Driver', 'IMEI-WORK');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-WORK',
        'latitude' => -26.0, 'longitude' => 28.0,
        'reported_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
    ]);

    [$company, $pickup] = wbCompanyAndLocation('Acme Customer', 'Pretoria Plant');
    $delivery = Location::create([
        'company_id' => $company->id,
        'company_name' => 'JHB Depot',
        'address' => 'JHB Depot',
        'type' => Location::TYPE_YARD,
        'is_active' => true,
    ]);

    wbJob($company->id, $driver->id, $pickup->id, $delivery->id, [
        'status' => Job::STATUS_IN_TRANSIT,
    ]);

    $component = Volt::test('admin.wallboard.index');
    $rows = collect($component->viewData('rows'))->keyBy('name');

    expect($rows->get('Working Driver')->bucket)->toBe('on_job');
    expect($rows->get('Working Driver')->job->status)->toBe(Job::STATUS_IN_TRANSIT);
    expect($rows->get('Working Driver')->job->pickup)->toBe('Pretoria Plant');
});

it('shows JobEvent rows in the events feed', function () {
    asInternal();

    $driver = makeWallboardDriver('Driver A', 'IMEI-A');
    [$company, $pickup] = wbCompanyAndLocation('Acme Customer', 'Plant X');
    $job = wbJob($company->id, $driver->id, $pickup->id);

    JobEvent::create([
        'job_id' => $job->id,
        'user_id' => $driver->id,
        'event_type' => JobEvent::TYPE_ARRIVED_PICKUP,
        'event_at' => now()->subMinutes(10),
    ]);
    JobEvent::create([
        'job_id' => $job->id,
        'user_id' => $driver->id,
        'event_type' => JobEvent::TYPE_DEPARTED_PICKUP,
        'event_at' => now()->subMinutes(5),
    ]);

    $events = collect(Volt::test('admin.wallboard.index')->viewData('events'));

    // 2 JobEvent rows + 1 synthesised "new order" row from the recent
    // Job::created_at.
    expect($events)->toHaveCount(3);

    $messages = $events->pluck('message')->implode("\n");
    expect($messages)->toContain('arrived at pickup');
    expect($messages)->toContain('departed');
    expect($messages)->toContain('New order');
});

it('synthesises a new-order row for jobs created in the last 6 hours', function () {
    asInternal();

    [$company, $pickup] = wbCompanyAndLocation('FAW South Africa', 'Coega Plant');

    wbJob($company->id, null, $pickup->id, null, [
        'created_at' => now()->subMinutes(15),
    ]);

    $events = collect(Volt::test('admin.wallboard.index')->viewData('events'));

    expect($events)->toHaveCount(1);
    expect($events->first()->kind)->toBe('new_order');
    expect($events->first()->message)->toContain('FAW South Africa');
});

it('hides offline drivers when showStale is toggled off', function () {
    asInternal();

    makeWallboardDriver('Fresh', 'IMEI-1');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-1',
        'latitude' => -26.0, 'longitude' => 28.0,
        'reported_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
    ]);
    makeWallboardDriver('Cold', 'IMEI-2');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-2',
        'latitude' => -26.0, 'longitude' => 28.0,
        'reported_at' => now()->subHours(3),
        'received_at' => now()->subHours(3),
    ]);

    $component = Volt::test('admin.wallboard.index')->set('showStale', false);
    $rows = collect($component->viewData('rows'));

    expect($rows->pluck('name')->all())->toBe(['Fresh']);

    $counts = $component->viewData('counts');
    expect($counts['total'])->toBe(2);
    expect($counts['offline'])->toBe(1);
});

it('exposes driver markers with normalised lat/lng for the map', function () {
    asInternal();

    makeWallboardDriver('Mapped', 'IMEI-M');
    TrackerPosition::create([
        'tracker_id' => 'IMEI-M',
        'latitude' => -26.20413,
        'longitude' => 28.04738,
        'reported_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
    ]);

    $markers = collect(Volt::test('admin.wallboard.index')->viewData('driverMarkers'));

    expect($markers)->toHaveCount(1);
    $first = $markers->first();
    expect($first['name'])->toBe('Mapped');
    expect($first['lat'])->toEqualWithDelta(-26.20413, 0.0001);
    expect($first['lng'])->toEqualWithDelta(28.04738, 0.0001);
    expect($first['bucket'])->toBe('idle');
});

it('enriches driver markers with phone, last-fix, and active job detail for the info window', function () {
    asInternal();

    $driver = User::factory()->create([
        'name' => 'Sipho M.',
        'phone' => '+27821234567',
        'is_active' => true,
    ]);
    $driver->assignRole('driver');
    DriverProfile::create(['user_id' => $driver->id, 'tracker_id' => 'IMEI-INFO']);

    TrackerPosition::create([
        'tracker_id' => 'IMEI-INFO',
        'latitude' => -26.0, 'longitude' => 28.0,
        'speed_kmh' => 78.4,
        'reported_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
    ]);

    [$company, $pickup] = wbCompanyAndLocation('Acme Customer', 'Pretoria Plant');
    $delivery = Location::create([
        'company_id' => $company->id,
        'company_name' => 'JHB Depot',
        'address' => 'JHB Depot',
        'type' => Location::TYPE_YARD,
        'is_active' => true,
    ]);

    wbJob($company->id, $driver->id, $pickup->id, $delivery->id, [
        'status' => Job::STATUS_IN_TRANSIT,
        'job_number' => 'JOB-INFO',
    ]);

    $markers = collect(Volt::test('admin.wallboard.index')->viewData('driverMarkers'));
    $first = $markers->first();

    expect($first['phone'])->toBe('+27821234567');
    expect($first['speed_kmh'])->toBe(78);
    expect($first['last_fix_human'])->not->toBeNull();
    expect($first['job']['job_number'])->toBe('JOB-INFO');
    expect($first['job']['pickup'])->toBe('Pretoria Plant');
    expect($first['job']['delivery'])->toBe('JHB Depot');
    expect($first['job']['customer'])->toBe('Acme Customer');
    expect($first['job']['detail_url'])->toContain('/admin/orders/');
});

it('boots in kiosk mode when ?kiosk=1 is on the URL', function () {
    $this->actingAs(asInternal('operations_controller'));

    // Hit the route with the query param so mount() picks it up.
    $response = $this->get('/admin/wallboard?kiosk=1');
    $response->assertOk();

    // The component should also expose kiosk=true via Volt::test+set, so
    // the rendered output omits the app shell.
    $component = Volt::test('admin.wallboard.index')->set('kiosk', true);
    $html = $component->html();
    expect($html)->toContain('Exit kiosk');
    expect($html)->not->toContain('Operations Wallboard</x-slot');
});

it('defaults to non-kiosk mode without the query param', function () {
    $this->actingAs(asInternal('operations_controller'));
    $component = Volt::test('admin.wallboard.index');
    expect($component->get('kiosk'))->toBeFalse();
    expect($component->html())->toContain('Kiosk mode');
});
