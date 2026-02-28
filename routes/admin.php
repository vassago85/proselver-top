<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'admin.dashboard')->name('dashboard');

// Bookings
Volt::route('bookings', 'admin.bookings.index')->name('bookings.index');
Volt::route('bookings/{job}', 'admin.bookings.show')->name('bookings.show');

// Jobs
Volt::route('jobs', 'admin.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'admin.jobs.show')->name('jobs.show');

// Drivers
Volt::route('drivers', 'admin.drivers.index')->name('drivers.index');
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
Volt::route('settings/toll-plazas', 'admin.settings.toll-plazas')->name('settings.toll-plazas');
Volt::route('settings/integrations', 'admin.settings.integrations')->name('settings.integrations');
Volt::route('settings/zones', 'admin.settings.zones')->name('settings.zones');
Volt::route('settings/zone-rates', 'admin.settings.zone-rates')->name('settings.zone-rates');

// Change Requests
Volt::route('change-requests', 'admin.change-requests.index')->name('change-requests.index');
