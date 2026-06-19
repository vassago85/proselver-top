<?php

namespace App\Services;

use App\Models\Job;
use App\Support\Documents\DocumentImage;
use App\Support\Documents\IssuerProfile;
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
        $inspectionDiagramUri = DocumentImage::fromLocalPath(public_path('inspection-diagram.png'));

        // Resolve which company / firm is actually moving the vehicle
        // so the masthead, "Carrier" rows and signature blocks reflect
        // that. ProSelver-executed jobs keep the existing branded PDF;
        // dealer-internal / 3rd-party-courier / self-collect jobs swap
        // the carrier name, doc title, footer AND now the full
        // letterhead (logo + address + VAT + registration) to match
        // the actual executor — the dealer is the one issuing the
        // paperwork to their own driver, not us.
        $issuer = $this->resolveCarrier($job);

        $html = view('documents.collection-note', [
            'job' => $job,
            'driver' => $driver,
            'profile' => $profile,
            'qrUrl' => $qrDataUri,
            'verificationUrl' => $verificationUrl,
            'issuer' => $issuer,
            // Backwards-compatible scalar aliases derived from the
            // IssuerProfile DTO so the rest of the template (and any
            // other includer) keeps working unchanged.
            'carrierLogoUri' => $issuer->logoUri,
            'inspectionDiagramUri' => $inspectionDiagramUri,
            'carrierName' => $issuer->name,
            'docTitle' => $issuer->docTitle,
            'footerLine' => $issuer->footer,
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
     * Decide the issuer identity for the PDF based on executor_type.
     * Keeps the template generic so the same document can serve
     * ProSelver-executed jobs, dealer-internal jobs, 3rd-party
     * courier jobs and self-collect releases.
     *
     * Dealer-internal and self-collect notes now resolve to the
     * dealer company's full letterhead (logo + address + VAT + reg)
     * via IssuerProfile::forCompany(); ProSelver and 3rd-party
     * couriers keep their existing identity.
     */
    protected function resolveCarrier(Job $job): IssuerProfile
    {
        return match ($job->executor_type) {
            Job::EXECUTOR_INTERNAL => $job->company
                ? IssuerProfile::forCompany($job->company, 'Delivery Note')
                : IssuerProfile::forCourier('Dealer-managed movement', 'Delivery Note'),

            Job::EXECUTOR_THIRD_PARTY => IssuerProfile::forCourier(
                $job->third_party_courier_name ?: '3rd-Party Courier',
                'Delivery Note'
            ),

            Job::EXECUTOR_SELF_COLLECT => $job->company
                ? IssuerProfile::forCompany($job->company, 'Vehicle Release Note')
                : IssuerProfile::forCourier('Self-collect release', 'Vehicle Release Note'),

            // ProSelver (default) — preserves the original branded PDF.
            default => IssuerProfile::forProselver('Collection Note'),
        };
    }
}
