<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// ─── Internal dashboards ────────────────────────────────────────────
// Split three ways so each internal role opens on work it can act on
// instead of one page carrying everybody's concerns:
//
//   Operations  live movement pipeline, exceptions, dispatch health
//   Finance     invoicing progress, petty cash recon, driver pay
//   Owner       thin roll-up of both, everything links through
//
// /admin/dashboard is kept as a redirect rather than a page so the many
// existing route('admin.dashboard') call sites, old bookmarks and the
// post-login redirect all keep resolving.  resolveInternalDashboardRoute()
// (app/helpers.php) is the single source of truth for who lands where --
// it must stay in an autoloaded file because this closure gets serialised
// by route:cache.
Route::get('dashboard', function () {
    $user = auth()->user();

    // The auth middleware on this group makes a null user unreachable, but
    // this is the entry point every internal sign-in passes through -- a
    // fatal here is a white screen on login, so we don't lean on that.
    if (!$user) {
        return redirect()->route('login');
    }

    return redirect()->route(resolveInternalDashboardRoute($user));
})->name('dashboard');

// The Operations dashboard is the original command centre, unchanged and
// still open to every internal role.
Volt::route('dashboard/operations', 'admin.dashboard')->name('dashboard.ops');

// Finance dashboard -- accounts, owner, developer, super admin and the
// operations controller (who owns petty-cash issuing).  The component's
// mount() is the source of truth; everyone else 403s.
Volt::route('dashboard/finance', 'admin.dashboard.finance')->name('dashboard.finance');

// Owner roll-up -- owner, developer, super admin only.
Volt::route('dashboard/owner', 'admin.dashboard.owner')->name('dashboard.owner');

// Petty-cash overview dashboard. Owner, developer, accounts and the
// operations controller can land here -- the component's mount() is
// the source of truth; everyone else 403s.  The tab in the Petty
// Cash section strip mirrors this list.
Volt::route('overview', 'admin.overview')->name('overview');

// Keep the old /admin/executive link resolvable for anyone who bookmarked
// the short-lived standalone route. Single 302 to /admin/dashboard.
Route::redirect('executive', '/admin/dashboard')->name('executive');

// Phase 1 operational routes
Volt::route('planning', 'admin.planning')->name('planning');
Volt::route('orders', 'admin.orders.index')->name('orders.index');
// Bulk upload sits BEFORE the {job} route so the literal segment wins
// the match — otherwise Laravel would try to bind 'bulk-upload' as a Job.
Volt::route('orders/bulk-upload', 'admin.orders.bulk-upload')->name('orders.bulk-upload');
Volt::route('orders/{job}', 'admin.orders.show')->name('orders.show');
Volt::route('dispatch', 'admin.dispatch')->name('dispatch');
// Wallboard route retired 2026-05-26: the TrackSolid position feed it
// depended on isn't going to be available. The Live Display below still
// works for the 3-lane status board. We keep a redirect rather than a
// 404 so any bookmark / TV browser lands on something useful.
Route::redirect('wallboard', '/admin/live-display')->name('wallboard');
// Live Movements board — system-wide 3-lane view (waiting / in
// transit / delivered today) for ops controllers, owners, super
// admins and developers.  Same Volt component as the customer
// portal's tenant-scoped board; the component branches on
// $user->isInternal() to run unscoped here and per-tenant there.
Volt::route('live-display', 'live-display')->name('live-display');
// /admin/tracking has been merged into /admin/vehicles under the
// "Live" bucket. Keep the route name so dashboard links and old
// bookmarks still resolve — just 302 to the merged page.
Route::redirect('tracking', '/admin/vehicles?bucket=live')->name('tracking');
Volt::route('deliveries', 'admin.deliveries')->name('deliveries');
Volt::route('damage', 'admin.damage.index')->name('damage');
// Petty cash review queue. Phase 2 added structured amount + approval
// workflow on top of the Phase 1 photo-only slips. Internal staff +
// platform-owner only; customers must never see this page.
Volt::route('petty-cash', 'admin.petty-cash.index')->name('petty-cash.index');

// Pre-issue petty-cash plan + owner sign-off.  Ops picks tomorrow's
// trips, system snapshots the computed advances, owner approves the
// bundle.  Internal staff only -- the page exposes per-trip cost
// breakdowns no customer should see.
Volt::route('petty-cash/plans', 'admin.petty-cash.plans')->name('petty-cash.plans');

// Reconciliation report -- advances that left the till on trips which were
// then cancelled, plus the written explanation for every one already
// settled.  The owner's audit of where that cash went; accounts and ops
// clear queries from here as well as from the Overview.  Same gate as the
// Overview (canViewPettyCashOverview), enforced in the component's mount().
Volt::route('petty-cash/reconciliation', 'admin.petty-cash.reconciliation')->name('petty-cash.reconciliation');
Volt::route('vehicles', 'vehicles.index')->name('vehicles.index');

// TFN fuel operations — single-screen view for our Truckfuelnet
// integration: live sub-account balance & credit, live diesel product
// pricing, place / cancel pre-authorisation orders, recent
// transactions from the pump, per-vehicle virtual card status.
// Component's mount() enforces internal-only (+ developer) access; the
// page runs in demo mode until TFN credentials are populated in .env
// so it is safe to expose ahead of go-live.
Volt::route('fuel', 'admin.fuel')->name('fuel');
Volt::route('documents', 'admin.documents.index')->name('documents.index');
// Companies (formerly "Customers" — the model has always been Company,
// the menu just used to call it Customers).  Old /admin/customers and
// /admin/customers/{id} URLs redirect below so deep-links keep working.
Volt::route('companies', 'admin.companies.index')->name('companies.index');
// Ops queue for dealer-initiated "add a new body builder" requests.
// Approve mints a body_builder Company; merge points an existing
// Company at the dealer.  Auto-links the dealer in both cases.
Volt::route('body-builder-requests', 'admin.body-builder-requests.index')->name('body-builder-requests.index');
// /admin/companies/groups must be registered BEFORE the {company} route
// so the literal "groups" segment wins the match — otherwise Laravel
// would try to bind 'groups' as a Company id.
Volt::route('companies/groups', 'admin.companies.groups')->name('companies.groups');
Volt::route('companies/{company}', 'admin.companies.show')->name('companies.show');

Route::redirect('customers', '/admin/companies')->name('customers.index');
Route::get('customers/{company}', function (\App\Models\Company $company) {
    return redirect()->route('admin.companies.show', $company);
})->name('customers.show');

// Impersonation.  The literal `impersonate/stop` route MUST be declared
// BEFORE the parameterized `impersonate/{user}` -- Laravel matches
// routes in registration order, and once `route:cache` freezes that
// order a POST to /admin/impersonate/stop would otherwise bind `$user`
// to the literal string "stop" and blow up on the bigint cast.
Route::post('impersonate/stop', function () {
    $originalId = session('impersonating_from');
    if (!$originalId) {
        return redirect()->route('admin.dashboard');
    }
    session()->forget('impersonating_from');
    session()->forget('dev_role_override');
    \Illuminate\Support\Facades\Auth::loginUsingId($originalId);
    return redirect()->route('admin.dashboard');
})->name('impersonate.stop');

Route::post('impersonate/{user}', function (\App\Models\User $user) {
    if (!auth()->user()->isDeveloper()) {
        abort(403);
    }
    session(['impersonating_from' => auth()->id()]);
    \Illuminate\Support\Facades\Auth::loginUsingId($user->id);
    return redirect()->route('dashboard');
})->name('impersonate');

// Developer role switching
Route::post('dev/role-switch', function (\Illuminate\Http\Request $request) {
    if (!auth()->user()->isDeveloper()) {
        abort(403);
    }
    if ($request->role_slug === 'reset') {
        session()->forget('dev_role_override');
    } else {
        session(['dev_role_override' => $request->role_slug]);
    }
    return redirect()->back();
})->name('dev.role-switch');

// Legacy /admin/bookings and /admin/jobs → /admin/orders.  Orders is the
// only internal list for a Job row; the old URLs and route names stay
// resolvable so bookmarks, emails and any leftover route('admin.bookings.*')
// / route('admin.jobs.*') call sites keep working.  Same shape as the
// customers → companies redirects above.
Route::redirect('bookings', '/admin/orders')->name('bookings.index');
Route::get('bookings/{job}', function (\App\Models\Job $job) {
    return redirect()->route('admin.orders.show', $job);
})->name('bookings.show');

Route::redirect('jobs', '/admin/orders')->name('jobs.index');
Route::get('jobs/{job}', function (\App\Models\Job $job) {
    return redirect()->route('admin.orders.show', $job);
})->name('jobs.show');

// Drivers
Volt::route('drivers', 'admin.drivers.index')->name('drivers.index');
// Driver Operations — fleet-control view of who is on the road, who is
// idle, who is late, who is overloaded. Separate from /admin/drivers
// (roster + compliance) by design: ops and HR are different jobs.
Volt::route('drivers/operations', 'admin.drivers.operations')->name('drivers.operations');
// Month-end driver pay & movement report -- accounts / owner / developer only;
// component mount() 403s anyone else.  Bundled with the Petty Cash tab strip
// because it's the same monthly recon workflow.
Volt::route('drivers/pay', 'admin.drivers.pay')->name('drivers.pay');
Volt::route('drivers/create', 'admin.drivers.create')->name('drivers.create');
Volt::route('drivers/{user}/edit', 'admin.drivers.edit')->name('drivers.edit');

// Customer invoicing -- replaces the legacy "ready for invoicing" stub.
// Accounts picks a customer + window and captures invoice number /
// amounts / fuel per ProSelver-executed movement, then exports the
// OEM-shaped Excel.  Owner/dev can also mark rows "not required" to
// keep test runs and write-offs out of the FAW spreadsheet.
Volt::route('invoices', 'admin.invoices.index')->name('invoices.index');

// ProSelver platform licence meter (SaaS fee for Trident). Owner +
// developer only — mount() 403s everyone else. Separate from customer
// freight invoicing above. Rates live in SystemSetting; copy block for
// Invoice Ninja (no API).
Volt::route('billing', 'admin.billing')->name('billing');

// Users
Volt::route('users', 'admin.users.index')->name('users.index');
Volt::route('users/create', 'admin.users.create')->name('users.create');
Volt::route('users/{user}/edit', 'admin.users.edit')->name('users.edit');

// Trips (ops view — cross-company)
Volt::route('trips', 'admin.trips.index')->name('trips.index');
Volt::route('trips/{trip}', 'admin.trips.show')->name('trips.show');

// Reports
Volt::route('reports', 'admin.reports.index')->name('reports.index');
Volt::route('reports/performance', 'admin.reports.performance')->name('reports.performance');
Volt::route('reports/financials', 'admin.reports.financials')->name('reports.financials');
Volt::route('reports/routes', 'admin.reports.routes')->name('reports.routes');
// Back-compat alias: /admin/reports/invoicing now redirects to the
// canonical /admin/invoices page (which is the customer-invoicing UI).
Route::get('reports/invoicing', fn () => redirect()->route('admin.invoices.index'))->name('reports.invoicing');

// Audit Log
Volt::route('audit-log', 'admin.audit-log')->name('audit-log');

// Login History — sign-in / failed / sign-out trail.  Same viewer gate as
// Audit Log; the component's mount() 403s everyone else.  Backed by the
// login_history table populated by App\Listeners\LogLoginActivity.
Volt::route('login-history', 'admin.login-history')->name('login-history');

// Settings
Volt::route('settings', 'admin.settings.index')->name('settings.index');
Volt::route('settings/general', 'admin.settings.general')->name('settings.general');
Volt::route('settings/email', 'admin.settings.email')->name('settings.email');
Volt::route('settings/roles', 'admin.settings.roles')->name('settings.roles');
Volt::route('settings/roles/create', 'admin.settings.roles-create')->name('settings.roles.create');
Volt::route('settings/roles/{role}/edit', 'admin.settings.roles-edit')->name('settings.roles.edit');
Volt::route('settings/brands', 'admin.settings.brands')->name('settings.brands');
Volt::route('settings/body-types', 'admin.settings.body-types')->name('settings.body-types');
Volt::route('settings/locations', 'admin.settings.locations')->name('settings.locations');
Volt::route('settings/vehicle-classes', 'admin.settings.vehicle-classes')->name('settings.vehicle-classes');
Volt::route('settings/storage', 'admin.settings.storage')->name('settings.storage');
Volt::route('settings/booking', 'admin.settings.booking')->name('settings.booking');
Volt::route('settings/cancellation', 'admin.settings.cancellation')->name('settings.cancellation');
Volt::route('settings/document-retention', 'admin.settings.document-retention')->name('settings.document-retention');
Volt::route('settings/toll-plazas', 'admin.settings.toll-plazas')->name('settings.toll-plazas');
Volt::route('settings/integrations', 'admin.settings.integrations')->name('settings.integrations');
Volt::route('settings/zones', 'admin.settings.zones')->name('settings.zones');
Volt::route('settings/zone-rates', 'admin.settings.zone-rates')->name('settings.zone-rates');

// Change Requests
Volt::route('change-requests', 'admin.change-requests.index')->name('change-requests.index');
