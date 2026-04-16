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

// Collection Note verification (public, no auth)
Route::get('/verify/{job:uuid}', function (\App\Models\Job $job) {
    $job->load(['company', 'driver', 'brand', 'pickupLocation', 'deliveryLocation']);
    return view('verify.collection-note', compact('job'));
})->name('verify.collection-note');

// Collection Note PDF download (authenticated)
Route::get('/collection-note/{job}/download', function (\App\Models\Job $job) {
    $service = app(\App\Services\CollectionNoteService::class);
    $pdf = $service->generate($job);
    return response($pdf, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="collection-note-' . $job->job_number . '.pdf"',
    ]);
})->middleware('auth')->name('collection-note.download');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->to(resolveUserHomePath(auth()->user()));
    }
    return view('pages.landing');
})->name('home');

Route::get('/dashboard', function () {
    return redirect()->to(resolveUserHomePath(auth()->user()));
})->middleware('auth')->name('dashboard');

if (!function_exists('resolveUserHomePath')) {
    function resolveUserHomePath($user): string
    {
        if ($user->isInternal() || $user->isDeveloper()) {
            return route('admin.dashboard');
        }
        if ($user->isCustomer()) {
            return route('customer.dashboard');
        }
        if ($user->isDealer()) {
            return route('dealer.dashboard');
        }
        if ($user->isOem()) {
            return route('oem.dashboard');
        }
        if ($user->isDriver()) {
            return route('driver.dashboard');
        }
        return route('login');
    }
}
