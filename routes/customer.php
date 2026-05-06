<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'customer.dashboard')->name('dashboard');
Volt::route('orders', 'customer.orders.index')->name('orders.index');
Volt::route('orders/create', 'customer.orders.create')->name('orders.create');
// Bulk upload sits BEFORE orders/{job} so the literal segment wins the
// match — otherwise Laravel would try to bind 'bulk-upload' as a Job UUID
// and 404 the page. Same trick used in routes/admin.php.
Volt::route('orders/bulk-upload', 'customer.orders.bulk-upload')->name('orders.bulk-upload');
Volt::route('orders/{job}', 'customer.orders.show')->name('orders.show');
Volt::route('documents', 'customer.documents')->name('documents');
Volt::route('locations', 'customer.locations.index')->name('locations.index');
Volt::route('team', 'customer.team.index')->name('team.index');
