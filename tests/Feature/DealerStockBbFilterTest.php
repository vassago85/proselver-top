<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * /customer/stock filters that the user just asked for:
 *
 *   - Pills filter by one OR more body builder companies.  Filter
 *     implicitly narrows the bucket to body_builder so the result
 *     set is always sensible (you can't be at "Pretoria BB" while
 *     sitting at premises).
 *
 *   - Pills filter by one OR more salespeople.
 *
 *   - The body-builder picker only enumerates BBs that currently
 *     host the dealer's stock (no 200-entry directory dump).
 */

function bbFilterMakeUser(Company $dealer): User
{
    Role::firstOrCreate(['slug' => 'stock_controller'], ['name' => 'Stock Controller', 'tier' => 'dealer']);
    $viewPerm  = Permission::firstOrCreate(['slug' => 'view_dealer_stock'],   ['name' => 'View dealer stock',   'group' => 'dealer_stock']);
    Role::where('slug', 'stock_controller')->first()->permissions()->syncWithoutDetaching([$viewPerm->id]);
    $u = User::factory()->create();
    $u->assignRole('stock_controller');
    $dealer->users()->attach($u->id);
    return $u;
}

function bbFilterMakeBbLocation(Company $dealer, string $bbName): Location
{
    $bb = Company::create(['name' => $bbName, 'type' => Company::TYPE_BODY_BUILDER]);
    return Location::create([
        'company_id'   => $bb->id,
        'type'         => Location::TYPE_BODY_BUILDER,
        'company_name' => $bbName,
        'address'      => '1 Workshop St',
        'is_active'    => true,
    ]);
}

test('body-builder filter narrows the list to the selected BBs and forces the bucket', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    $bbAlphaLoc = bbFilterMakeBbLocation($dealer, 'Alpha Bodies');
    $bbBetaLoc  = bbFilterMakeBbLocation($dealer, 'Beta Bodies');

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'FILTERBB000001',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $bbAlphaLoc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);
    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'FILTERBB000002',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $bbBetaLoc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);
    // A vehicle at premises -- should NEVER appear once a BB filter
    // is active, regardless of how it's tagged.
    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'FILTERBBPREM01',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.index')
        ->call('toggleBodyBuilder', $bbAlphaLoc->company_id)
        ->assertSee('FILTERBB000001')
        ->assertDontSee('FILTERBB000002')
        ->assertDontSee('FILTERBBPREM01');
});

test('toggling a body-builder twice clears that BB from the filter', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    $loc = bbFilterMakeBbLocation($dealer, 'Toggle Bodies');

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'TOGGLEBB000001',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $loc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.index')
        ->call('toggleBodyBuilder', $loc->company_id)
        ->assertSet('bodyBuilderFilter', [$loc->company_id])
        ->call('toggleBodyBuilder', $loc->company_id)
        ->assertSet('bodyBuilderFilter', []);
});

test('multiple BBs can be active at once -- OR semantics', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    $alphaLoc = bbFilterMakeBbLocation($dealer, 'Alpha Bodies');
    $betaLoc  = bbFilterMakeBbLocation($dealer, 'Beta Bodies');
    $gammaLoc = bbFilterMakeBbLocation($dealer, 'Gamma Bodies');

    foreach ([
        ['MULTIBB0001', $alphaLoc->id],
        ['MULTIBB0002', $betaLoc->id],
        ['MULTIBB0003', $gammaLoc->id],
    ] as [$vin, $locId]) {
        DealerStock::create([
            'dealer_company_id'     => $dealer->id,
            'vin'                   => $vin,
            'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
            'current_location_id'   => $locId,
            'status'                => DealerStock::STATUS_AVAILABLE,
        ]);
    }

    Volt::test('customer.stock.index')
        ->call('toggleBodyBuilder', $alphaLoc->company_id)
        ->call('toggleBodyBuilder', $betaLoc->company_id)
        ->assertSee('MULTIBB0001')
        ->assertSee('MULTIBB0002')
        ->assertDontSee('MULTIBB0003');
});

test('salesperson filter narrows to the chosen salespeople', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    $alice = User::factory()->create(['name' => 'Alice Sales']);
    $bob   = User::factory()->create(['name' => 'Bob Sales']);
    $dealer->users()->attach([$alice->id, $bob->id]);

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'SPFILT000001',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
        'salesperson_user_id'   => $alice->id,
    ]);
    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'SPFILT000002',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
        'salesperson_user_id'   => $bob->id,
    ]);

    Volt::test('customer.stock.index')
        ->call('toggleSalesperson', $alice->id)
        ->assertSee('SPFILT000001')
        ->assertDontSee('SPFILT000002');
});

test('BB picker only includes BBs actually holding stock', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    // Dealer has a vehicle at "Active Bodies"; "Idle Bodies" exists
    // in the directory but holds nothing of ours.  The picker should
    // surface Active but NOT Idle.
    $activeLoc = bbFilterMakeBbLocation($dealer, 'Active Bodies');
    $idleLoc   = bbFilterMakeBbLocation($dealer, 'Idle Bodies');

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'PICK000001',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $activeLoc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.index')
        ->assertSee('Active Bodies')
        ->assertDontSee('Idle Bodies');
});

test('the bucket column shows the BB name underneath the bucket pill', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = bbFilterMakeUser($dealer);
    $this->actingAs($user);

    $loc = bbFilterMakeBbLocation($dealer, 'Pretoria Bodies');

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'WHERELOC0001',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $loc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.index')
        ->assertSee('Pretoria Bodies'); // appears under the bucket pill in the table
});
