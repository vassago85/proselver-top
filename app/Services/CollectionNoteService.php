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

        $html = view('documents.collection-note', [
            'job' => $job,
            'driver' => $driver,
            'profile' => $profile,
            'qrUrl' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
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
}
