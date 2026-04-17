<?php

use App\Http\Controllers\Api\DriverSyncController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('dashboard', 'driver.dashboard')->name('dashboard');
Volt::route('jobs/{job}', 'driver.job')->name('job');

/*
 * Driver PWA sync endpoints.
 *
 * Kept under /driver/api/... so they inherit the same session + auth +
 * driver.access middleware as the Livewire pages — no Sanctum plumbing
 * required for a same-origin PWA. CSRF is handled automatically via the
 * XSRF-TOKEN cookie that Laravel sets on each response.
 */
Route::prefix('api')->name('api.')->group(function () {
    Route::get('jobs', [DriverSyncController::class, 'jobs'])->name('jobs');
    Route::post('jobs/{job}/events', [DriverSyncController::class, 'syncEvents'])->name('events');
    Route::post('jobs/{job}/documents', [DriverSyncController::class, 'uploadDocument'])->name('documents');
});
