<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'dealer.dashboard')->name('dashboard');

// Chromeless "wall display" board — meant to live on a TV in the
// dispatch office. Uses the `display` layout (no sidebar, no top bar)
// and auto-refreshes every 30 seconds. Permission gate is inside the
// Volt component (view_all_bookings); the page itself aborts 403 for
// CSO-style users with only-own-bookings access.
Volt::route('display', 'dealer.display')->name('display');

Volt::route('bookings', 'dealer.bookings.index')->name('bookings.index');
Volt::route('bookings/create', 'dealer.bookings.create')->name('bookings.create');
Volt::route('bookings/{job}', 'dealer.bookings.show')->name('bookings.show');
Volt::route('jobs', 'dealer.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'dealer.jobs.show')->name('jobs.show');
Volt::route('invoices', 'dealer.invoices.index')->name('invoices.index');
Volt::route('performance', 'dealer.performance')->name('performance');
Volt::route('team', 'dealer.team.index')->name('team.index');
Volt::route('settings/roles', 'dealer.settings.roles')->name('settings.roles');
Volt::route('locations', 'dealer.locations.index')->name('locations.index');
Volt::route('help', 'dealer.help.index')->name('help');
