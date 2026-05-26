<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobDocument;
use App\Models\PettyCashEntry;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Per-vehicle / per-trip petty-cash PDF report.
 *
 * Generates the owner-requested report for a single Job (= one cargo
 * VIN being moved).  Layout:
 *
 *   1. Header strip: VIN, customer, route, driver name + cellphone
 *      (cellphone surfaces because it's the bank-send routing key).
 *   2. Advance issued: the toll-plaza snapshot from advance_toll_breakdown
 *      followed by accommodation/taxi/food/total + who issued + when.
 *   3. Slips submitted: one row per petty_cash_entry on this job, with
 *      the receipt image embedded inline as a base64 data URI.
 *   4. Variance summary: per-category and overall.
 *
 * Mirrors the CollectionNoteService pattern (Dompdf, isRemoteEnabled
 * false, local raster embeds) so the production PDF output stays
 * consistent across the app.
 */
class TripReportService
{
    public function generate(Job $job): string
    {
        $job->load([
            'company:id,name',
            'pickupLocation:id,company_name,city',
            'deliveryLocation:id,company_name,city',
            'brand:id,name',
            'vehicleClass:id,name',
            'driver:id,name,phone',
            'driver.driverProfile:user_id,cellphone',
            'advanceAssignedBy:id,name',
        ]);

        $slips = PettyCashEntry::query()
            ->where('job_id', $job->id)
            ->with(['document:id,disk,path,mime_type'])
            ->orderBy('created_at')
            ->get();

        // Pre-encode each slip image as a base64 data URI -- DomPDF
        // can't fetch remote URLs (isRemoteEnabled is off for safety),
        // and slip images often live on R2 or another non-public disk
        // anyway.  Slips without a viewable image keep $imageUri = null
        // and the template renders a placeholder card.
        $slipPayload = $slips->map(function (PettyCashEntry $slip) {
            return [
                'entry' => $slip,
                'imageUri' => $this->encodeDocumentImage($slip->document),
            ];
        });

        // Spend roll-up per advance category.  We map slip categories
        // onto the four advance lines so the variance line in the
        // template compares like-with-like.  Anything else (parking,
        // other) falls into "Other" -- still counted in total spent.
        $approved = $slips->where('status', PettyCashEntry::STATUS_APPROVED);
        $spent = [
            'tolls' => round($approved->where('category', PettyCashEntry::CATEGORY_TOLL)->sum('amount_cents') / 100, 2),
            'accommodation' => round($approved->where('category', PettyCashEntry::CATEGORY_ACCOMMODATION)->sum('amount_cents') / 100, 2),
            'food' => round($approved->where('category', PettyCashEntry::CATEGORY_FOOD)->sum('amount_cents') / 100, 2),
            // Parking rolls in with taxi for v1 -- they share the
            // "moving the driver around" advance bucket.
            'taxi' => round($approved->where('category', PettyCashEntry::CATEGORY_PARKING)->sum('amount_cents') / 100, 2),
            'other' => round($approved->whereIn('category', [PettyCashEntry::CATEGORY_OTHER, PettyCashEntry::CATEGORY_FUEL])->sum('amount_cents') / 100, 2),
            'total' => round($slips->where('status', '!=', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents') / 100, 2),
        ];

        $advance = [
            'tolls' => (float) ($job->advance_tolls ?? 0),
            'accommodation' => (float) ($job->advance_accommodation ?? 0),
            'taxi' => (float) ($job->advance_taxi ?? 0),
            'food' => (float) ($job->advance_food ?? 0),
            'total' => (float) ($job->advance_total ?? 0),
            'toll_breakdown' => $job->advance_toll_breakdown ?? [],
            'issued_by' => $job->advanceAssignedBy?->name,
            'issued_at' => $job->advance_assigned_at,
            'increase_reason' => $job->advance_increase_reason,
        ];

        $driverPhone = $job->driver?->phone ?: ($job->driver?->driverProfile?->cellphone ?? null);

        $html = view('documents.trip-report', [
            'job' => $job,
            'driverPhone' => $driverPhone,
            'advance' => $advance,
            'spent' => $spent,
            'variance' => round($spent['total'] - $advance['total'], 2),
            'slipPayload' => $slipPayload,
            'logoUri' => $this->buildImageDataUri(public_path('proselverlogo-2.png')),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Read a JobDocument's underlying file from its configured disk and
     * return it as a base64 data URI suitable for inline embedding.
     * Non-image documents (PDF receipts) and unreadable files return
     * null so the template can render a placeholder.
     */
    protected function encodeDocumentImage(?JobDocument $document): ?string
    {
        if (!$document || !$document->path) {
            return null;
        }
        if (!str_starts_with((string) $document->mime_type, 'image/')) {
            return null;
        }

        try {
            $disk = Storage::disk($document->disk ?: config('filesystems.default'));
            if (!$disk->exists($document->path)) {
                return null;
            }
            $contents = $disk->get($document->path);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$contents) {
            return null;
        }

        return 'data:' . $document->mime_type . ';base64,' . base64_encode($contents);
    }

    protected function buildImageDataUri(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/png',
        };
        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
