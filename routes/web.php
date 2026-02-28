<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->isInternal()) {
            return redirect()->route('admin.dashboard');
        }
        if ($user->isDealer()) {
            return redirect()->route('dealer.dashboard');
        }
        if ($user->isOem()) {
            return redirect()->route('oem.dashboard');
        }
        if ($user->isDriver()) {
            return redirect()->route('driver.dashboard');
        }
    }
    return redirect()->route('login');
})->name('home');

Route::get('/dashboard', function () {
    $user = auth()->user();
    if ($user->isInternal()) {
        return redirect()->route('admin.dashboard');
    }
    if ($user->isDealer()) {
        return redirect()->route('dealer.dashboard');
    }
    if ($user->isOem()) {
        return redirect()->route('oem.dashboard');
    }
    if ($user->isDriver()) {
        return redirect()->route('driver.dashboard');
    }
    return redirect()->route('login');
})->middleware('auth')->name('dashboard');
