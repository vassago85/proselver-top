<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Phase 2 behaviour:
 *
 *   - Dealer can flip the BB share toggle on the stock card and the
 *     boolean persists.
 *   - Salesperson / end customer / build notes save through the
 *     dedicated saveBodyBuilderDetails action.
 *   - The BB's internal job number is READ-ONLY from the dealer side
 *     (we don't expose a wire-bound input for it in the dealer panel)
 *     -- the dealer just sees what the BB set.
 *   - A user without manage_dealer_stock can't write.
 */

function makeStockCtrl(Company $dealer): User
{
    Role::firstOrCreate(['slug' => 'stock_controller'], ['name' => 'Stock Controller', 'tier' => 'dealer']);
    $mgrPerm  = Permission::firstOrCreate(['slug' => 'manage_dealer_stock'], ['name' => 'Manage dealer stock', 'group' => 'dealer_stock']);
    $viewPerm = Permission::firstOrCreate(['slug' => 'view_dealer_stock'],   ['name' => 'View dealer stock',   'group' => 'dealer_stock']);
    Role::where('slug', 'stock_controller')->first()
        ->permissions()->syncWithoutDetaching([$mgrPerm->id, $viewPerm->id]);

    $u = User::factory()->create();
    $u->assignRole('stock_controller');
    $dealer->users()->attach($u->id);
    return $u;
}

test('dealer can save body builder share details and the toggle persists', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN1',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->set('bb_share_with_body_builder', true)
        ->set('bb_share_salesperson', 'Andile S.')
        ->set('bb_share_end_customer', 'ABC Logistics (Pty) Ltd')
        ->set('bb_build_notes', "Tipper body, blue cab, tow-bar.\nBatch 2 of 3.")
        ->call('saveBodyBuilderDetails')
        ->assertHasNoErrors();

    $stock->refresh();
    expect($stock->bb_share_with_body_builder)->toBeTrue();
    expect($stock->bb_share_salesperson)->toBe('Andile S.');
    expect($stock->bb_share_end_customer)->toBe('ABC Logistics (Pty) Ltd');
    expect($stock->bb_build_notes)->toContain('Tipper body');
});

test('share toggle off clears nothing -- the fields still save (dealer prep work shouldn\'t be lost when toggle is off)', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN2',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
        'bb_build_notes'        => 'Existing notes from earlier.',
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->set('bb_share_with_body_builder', false)
        ->set('bb_build_notes', 'Updated notes -- still relevant.')
        ->call('saveBodyBuilderDetails')
        ->assertHasNoErrors();

    $stock->refresh();
    expect($stock->bb_share_with_body_builder)->toBeFalse();
    expect($stock->bb_build_notes)->toBe('Updated notes -- still relevant.');
});

test('a user without manage_dealer_stock cannot save BB details', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);

    // View-only role: has view_dealer_stock but not manage_dealer_stock.
    Role::firstOrCreate(['slug' => 'stock_viewer'], ['name' => 'Stock viewer', 'tier' => 'dealer']);
    $viewPerm = Permission::firstOrCreate(['slug' => 'view_dealer_stock'], ['name' => 'View dealer stock', 'group' => 'dealer_stock']);
    Role::where('slug', 'stock_viewer')->first()->permissions()->syncWithoutDetaching([$viewPerm->id]);

    $viewer = User::factory()->create();
    $viewer->assignRole('stock_viewer');
    $dealer->users()->attach($viewer->id);
    $this->actingAs($viewer);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN3',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    // abort_unless(false, 403) inside the action; Livewire surfaces it
    // however the runtime decides -- the *guarantee* the test cares
    // about is that the write didn't happen.
    try {
        Volt::test('customer.stock.show', ['dealerStock' => $stock])
            ->set('bb_share_with_body_builder', true)
            ->call('saveBodyBuilderDetails');
    } catch (\Throwable $e) {
        // Expected -- swallowed.
    }

    $stock->refresh();
    expect($stock->bb_share_with_body_builder)->toBeFalse();
});

test('bb_internal_job_number is displayed on the dealer card when set by the BB', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user   = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'      => $dealer->id,
        'vin'                    => 'BBSHARETESTVIN4',
        'current_location_type'  => DealerStock::LOCATION_BODY_BUILDER,
        'status'                 => DealerStock::STATUS_AVAILABLE,
        'bb_internal_job_number' => 'BB-2026-0042',
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->assertSee('BB-2026-0042');
});
