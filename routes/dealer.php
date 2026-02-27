<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'dealer.dashboard')->name('dashboard');
Volt::route('bookings', 'dealer.bookings.index')->name('bookings.index');
Volt::route('bookings/create', 'dealer.bookings.create')->name('bookings.create');
Volt::route('bookings/{job}', 'dealer.bookings.show')->name('bookings.show');
Volt::route('jobs', 'dealer.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'dealer.jobs.show')->name('jobs.show');
Volt::route('invoices', 'dealer.invoices.index')->name('invoices.index');
Volt::route('performance', 'dealer.performance')->name('performance');
