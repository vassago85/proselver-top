@php
    /*
     * Dynamic carrier identity — set by CollectionNoteService::resolveCarrier()
     * based on $job->executor_type. ProSelver-executed jobs keep the
     * historical "Collection Note" branding; dealer-internal / courier
     * / self-collect jobs render the same template with the dealer's
     * (or courier's) name in the masthead, Carrier rows and footer.
     *
     * Defaults below keep the partial render-safe if the variable is
     * ever passed through without going via the service.
     */
    $carrierName  = $carrierName  ?? 'Proselver Technologies';
    $docTitle     = $docTitle     ?? 'Collection Note';
    $footerLine   = $footerLine   ?? 'Proselver Technologies (Pty) Ltd — dispatched via TRIDENT Control & Dispatch Center';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $docTitle }} - {{ $job->job_number }}</title>
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
        .doc-copy { font-size: 9.5px; color: #6b7280; text-align: right; margin-top: 1px; text-transform: uppercase; letter-spacing: 0.2px; }

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

        /* ---------- Released By (full width) ---------- */
        .released-full { width: 100%; border: 1px solid #d1d5db; padding: 10px 12px; margin-top: 12px; }
        .released-full .sig-title { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; margin-bottom: 2px; }
        .released-full .sig-sub { font-size: 9px; color: #6b7280; line-height: 1.3; margin-bottom: 8px; }

        .released-grid { width: 100%; border-collapse: collapse; }
        .released-grid td { border: none; padding: 0; vertical-align: top; }
        .released-grid td.lines { width: 40%; padding-right: 14px; }
        .released-grid td.stamp { width: 60%; padding-left: 14px; }

        .sig-line { border-bottom: 1px solid #9ca3af; height: 16px; margin-top: 12px; }
        .sig-line.filled { font-size: 10px; color: #111827; padding-bottom: 1px; line-height: 16px; }
        .sig-label { font-size: 8px; color: #6b7280; margin-top: 1px; }

        .stamp-box-lg { border: 1px dashed #9ca3af; height: 130px; display: block; }
        .stamp-box { border: 1px dashed #9ca3af; height: 74px; display: block; }
        .stamp-label { font-size: 8px; color: #6b7280; text-align: center; margin-top: 2px; }

        /* ---------- Driver declaration + QR row (below Released By) ---------- */
        .declrow { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 10px -12px 0 -12px; }
        .declrow > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .decl-box { border: 1px solid #d1d5db; padding: 10px 12px; height: 160px; }
        .decl-box .sig-title { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; margin-bottom: 2px; }
        .decl-box .sig-sub { font-size: 9px; color: #6b7280; line-height: 1.3; margin-bottom: 4px; }

        .inspect-confirm { border: 1px solid #1e40af; background: #eff6ff; padding: 5px 8px; margin-top: 4px; }
        .inspect-confirm .body { font-size: 8.5px; color: #1e3a8a; line-height: 1.35; }

        .qr-panel { border: 1px solid #d1d5db; padding: 10px 12px; height: 160px; text-align: center; }
        .qr-panel .qr-img { width: 108px; height: 108px; margin-top: 2px; }
        .qr-panel .qr-heading { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; }
        .qr-panel .qr-sub { font-size: 8.5px; color: #6b7280; line-height: 1.3; margin-top: 2px; }
        .qr-panel .qr-url { font-size: 7.5px; color: #9ca3af; word-break: break-all; margin-top: 4px; }

        /* ---------- Special instructions callout ---------- */
        .instructions { border: 1px solid #fcd34d; background: #fffbeb; padding: 6px 10px; margin-top: 10px; }
        .instructions .hdr { font-size: 9px; font-weight: bold; color: #92400e; text-transform: uppercase; margin-bottom: 2px; }
        .instructions .body { font-size: 10.5px; color: #1f2937; white-space: pre-line; line-height: 1.35; }

        /* ---------- Fixed page footer (uses @page bottom margin) ---------- */
        .page-footer { position: fixed; left: 0; right: 0; bottom: -38px; font-size: 8.5px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }

        /* ---------- Page break helper ---------- */
        .page-break { page-break-before: always; }

        /* ========================================================
           PAGE 2 — Manual Inspection Report (Motorvia-style)
           ======================================================== */
        .mi-banner { border: 2px solid #b91c1c; background: #fef2f2; padding: 8px 12px; margin-bottom: 10px; }
        .mi-banner .title { font-size: 13px; font-weight: bold; color: #991b1b; text-transform: uppercase; letter-spacing: 0.2px; }
        .mi-banner .sub { font-size: 9px; color: #7f1d1d; margin-top: 2px; line-height: 1.35; }

        .mi-ref { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-bottom: 8px; }
        .mi-ref td { border: none; padding: 2px 4px; vertical-align: top; }
        .mi-ref td.lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; width: 14%; white-space: nowrap; }
        .mi-ref td.val { font-size: 10px; color: #111827; }

        .mi-section { margin-top: 8px; }
        .mi-section-title { font-size: 10px; font-weight: bold; color: #1e40af; text-transform: uppercase; padding: 3px 0; border-bottom: 1.5px solid #1e40af; margin-bottom: 4px; }

        .mi-legend { font-size: 8px; color: #6b7280; font-style: italic; margin-bottom: 4px; line-height: 1.35; }

        .mi-check-row { width: 100%; border-collapse: collapse; font-size: 9.5px; margin: 2px 0; }
        .mi-check-row td { border: none; padding: 3px 0; vertical-align: middle; }
        .mi-check-row td.letter { width: 18px; font-weight: bold; color: #1e40af; }
        .mi-check-row td.name { color: #1f2937; }
        .mi-check-row td.entry { width: 38%; padding-left: 8px; }
        .mi-check-row td.entry .entry-line { border-bottom: 1px solid #9ca3af; height: 11px; }

        /* Accessories list — two columns of single-line items */
        .acc-list { width: 100%; border-collapse: separate; border-spacing: 14px 0; margin: 0 -14px; }
        .acc-list > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .acc-row { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .acc-row td { border: none; padding: 2px 0; vertical-align: middle; }
        .acc-row td.name { color: #1f2937; width: 55%; }
        .acc-row td.mark { border-bottom: 1px solid #9ca3af; height: 11px; }

        /* Fuel 1-10 scale */
        .fuel-scale { border-collapse: collapse; margin-top: 2px; }
        .fuel-scale td {
            border: 1px solid #374151; width: 18px; height: 18px;
            text-align: center; font-size: 9px; font-weight: bold; color: #374151;
        }
        .fuel-scale td.header { font-weight: normal; color: #6b7280; background: #f3f4f6; border-color: #d1d5db; font-size: 8px; }

        /* Remarks lines */
        .remark-line { border-bottom: 1px solid #9ca3af; height: 12px; margin-top: 6px; }

        /* Damage diagram + written */
        .damage-grid { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 6px -10px 0 -10px; }
        .damage-grid > tbody > tr > td { border: none; padding: 0; vertical-align: top; }
        .damage-grid > tbody > tr > td.diagram { width: 45%; }
        .damage-grid > tbody > tr > td.written { width: 55%; }

        .diagram-box { border: 1px solid #d1d5db; padding: 6px 6px 4px 6px; }
        .diagram-box .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; text-align: left; }

        /* Diagram is rendered as a raster image (base64 PNG) because Dompdf's
           SVG renderer is unreliable with strokes/paths/rotated text. */
        .diagram-layout { width: 100%; border-collapse: collapse; }
        .diagram-layout td { border: none; padding: 0; vertical-align: middle; }
        .diagram-layout td.label-side { width: 14px; font-size: 7px; font-weight: bold; color: #6b7280; text-align: center; text-transform: uppercase; }
        .diagram-layout td.label-left { letter-spacing: 2px; }
        .diagram-layout td.label-right { letter-spacing: 2px; }
        .diagram-img { display: block; width: 100%; max-height: 190px; }
        .diagram-top-lbl, .diagram-bot-lbl { text-align: center; font-size: 7px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 3px; padding: 2px 0; }

        /* Fallback when the diagram asset is missing — a plain framed area so
           the driver can still sketch freehand. */
        .diagram-fallback { height: 180px; border: 1px dashed #9ca3af; }

        .written-box { border: 1px solid #d1d5db; padding: 6px 8px; min-height: 180px; }
        .written-box .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 2px; }

        /* Motorvia-style signature rows */
        .mi-sig { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 8px; }
        .mi-sig td { padding: 4px 6px; vertical-align: bottom; border: none; }
        .mi-sig td.lbl { font-size: 8px; color: #6b7280; font-weight: bold; text-transform: uppercase; width: 8%; padding-right: 4px; }
        .mi-sig td.role { font-size: 9px; color: #111827; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; width: 11%; padding-right: 4px; }
        .mi-sig td.line { border-bottom: 1px solid #9ca3af; height: 14px; }
        .mi-sig td.line.filled { font-size: 9px; color: #111827; padding-bottom: 1px; }

        /* ========================================================
           PAGES 3 & 5 — Proof of Delivery (Customer / Office copies)
           Page 4 is intentionally blank so the Customer Copy comes
           off the printer as a clean single-sided sheet when printed
           double-sided.
           ======================================================== */
        .pod-ref { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-bottom: 4px; }
        .pod-ref td { border: none; padding: 2px 4px; vertical-align: top; }
        .pod-ref td.lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; width: 14%; white-space: nowrap; }
        .pod-ref td.val { font-size: 10px; color: #111827; }

        .pod-values { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 6px -10px 0 -10px; }
        .pod-values > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .pod-box { border: 1px solid #d1d5db; padding: 8px 10px; }
        .pod-box .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }

        .pod-line-row { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .pod-line-row td { border: none; padding: 3px 0; vertical-align: bottom; }
        .pod-line-row td.name { color: #6b7280; font-size: 8.5px; width: 42%; padding-right: 6px; }
        .pod-line-row td.line { border-bottom: 1px solid #9ca3af; height: 13px; }

        /* POD condition checklist */
        .pod-cond { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 6px -12px 0 -12px; }
        .pod-cond > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .pod-cond-row { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .pod-cond-row td { border: none; padding: 3px 0; vertical-align: middle; }
        .pod-cond-row td.check { width: 14px; padding-right: 4px; }
        .pod-cond-row td.name { font-size: 9.5px; color: #1f2937; }

        .fb-box { border: 1px solid #d1d5db; display: inline-block; width: 10px; height: 10px; vertical-align: middle; }

        .pod-damage { border: 1px solid #d1d5db; padding: 8px 10px; margin-top: 8px; }
        .pod-damage .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }
        .pod-damage-line { border-bottom: 1px solid #d1d5db; height: 14px; margin-top: 6px; }

        /* POD signature row — driver left, customer right with larger stamp */
        .pod-sig-row { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 10px -12px 0 -12px; }
        .pod-sig-row > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .pod-sig-box { border: 1px solid #d1d5db; padding: 10px 12px; height: 170px; }
        .pod-sig-box .sig-title { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; margin-bottom: 2px; }
        .pod-sig-box .sig-sub { font-size: 9px; color: #6b7280; line-height: 1.3; margin-bottom: 6px; }

        .pod-customer-grid { width: 100%; border-collapse: collapse; }
        .pod-customer-grid td { border: none; padding: 0; vertical-align: top; }
        .pod-customer-grid td.lines { width: 55%; padding-right: 8px; }
        .pod-customer-grid td.stamp { width: 45%; padding-left: 8px; }

        .copy-badge { display: inline-block; padding: 2px 8px; border: 1px solid #1e40af; color: #1e40af; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; border-radius: 2px; }
    </style>
</head>
<body>

    {{-- =========================================================
         PAGE 1 — COLLECTION NOTE
         ========================================================= --}}

    {{-- ===== Masthead: carrier logo + document title ===== --}}
    <table class="masthead">
        <tr>
            <td style="width: 60%;">
                @if($carrierLogoUri)
                    <img src="{{ $carrierLogoUri }}" alt="{{ $carrierName }}" class="carrier-logo">
                @else
                    <div class="carrier-fallback">{{ $carrierName }}</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="doc-title">{{ $docTitle }}</div>
                <div class="doc-number">{{ $job->job_number }}</div>
            </td>
        </tr>
    </table>

    {{-- Platform brand band --}}
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
                    <tr><td class="label">Carrier</td><td class="value">{{ $carrierName }}</td></tr>
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

    {{-- ===== Collection / Delivery (two columns) ===== --}}
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

    {{-- ===== Special Instructions (only when set) ===== --}}
    @if(trim($job->customer_notes ?? '') !== '')
    <div class="instructions">
        <div class="hdr">Special Instructions</div>
        <div class="body">{{ $job->customer_notes }}</div>
    </div>
    @endif

    {{-- ===== Released By (full width — signature 40% / stamp 60%) ===== --}}
    <div class="released-full">
        <div class="sig-title">Released By</div>
        <div class="sig-sub">Releasing party at the pickup site (dealer / plant / yard) &mdash; authority to release the unit.</div>
        <table class="released-grid">
            <tr>
                <td class="lines">
                    <div class="sig-line"></div>
                    <div class="sig-label">Firm (releasing party)</div>
                    <div class="sig-line" style="margin-top: 12px;"></div>
                    <div class="sig-label">Agent print name</div>
                    <div class="sig-line" style="margin-top: 12px;"></div>
                    <div class="sig-label">Agent signature</div>
                    <div class="sig-line" style="margin-top: 12px;"></div>
                    <div class="sig-label">Date &amp; time</div>
                </td>
                <td class="stamp">
                    <div class="stamp-box-lg"></div>
                    <div class="stamp-label">Dispatcher's Stamp</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ===== Driver declaration + QR row ===== --}}
    <table class="declrow">
        <tr>
            <td>
                <div class="decl-box">
                    <div class="sig-title">Received By (Driver)</div>
                    <div class="sig-sub">I confirm this is the correct vehicle (VIN &amp; registration as shown above).</div>
                    <div class="inspect-confirm">
                        <div class="body">
                            Detailed condition photographs (front, rear, left, right) are captured in the
                            Trident driver app. If the app is unavailable, complete the
                            <strong>Manual Inspection Report</strong> on page&nbsp;2.
                        </div>
                    </div>
                    <div class="sig-line filled" style="margin-top: 10px;">{{ $driver?->name ?? '' }}</div>
                    <div class="sig-label">Driver print name</div>
                    <div class="sig-line" style="margin-top: 10px;"></div>
                    <div class="sig-label">Driver signature &amp; date</div>
                </div>
            </td>
            <td>
                <div class="qr-panel">
                    <div class="qr-heading">Scan to verify</div>
                    <div class="qr-sub">Opens the Trident verification page for this document.</div>
                    <img src="{{ $qrUrl }}" alt="Verify" class="qr-img">
                    <div class="qr-url">{{ $verificationUrl }}</div>
                </div>
            </td>
        </tr>
    </table>


    {{-- =========================================================
         PAGE 2 — MANUAL INSPECTION REPORT (Motorvia-style)
         ========================================================= --}}
    <div class="page-break"></div>

    <table class="masthead">
        <tr>
            <td style="width: 60%;">
                @if($carrierLogoUri)
                    <img src="{{ $carrierLogoUri }}" alt="{{ $carrierName }}" class="carrier-logo">
                @else
                    <div class="carrier-fallback">{{ $carrierName }}</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="doc-title">Manual Inspection</div>
                <div class="doc-number">{{ $job->job_number }}</div>
                <div class="doc-copy">Page 2 of 5</div>
            </td>
        </tr>
    </table>

    <div class="mi-banner">
        <div class="title">Manual Inspection Report</div>
        <div class="sub">Use this sheet <strong>only</strong> when the driver app is unavailable. It replaces the app's photographic evidence for this movement. Hand the completed sheet back with the signed collection note.</div>
    </div>

    <table class="mi-ref">
        <tr>
            <td class="lbl">Make / Model</td>
            <td class="val">{{ $job->brand?->name }} {{ $job->model_name ?? '' }}</td>
            <td class="lbl">VIN</td>
            <td class="val">{{ $job->vin ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Reg No.</td>
            <td class="val">{{ $job->registration ?? '—' }}</td>
            <td class="lbl">Job No.</td>
            <td class="val">{{ $job->job_number }}</td>
        </tr>
        <tr>
            <td class="lbl">Driver</td>
            <td class="val">{{ $driver?->name ?? '—' }}</td>
            <td class="lbl">Date</td>
            <td class="val">{{ ($job->scheduled_date ?? now())->format('d M Y') }}</td>
        </tr>
    </table>

    {{-- ---- Exterior Check A–D with legend ---- --}}
    <div class="mi-section">
        <div class="mi-section-title">Exterior Check</div>
        <div class="mi-legend">
            Legend &mdash; CHP: Chips &nbsp;·&nbsp; DNT: Dents &nbsp;·&nbsp; SCR: Scratches &nbsp;·&nbsp; CRK: Cracked &nbsp;·&nbsp; DMG: Damage.
            Note findings on the line next to each section.
        </div>
        <table class="mi-check-row">
            <tr><td class="letter">A.</td><td class="name">Body &amp; Paintwork (CHP / DNT / SCR / DMG)</td><td class="entry"><div class="entry-line"></div></td></tr>
            <tr><td class="letter">B.</td><td class="name">Windscreen &amp; Window Glass (CHP / CRK / SCR)</td><td class="entry"><div class="entry-line"></div></td></tr>
            <tr><td class="letter">C.</td><td class="name">Headlamp / Foglight / Indicator &amp; Taillight Lenses (CHP / CRK / SCR)</td><td class="entry"><div class="entry-line"></div></td></tr>
            <tr><td class="letter">D.</td><td class="name">Bumpers &amp; Body Moulding (CHP / CRK / SCR / DMG)</td><td class="entry"><div class="entry-line"></div></td></tr>
        </table>
    </div>

    {{-- ---- Remarks + Fuel + KM ---- --}}
    <div class="mi-section">
        <div class="mi-section-title">Remarks &middot; Fuel &middot; Mileage</div>
        <table style="width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 0 -12px;">
            <tr>
                <td style="width: 60%; padding: 0; vertical-align: top;">
                    <div style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold;">Remarks</div>
                    <div class="remark-line"></div>
                    <div class="remark-line"></div>
                    <div class="remark-line"></div>
                </td>
                <td style="width: 40%; padding: 0; vertical-align: top;">
                    <div style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 3px;">Fuel Level &mdash; circle one</div>
                    <table class="fuel-scale">
                        <tr>
                            @for($f = 1; $f <= 10; $f++)
                                <td>{{ $f }}</td>
                            @endfor
                        </tr>
                        <tr>
                            @for($f = 1; $f <= 10; $f++)
                                <td class="header" style="font-size: 7px;">{{ $f }}0%</td>
                            @endfor
                        </tr>
                    </table>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 9.5px;">
                        <tr>
                            <td style="border: none; padding: 2px 0; color: #6b7280; font-size: 8.5px; width: 30%;">Odometer (km)</td>
                            <td style="border: none; padding: 2px 0; border-bottom: 1px solid #9ca3af; height: 12px;"></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- ---- Items & Accessories checklist ---- --}}
    <div class="mi-section">
        <div class="mi-section-title">Items &amp; Accessories Check</div>
        <div class="mi-legend">
            Legend &mdash; <strong>–</strong>: Present / Fitted &nbsp;·&nbsp; <strong>X</strong>: Not Supplied / Fitted / Seen &nbsp;·&nbsp; <strong>M</strong>: Missing &nbsp;·&nbsp; <strong>DMG</strong>: Damaged &nbsp;·&nbsp; <strong>A</strong>: Additional. Write the mark on the line beside each item.
        </div>
        @php
            $accCol1 = [
                'Exterior Mirrors', 'Wheel Covers / Hub Caps', 'Radio Antenna',
                'Wipers (Windscreen / Lights)', 'Keys / No. of Keys',
                'Interior Mirror', 'Sun Visors', 'Head Rests',
                'Seat Belts', 'Cig. Lighter', "Owner's Manual",
            ];
            $accCol2 = [
                'Radio / Tape', 'Speakers', 'Carpets',
                'Interior Condition (C = Clean / S = Soiled)',
                'Spare Wheel', 'Jack', 'Wheel Spanner',
                'Tools (No. of items)', 'Warning Triangle',
                'Tow Bar / Tow Hook', 'Lock Nut',
            ];
        @endphp
        <table class="acc-list">
            <tr>
                <td>
                    <table class="acc-row">
                        @foreach($accCol1 as $item)
                            <tr><td class="name">{{ $item }}</td><td class="mark"></td></tr>
                        @endforeach
                    </table>
                </td>
                <td>
                    <table class="acc-row">
                        @foreach($accCol2 as $item)
                            <tr><td class="name">{{ $item }}</td><td class="mark"></td></tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- ---- Damage diagram + written description ---- --}}
    <div class="mi-section">
        <div class="mi-section-title">Damage &amp; Defects</div>
        <table class="damage-grid">
            <tr>
                <td class="diagram">
                    <div class="diagram-box">
                        <div class="lbl">Mark damage locations (X)</div>
                        <div class="diagram-top-lbl">Front</div>
                        <table class="diagram-layout">
                            <tr>
                                <td class="label-side label-left">L&nbsp;E&nbsp;F&nbsp;T</td>
                                <td>
                                    @if($inspectionDiagramUri)
                                        <img src="{{ $inspectionDiagramUri }}" alt="Vehicle outline" class="diagram-img">
                                    @else
                                        {{-- Asset missing — provide a blank framed area so the driver can sketch by hand. --}}
                                        <div class="diagram-fallback"></div>
                                    @endif
                                </td>
                                <td class="label-side label-right">R&nbsp;I&nbsp;G&nbsp;H&nbsp;T</td>
                            </tr>
                        </table>
                        <div class="diagram-bot-lbl">Rear</div>
                    </div>
                </td>
                <td class="written">
                    <div class="written-box">
                        <div class="lbl">Describe each marked point (location, type, size)</div>
                        @for($i = 0; $i < 8; $i++)
                            <div class="remark-line"></div>
                        @endfor
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ---- Signatures — Dispatch (releasing party at the pickup site) and Driver.
         Dispatch row is fully blank because we don't know who is releasing the unit
         until they sign; the driver row pre-populates Name / Firm / Date since the
         driver and carrier are known at print time and the date is the scheduled
         collection date. ---- --}}
    <div class="mi-section">
        <div class="mi-section-title">Signatures</div>
        @php($_sigDate = ($job->scheduled_date ?? now())->format('d M Y'))
        @php($_carrierName = $carrierName)
        <table class="mi-sig">
            <tr>
                <td class="role">Dispatch</td>
                <td class="lbl">Sign</td>
                <td class="line"></td>
                <td class="lbl">Name</td>
                <td class="line"></td>
                <td class="lbl">Firm</td>
                <td class="line" style="width: 16%;"></td>
                <td class="lbl">Date</td>
                <td class="line" style="width: 12%;"></td>
            </tr>
            <tr>
                <td class="role">Driver</td>
                <td class="lbl">Sign</td>
                <td class="line"></td>
                <td class="lbl">Name</td>
                <td class="line filled">{{ $driver?->name ?? '' }}</td>
                <td class="lbl">Firm</td>
                <td class="line filled" style="width: 16%;">{{ $_carrierName }}</td>
                <td class="lbl">Date</td>
                <td class="line filled" style="width: 12%;">{{ $_sigDate }}</td>
            </tr>
        </table>
    </div>


    {{-- =========================================================
         PAGE 3 — PROOF OF DELIVERY (Customer Copy)
         ========================================================= --}}
    <div class="page-break"></div>
    @include('documents.partials.pod-page', ['copyLabel' => 'Customer Copy', 'copyNum' => 3])


    {{-- =========================================================
         PAGE 4 — INTENTIONALLY BLANK
         Sits on the back of the Customer Copy so that when the
         document is printed double-sided the customer can walk
         away with a clean, single-sided copy and the Office Copy
         stays with dispatch on its own sheet.
         ========================================================= --}}
    <div class="page-break"></div>
    <div style="height: 100%; display: table; width: 100%; color: #cbd5e1; text-align: center; font-size: 9pt;">
        <div style="display: table-cell; vertical-align: middle;">
            This page intentionally left blank.
        </div>
    </div>


    {{-- =========================================================
         PAGE 5 — PROOF OF DELIVERY (Office Copy)
         ========================================================= --}}
    <div class="page-break"></div>
    @include('documents.partials.pod-page', ['copyLabel' => 'Office Copy', 'copyNum' => 5])


    {{-- Fixed footer — lives inside the @page bottom margin --}}
    <div class="page-footer">
        {{ $footerLine }}
    </div>

</body>
</html>
