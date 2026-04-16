<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Collection Note - {{ $job->job_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; margin: 40px; }
        .company-name { font-size: 24px; font-weight: bold; color: #1e40af; }
        .doc-title { font-size: 20px; font-weight: bold; text-align: right; text-transform: uppercase; }
        .section-title { font-size: 13px; font-weight: bold; color: #1e40af; text-transform: uppercase; padding: 8px 0 4px 0; border-bottom: 2px solid #1e40af; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; }
        .detail-table td { padding: 5px 10px; border: none; vertical-align: top; }
        .detail-label { font-weight: bold; color: #666; width: 35%; font-size: 11px; text-transform: uppercase; }
        .detail-value { color: #111; }
        .qr-section { text-align: center; margin-top: 30px; }
        .qr-section img { width: 150px; height: 150px; }
        .qr-text { font-size: 10px; color: #666; margin-top: 6px; }
        .verification-url { font-size: 9px; color: #999; word-break: break-all; margin-top: 4px; }
        .footer { margin-top: 40px; padding-top: 15px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #999; text-align: center; }
    </style>
</head>
<body>
    {{-- Header --}}
    <table style="margin-bottom: 20px;">
        <tr>
            <td style="border: none; padding: 0;">
                <div class="company-name">ProSelverTech</div>
                <div style="color: #666; margin-top: 5px;">Booking, Dispatching & Tracking</div>
            </td>
            <td style="border: none; padding: 0; text-align: right;">
                <div class="doc-title">Collection Note</div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">{{ $job->job_number }}</div>
            </td>
        </tr>
    </table>

    {{-- Movement Reference --}}
    <div class="section-title">Movement Reference</div>
    <table class="detail-table">
        <tr>
            <td class="detail-label">Job Number</td>
            <td class="detail-value">{{ $job->job_number }}</td>
        </tr>
        <tr>
            <td class="detail-label">Date</td>
            <td class="detail-value">{{ $job->scheduled_date?->format('d M Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Company</td>
            <td class="detail-value">{{ $job->company?->name ?? '-' }}</td>
        </tr>
    </table>

    {{-- Driver Details --}}
    <div class="section-title">Driver Details</div>
    <table class="detail-table">
        <tr>
            <td class="detail-label">Driver Name</td>
            <td class="detail-value">{{ $driver?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">ID Number</td>
            <td class="detail-value">{{ $profile?->id_number ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Cellphone</td>
            <td class="detail-value">{{ $profile?->cellphone ?? $driver?->phone ?? '-' }}</td>
        </tr>
    </table>

    {{-- Vehicle Details --}}
    <div class="section-title">Vehicle Details</div>
    <table class="detail-table">
        <tr>
            <td class="detail-label">Brand</td>
            <td class="detail-value">{{ $job->brand?->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Model</td>
            <td class="detail-value">{{ $job->model_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">VIN / Chassis</td>
            <td class="detail-value">{{ $job->vin ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Registration</td>
            <td class="detail-value">{{ $job->registration ?? '-' }}</td>
        </tr>
    </table>

    {{-- Collection Location --}}
    <div class="section-title">Collection Location</div>
    <table class="detail-table">
        <tr>
            <td class="detail-label">Address</td>
            <td class="detail-value">{{ $job->pickupLocation?->full_address ?? $job->pickupLocation?->company_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Contact Name</td>
            <td class="detail-value">{{ $job->pickup_contact_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="detail-label">Contact Phone</td>
            <td class="detail-value">{{ $job->pickup_contact_phone ?? '-' }}</td>
        </tr>
    </table>

    {{-- Delivery Location --}}
    <div class="section-title">Delivery Location</div>
    <table class="detail-table">
        <tr>
            <td class="detail-label">Address</td>
            <td class="detail-value">{{ $job->deliveryLocation?->full_address ?? $job->deliveryLocation?->company_name ?? '-' }}</td>
        </tr>
    </table>

    {{-- QR Code --}}
    <div class="qr-section">
        <img src="{{ $qrUrl }}" alt="Verification QR Code">
        <div class="qr-text">Scan to verify this collection note</div>
        <div class="verification-url">{{ $verificationUrl }}</div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        <p>ProSelverTech &mdash; Generated {{ now()->format('d M Y H:i') }}</p>
    </div>
</body>
</html>
