<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'oem.dashboard')->name('dashboard');
Volt::route('bookings', 'oem.bookings.index')->name('bookings.index');
Volt::route('bookings/create', 'oem.bookings.create')->name('bookings.create');
Volt::route('bookings/{job}', 'oem.bookings.show')->name('bookings.show');
Volt::route('jobs', 'oem.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'oem.jobs.show')->name('jobs.show');
Volt::route('invoices', 'oem.invoices.index')->name('invoices.index');
Volt::route('team', 'oem.team.index')->name('team.index');
Volt::route('locations', 'oem.locations.index')->name('locations.index');
Volt::route('help', 'oem.help.index')->name('help');
