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

// View a driver-uploaded job document (vehicle photos, POD, collection
// note photos, petty-cash slips). Policy-gated by JobDocumentPolicy::view,
// which enforces the two-tier rule: paperwork = everyone on the job,
// petty-cash slips = ops + owner only.
//
// We stream through the app (rather than redirecting to a pre-signed R2
// URL) because the disk on each document row might be `local` (when R2
// was unconfigured at upload time) OR `r2`, and we don't want the URL
// scheme to change on consumers. The file size cap on uploads is 10MB
// which is fine to proxy.
Route::get('/documents/{document}/view', function (\App\Models\JobDocument $document) {
    \Illuminate\Support\Facades\Gate::authorize('view', $document);

    $disk = \Illuminate\Support\Facades\Storage::disk($document->disk);
    if (!$disk->exists($document->path)) {
        abort(404, 'Document file not found on disk.');
    }

    return $disk->response(
        $document->path,
        $document->original_filename ?: basename($document->path),
        [
            'Content-Type' => $document->mime_type ?: $disk->mimeType($document->path),
            'Content-Disposition' => 'inline; filename="' . ($document->original_filename ?: 'document') . '"',
            // Short cache so reloads are snappy but permission changes take
            // effect quickly. Don't mark public — these are auth-gated.
            'Cache-Control' => 'private, max-age=60',
        ]
    );
})->middleware('auth')->name('documents.view');

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

// Damage Report PDF download. Uses JobPolicy::generateDamageReport which
// reuses the job-view gate — anyone allowed to see the job can download
// its damage report. No status restriction (reports may be pulled long
// after completion for insurance / dispute work).
Route::get('/damage-report/{job}/download', function (\App\Models\Job $job) {
    \Illuminate\Support\Facades\Gate::authorize('generateDamageReport', $job);

    $service = app(\App\Services\DamageReportService::class);
    $pdf = $service->generate($job);
    return response($pdf, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="damage-report-' . ($job->job_number ?: $job->uuid) . '.pdf"',
    ]);
})->middleware('auth')->name('damage-report.download');

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
