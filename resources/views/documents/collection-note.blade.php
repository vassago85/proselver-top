<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Collection Note - {{ $job->job_number }}</title>
    <style>
        @page { margin: 28px 32px 56px 32px; }

        * { box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }

        /* ---------- Masthead ---------- */
        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .masthead td { border: none; padding: 0; vertical-align: middle; }
        .carrier-logo { height: 34px; }
        .carrier-fallback { font-size: 14px; font-weight: bold; color: #111827; letter-spacing: 0.3px; }
        .doc-title { font-size: 18px; font-weight: bold; color: #1e40af; text-transform: uppercase; text-align: right; line-height: 1; }
        .doc-number { font-size: 11px; color: #6b7280; text-align: right; margin-top: 3px; }

        .brand-band { border-top: 2px solid #1e40af; border-bottom: 1px solid #e5e7eb; padding: 6px 0; margin-bottom: 12px; }
        .brand-band .name { font-size: 13px; font-weight: bold; color: #1e40af; }
        .brand-band .tagline { font-size: 9.5px; color: #6b7280; }

        /* ---------- Sections ---------- */
        .section-title { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; padding: 4px 0 3px 0; border-bottom: 1.5px solid #1e40af; margin: 10px 0 4px 0; }

        table.detail { width: 100%; border-collapse: collapse; }
        table.detail td { padding: 2px 6px; border: none; vertical-align: top; }
        td.label { font-weight: bold; color: #6b7280; font-size: 9px; text-transform: uppercase; width: 34%; white-space: nowrap; }
        td.value { color: #111827; font-size: 10.5px; }

        /* ---------- Two-column grid ---------- */
        .grid { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 0 -12px; }
        .grid > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        /* ---------- Signature / QR row ---------- */
        .bottom { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .bottom td { border: none; padding: 0; vertical-align: top; }

        .signature-block { border: 1px solid #d1d5db; padding: 10px; height: 130px; }
        .signature-title { font-size: 9.5px; font-weight: bold; color: #374151; text-transform: uppercase; margin-bottom: 6px; }
        .sig-line { border-bottom: 1px solid #9ca3af; height: 18px; margin-top: 14px; }
        .sig-label { font-size: 8.5px; color: #6b7280; margin-top: 2px; }

        .qr-cell { text-align: center; width: 170px; padding-left: 14px !important; }
        .qr-cell img { width: 130px; height: 130px; }
        .qr-text { font-size: 8.5px; color: #6b7280; margin-top: 4px; }
        .verification-url { font-size: 7.5px; color: #9ca3af; word-break: break-all; margin-top: 2px; line-height: 1.2; }

        /* ---------- Fixed page footer (uses @page bottom margin) ---------- */
        .page-footer { position: fixed; left: 0; right: 0; bottom: -38px; font-size: 8.5px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>

    {{-- ===== Masthead: carrier logo + document title ===== --}}
    <table class="masthead">
        <tr>
            <td style="width: 60%;">
                @if($carrierLogoUri)
                    <img src="{{ $carrierLogoUri }}" alt="Proselver Technologies" class="carrier-logo">
                @else
                    <div class="carrier-fallback">Proselver Technologies</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="doc-title">Collection Note</div>
                <div class="doc-number">{{ $job->job_number }}</div>
            </td>
        </tr>
    </table>

    {{-- Platform brand band: TRIDENT is the dispatch platform, Proselver is the carrier --}}
    <div class="brand-band">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="border: none; padding: 0;">
                    <span class="name">TRIDENT</span>
                    <span class="tagline">&nbsp;·&nbsp; Control &amp; Dispatch Center</span>
                </td>
                <td style="border: none; padding: 0; text-align: right;" class="tagline">
                    Issued {{ now()->format('d M Y H:i') }}
                </td>
            </tr>
        </table>
    </div>

    {{-- ===== Movement + Driver (two columns) ===== --}}
    <table class="grid">
        <tr>
            <td>
                <div class="section-title">Movement Reference</div>
                <table class="detail">
                    <tr><td class="label">Job No.</td><td class="value">{{ $job->job_number }}</td></tr>
                    <tr><td class="label">Date</td><td class="value">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="label">Customer</td><td class="value">{{ $job->company?->name ?? '—' }}</td></tr>
                    <tr><td class="label">Carrier</td><td class="value">Proselver Technologies</td></tr>
                </table>
            </td>
            <td>
                <div class="section-title">Driver Details</div>
                <table class="detail">
                    <tr><td class="label">Name</td><td class="value">{{ $driver?->name ?? '—' }}</td></tr>
                    <tr><td class="label">ID No.</td><td class="value">{{ $profile?->id_number ?? '—' }}</td></tr>
                    <tr><td class="label">Cellphone</td><td class="value">{{ $profile?->cellphone ?? $driver?->phone ?? '—' }}</td></tr>
                    <tr><td class="label">Trade Plate</td><td class="value">{{ $profile?->trade_plate ?: '—' }}</td></tr>
                    <tr><td class="label">Plate Expiry</td><td class="value">{{ $profile?->trade_plate_expiry?->format('d M Y') ?: '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ===== Vehicle (full width) ===== --}}
    <div class="section-title">Vehicle Details</div>
    <table class="detail">
        <tr>
            <td class="label" style="width: 17%;">Brand</td>
            <td class="value" style="width: 33%;">{{ $job->brand?->name ?? '—' }}</td>
            <td class="label" style="width: 17%;">Model</td>
            <td class="value" style="width: 33%;">{{ $job->model_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">VIN / Chassis</td>
            <td class="value">{{ $job->vin ?? '—' }}</td>
            <td class="label">Registration</td>
            <td class="value">{{ $job->registration ?? '—' }}</td>
        </tr>
    </table>

    {{-- ===== Collection / Delivery (two columns, matching carrier-style layout) ===== --}}
    @php
        $pickup = $job->pickupLocation;
        $delivery = $job->deliveryLocation;

        $formatLocation = function ($loc) {
            if (!$loc) return '—';
            $parts = array_filter([
                $loc->company_name,
                $loc->address,
                trim(implode(', ', array_filter([$loc->city, $loc->province]))),
            ]);
            return implode("\n", $parts);
        };
    @endphp
    <table class="grid">
        <tr>
            <td>
                <div class="section-title">Collect From</div>
                <table class="detail">
                    <tr>
                        <td class="value" style="white-space: pre-line; padding: 4px 6px;">{{ $formatLocation($pickup) }}</td>
                    </tr>
                </table>
                <table class="detail" style="margin-top: 4px;">
                    <tr><td class="label">Contact</td><td class="value">{{ $job->pickup_contact_name ?? '—' }}</td></tr>
                    <tr><td class="label">Phone</td><td class="value">{{ $job->pickup_contact_phone ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <div class="section-title">Deliver To</div>
                <table class="detail">
                    <tr>
                        <td class="value" style="white-space: pre-line; padding: 4px 6px;">{{ $formatLocation($delivery) }}</td>
                    </tr>
                </table>
                <table class="detail" style="margin-top: 4px;">
                    <tr><td class="label">Contact</td><td class="value">{{ $job->delivery_contact_name ?? '—' }}</td></tr>
                    <tr><td class="label">Phone</td><td class="value">{{ $job->delivery_contact_phone ?? '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ===== Signature block + QR (side-by-side) ===== --}}
    <table class="bottom">
        <tr>
            <td>
                <div class="signature-block">
                    <div class="signature-title">Collected By (Name &amp; Signature)</div>
                    <div class="sig-line"></div>
                    <div class="sig-label">Print name</div>
                    <div class="sig-line" style="margin-top: 10px;"></div>
                    <div class="sig-label">Signature &amp; date</div>
                </div>
            </td>
            <td class="qr-cell">
                <img src="{{ $qrUrl }}" alt="Verification QR Code">
                <div class="qr-text">Scan to verify</div>
                <div class="verification-url">{{ $verificationUrl }}</div>
            </td>
        </tr>
    </table>

    {{-- Fixed footer — lives inside the @page bottom margin so it never pushes a new page --}}
    <div class="page-footer">
        Proselver Technologies (Pty) Ltd &mdash; dispatched via TRIDENT Control &amp; Dispatch Center
    </div>

</body>
</html>
