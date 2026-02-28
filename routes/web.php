<?php

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/po/{po}/preview', function (PurchaseOrder $po) {
    $user = auth()->user();
    $job = $po->job;

    if (!$job) {
        abort(404);
    }

    $isOwner = $user->company() && $job->company_id === $user->company()->id;
    $isInternal = $user->isInternal();

    if (!$isOwner && !$isInternal) {
        abort(403);
    }

    if (!$po->document_path || !Storage::disk($po->document_disk)->exists($po->document_path)) {
        abort(404, 'Document not found.');
    }

    $mime = Storage::disk($po->document_disk)->mimeType($po->document_path);

    return Storage::disk($po->document_disk)->response($po->document_path, $po->original_filename, [
        'Content-Type' => $mime,
        'Content-Disposition' => 'inline; filename="' . ($po->original_filename ?? 'document') . '"',
    ]);
})->middleware('auth')->name('po.preview');

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
