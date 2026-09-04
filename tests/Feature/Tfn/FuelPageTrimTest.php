<?php

/**
 * Regressions for the 2026-09-03 "too much info + phantom transaction"
 * fuel-page cleanup:
 *
 *   1. Fleet grid ("Vehicles with open TFN orders") is scoped to
 *      registrations that actually appear on an open order -- not the
 *      entire TFN vehicle catalogue.  Real customer accounts hold 1000+
 *      registered vehicles and rendering the whole list was noise.
 *
 *   2. Transactions are attached to fleet rows by VehicleRegistration
 *      (real TFN key), not VIN.  The previous groupBy('VIN') bucketed
 *      every real transaction under the empty-string key and every row
 *      in the grid showed the same phantom "last transaction".
 *
 *   3. Cards are only looked up for the small set of vehicles with
 *      open orders, not for every vehicle on the account.  Sequential
 *      1000+ /api/VirtualCardNumber calls per page render was the
 *      perf killer.
 */

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TfnFuelOrderPlacement;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use App\Services\Tfn\TfnTokenManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fake TfnClient that lets each test wire up exactly the payload it
 * cares about + counts how many times each read is called (used to
 * assert we're not walking every vehicle for cards on every render).
 */
function fakeTfnClient(array $overrides = []): TfnClient
{
    return new class(app(TfnTokenManager::class), $overrides) extends TfnClient {
        public array $calls = [];
        public function __construct(TfnTokenManager $t, private array $data)
        {
            parent::__construct($t);
        }
        public function isLive(): bool { return true; }
        public function ping(): array { return ['status' => 'ok', 'timestamp' => null, 'latency_ms' => 1]; }
        public function depots(): array { $this->calls['depots'] = ($this->calls['depots'] ?? 0) + 1; return $this->data['depots'] ?? (new TfnDemoFixtures())->depots(); }
        public function vehicles(): array { $this->calls['vehicles'] = ($this->calls['vehicles'] ?? 0) + 1; return $this->data['vehicles'] ?? []; }
        public function virtualCardNumber(string $r): array {
            $this->calls['virtualCardNumber'] = ($this->calls['virtualCardNumber'] ?? 0) + 1;
            $this->calls['virtualCardNumberFor'][] = $r;
            return $this->data['cards'][$r] ?? [];
        }
        public function pricing(string $p): array { return (new TfnDemoFixtures())->pricing(); }
        public function subAccountBalance(): array { return ['AccountBalance' => 175555.31, 'AccountAvailableBalance' => 175555.31]; }
        public function subAccountAggregateLitres(?\DateTimeInterface $m = null): array { return []; }
        public function transactions(?\DateTimeInterface $c = null): array { $this->calls['transactions'] = ($this->calls['transactions'] ?? 0) + 1; return $this->data['transactions'] ?? []; }
        public function orders(?\DateTimeInterface $m = null): array { $this->calls['orders'] = ($this->calls['orders'] ?? 0) + 1; return $this->data['orders'] ?? []; }
    };
}

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, true, 'boolean');

    config()->set('tfn.enabled', true);
    config()->set('tfn.demo_mode', false);
    config()->set('tfn.username', 'test');
    config()->set('tfn.password', 'test');
    config()->set('tfn.customer_number', '01/2951');
});

test('fleet grid shows only vehicles with an open order, not the whole catalogue', function () {
    // 5 vehicles on the account -- most are Unused / Stolen / Dormant.
    // Only 2 are actually in flight (i.e. have an open TFN order).
    $vehicles = [
        ['Registration' => 'AGH818GP', 'FleetNumber' => 'FUEL',     'TankSize' => 1000, 'Status' => 3],
        ['Registration' => 'MR44JRGP', 'FleetNumber' => '',         'TankSize' => 100,  'Status' => 3],
        ['Registration' => 'ABJ427FS', 'FleetNumber' => '',         'TankSize' => 400,  'Status' => 6],  // Sold
        ['Registration' => 'AFY683GP', 'FleetNumber' => 'FUEL',     'TankSize' => 1000, 'Status' => 4],  // Stolen
        ['Registration' => 'ABC214EC', 'FleetNumber' => 'OUTBOUND', 'TankSize' => 1000, 'Status' => 3],  // no open order
    ];

    // Two open orders: AGH818GP + MR44JRGP.  The rest of the catalogue
    // has no active order and should NOT appear in the fleet grid.
    $orders = [
        [
            'OrderNumber' => 'ORD/01/2951/11291',
            'Status' => 'open',
            'StatusTitle' => 'Open',
            'Entries' => [[
                'VehicleRegistration' => 'AGH818GP',
                'ProductCode' => 'D0',
                'Litres' => 200,
                'Amount' => 5000,
            ]],
        ],
        [
            'OrderNumber' => 'ORD/01/2951/11235',
            'Status' => 'open',
            'StatusTitle' => 'Open',
            'Entries' => [[
                'VehicleRegistration' => 'MR44JRGP',
                'ProductCode' => 'D0',
                'Litres' => 100,
                'Amount' => 2600,
            ]],
        ],
    ];

    $fake = fakeTfnClient(compact('vehicles', 'orders'));
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    // Live path only tracks orders TRIDENT itself placed.
    foreach (['ORD/01/2951/11291', 'ORD/01/2951/11235'] as $num) {
        TfnFuelOrderPlacement::query()->create([
            'order_number'         => $num,
            'vehicle_registration' => $num === 'ORD/01/2951/11291' ? 'AGH818GP' : 'MR44JRGP',
            'product_code'         => 'D0',
            'litres'               => 200,
            'user_id'              => $u->id,
            'placed_by_name'       => $u->name,
            'placed_at'            => now(),
        ]);
    }

    $response = $this->actingAs($u)->get('/admin/fuel')->assertOk();

    // In-flight regs shown.
    $response->assertSee('AGH818GP');
    $response->assertSee('MR44JRGP');

    // Fleet grid header count -- "2 open" not "5 open".
    $response->assertSee('2 open');

    // Header names TRIDENT-placed orders (not the whole TFN catalogue).
    $response->assertSee('Vehicles with open TRIDENT fuel orders');
});

test('live fleet stays empty when TFN has vehicles but TRIDENT has placed no orders', function () {
    // Regression for the "1080 open" dump: empty tracked-order set
    // must NOT fall through to the full vehicle catalogue.
    $vehicles = [
        ['Registration' => 'ABC214EC', 'FleetNumber' => 'FUEL', 'TankSize' => 1000, 'Status' => 3],
        ['Registration' => 'ABJ427FS', 'FleetNumber' => '',     'TankSize' => 400,  'Status' => 6],
        ['Registration' => 'AGH818GP', 'FleetNumber' => 'FUEL', 'TankSize' => 1000, 'Status' => 3],
    ];

    $fake = fakeTfnClient([
        'vehicles' => $vehicles,
        'orders'   => [],
    ]);
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $this->actingAs($u)->get('/admin/fuel')
        ->assertOk()
        ->assertSee('0 open')
        ->assertSee('Vehicles with open TRIDENT fuel orders');
    // Plates may still appear in the order-form vehicle picker (full
    // TFN catalogue) — that is intentional.  The regression is the
    // fleet badge claiming "1080 open".
});

test('cards are only looked up for vehicles with open orders (not the whole catalogue)', function () {
    // 20 registered vehicles, 1 open order.  We expect exactly ONE
    // /api/VirtualCardNumber call, not 20.
    $vehicles = collect(range(1, 20))->map(fn ($i) => [
        'Registration' => 'TEST' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'FleetNumber' => 'OUT',
        'TankSize' => 1000,
        'Status' => 3,
    ])->all();

    $orders = [[
        'OrderNumber' => 'ORD/01/2951/1',
        'Status' => 'open',
        'StatusTitle' => 'Open',
        'Entries' => [[
            'VehicleRegistration' => 'TEST007',
            'ProductCode' => 'D0',
            'Litres' => 200,
            'Amount' => 5000,
        ]],
    ]];

    $fake = fakeTfnClient(compact('vehicles', 'orders'));
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    TfnFuelOrderPlacement::query()->create([
        'order_number'         => 'ORD/01/2951/1',
        'vehicle_registration' => 'TEST007',
        'product_code'         => 'D0',
        'litres'               => 200,
        'user_id'              => $u->id,
        'placed_by_name'       => $u->name,
        'placed_at'            => now(),
    ]);

    $this->actingAs($u)->get('/admin/fuel')->assertOk();

    expect($fake->calls['virtualCardNumber'] ?? 0)->toBe(1);
    expect($fake->calls['virtualCardNumberFor'] ?? [])->toBe(['TEST007']);
});

test('transactions are attached to the correct fleet row by VehicleRegistration (no phantom bleed)', function () {
    $vehicles = [
        ['Registration' => 'AGH818GP', 'FleetNumber' => 'FUEL',     'TankSize' => 1000, 'Status' => 3],
        ['Registration' => 'MR44JRGP', 'FleetNumber' => '',         'TankSize' => 100,  'Status' => 3],
    ];

    $orders = [
        ['OrderNumber' => 'A', 'Status' => 'open', 'StatusTitle' => 'Open',
         'Entries' => [['VehicleRegistration' => 'AGH818GP', 'ProductCode' => 'D0', 'Litres' => 200, 'Amount' => 5000]]],
        ['OrderNumber' => 'B', 'Status' => 'open', 'StatusTitle' => 'Open',
         'Entries' => [['VehicleRegistration' => 'MR44JRGP', 'ProductCode' => 'D0', 'Litres' => 100, 'Amount' => 2600]]],
    ];

    // Only ONE transaction, keyed on VehicleRegistration=AGH818GP.
    // Under the old (broken) code every row's `$vin` was '' so both
    // vehicles would show this transaction attached.  After the fix
    // only AGH818GP's row should reference it.
    $transactions = [[
        'VehicleRegistration' => 'AGH818GP',
        'SupplierName' => 'Engen Truck Stop EDC - Beaufort West',
        'CapturedDate' => now()->subMinutes(15)->toIso8601String(),
        'Litres' => 168,
        'Amount' => 4432.08,
    ]];

    $fake = fakeTfnClient(compact('vehicles', 'orders', 'transactions'));
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    foreach (['A' => 'AGH818GP', 'B' => 'MR44JRGP'] as $num => $reg) {
        TfnFuelOrderPlacement::query()->create([
            'order_number'         => $num,
            'vehicle_registration' => $reg,
            'product_code'         => 'D0',
            'litres'               => 100,
            'user_id'              => $u->id,
            'placed_by_name'       => $u->name,
            'placed_at'            => now(),
        ]);
    }

    $body = $this->actingAs($u)->get('/admin/fuel')->assertOk()->getContent();

    // The supplier name for the single transaction should appear
    // exactly once in the fleet-grid last-tx column (once per matching
    // vehicle row).  If we've bled every transaction across every row,
    // we'd see it 2+ times.
    $occurrences = substr_count($body, 'Engen Truck Stop EDC - Beaufort West');
    // 1 for the fleet-grid row, 1 for the transactions table below.
    // Anything more means the phantom bleed is back.
    expect($occurrences)->toBeLessThanOrEqual(2);
});

test('vehicle picker filter attribute exists on every option (typeahead ready)', function () {
    $vehicles = [
        ['Registration' => 'AGH818GP', 'FleetNumber' => 'FUEL',     'TankSize' => 1000, 'Status' => 3],
        ['Registration' => 'MR44JRGP', 'FleetNumber' => '',         'TankSize' => 100,  'Status' => 3],
    ];

    $fake = fakeTfnClient(compact('vehicles'));
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $body = $this->actingAs($u)->get('/admin/fuel')->assertOk()->getContent();

    // Every option carries a lower-case searchable haystack.
    expect($body)->toContain('data-search-label="agh818gp fuel"');
    expect($body)->toContain('data-search-label="mr44jrgp"');

    // The typeahead input exists.
    expect($body)->toContain('Filter by plate, customer, VIN, driver, fleet number');
});

test('litres MTD includes recent fills that fell off TFN month-start 100-row page', function () {
    // Reproduce the production stuck-MTD: SubAccountAggregateLitres is
    // stale, /api/Transactions(month-start) is truncated at 100 rows and
    // no longer contains today's fills, but the 24h window still does.
    // Litres MTD must merge both lists so the tile moves when the
    // transactions table does.
    $staleMonthPage = [[
        'TransactionID'       => 'TX-OLD-1',
        'ProductCode'         => 'D0',
        'TransactionTypeCode' => 'GP',
        'CapturedDate'        => now()->startOfMonth()->addDay()->toIso8601String(),
        'TransactionDate'     => now()->startOfMonth()->addDay()->toIso8601String(),
        'SupplierName'        => 'Kroonstad',
        'Amount'              => -22000,
        'Litres'              => 1000.0,
    ]];
    $todaysFill = [[
        'TransactionID'       => 'TX-NEW-300',
        'ProductCode'         => 'D0',
        'TransactionTypeCode' => 'GP',
        'CapturedDate'        => now()->subHour()->toIso8601String(),
        'TransactionDate'     => now()->subHour()->toIso8601String(),
        'SupplierName'        => 'Harrismith',
        'Amount'              => -6900,
        'Litres'              => 300.0,
    ]];

    $fake = new class(app(TfnTokenManager::class), $staleMonthPage, $todaysFill) extends TfnClient {
        public function __construct(
            TfnTokenManager $t,
            private array $monthPage,
            private array $recentPage,
        ) {
            parent::__construct($t);
        }
        public function isLive(): bool { return true; }
        public function ping(): array { return ['status' => 'ok', 'timestamp' => null, 'latency_ms' => 1]; }
        public function depots(): array { return []; }
        public function vehicles(): array { return []; }
        public function pricing(string $p): array { return []; }
        public function subAccountBalance(): array
        {
            return ['AccountBalance' => 25000, 'AccountAvailableBalance' => 25000];
        }
        public function subAccountAggregateLitres(?\DateTimeInterface $m = null): array
        {
            // Stale aggregate — does not include today's 300 L.
            return [['ProductCode' => 'D0', 'Litres' => 1000.0]];
        }
        public function transactions(?\DateTimeInterface $c = null): array
        {
            $after = $c ? \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($c)) : now()->subDay();
            // Month-start pull = truncated page without today's fill.
            if ($after->lte(now()->startOfMonth()->addHour())) {
                return $this->monthPage;
            }
            // Short window (24h) still has today's fill.
            return array_merge($this->monthPage, $this->recentPage);
        }
        public function orders(?\DateTimeInterface $m = null): array { return []; }
    };
    app()->instance(TfnClient::class, $fake);

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    $c = \Livewire\Volt\Volt::actingAs($u)->test('admin.fuel');

    expect((float) $c->viewData('litresMtd'))->toBe(1300.0);
});
