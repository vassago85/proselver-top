<?php

/**
 * Pre-deploy smoke: render the two pages this branch touches (fuel
 * ops + customer invoicing) as an internal user and assert HTTP 200.
 *
 * These pages are Blade-heavy and any missing key / stale template
 * would 500 in production; the existing test coverage exercises the
 * Livewire methods but not the initial full-page render.  Keeping
 * these smokes cheap so they run on every push.
 */

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use App\Services\Tfn\TfnTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
    // Keep the licence meter deterministic so the finance dashboard
    // banner doesn't lift its head into the smoke assertions.
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, true, 'boolean');
});

test('/admin/fuel renders 200 for an operations controller in demo mode', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $this->actingAs($u)
        ->get('/admin/fuel')
        ->assertOk()
        // Real proof the demo data flowed through both fixtures and
        // the flattener: at least one driver-trade-plate row lands in
        // the picker / open-orders / transactions blocks.
        ->assertSee('TPJHB011')
        // The banner about demo-mode config renders when TFN is off.
        ->assertSee('demo');
});

test('/admin/fuel renders 200 when TFN returns real-shaped vehicles (no VIN field)', function () {
    // Regression for the 2026-09-03 production 500:
    //   Undefined array key "VIN" at storage/framework/views/...
    // Real TFN /api/Vehicle only carries Registration / FleetNumber /
    // TankSize / Status / ExternalNumber -- no VIN key.  The picker
    // used `$v['VIN'] ?: $v['Registration']` which walks the left
    // operand and raises an Undefined-key warning; in production
    // Laravel promotes that warning to a fatal, and the whole page
    // 500s.  Binding a fake TfnClient that returns the same real
    // shape locks the fix in.

    config()->set('tfn.enabled', true);
    config()->set('tfn.demo_mode', false);
    config()->set('tfn.username', 'test');
    config()->set('tfn.password', 'test');
    config()->set('tfn.customer_number', '01/2951');

    $realShapedVehicles = [
        ['Registration' => 'ABC214EC', 'FleetNumber' => 'OUTBOUND', 'TankSize' => 1000, 'Status' => 3, 'ExternalNumber' => null],
        ['Registration' => 'ABJ320EC', 'FleetNumber' => 'OUTBOUND', 'TankSize' => 1000, 'Status' => 3, 'ExternalNumber' => null],
        ['Registration' => 'AFK185GP', 'FleetNumber' => '',         'TankSize' => 200,  'Status' => 2, 'ExternalNumber' => null],
    ];

    $fake = new class(app(TfnTokenManager::class), $realShapedVehicles) extends TfnClient {
        public function __construct(TfnTokenManager $t, private array $vehiclesFixture) { parent::__construct($t); }
        public function isLive(): bool { return true; }
        public function ping(): array { return ['status' => 'ok', 'timestamp' => null, 'latency_ms' => 12]; }
        public function depots(): array { return (new TfnDemoFixtures())->depots(); }
        public function vehicles(): array { return $this->vehiclesFixture; }
        public function virtualCardNumber(string $r): array { return []; }
        public function pricing(string $p): array { return (new TfnDemoFixtures())->pricing(); }
        public function subAccountBalance(): array { return ['AccountBalance' => 175555.31, 'AccountAvailableBalance' => 175555.31]; }
        public function subAccountAggregateLitres(?\DateTimeInterface $m = null): array { return (new TfnDemoFixtures())->aggregateLitres(); }
        public function transactions(?\DateTimeInterface $c = null): array { return []; }
        public function orders(?\DateTimeInterface $m = null): array { return []; }
    };
    $this->app->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $this->actingAs($u)
        ->get('/admin/fuel')
        ->assertOk()
        ->assertSee('ABC214EC');
});

test('/admin/invoices renders 200 for accounts even when jobs have no driver', function () {
    $accounts = User::factory()->create(['is_active' => true]);
    $accounts->assignRole('accounts');

    // Seed one delivered job with no driver, one with a driver whose
    // profile has a trade plate -- proves the eager-load path used by
    // the fuel reconciler doesn't blow up when either side is missing.
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    $pickup = Location::create(['company_id' => null, 'company_name' => 'Plant', 'address' => 'Plant', 'is_active' => true]);
    $delivery = Location::create(['company_id' => null, 'company_name' => 'Dealer', 'address' => 'Dealer', 'is_active' => true]);

    $baseJob = fn () => [
        'uuid' => (string) Str::uuid(),
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->subDay()->toDateString(),
        'collected_at' => now()->subDay(),
        'delivered_at' => now()->subHours(2),
    ];

    // Job without a driver.
    Job::create(array_merge($baseJob(), [
        'job_number' => 'JOB-' . Str::upper(Str::random(8)),
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'registration' => 'ND456GP',
        'driver_user_id' => null,
    ]));

    // Job with a driver + trade plate.
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole('driver');
    DriverProfile::create(['user_id' => $driver->id, 'trade_plate' => 'TPJHB011']);

    Job::create(array_merge($baseJob(), [
        'job_number' => 'JOB-' . Str::upper(Str::random(8)),
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'registration' => null,
        'driver_user_id' => $driver->id,
    ]));

    $this->actingAs($accounts)
        ->get(route('admin.invoices.index'))
        ->assertOk();
});
