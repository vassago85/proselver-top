<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// /admin/dashboard now renders the Executive Overview (vehicle movement
// command centre driven by Inventory + Jobs + Invoices). Owners / ops
// controllers land here on sign-in. Previous revenue-focused dashboard
// has been retired in favour of this consolidated view.
Volt::route('dashboard', 'admin.dashboard')->name('dashboard');

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
// Ops Wallboard — second-screen overview (drivers / live events / map).
// Designed to live on a dispatch TV; uses Livewire wire:poll.5s for the
// "live" feel rather than Reverb/Echo.
Volt::route('wallboard', 'admin.wallboard.index')->name('wallboard');
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
Volt::route('vehicles', 'vehicles.index')->name('vehicles.index');
Volt::route('documents', 'admin.documents.index')->name('documents.index');
Volt::route('customers', 'admin.customers.index')->name('customers.index');
Volt::route('customers/{company}', 'admin.customers.show')->name('customers.show');

// Impersonation
Route::post('impersonate/{user}', function (\App\Models\User $user) {
    if (!auth()->user()->isDeveloper()) {
        abort(403);
    }
    session(['impersonating_from' => auth()->id()]);
    \Illuminate\Support\Facades\Auth::loginUsingId($user->id);
    return redirect()->route('dashboard');
})->name('impersonate');

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

// Legacy bookings (still accessible by URL)
Volt::route('bookings', 'admin.bookings.index')->name('bookings.index');
Volt::route('bookings/{job}', 'admin.bookings.show')->name('bookings.show');

// Jobs
Volt::route('jobs', 'admin.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'admin.jobs.show')->name('jobs.show');

// Drivers
Volt::route('drivers', 'admin.drivers.index')->name('drivers.index');
// Driver Operations — fleet-control view of who is on the road, who is
// idle, who is late, who is overloaded. Separate from /admin/drivers
// (roster + compliance) by design: ops and HR are different jobs.
Volt::route('drivers/operations', 'admin.drivers.operations')->name('drivers.operations');
Volt::route('drivers/create', 'admin.drivers.create')->name('drivers.create');
Volt::route('drivers/{user}/edit', 'admin.drivers.edit')->name('drivers.edit');

// Invoices
Volt::route('invoices', 'admin.invoices.index')->name('invoices.index');

// Companies
Volt::route('companies', 'admin.companies.index')->name('companies.index');

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

// Audit Log
Volt::route('audit-log', 'admin.audit-log')->name('audit-log');

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
