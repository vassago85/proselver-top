<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'customer.dashboard')->name('dashboard');

// Live Movements board — chromeless TV view, shared Volt component
// with the admin portal.  Covers every customer-tier tenant
// (dealer-customers, OEM-customers, body builders).  Scoped to the
// user's company by the component, permission-gated on
// view_all_bookings inside mount().
Volt::route('display', 'live-display')->name('display');
Volt::route('orders', 'customer.orders.index')->name('orders.index');
Volt::route('orders/create', 'customer.orders.create')->name('orders.create');
// Bulk upload sits BEFORE orders/{job} so the literal segment wins the
// match — otherwise Laravel would try to bind 'bulk-upload' as a Job UUID
// and 404 the page. Same trick used in routes/admin.php.
Volt::route('orders/bulk-upload', 'customer.orders.bulk-upload')->name('orders.bulk-upload');
Volt::route('orders/{job}', 'customer.orders.show')->name('orders.show');
Volt::route('documents', 'customer.documents')->name('documents');
Volt::route('locations', 'customer.locations.index')->name('locations.index');
// In-app dealer help / user guide. Read-only static page (no DB hits),
// permission-free so any customer-tier user can land on it from a
// "Help" link in the sidebar.
Volt::route('help', 'customer.help')->name('help');
Volt::route('team', 'customer.team.index')->name('team.index');
// Delivery-note branding (Phase 1B). Logo + address/VAT/registration
// + footer printed on the dealer's own collection / delivery notes.
// Page guards itself to company owners/admins (canManageCompanyData()).
Volt::route('settings/branding', 'customer.settings.branding')->name('settings.branding');
// Dealer-side driver pool — for executor_type=internal jobs. Sits
// alongside team management because both create User rows attached to
// the dealer's company via company_users.
Volt::route('drivers', 'customer.drivers.index')->name('drivers.index');
// Dealer-side petty cash queue.  Scoped to slips submitted by the
// dealer's own drivers; the page enforces tenant scoping via
// PettyCashEntryPolicy + an explicit company-id intersection.
Volt::route('petty-cash', 'customer.petty-cash.index')->name('petty-cash.index');
// Body-builder stock view — vehicles delivered to a body builder that
// are still in the dealer's stock (no return movement booked yet).
Volt::route('stock/at-body-builder', 'customer.stock.at-body-builder')->name('stock.at-body-builder');

// Dealer stock ledger (Phase 1). Index + per-vehicle show.  Bulk
// import is on its own page so the action buttons on the index
// stay focussed on sale/demo/archive.
Volt::route('stock', 'customer.stock.index')->name('stock.index');
Volt::route('stock/create', 'customer.stock.create')->name('stock.create');
Volt::route('stock/import', 'customer.stock.import')->name('stock.import');
// Sample CSV the dealer can download from the import page, paste their
// DMS export columns into, and re-upload.  Static -- no DB access -- so
// it stays a plain closure rather than a Volt component.
Route::get('stock/import/template.csv', function () {
    abort_unless(auth()->user()?->company()?->isDealer(), 404);
    abort_unless(auth()->user()?->hasPermission('manage_dealer_stock'), 403);
    $headers = ['VIN', 'Suffix', 'Variant', 'Description', 'Engine number', 'Colour', 'Registration', 'Brand', 'Model', 'Model year'];
    $sample  = ['JN1TBNT32U0000001', 'X62', 'LE', '4x4 D/Cab 3.0 DDi', 'YD25-000123', 'Galaxy Black', '', 'Nissan', 'Navara', (string) date('Y')];
    $csv  = implode(',', array_map(fn ($h) => '"' . str_replace('"', '""', $h) . '"', $headers)) . "\r\n";
    $csv .= implode(',', array_map(fn ($h) => '"' . str_replace('"', '""', $h) . '"', $sample)) . "\r\n";
    return response($csv, 200, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="proselver-stock-template.csv"',
    ]);
})->name('stock.import.template');
Volt::route('stock/{dealerStock}', 'customer.stock.show')->name('stock.show');
// Deliveries report (dealer-scoped mirror of admin/reports).
Volt::route('reports/deliveries', 'customer.reports.deliveries')->name('reports.deliveries');
// Driver trips — Phase 6.
Volt::route('trips', 'customer.trips.index')->name('trips.index');
Volt::route('trips/create', 'customer.trips.create')->name('trips.create');
Volt::route('trips/my-day', 'customer.trips.my-day')->name('trips.my-day');
Volt::route('trips/{trip}', 'customer.trips.show')->name('trips.show');

// Dealer ↔ body-builder management. Dealers add / pause body-builder
// companies that can confirm receipts and raise movement requests
// against their inventory.  Companion movement-requests queue handles
// the approve / reject side.
Volt::route('body-builders', 'customer.body-builders.index')->name('body-builders.index');
// Dealer-initiated "add a body builder we don't have in the directory"
// workflow.  Ops approves, optionally merging into an existing BB.
Volt::route('body-builders/requests', 'customer.body-builders.requests.index')->name('body-builders.requests.index');
Volt::route('body-builders/requests/create', 'customer.body-builders.requests.create')->name('body-builders.requests.create');
Volt::route('movement-requests', 'customer.movement-requests.index')->name('movement-requests.index');
Volt::route('movement-requests/{request}', 'customer.movement-requests.show')->name('movement-requests.show');
