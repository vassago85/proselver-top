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
        $inspectionDiagramUri = $this->buildImageDataUri(public_path('inspection-diagram.png'));

        // Resolve which company / firm is actually moving the vehicle
        // so the masthead, "Carrier" rows and signature blocks reflect
        // that. ProSelver-executed jobs keep the existing branded PDF;
        // dealer-internal / 3rd-party-courier / self-collect jobs swap
        // the carrier name, doc title, and footer to match the actual
        // executor — the dealer is the one issuing the paperwork to
        // their own driver, not us.
        $carrier = $this->resolveCarrier($job);

        $html = view('documents.collection-note', [
            'job' => $job,
            'driver' => $driver,
            'profile' => $profile,
            'qrUrl' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
            'carrierLogoUri' => $carrier['logo_uri'],
            'inspectionDiagramUri' => $inspectionDiagramUri,
            'carrierName' => $carrier['name'],
            'docTitle' => $carrier['doc_title'],
            'footerLine' => $carrier['footer'],
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
     * Decide the carrier identity for the PDF based on executor_type.
     * Keeps the template generic so the same document can serve
     * ProSelver-executed jobs, dealer-internal jobs, 3rd-party
     * courier jobs and self-collect releases.
     *
     * @return array{name:string, doc_title:string, footer:string, logo_uri:?string}
     */
    protected function resolveCarrier(Job $job): array
    {
        $proselverLogo = $this->buildImageDataUri(public_path('proselverlogo-2.png'));

        return match ($job->executor_type) {
            Job::EXECUTOR_INTERNAL => [
                'name'      => $job->company?->name ?: 'Dealer-managed movement',
                'doc_title' => 'Delivery Note',
                'footer'    => ($job->company?->name ?: 'Dealer') . ' — issued via TRIDENT Control & Dispatch Center',
                // We deliberately drop the ProSelver logo for dealer-
                // managed paperwork — no carrier logo for internal
                // moves until we wire a per-company logo field on
                // Company. The text masthead falls back cleanly.
                'logo_uri'  => null,
            ],
            Job::EXECUTOR_THIRD_PARTY => [
                'name'      => $job->third_party_courier_name ?: '3rd-Party Courier',
                'doc_title' => 'Delivery Note',
                'footer'    => 'Movement by ' . ($job->third_party_courier_name ?: '3rd-party courier') . ' — issued via TRIDENT Control & Dispatch Center',
                'logo_uri'  => null,
            ],
            Job::EXECUTOR_SELF_COLLECT => [
                'name'      => $job->company?->name ?: 'Self-collect release',
                'doc_title' => 'Vehicle Release Note',
                'footer'    => ($job->company?->name ?: 'Dealer') . ' — issued via TRIDENT Control & Dispatch Center',
                'logo_uri'  => null,
            ],
            // ProSelver (default) — preserves the original branded PDF.
            default => [
                'name'      => 'Proselver Technologies',
                'doc_title' => 'Collection Note',
                'footer'    => 'Proselver Technologies (Pty) Ltd — dispatched via TRIDENT Control & Dispatch Center',
                'logo_uri'  => $proselverLogo,
            ],
        };
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
