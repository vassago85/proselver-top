<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'oem.dashboard')->name('dashboard');

// Live Movements board — chromeless TV view, shared Volt component
// with the dealer / admin portals (pages/dealer/display.blade.php
// scopes itself by the user's company for tenant audiences and
// system-wide for internal users).  Permission gated on
// view_all_bookings inside the component, same as the dealer route.
Volt::route('display', 'dealer.display')->name('display');
Volt::route('bookings', 'oem.bookings.index')->name('bookings.index');
Volt::route('bookings/create', 'oem.bookings.create')->name('bookings.create');
Volt::route('bookings/{job}', 'oem.bookings.show')->name('bookings.show');
Volt::route('jobs', 'oem.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'oem.jobs.show')->name('jobs.show');
Volt::route('vehicles', 'vehicles.index')->name('vehicles.index');
Volt::route('invoices', 'oem.invoices.index')->name('invoices.index');
Volt::route('team', 'oem.team.index')->name('team.index');
Volt::route('settings/roles', 'oem.settings.roles')->name('settings.roles');
Volt::route('locations', 'oem.locations.index')->name('locations.index');
Volt::route('help', 'oem.help.index')->name('help');
