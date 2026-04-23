<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates the customer-facing Damage Report PDF for a single movement.
 *
 * This is distinct from the collection note / POD paperwork: damage
 * reports are pulled on demand after the fact (insurance claims, credit
 * notes, dispute resolution) so they need to be self-contained — every
 * vehicle detail, every damage photo, every note — rendered inline as
 * base64 data URIs so the doc stays valid when emailed around.
 *
 * Usage:
 *   $pdf = app(DamageReportService::class)->generate($job);
 *   return response($pdf, 200, ['Content-Type' => 'application/pdf']);
 */
class DamageReportService
{
    public function generate(Job $job): string
    {
        $job->load([
            'company',
            'pickupLocation',
            'deliveryLocation',
            'driver.driverProfile',
            'brand',
            'createdBy',
            'documents' => fn ($q) => $q->orderBy('captured_at')->orderBy('created_at'),
            'documents.uploadedBy:id,name',
        ]);

        $damagePhotos = $job->documents
            ->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
            ->values();

        // Embed each damage photo as a data URI so Dompdf can render it
        // without isRemoteEnabled (kept off for safety). Skip non-images
        // gracefully: we still list the row but render a placeholder.
        $renderedPhotos = $damagePhotos->map(function (JobDocument $doc) {
            return [
                'doc'        => $doc,
                'dataUri'    => $this->embedDocumentAsDataUri($doc),
                'isImage'    => str_starts_with((string) $doc->mime_type, 'image/'),
                'noteText'   => $this->cleanNote($doc->notes),
                'capturedAt' => $doc->captured_at ?? $doc->created_at,
                'uploader'   => $doc->uploadedBy?->name,
                'lat'        => $doc->latitude,
                'lng'        => $doc->longitude,
            ];
        });

        $html = view('documents.damage-report', [
            'job'             => $job,
            'damagePhotos'    => $renderedPhotos,
            'generatedAt'     => now(),
            'carrierLogoUri'  => $this->buildCarrierLogoDataUri(),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Read the document off its configured disk (local or r2) and return
     * a base64 data URI. Returns null on failure so the template can show
     * a placeholder instead of blowing up the whole PDF.
     */
    protected function embedDocumentAsDataUri(JobDocument $doc): ?string
    {
        try {
            $disk = Storage::disk($doc->disk);
            if (!$disk->exists($doc->path)) {
                return null;
            }

            $contents = $disk->get($doc->path);
            if ($contents === null || $contents === '') {
                return null;
            }

            $mime = $doc->mime_type ?: 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode($contents);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The driver PWA captures damage photos with an optional free-text
     * note in the notes field. Anything that looks like an internal
     * slot tag (slot:xxx — used for position tagging) is stripped so it
     * doesn't leak into the customer PDF.
     */
    protected function cleanNote(?string $notes): ?string
    {
        if (!is_string($notes) || $notes === '') {
            return null;
        }
        if (str_starts_with($notes, 'slot:')) {
            return null;
        }
        return trim($notes);
    }

    protected function buildCarrierLogoDataUri(): ?string
    {
        $path = public_path('proselverlogo-2.png');
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
