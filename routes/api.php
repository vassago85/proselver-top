<?php

use App\Http\Controllers\Api\DriverSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('driver')->group(function () {
    Route::get('jobs', [DriverSyncController::class, 'jobs']);
    Route::post('jobs/{job}/events', [DriverSyncController::class, 'syncEvents']);
    Route::post('jobs/{job}/documents', [DriverSyncController::class, 'uploadDocument']);
});
