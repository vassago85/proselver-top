<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Damage Report — {{ $job->job_number ?? $job->uuid }}</title>
    <style>
        @page { margin: 18mm 14mm 18mm 14mm; }

        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #0f172a;
            margin: 0;
        }

        /* -------- Header / masthead -------- */
        .masthead {
            border-bottom: 2px solid #b91c1c;
            padding-bottom: 8mm;
            margin-bottom: 6mm;
        }
        .masthead-row {
            display: table;
            width: 100%;
        }
        .masthead-cell {
            display: table-cell;
            vertical-align: middle;
        }
        .carrier-logo {
            max-height: 18mm;
            max-width: 55mm;
        }
        .doc-title {
            text-align: right;
        }
        .doc-title h1 {
            margin: 0;
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #b91c1c;
            text-transform: uppercase;
        }
        .doc-title .subtitle {
            margin-top: 1mm;
            font-size: 9pt;
            color: #64748b;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .doc-title .meta {
            margin-top: 2mm;
            font-size: 8.5pt;
            color: #334155;
        }
        .doc-title .meta strong { color: #0f172a; }

        /* -------- Section cards -------- */
        .section {
            margin-bottom: 5mm;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        .section-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 2mm 4mm;
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
        }
        .section-body {
            padding: 3mm 4mm;
        }

        /* -------- Detail grid -------- */
        .grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
        }
        .grid .col {
            display: table-cell;
            vertical-align: top;
            padding-right: 4mm;
        }
        .grid .col:last-child { padding-right: 0; }

        dl.kv {
            margin: 0;
            padding: 0;
        }
        dl.kv dt {
            font-size: 7.5pt;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5mm;
        }
        dl.kv dd {
            margin: 0 0 2.5mm 0;
            font-size: 10pt;
            color: #0f172a;
            font-weight: 500;
        }
        dl.kv dd.mono { font-family: 'DejaVu Sans Mono', monospace; }

        /* -------- Severity banner -------- */
        .severity {
            padding: 3mm 4mm;
            border-radius: 4px;
            font-size: 9pt;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            margin-bottom: 5mm;
        }
        .severity strong { font-weight: 700; }

        /* -------- Photos grid -------- */
        .photo-card {
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 3mm;
            margin-bottom: 4mm;
            page-break-inside: avoid;
        }
        .photo-card .photo-header {
            display: table;
            width: 100%;
            margin-bottom: 2mm;
        }
        .photo-card .photo-header .num {
            display: table-cell;
            width: 12mm;
            font-weight: 700;
            font-size: 11pt;
            color: #b91c1c;
            vertical-align: top;
        }
        .photo-card .photo-header .meta {
            display: table-cell;
            vertical-align: top;
            font-size: 8.5pt;
            color: #64748b;
        }
        .photo-card .photo-header .meta .time {
            color: #0f172a;
            font-weight: 600;
        }
        .photo-card img {
            display: block;
            width: 100%;
            max-height: 110mm;
            object-fit: contain;
            border-radius: 3px;
            border: 1px solid #e2e8f0;
        }
        .photo-card .placeholder {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 3px;
            height: 60mm;
            text-align: center;
            padding-top: 25mm;
            color: #94a3b8;
            font-size: 9pt;
        }
        .photo-card .note {
            margin-top: 2.5mm;
            padding: 2.5mm 3mm;
            background: #fef9c3;
            border: 1px solid #fde68a;
            border-radius: 3px;
            font-size: 9pt;
            color: #713f12;
            white-space: pre-wrap;
        }
        .photo-card .note strong {
            color: #422006;
            text-transform: uppercase;
            font-size: 8pt;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 0.5mm;
        }

        /* -------- Sign-off block -------- */
        .signoff {
            margin-top: 8mm;
            page-break-inside: avoid;
        }
        .signoff-row {
            display: table;
            width: 100%;
            margin-bottom: 4mm;
        }
        .signoff-cell {
            display: table-cell;
            vertical-align: top;
            width: 50%;
            padding-right: 3mm;
        }
        .signoff-cell:last-child { padding-right: 0; padding-left: 3mm; }
        .sig-line {
            border-top: 1px solid #0f172a;
            padding-top: 1.5mm;
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .sig-box {
            height: 28mm;
        }

        /* -------- Footer -------- */
        .footer {
            margin-top: 6mm;
            border-top: 1px solid #e2e8f0;
            padding-top: 3mm;
            font-size: 7.5pt;
            color: #94a3b8;
            text-align: center;
            line-height: 1.5;
        }

        /* Utility */
        .muted { color: #64748b; }
        .small { font-size: 8.5pt; }
    </style>
</head>
<body>

{{-- ================================================================= --}}
{{-- MASTHEAD                                                           --}}
{{-- ================================================================= --}}
<div class="masthead">
    <div class="masthead-row">
        <div class="masthead-cell" style="width: 50%;">
            @if($carrierLogoUri)
                <img src="{{ $carrierLogoUri }}" alt="Carrier" class="carrier-logo">
            @else
                <div style="font-weight: 700; font-size: 14pt; color: #0f172a;">Proselver Technologies</div>
                <div style="font-size: 8pt; color: #64748b;">Movements executed for TRIDENT</div>
            @endif
        </div>
        <div class="masthead-cell doc-title" style="width: 50%;">
            <h1>Damage Report</h1>
            <div class="subtitle">Vehicle Incident Record</div>
            <div class="meta">
                <strong>Ref:</strong> {{ $job->job_number ?? $job->uuid }}<br>
                <strong>Generated:</strong> {{ $generatedAt->format('d M Y · H:i') }}
            </div>
        </div>
    </div>
</div>

{{-- ================================================================= --}}
{{-- SEVERITY SUMMARY                                                   --}}
{{-- ================================================================= --}}
@php $photoCount = $damagePhotos->count(); @endphp
<div class="severity">
    <strong>{{ $photoCount }}</strong> damage {{ $photoCount === 1 ? 'photograph has' : 'photographs have' }} been recorded against this movement.
    @if($photoCount > 0)
        First captured {{ $damagePhotos->first()['capturedAt']?->format('d M Y · H:i') ?? 'date unknown' }};
        last captured {{ $damagePhotos->last()['capturedAt']?->format('d M Y · H:i') ?? 'date unknown' }}.
    @endif
    This report is for the customer's records and should be retained alongside the original collection note and proof of delivery.
</div>

{{-- ================================================================= --}}
{{-- VEHICLE DETAILS                                                    --}}
{{-- ================================================================= --}}
<div class="section">
    <div class="section-header">Vehicle Details</div>
    <div class="section-body">
        <div class="grid">
            <div class="col" style="width: 33.3%;">
                <dl class="kv">
                    <dt>Registration</dt>
                    <dd class="mono">{{ strtoupper($job->registration ?? '—') }}</dd>

                    <dt>VIN</dt>
                    <dd class="mono">{{ strtoupper($job->vin ?? '—') }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 33.3%;">
                <dl class="kv">
                    <dt>Brand</dt>
                    <dd>{{ $job->brand?->name ?? '—' }}</dd>

                    <dt>Model</dt>
                    <dd>{{ $job->model_name ?? '—' }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 33.3%;">
                <dl class="kv">
                    <dt>Vehicle Class</dt>
                    <dd>{{ $job->vehicle_class_id ? ($job->vehicleClass?->name ?? '—') : '—' }}</dd>

                    <dt>Booking Company</dt>
                    <dd>{{ $job->company?->name ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================= --}}
{{-- MOVEMENT DETAILS                                                   --}}
{{-- ================================================================= --}}
<div class="section">
    <div class="section-header">Movement Details</div>
    <div class="section-body">
        <div class="grid">
            <div class="col" style="width: 50%;">
                <dl class="kv">
                    <dt>Collection From</dt>
                    <dd>
                        {{ $job->pickupLocation?->company_name ?? '—' }}<br>
                        <span class="small muted">{{ $job->pickupLocation?->address ?? '' }}</span>
                    </dd>

                    <dt>Scheduled Date</dt>
                    <dd>{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd>

                    <dt>Booked By</dt>
                    <dd>{{ $job->createdBy?->name ?? '—' }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 50%;">
                <dl class="kv">
                    <dt>Delivery To</dt>
                    <dd>
                        {{ $job->deliveryLocation?->company_name ?? '—' }}<br>
                        <span class="small muted">{{ $job->deliveryLocation?->address ?? '' }}</span>
                    </dd>

                    <dt>Assigned Driver</dt>
                    <dd>
                        {{ $job->driver?->name ?? '—' }}
                        @if($job->driver?->driverProfile?->trade_plate)
                            <br><span class="small muted">T-plate: {{ $job->driver->driverProfile->trade_plate }}</span>
                        @endif
                    </dd>

                    <dt>Status at Report</dt>
                    <dd>{{ ucfirst(str_replace('_', ' ', $job->status)) }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================= --}}
{{-- DAMAGE EVIDENCE                                                    --}}
{{-- ================================================================= --}}
<div class="section">
    <div class="section-header">Damage Evidence ({{ $photoCount }})</div>
    <div class="section-body">
        @if($photoCount === 0)
            <p class="muted small">No damage photographs recorded.</p>
        @else
            @foreach($damagePhotos as $index => $item)
                <div class="photo-card">
                    <div class="photo-header">
                        <div class="num">#{{ $index + 1 }}</div>
                        <div class="meta">
                            <span class="time">
                                @if($item['capturedAt'])
                                    {{ $item['capturedAt']->format('d M Y · H:i') }}
                                @else
                                    Time not recorded
                                @endif
                            </span>
                            @if($item['uploader'])
                                &middot; Captured by {{ $item['uploader'] }}
                            @endif
                            @if($item['lat'] !== null && $item['lng'] !== null)
                                <br>GPS: {{ number_format((float) $item['lat'], 5) }}, {{ number_format((float) $item['lng'], 5) }}
                            @endif
                        </div>
                    </div>

                    @if($item['isImage'] && $item['dataUri'])
                        <img src="{{ $item['dataUri'] }}" alt="Damage photo {{ $index + 1 }}">
                    @else
                        <div class="placeholder">
                            @if($item['dataUri'])
                                (non-image attachment — view digital copy for full document)
                            @else
                                (photo could not be rendered — original is available in the online record)
                            @endif
                        </div>
                    @endif

                    @if($item['noteText'])
                        <div class="note">
                            <strong>Driver note</strong>
                            {{ $item['noteText'] }}
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>

{{-- ================================================================= --}}
{{-- SIGN-OFF                                                           --}}
{{-- ================================================================= --}}
<div class="signoff">
    <div class="section-header" style="border: 1px solid #e2e8f0; border-bottom: 0; border-radius: 4px 4px 0 0;">Acknowledgement</div>
    <div style="border: 1px solid #e2e8f0; border-top: 0; border-radius: 0 0 4px 4px; padding: 4mm;">
        <p class="small muted" style="margin: 0 0 4mm 0;">
            The undersigned acknowledges receipt of this Damage Report and the
            photographic evidence attached. Signing does not constitute admission of
            liability; it records that the report has been received and that the
            damage shown was observed at the time and place indicated.
        </p>

        <div class="signoff-row">
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Customer signature</div>
            </div>
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Print name &amp; date</div>
            </div>
        </div>

        <div class="signoff-row">
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Carrier representative</div>
            </div>
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Print name &amp; date</div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================= --}}
{{-- FOOTER                                                             --}}
{{-- ================================================================= --}}
<div class="footer">
    TRIDENT Control &amp; Dispatch Center · Damage Report {{ $job->job_number ?? $job->uuid }}<br>
    Generated {{ $generatedAt->format('d M Y H:i') }} · This document is confidential and intended for the named parties only.
</div>

</body>
</html>
