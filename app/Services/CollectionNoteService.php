<?php

namespace App\Services;

use App\Models\Job;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class CollectionNoteService
{
    public function generate(Job $job): string
    {
        $job->load(['driver.driverProfile', 'pickupLocation', 'deliveryLocation', 'brand', 'company']);

        $driver = $job->driver;
        $profile = $driver?->driverProfile;
        $verificationUrl = $this->buildVerificationUrl($job);
        $qrDataUri = $this->buildQrDataUri($verificationUrl);
        $carrierLogoUri = $this->buildCarrierLogoDataUri();
        $inspectionDiagramUri = $this->buildImageDataUri(public_path('inspection-diagram.png'));

        $html = view('documents.collection-note', [
            'job' => $job,
            'driver' => $driver,
            'profile' => $profile,
            'qrUrl' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
            'carrierLogoUri' => $carrierLogoUri,
            'inspectionDiagramUri' => $inspectionDiagramUri,
        ])->render();

        $options = new Options();
        // QR is embedded as a data URI so remote fetch is not required for the PDF.
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Build the absolute URL embedded in the QR code. If `COLLECTION_NOTE_PUBLIC_URL`
     * is configured we use that as the base so we can cut over domains (e.g. during
     * the move to tcdc.co.za) without reconfiguring `APP_URL` everywhere.
     */
    protected function buildVerificationUrl(Job $job): string
    {
        $relative = route('verify.collection-note', $job->uuid, false);

        $base = rtrim((string) config('services.collection_note_public_url', ''), '/');

        return $base !== ''
            ? $base . $relative
            : route('verify.collection-note', $job->uuid);
    }

    protected function buildQrDataUri(string $payload): string
    {
        $qr = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 400,
            margin: 10,
        );

        $png = (new PngWriter())->write($qr)->getString();

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Executing-carrier logo (currently Proselver Technologies — the company
     * physically performing every movement on the platform today). Embedded
     * as a base64 data URI so Dompdf can render it without `isRemoteEnabled`.
     * Returns null if the logo file is missing, and the view falls back to
     * a text-only masthead.
     */
    protected function buildCarrierLogoDataUri(): ?string
    {
        return $this->buildImageDataUri(public_path('proselverlogo-2.png'));
    }

    /**
     * Read any local PNG/JPEG as a base64 data URI for inline embedding in
     * the PDF. Dompdf's SVG renderer is patchy, so we prefer raster assets
     * embedded this way for anything beyond the simplest shapes. Returns
     * null if the file is missing or can't be read, so the view can fall
     * back cleanly.
     */
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
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            default       => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
