<?php

namespace App\Services;

use App\Models\Job;
use Dompdf\Dompdf;
use Dompdf\Options;

class CollectionNoteService
{
    public function generate(Job $job): string
    {
        $job->load(['driver.driverProfile', 'pickupLocation', 'deliveryLocation', 'brand', 'company']);

        $driver = $job->driver;
        $profile = $driver?->driverProfile;
        $verificationUrl = route('verify.collection-note', $job->uuid);

        // Google Charts API for QR code generation without external packages
        $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' . urlencode($verificationUrl);

        $html = view('documents.collection-note', [
            'job' => $job,
            'driver' => $driver,
            'profile' => $profile,
            'qrUrl' => $qrUrl,
            'verificationUrl' => $verificationUrl,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
