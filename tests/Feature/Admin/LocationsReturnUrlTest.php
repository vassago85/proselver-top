<?php

use App\Models\Company;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
});

/**
 * Ops opens the address book from an order page via
 * /admin/settings/locations?focus=123&return=/admin/orders/456
 * and expects three things:
 *   1. The edit form auto-opens for that location.
 *   2. A "Back" banner is rendered so they can bail out.
 *   3. Saving (or Cancel) redirects them BACK to the order.
 *
 * The return-URL param is also a classic open-redirect vector, so we
 * pin that only same-host / rooted-path values are honoured -- anything
 * else (external host, javascript: scheme, //evil.com) gets stripped.
 */

function locationsReturnUrlAdmin(): User
{
    $u = User::factory()->create(['is_active' => true, 'name' => 'Ops Admin']);
    $u->assignRole('super_admin');
    return $u;
}

function locationsReturnUrlLocation(): Location
{
    $company = Company::factory()->create(['name' => 'OEM 1']);
    return Location::create([
        'company_id' => $company->id,
        'company_name' => 'OEM 1 Plant',
        'address' => 'OEM 1 Plant', // deliberate stub so we test the edit path
        'city' => '',
        'province' => '',
        'is_active' => true,
    ]);
}

test('opening ?focus= without ?return= keeps returnUrl null (no banner)', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', ['focusLocationId' => $loc->id])
        ->assertSet('editingId', $loc->id)
        ->assertSet('returnUrl', null);
});

test('rooted-path return URL survives sanitisation', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/admin/orders/999',
    ])
        ->assertSet('returnUrl', '/admin/orders/999')
        ->assertSet('editingId', $loc->id);
});

test('same-host absolute return URL survives sanitisation', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    // Livewire test uses request()->getHost() -- default is 'localhost'.
    $sameHost = 'http://' . request()->getHost() . '/admin/orders/999';

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => $sameHost,
    ])->assertSet('returnUrl', $sameHost);
});

test('open-redirect: external hostname is stripped', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => 'https://evil.example.com/admin/orders/999',
    ])->assertSet('returnUrl', null);
});

test('open-redirect: protocol-relative //evil.com is stripped', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '//evil.example.com/admin',
    ])->assertSet('returnUrl', null);
});

test('open-redirect: javascript: scheme is stripped', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => 'javascript:alert(1)',
    ])->assertSet('returnUrl', null);
});

test('save from a deep-link redirects back to the return URL', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/admin/orders/999',
    ])
        ->assertSet('editingId', $loc->id)
        ->set('editAddress', '12 Real Street, Randburg')
        ->set('editCity', 'Randburg')
        ->set('editProvince', 'Gauteng')
        ->call('update')
        ->assertRedirect('/admin/orders/999');

    // The row must actually have persisted the new address.
    expect($loc->fresh()->address)->toBe('12 Real Street, Randburg');
});

test('cancel from a deep-link redirects back without saving', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();
    $original = $loc->address;

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/admin/orders/999',
    ])
        ->set('editAddress', 'DIRTY EDIT SHOULD NOT PERSIST')
        ->call('cancelEdit')
        ->assertRedirect('/admin/orders/999');

    expect($loc->fresh()->address)->toBe($original);
});

test('save WITHOUT a return URL does not redirect (stays on the page)', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', ['focusLocationId' => $loc->id])
        ->set('editAddress', '12 Real Street, Randburg')
        ->set('editCity', 'Randburg')
        ->set('editProvince', 'Gauteng')
        ->call('update')
        ->assertNoRedirect()
        ->assertSet('editingId', null);
});
