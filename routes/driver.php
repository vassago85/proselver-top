<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'driver.dashboard')->name('dashboard');
Volt::route('jobs/{job}', 'driver.job')->name('job');
