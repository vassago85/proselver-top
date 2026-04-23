<?php

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

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

// Collection Note PDF download. Guarded by JobPolicy::generateCollectionNote
// to block cross-tenant IDOR — without this, any authenticated user could
// iterate numeric Job ids and pull another company's PDF (which contains
// driver SA ID, VIN, customer phone and notes).
Route::get('/collection-note/{job}/download', function (\App\Models\Job $job) {
    \Illuminate\Support\Facades\Gate::authorize('generateCollectionNote', $job);

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

// Self-service profile & password for every signed-in user, regardless of
// role. Sits outside the role-scoped /admin, /dealer, /oem, /customer groups
// so a user can always reach their own settings from the top-right dropdown.
Volt::route('/profile', 'profile.index')
    ->middleware('auth')
    ->name('profile.index');
