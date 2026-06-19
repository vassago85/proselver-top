<?php

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\DealerStock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Phase 2 dashboard refactor: six tap cards backed by dealer_stock.
 * Tests cover the count math, the click-to-filter behaviour and a
 * franchise CEO seeing combined counts across every dealership in
 * their group.
 */

beforeEach(function () {
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
});

function makeDealerWithUser(string $name = 'Test Dealer'): array
{
    $dealer = Company::factory()->create([
        'name' => $name,
        'type' => Company::TYPE_DEALER,
    ]);
    $user = User::factory()->create(['name' => 'Stock User']);
    $user->assignRole('customer_owner');
    $dealer->users()->attach($user->id);
    return [$dealer, $user];
}

function seedStock(Company $dealer, string $vin, string $bucket = 'premises', string $status = 'available', array $extras = []): DealerStock
{
    return DealerStock::create(array_merge([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => $vin,
        'current_location_type' => $bucket,
        'status'                => $status,
    ], $extras));
}

test('countPremises returns the number of stock rows at premises', function () {
    [$dealer, $user] = makeDealerWithUser();
    seedStock($dealer, 'PREM01', DealerStock::LOCATION_PREMISES);
    seedStock($dealer, 'PREM02', DealerStock::LOCATION_PREMISES);
    seedStock($dealer, 'BB01', DealerStock::LOCATION_BODY_BUILDER);

    $this->actingAs($user);

    Volt::test('customer.dashboard')
        ->assertViewHas('isDealer', true)
        ->assertSet('selectedBucket', null);

    expect(Volt::test('customer.dashboard')->get('countPremises'))->toBe(2);
    expect(Volt::test('customer.dashboard')->get('countBodyBuilder'))->toBe(1);
});

test('countOnDemo and countRecentlyDelivered use the status column', function () {
    [$dealer, $user] = makeDealerWithUser();
    seedStock($dealer, 'DEMO1', DealerStock::LOCATION_ON_DEMO, DealerStock::STATUS_DEMO);
    seedStock($dealer, 'SOLD1', DealerStock::LOCATION_DELIVERED, DealerStock::STATUS_SOLD, [
        'sold_at' => now()->subDays(2),
    ]);
    seedStock($dealer, 'SOLD2', DealerStock::LOCATION_DELIVERED, DealerStock::STATUS_SOLD, [
        'sold_at' => now()->subDays(60),   // outside the window
    ]);

    $this->actingAs($user);

    expect(Volt::test('customer.dashboard')->get('countOnDemo'))->toBe(1);
    expect(Volt::test('customer.dashboard')->get('countRecentlyDelivered'))->toBe(1);
});

test('selectBucket sets the filter and re-tapping clears it', function () {
    [$dealer, $user] = makeDealerWithUser();
    seedStock($dealer, 'TAP01');

    $this->actingAs($user);

    Volt::test('customer.dashboard')
        ->call('selectBucket', 'premises')
        ->assertSet('selectedBucket', 'premises')
        ->call('selectBucket', 'premises')
        ->assertSet('selectedBucket', null);
});

test('selectBucket rejects an invalid bucket key', function () {
    [$dealer, $user] = makeDealerWithUser();
    $this->actingAs($user);

    Volt::test('customer.dashboard')
        ->call('selectBucket', 'definitely_not_a_real_bucket')
        ->assertSet('selectedBucket', null);
});

test('filteredStock returns rows matching the selected bucket', function () {
    [$dealer, $user] = makeDealerWithUser();
    seedStock($dealer, 'FILTER01', DealerStock::LOCATION_BODY_BUILDER);
    seedStock($dealer, 'FILTER02', DealerStock::LOCATION_PREMISES);

    $this->actingAs($user);

    $component = Volt::test('customer.dashboard')->call('selectBucket', 'body_builder');
    $rows = $component->get('filteredStock');

    expect($rows->pluck('vin')->all())->toEqual(['FILTER01']);
});

test('archived stock never counts toward dashboard cards', function () {
    [$dealer, $user] = makeDealerWithUser();
    seedStock($dealer, 'LIVE01', DealerStock::LOCATION_PREMISES);
    seedStock($dealer, 'GONE01', DealerStock::LOCATION_PREMISES, DealerStock::STATUS_ARCHIVED, [
        'archived_at' => now(),
    ]);

    $this->actingAs($user);

    expect(Volt::test('customer.dashboard')->get('countPremises'))->toBe(1);
});

test('group principal sees combined counts across every dealership in the group', function () {
    $group = CompanyGroup::create([
        'name' => 'Williams Hunt Holdings',
        'normalized_name' => 'wh_holdings',
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

    seedStock($sandton, 'S01', DealerStock::LOCATION_PREMISES);
    seedStock($sandton, 'S02', DealerStock::LOCATION_PREMISES);
    seedStock($bryanston, 'B01', DealerStock::LOCATION_PREMISES);

    $ceo = User::factory()->create();
    $ceo->assignRole('customer_owner');
    $sandton->users()->attach($ceo->id);
    $bryanston->users()->attach($ceo->id);

    $this->actingAs($ceo);

    expect(Volt::test('customer.dashboard')->get('countPremises'))->toBe(3);

    Volt::test('customer.dashboard')->assertViewHas('isMultiCompany', true);
});

test('non-dealer customer companies retain the legacy KPI strip', function () {
    Role::create(['name' => 'OEM Admin', 'slug' => 'oem_admin', 'tier' => 'customer']);

    $oem = Company::factory()->create([
        'name' => 'FAW SA',
        'type' => Company::TYPE_OEM,
    ]);
    $oemUser = User::factory()->create();
    $oemUser->assignRole('oem_admin');
    $oem->users()->attach($oemUser->id);

    $this->actingAs($oemUser);

    Volt::test('customer.dashboard')
        ->assertViewHas('isDealer', false)
        ->assertViewHas('hasCompany', true);
});
