<?php

/**
 * View-as portal scoping regression tests.
 *
 * The developer role bypasses every portal-access middleware
 * (EnsureInternalAccess, EnsureCustomerAccess, EnsureDriverAccess,
 * EnsureBodyBuilderAccess) when the user is acting as themselves, so
 * ProSelver staff can shoulder-surf any surface.  The dev toolbar's
 * "View as <role>" switch (session key `dev_role_override`) is
 * meant to preview what a real user of that role would see -- but
 * the middleware bypass used to hold regardless of the switch, so a
 * developer previewing as a Driver still had unrestricted access to
 * /admin/orders (all 2 665 jobs) and the Owner command centre.
 *
 * These tests lock down the boundary:
 *   - Real customer / driver / OEM users cannot reach /admin/*.
 *   - Developer without override still gets every portal.
 *   - Developer WITH override loses the bypass and is judged by
 *     effectiveRoles() -- View as Driver 403s on /admin/orders,
 *     View as Owner 403s on /driver/dashboard, etc.
 *   - resolveUserHomePath() honours the override so "Back to my
 *     dashboard" on a driver session lands on the driver PWA, not
 *     /admin/dashboard/owner.
 */

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Roles the resolver and portal middleware actually branch on.
    // Kept as a firstOrCreate loop so any future addition doesn't
    // silently break this file when a slug shifts.
    foreach ([
        ['slug' => 'developer',              'name' => 'Developer',              'tier' => 'internal'],
        ['slug' => 'owner',                  'name' => 'Owner',                  'tier' => 'internal'],
        ['slug' => 'super_admin',            'name' => 'Super Admin',            'tier' => 'internal'],
        ['slug' => 'operations_controller',  'name' => 'Ops Controller',         'tier' => 'internal'],
        ['slug' => 'dispatcher',             'name' => 'Dispatcher',             'tier' => 'internal'],
        ['slug' => 'accounts',               'name' => 'Accounts',               'tier' => 'internal'],
        ['slug' => 'customer_owner',         'name' => 'Customer Owner',         'tier' => 'customer'],
        ['slug' => 'oem_planner',            'name' => 'OEM Planner',            'tier' => 'oem'],
        ['slug' => 'driver',                 'name' => 'Driver',                 'tier' => 'driver'],
    ] as $r) {
        Role::firstOrCreate(['slug' => $r['slug']], $r);
    }
});

function viewAsUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u->fresh();
}

function viewAsDeveloper(): User
{
    return viewAsUser('developer');
}

// -----------------------------------------------------------------
// 1. Real tenant users cannot reach /admin/* at all
// -----------------------------------------------------------------
//
// This is the check the audit asked us to make BEFORE trusting the
// dev-toolbar reproduction.  A real customer_owner / oem_planner /
// driver signing into production must be 403'd off /admin/orders.

test('a real customer user cannot open /admin/orders', function () {
    $this->actingAs(viewAsUser('customer_owner'))
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

test('a real customer user cannot open the owner command centre', function () {
    $this->actingAs(viewAsUser('customer_owner'))
        ->get(route('admin.dashboard.owner'))
        ->assertForbidden();
});

test('a real OEM user cannot open /admin/orders', function () {
    $this->actingAs(viewAsUser('oem_planner'))
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

test('a real driver user cannot open /admin/orders', function () {
    $this->actingAs(viewAsUser('driver'))
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

// -----------------------------------------------------------------
// 2. Developer without override keeps the bypass
// -----------------------------------------------------------------
//
// The switch only fires when the toolbar has set an override.  A
// plain developer session is unchanged -- they can still open the
// admin portal, the customer portal (for shoulder-surfing) and the
// driver PWA.

test('a developer with no view-as override still reaches /admin/orders', function () {
    $this->actingAs(viewAsDeveloper())
        ->get(route('admin.orders.index'))
        ->assertOk();
});

test('a developer with no view-as override still lands on the owner command centre', function () {
    $this->actingAs(viewAsDeveloper())
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.owner'));
});

test('resolveUserHomePath for a plain developer still resolves to the owner command centre', function () {
    expect(resolveUserHomePath(viewAsDeveloper()))
        ->toBe(route('admin.dashboard.owner'));
});

// -----------------------------------------------------------------
// 3. Developer WITH override is judged by effectiveRoles()
// -----------------------------------------------------------------
//
// This is the fix.  Once `dev_role_override` is set the developer's
// real badge stops opening portal doors and they get the same 403 /
// redirect a real user of that role would get.

test('a developer viewing as a driver is 403ed off /admin/orders', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'driver']);

    $this->actingAs($dev)
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

test('a developer viewing as a driver is 403ed off the owner command centre', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'driver']);

    $this->actingAs($dev)
        ->get(route('admin.dashboard.owner'))
        ->assertForbidden();
});

test('a developer viewing as a customer owner is 403ed off /admin/orders', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'customer_owner']);

    $this->actingAs($dev)
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

test('a developer viewing as an OEM planner is 403ed off /admin/orders', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'oem_planner']);

    $this->actingAs($dev)
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

test('a developer viewing as an owner keeps admin access', function () {
    // Owner is an internal-tier role, so isInternal() on effectiveRoles()
    // still returns true and the admin middleware lets them through.
    // This locks the "safe" direction of the switch: previewing another
    // internal role does not lock the developer out of the admin portal.
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'owner']);

    $this->actingAs($dev)
        ->get(route('admin.orders.index'))
        ->assertOk();
});

test('a developer viewing as an accounts user is 403ed off the owner command centre', function () {
    // Owner mount() checks isOwner() || isDeveloper().  isOwner() reads
    // effectiveRoles() so an override to accounts flips it to false, and
    // the isDeveloper() branch is now gated on "no override active".
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'accounts']);

    $this->actingAs($dev)
        ->get(route('admin.dashboard.owner'))
        ->assertForbidden();
});

// -----------------------------------------------------------------
// 4. Home path resolution honours the override
// -----------------------------------------------------------------
//
// The 403 page's "Back to my dashboard" button, the app layout's
// home URL, and the post-login redirect all call resolveUserHomePath().
// With the fix these must land on the PORTAL matching the effective
// role -- otherwise a developer viewing as a driver still gets sent
// to the Owner command centre when they hit any 403.

test('resolveUserHomePath for a developer viewing as a driver lands on the driver PWA', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'driver']);

    expect(resolveUserHomePath($dev))->toBe(route('driver.dashboard'));
});

test('resolveUserHomePath for a developer viewing as a customer owner lands on the customer portal', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'customer_owner']);

    expect(resolveUserHomePath($dev))->toBe(route('customer.dashboard'));
});

test('resolveUserHomePath for a developer viewing as an OEM planner lands on the customer portal', function () {
    // Legacy OEM-tier roles have been folded into /customer/*; the home
    // path must still resolve there rather than a dead /oem/* URL.
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'oem_planner']);

    expect(resolveUserHomePath($dev))->toBe(route('customer.dashboard'));
});

test('resolveUserHomePath for a developer viewing as an ops controller lands on the orders index', function () {
    // Internal-tier override still uses resolveInternalDashboardRoute();
    // ops controllers land on the Orders index (not Owner or Finance).
    // Pre 2026-09-30 they landed on the Operations dashboard, but that
    // page was hidden from ops/dispatch/super_admin in the staff-request
    // nav cut, so their home moved to Orders where the shift is run.
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'operations_controller']);

    expect(resolveUserHomePath($dev))->toBe(route('admin.orders.index'));
});

// -----------------------------------------------------------------
// 5. Driver PWA gate honours the override in the other direction
// -----------------------------------------------------------------
//
// A developer viewing as an owner must not still be able to open the
// driver PWA -- the audit's "Back to my dashboard" bug was the same
// class of leak in the opposite portal.

test('a developer viewing as an owner is bounced off the driver PWA', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'owner']);

    // EnsureDriverAccess redirects non-drivers back to their own home
    // rather than 403ing.  For an owner override the home resolves to
    // the Owner command centre.
    $this->actingAs($dev)
        ->get(route('driver.dashboard'))
        ->assertRedirect(route('admin.dashboard.owner'));
});

// -----------------------------------------------------------------
// 6. Real drivers / real customers are unchanged
// -----------------------------------------------------------------
//
// The middleware refactor must not accidentally break the real users
// it's supposed to protect.

test('a real driver still reaches the driver PWA', function () {
    $this->actingAs(viewAsUser('driver'))
        ->get(route('driver.dashboard'))
        ->assertOk();
});

test('resolveUserHomePath for a real driver still lands on the driver PWA', function () {
    expect(resolveUserHomePath(viewAsUser('driver')))
        ->toBe(route('driver.dashboard'));
});

test('a real customer owner still reaches the customer dashboard', function () {
    $this->actingAs(viewAsUser('customer_owner'))
        ->get(route('customer.dashboard'))
        ->assertOk();
});

// -----------------------------------------------------------------
// 7. "owner OR developer" gates respect the override too
// -----------------------------------------------------------------
//
// Four surfaces are gated on "owner OR developer OR [sometimes
// super_admin]" rather than tier/portal membership: the Owner command
// centre, the Operations dashboard, the Customer Invoicing page, and
// the TFN Fuel page.  Before 2026-10-01 these all used the naive
// `$u->isOwner() || $u->isDeveloper()` pattern, which short-circuits
// true for the real developer regardless of the View-as switch -- so
// a developer previewing as ops_controller / accounts / driver still
// saw the sidebar link AND, worse, could URL-hop into the page.
//
// Owner command centre already had the explicit idiom and is covered
// by the earlier "viewing as accounts/driver is 403ed" tests.  TFN
// Fuel + Customer Invoicing are the two that leaked; both now use the
// `isDeveloperNoOverride()` helper on the HasRoles trait, and these
// tests lock them down.

test('a developer viewing as an ops controller is 403ed off /admin/fuel', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'operations_controller']);

    $this->actingAs($dev)
        ->get(route('admin.fuel'))
        ->assertForbidden();
});

test('a developer viewing as accounts is 403ed off /admin/fuel', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'accounts']);

    $this->actingAs($dev)
        ->get(route('admin.fuel'))
        ->assertForbidden();
});

test('a developer with no override still reaches /admin/fuel', function () {
    // The TFN page is owner / developer / super_admin only; a plain
    // developer session must still land on it.
    $this->actingAs(viewAsDeveloper())
        ->get(route('admin.fuel'))
        ->assertOk();
});

test('a developer viewing as an owner still reaches /admin/fuel', function () {
    // Owner is in the allow-list, so previewing as owner is the
    // "safe" direction and must not lock the dev out of the page.
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'owner']);

    $this->actingAs($dev)
        ->get(route('admin.fuel'))
        ->assertOk();
});

test('a developer viewing as an ops controller is 403ed off /admin/invoices', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'operations_controller']);

    $this->actingAs($dev)
        ->get(route('admin.invoices.index'))
        ->assertForbidden();
});

test('a developer viewing as a driver is 403ed off /admin/invoices', function () {
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'driver']);

    $this->actingAs($dev)
        ->get(route('admin.invoices.index'))
        ->assertForbidden();
});

test('a developer viewing as accounts still reaches /admin/invoices', function () {
    // Accounts is in the invoicing allow-list (even though the sidebar
    // link has been hidden from them since 2026-09-30), so previewing
    // as accounts must not lock a dev out of the page itself.
    $dev = viewAsDeveloper();
    session(['dev_role_override' => 'accounts']);

    $this->actingAs($dev)
        ->get(route('admin.invoices.index'))
        ->assertOk();
});
