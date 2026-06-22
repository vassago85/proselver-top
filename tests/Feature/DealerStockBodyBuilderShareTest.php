<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\DealerStockFitment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Phase 3 behaviour:  the dealer's "Body builder details" panel has
 * been replaced with a multi-leg Fitment chain.  Sharing decisions
 * (and notes, fitment type, internal job numbers) are PER LEG, so
 * a dropside supplier can see the customer while a downstream crane
 * supplier doesn't.  This test file covers the per-leg semantics.
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

test('dealer can add a fitment leg with share toggle ON and per-leg notes / shared contact', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $bb     = Company::create(['name' => 'Dropside Pty', 'type' => Company::TYPE_BODY_BUILDER]);
    $bb->linkedDealers()->attach($dealer->id, ['is_active' => true]);

    $user = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN1',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->set('fitment_body_builder_id', $bb->id)
        ->set('fitment_type', 'Dropside body')
        ->set('fitment_notes', "Tipper body, blue cab, tow-bar.\nBatch 2 of 3.")
        ->set('fitment_share_with_bb', true)
        ->set('fitment_share_salesperson', 'Andile S.')
        ->set('fitment_share_end_customer', 'ABC Logistics (Pty) Ltd')
        ->call('saveFitment')
        ->assertHasNoErrors();

    $leg = $stock->fitments()->first();
    expect($leg)->not->toBeNull();
    expect($leg->share_with_bb)->toBeTrue();
    expect($leg->share_salesperson)->toBe('Andile S.');
    expect($leg->share_end_customer)->toBe('ABC Logistics (Pty) Ltd');
    expect($leg->notes)->toContain('Tipper body');
    expect($leg->status)->toBe(DealerStockFitment::STATUS_PLANNED);
});

test('dealer can add a leg with share toggle OFF and the notes still save for internal use', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $bb     = Company::create(['name' => 'Crane Co', 'type' => Company::TYPE_BODY_BUILDER]);
    $bb->linkedDealers()->attach($dealer->id, ['is_active' => true]);

    $user = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN2',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->set('fitment_body_builder_id', $bb->id)
        ->set('fitment_type', 'Crane mount')
        ->set('fitment_notes', 'Confidential brief -- do not share with BB.')
        ->set('fitment_share_with_bb', false)
        ->call('saveFitment')
        ->assertHasNoErrors();

    $leg = $stock->fitments()->first();
    expect($leg->share_with_bb)->toBeFalse();
    expect($leg->share_salesperson)->toBeNull();
    expect($leg->share_end_customer)->toBeNull();
    expect($leg->notes)->toBe('Confidential brief -- do not share with BB.');
});

test('a user without manage_dealer_stock cannot add a fitment leg', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $bb     = Company::create(['name' => 'View BB', 'type' => Company::TYPE_BODY_BUILDER]);
    $bb->linkedDealers()->attach($dealer->id, ['is_active' => true]);

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

    try {
        Volt::test('customer.stock.show', ['dealerStock' => $stock])
            ->set('fitment_body_builder_id', $bb->id)
            ->set('fitment_type', 'Dropside body')
            ->call('saveFitment');
    } catch (\Throwable $e) {
        // ensureManage() aborts with 403 -- swallow and assert nothing was written.
    }

    expect($stock->fitments()->count())->toBe(0);
});

test('a fitment leg with an internal job number set by the BB is visible on the dealer card', function () {
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $bb     = Company::create(['name' => 'Job Number BB', 'type' => Company::TYPE_BODY_BUILDER]);
    $bb->linkedDealers()->attach($dealer->id, ['is_active' => true]);

    $user = makeStockCtrl($dealer);
    $this->actingAs($user);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'BBSHARETESTVIN4',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    // Simulate what the BB's tablet view writes when a foreman captures
    // the internal job number.
    $stock->fitments()->create([
        'body_builder_company_id' => $bb->id,
        'sequence'                => 1,
        'status'                  => DealerStockFitment::STATUS_IN_PROGRESS,
        'started_at'              => now(),
        'internal_job_number'     => 'BB-2026-0042',
    ]);

    Volt::test('customer.stock.show', ['dealerStock' => $stock])
        ->assertSee('BB-2026-0042');
});
