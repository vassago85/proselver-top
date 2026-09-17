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

/**
 * Regression guard for the 405 bug seen on 2026-09-17:
 *
 *   1. Ops opens Clean up while on the address book.
 *   2. That's a Livewire re-render, so a Blade link that uses
 *      request()->fullUrl() for ?return= embeds /livewire/update.
 *   3. sanitiseReturnUrl() used to accept /livewire/update because
 *      it's a same-host rooted path.
 *   4. Save then redirect()->to('/livewire/update') fires a GET on a
 *      POST-only endpoint -> 405 Method Not Allowed.
 *
 * Any /livewire/* return URL MUST be rejected as null, no matter how
 * it snuck in (rooted, absolute, upper-case, trailing slash, query
 * string, etc.).
 */
test('open-redirect: /livewire/update rooted path is rejected (405 guard)', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/livewire/update',
    ])->assertSet('returnUrl', null);
});

test('open-redirect: /livewire/upload-file rooted path is rejected', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/livewire/upload-file',
    ])->assertSet('returnUrl', null);
});

test('open-redirect: /LIVEWIRE/update rooted path is rejected (case-insensitive)', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/LIVEWIRE/update',
    ])->assertSet('returnUrl', null);
});

test('open-redirect: same-host absolute /livewire/update is rejected', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    $sameHost = 'http://' . request()->getHost() . '/livewire/update';

    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => $sameHost,
    ])->assertSet('returnUrl', null);
});

test('non-livewire rooted paths still pass (regression guard for over-eager blacklist)', function () {
    $this->actingAs(locationsReturnUrlAdmin());
    $loc = locationsReturnUrlLocation();

    // The blacklist must ONLY hit /livewire — legitimate paths that
    // happen to contain the substring "livewire" (e.g. an internal
    // page called "livewire-diagnostics") should still pass.
    Volt::test('admin.settings.locations', [
        'focusLocationId' => $loc->id,
        'returnUrl' => '/admin/livewire-diagnostics',
    ])->assertSet('returnUrl', '/admin/livewire-diagnostics');
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
