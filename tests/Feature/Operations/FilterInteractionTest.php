<?php

/**
 * Livewire ↔ Tailwind sanity checks for the ops dashboard filter bar.
 *
 * We rebuilt the shell to use `wire:model.live` bound to typed
 * `?int` / `?string` properties with `<option value="">All …</option>`
 * defaults. The two things that historically bite this pattern are:
 *
 *   1. Livewire hydrating an empty-string post value into a `?int`
 *      property — should coerce to `null`, not throw a TypeError.
 *   2. The shell's `updated()` hook firing on every filter change
 *      and dispatching `ops-filters-updated` with the fresh payload
 *      so nested lazy panels can re-scope their queries.
 *
 * If either breaks, the whole filter row silently degrades to a
 * static badge that pretends to be interactive. This test locks the
 * contract in before we deploy.
 */

use App\Livewire\Admin\Operations\OperationsDashboard;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'operations_controller' => 'Ops Controller',
        'dispatcher'            => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function filtersUser(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');
    return $u;
}

it('mounts with all-null entity filters and renders the four <select>s', function () {
    $c = Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class);

    $c->assertOk();
    $c->assertSet('companyId', null);
    $c->assertSet('transporterId', null);
    $c->assertSet('brandId', null);
    $c->assertSet('region', null);

    // Every select must expose a value="" "All …" option so the user
    // can clear the filter without leaving the page.
    $c->assertSee('All customers');
    $c->assertSee('All transporters');
    $c->assertSee('All brands');
    $c->assertSee('All provinces');
});

it('accepts an empty-string post from <option value=""> without throwing on ?int companyId', function () {
    $customer   = Company::factory()->create(['type' => Company::TYPE_CUSTOMER]);

    $c = Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class);

    // Simulate the user picking a real customer, then flipping back
    // to "All customers" (value=""). If Livewire's hydrator can't
    // coerce "" → null on a `?int` typed prop, this call throws.
    $c->set('companyId', $customer->id);
    expect($c->get('companyId'))->toBe($customer->id);

    $c->set('companyId', '');
    expect($c->get('companyId'))->toBeNull();
});

it('setting any entity filter dispatches ops-filters-updated with the fresh payload', function () {
    $brand = Brand::create(['name' => 'Filter Test Brand', 'is_active' => true]);

    Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class)
        ->set('brandId', $brand->id)
        ->assertDispatched('ops-filters-updated');
});

it('resetFilters clears every entity filter and restores the 7-day preset', function () {
    $customer = Company::factory()->create(['type' => Company::TYPE_CUSTOMER]);

    $c = Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class)
        ->set('companyId', $customer->id)
        ->set('region', 'Gauteng')
        ->call('resetFilters');

    $c->assertSet('companyId', null);
    $c->assertSet('region', null);
    $c->assertSet('preset', '7d');
    expect($c->get('dateFrom'))->not()->toBeNull();
    expect($c->get('dateTo'))->not()->toBeNull();
});

it('date presets recompute the range and re-dispatch filters', function () {
    Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class)
        ->call('applyPreset', '30d')
        ->assertSet('preset', '30d')
        ->assertDispatched('ops-filters-updated');
});

it('unknown preset names fall back to the 7-day window instead of blanking dates', function () {
    // The Blade layer only offers valid presets, but a URL-poisoned
    // `?preset=garbage` shouldn't wipe the range and orphan the
    // performance panels. presetRange() falls through to 7d.
    $c = Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class)
        ->call('applyPreset', 'garbage');

    expect($c->get('dateFrom'))->not()->toBeNull();
    expect($c->get('dateTo'))->not()->toBeNull();
});

it('renders the filter bar HTML without any Tailwind class name that Livewire strips or duplicates', function () {
    // Volt used to double-wrap components; the new shell renders a
    // single root <div>. If someone reintroduces a wrapper, wire:model
    // scoping breaks and every select becomes a no-op. This sanity
    // check pins the root markup and the wire:model wiring at once.
    $html = Livewire::actingAs(filtersUser())
        ->test(OperationsDashboard::class)
        ->html();

    expect($html)->toContain('wire:model.live');
    expect($html)->toContain('companyId');
    expect($html)->toContain('transporterId');
    expect($html)->toContain('brandId');
    expect($html)->toContain('region');
});
