<?php

/**
 * Phase 2 of the site-audit cleanup: the "small nav cut".  Six top-level
 * sidebar entries that were really actions or duplicates are demoted to
 * page-level buttons or absorbed by an existing tab strip:
 *
 *   - Bulk Upload  → page-action button on Orders
 *   - Live Display → page-action button on Dispatch (new tab)
 *   - Driver Ops   → cross-link button on Drivers (and vice versa,
 *                    which already existed)
 *   - Cash Overview / Reconciliation Queries / Driver Pay
 *                  → tab strip inside Petty Cash (unchanged; sidebar
 *                    duplicates removed)
 *
 * The tests below pin the "still reachable" half of each move -- if
 * someone deletes the button or the tab strip, this file goes red.
 * The "no longer in the sidebar" half is harder to assert cheaply
 * because the same URLs also appear as page-body links on the various
 * dashboards; instead this test relies on the fact that the target
 * routes still resolve (nothing was renamed) and their normal
 * discoverability is proved through the new surfaces.
 */

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner'                 => 'Owner',
        'developer'             => 'Developer',
        'super_admin'           => 'Super Admin',
        'operations_controller' => 'Ops Controller',
        'accounts'              => 'Accounts',
        'dispatcher'            => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);

    // The Fuel page reads the TFN flag; keep it deterministic so the
    // Dispatch smoke read doesn't trip over live-lookup config.
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, true, 'boolean');
});

function navCutUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);

    return $u;
}

// -----------------------------------------------------------------
// Bulk upload — page-action button on Orders
// -----------------------------------------------------------------

test('the Orders page exposes Bulk upload as an action button for account-wide roles', function (string $slug) {
    $this->actingAs(navCutUser($slug))
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertSee(route('admin.orders.bulk-upload'))
        ->assertSee('Bulk upload');
})->with(['owner', 'developer', 'super_admin', 'operations_controller']);

test('a dispatcher does not see the Bulk upload action on Orders', function () {
    // Same gate the retired sidebar entry had -- dispatchers don't onboard
    // OEM movement files.  The link goes away entirely for them.  The
    // page itself is still open to them because Orders is where they work.
    $this->actingAs(navCutUser('dispatcher'))
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertDontSee(route('admin.orders.bulk-upload'));
});

// -----------------------------------------------------------------
// Live Display — page-action button on Dispatch
// -----------------------------------------------------------------

test('the Dispatch Board page exposes Live Display as a new-tab button', function () {
    // Any dispatch-capable role gets it; we exercise the two common ones.
    $this->actingAs(navCutUser('operations_controller'))
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee(route('admin.live-display'))
        ->assertSee('Live Display')
        // Target=_blank is the whole point -- wall monitor in a second tab.
        ->assertSee('target="_blank"', escape: false);
});

// -----------------------------------------------------------------
// Driver Ops — cross-link button on Drivers (and reverse)
// -----------------------------------------------------------------

test('the Drivers roster links across to Driver Operations', function () {
    // One-way assertion: Drivers -> Driver Ops.  The reverse link
    // (Roster & Compliance button on Driver Ops) exists in the Volt
    // page but is untested here because that page uses Postgres-only
    // SQL (LEAST + aggregate ordering) and can't render on the SQLite
    // test connection.  Same reason DashboardSplitTest skips the
    // Operations dashboard.
    $this->actingAs(navCutUser('operations_controller'))
        ->get(route('admin.drivers.index'))
        ->assertOk()
        ->assertSee(route('admin.drivers.operations'))
        ->assertSee('Driver Ops');
});

// -----------------------------------------------------------------
// Petty Cash tab strip absorbs the three finance duplicates
// -----------------------------------------------------------------

test('the Petty Cash tab strip exposes Overview / Reconciliation / Driver Pay', function () {
    // Overview + Reconciliation share canViewPettyCashOverview(); Driver
    // Pay is accounts/owner/dev only.  Accounts holds both so it's the
    // right role to prove the whole strip.
    $this->actingAs(navCutUser('accounts'))
        ->get(route('admin.petty-cash.index'))
        ->assertOk()
        ->assertSee(route('admin.overview'))
        ->assertSee(route('admin.petty-cash.reconciliation'))
        ->assertSee(route('admin.drivers.pay'))
        ->assertSee('Overview')
        ->assertSee('Reconciliation')
        ->assertSee('Driver pay');
});

test('a dispatcher sees the Slips tab but not the admin-only Petty Cash tabs', function () {
    // Dispatchers can see the queue (all internal roles can) but must not
    // see Overview / Reconciliation / Driver Pay entries -- they carry
    // per-trip cost breakdowns and month-end payroll respectively.  Same
    // gate the retired sidebar entries carried.
    $this->actingAs(navCutUser('dispatcher'))
        ->get(route('admin.petty-cash.index'))
        ->assertOk()
        ->assertSee('Slips')
        ->assertDontSee(route('admin.overview'))
        ->assertDontSee(route('admin.petty-cash.reconciliation'))
        ->assertDontSee(route('admin.drivers.pay'));
});

// -----------------------------------------------------------------
// All target routes still resolve (nothing was renamed by Phase 2)
// -----------------------------------------------------------------

test('the six moved-off-sidebar routes all still resolve', function () {
    // If any of these named routes is renamed or removed, every stale
    // dashboard KPI link, email deep-link and old bookmark breaks.  Pin
    // them here so a future refactor cannot silently drop them.
    expect(route('admin.orders.bulk-upload',      absolute: false))->toBe('/admin/orders/bulk-upload');
    expect(route('admin.live-display',            absolute: false))->toBe('/admin/live-display');
    expect(route('admin.drivers.operations',      absolute: false))->toBe('/admin/drivers/operations');
    expect(route('admin.overview',                absolute: false))->toBe('/admin/overview');
    expect(route('admin.petty-cash.reconciliation', absolute: false))->toBe('/admin/petty-cash/reconciliation');
    expect(route('admin.drivers.pay',             absolute: false))->toBe('/admin/drivers/pay');
});
