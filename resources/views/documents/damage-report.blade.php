<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Damage Report — {{ $job->job_number ?? $job->uuid }}</title>
    <style>
        @page { margin: 10mm 10mm 10mm 10mm; }

        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8pt;
            color: #0f172a;
            margin: 0;
            line-height: 1.25;
        }

        /* -------- Masthead -------- */
        .masthead {
            border-bottom: 1.5px solid #b91c1c;
            padding-bottom: 2mm;
            margin-bottom: 3mm;
        }
        .masthead-row { display: table; width: 100%; }
        .masthead-cell { display: table-cell; vertical-align: middle; }
        .carrier-logo { max-height: 11mm; max-width: 42mm; }
        .doc-title { text-align: right; }
        .doc-title h1 {
            margin: 0;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #b91c1c;
            text-transform: uppercase;
            line-height: 1;
        }
        .doc-title .subtitle {
            margin-top: 0.5mm;
            font-size: 7pt;
            color: #64748b;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .doc-title .meta {
            margin-top: 1mm;
            font-size: 7pt;
            color: #334155;
        }
        .doc-title .meta strong { color: #0f172a; }

        /* -------- Severity banner -------- */
        .severity {
            padding: 1.5mm 2.5mm;
            border-radius: 3px;
            font-size: 7.5pt;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            margin-bottom: 3mm;
            line-height: 1.35;
        }
        .severity strong { font-weight: 700; }

        /* -------- Section cards -------- */
        .section {
            margin-bottom: 3mm;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        .section-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 1mm 2.5mm;
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
        }
        .section-body { padding: 2mm 2.5mm; }

        /* -------- Detail grid -------- */
        .grid { display: table; width: 100%; border-collapse: collapse; }
        .grid .col {
            display: table-cell;
            vertical-align: top;
            padding-right: 3mm;
        }
        .grid .col:last-child { padding-right: 0; }

        dl.kv { margin: 0; padding: 0; }
        dl.kv dt {
            font-size: 6pt;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.3mm;
        }
        dl.kv dd {
            margin: 0 0 1.5mm 0;
            font-size: 8pt;
            color: #0f172a;
            font-weight: 500;
        }
        dl.kv dd:last-child { margin-bottom: 0; }
        dl.kv dd.mono { font-family: 'DejaVu Sans Mono', monospace; }

        /* -------- Photos grid (2-up) -------- */
        .photos-wrap { display: table; width: 100%; border-collapse: separate; border-spacing: 2mm 2mm; }
        .photos-row { display: table-row; }
        .photo-card {
            display: table-cell;
            width: 50%;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            padding: 1.5mm;
            vertical-align: top;
            page-break-inside: avoid;
        }
        .photo-card .photo-header {
            display: table;
            width: 100%;
            margin-bottom: 1mm;
        }
        .photo-card .photo-header .num {
            display: table-cell;
            width: 8mm;
            font-weight: 700;
            font-size: 8pt;
            color: #b91c1c;
            vertical-align: top;
        }
        .photo-card .photo-header .meta {
            display: table-cell;
            vertical-align: top;
            font-size: 6.5pt;
            color: #64748b;
            line-height: 1.2;
        }
        .photo-card .photo-header .meta .time {
            color: #0f172a;
            font-weight: 600;
        }
        .photo-card img {
            display: block;
            width: 100%;
            max-height: 55mm;
            object-fit: contain;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
        }
        .photo-card .placeholder {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 2px;
            height: 30mm;
            text-align: center;
            padding-top: 12mm;
            color: #94a3b8;
            font-size: 7pt;
        }
        .photo-card .note {
            margin-top: 1.5mm;
            padding: 1.5mm 2mm;
            background: #fef9c3;
            border: 1px solid #fde68a;
            border-radius: 2px;
            font-size: 7pt;
            color: #713f12;
            white-space: pre-wrap;
            line-height: 1.3;
        }
        .photo-card .note strong {
            color: #422006;
            text-transform: uppercase;
            font-size: 6.5pt;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 0.3mm;
        }

        /* -------- Sign-off (single compact row) -------- */
        .signoff {
            margin-top: 3mm;
            border: 1px solid #e2e8f0;
            border-radius: 3px;
            page-break-inside: avoid;
        }
        .signoff .signoff-head {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 1mm 2.5mm;
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
        }
        .signoff .signoff-body { padding: 2mm 2.5mm; }
        .signoff p.ack {
            margin: 0 0 2mm 0;
            font-size: 6.5pt;
            color: #64748b;
            line-height: 1.35;
        }
        .signoff-row { display: table; width: 100%; }
        .signoff-cell {
            display: table-cell;
            vertical-align: bottom;
            width: 50%;
            padding-right: 3mm;
        }
        .signoff-cell:last-child { padding-right: 0; padding-left: 3mm; }
        .sig-box { height: 11mm; }
        .sig-line {
            border-top: 1px solid #0f172a;
            padding-top: 0.8mm;
            font-size: 6.5pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        /* -------- Footer -------- */
        .footer {
            margin-top: 2.5mm;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5mm;
            font-size: 6pt;
            color: #94a3b8;
            text-align: center;
            line-height: 1.35;
        }

        .muted { color: #64748b; }
        .small { font-size: 7pt; }
    </style>
</head>
<body>

{{-- =============== MASTHEAD =============== --}}
<div class="masthead">
    <div class="masthead-row">
        <div class="masthead-cell" style="width: 55%;">
            @if($carrierLogoUri)
                <img src="{{ $carrierLogoUri }}" alt="Carrier" class="carrier-logo">
            @else
                <div style="font-weight: 700; font-size: 10pt; color: #0f172a;">Proselver Technologies</div>
                <div style="font-size: 6.5pt; color: #64748b;">Movements executed for TRIDENT</div>
            @endif
        </div>
        <div class="masthead-cell doc-title" style="width: 45%;">
            <h1>Damage Report</h1>
            <div class="subtitle">Vehicle Incident Record</div>
            <div class="meta">
                <strong>Ref:</strong> {{ $job->job_number ?? $job->uuid }}
                &nbsp;·&nbsp;
                <strong>Generated:</strong> {{ $generatedAt->format('d M Y · H:i') }}
            </div>
        </div>
    </div>
</div>

{{-- =============== SEVERITY SUMMARY =============== --}}
@php $photoCount = $damagePhotos->count(); @endphp
<div class="severity">
    <strong>{{ $photoCount }}</strong> damage {{ $photoCount === 1 ? 'photograph has' : 'photographs have' }} been recorded against this movement.
    @if($photoCount > 0)
        First {{ $damagePhotos->first()['capturedAt']?->format('d M Y · H:i') ?? 'unknown' }};
        last {{ $damagePhotos->last()['capturedAt']?->format('d M Y · H:i') ?? 'unknown' }}.
    @endif
    Retain alongside original collection note and proof of delivery.
</div>

{{-- =============== VEHICLE + MOVEMENT (combined) =============== --}}
<div class="section">
    <div class="section-header">Vehicle &amp; Movement</div>
    <div class="section-body">
        <div class="grid">
            <div class="col" style="width: 25%;">
                <dl class="kv">
                    <dt>Registration</dt>
                    <dd class="mono">{{ strtoupper($job->registration ?? '—') }}</dd>

                    <dt>VIN</dt>
                    <dd class="mono">{{ strtoupper($job->vin ?? '—') }}</dd>

                    <dt>Brand / Model</dt>
                    <dd>{{ $job->brand?->name ?? '—' }} {{ $job->model_name ?? '' }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 25%;">
                <dl class="kv">
                    <dt>Booking Company</dt>
                    <dd>{{ $job->company?->name ?? '—' }}</dd>

                    <dt>Booked By</dt>
                    <dd>{{ $job->createdBy?->name ?? '—' }}</dd>

                    <dt>Status at Report</dt>
                    <dd>{{ ucfirst(str_replace('_', ' ', $job->status)) }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 25%;">
                <dl class="kv">
                    <dt>Collection From</dt>
                    <dd>
                        {{ $job->pickupLocation?->company_name ?? '—' }}
                        @if($job->pickupLocation?->city)
                            <br><span class="small muted">{{ $job->pickupLocation->city }}</span>
                        @endif
                    </dd>

                    <dt>Scheduled Date</dt>
                    <dd>{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</dd>
                </dl>
            </div>
            <div class="col" style="width: 25%;">
                <dl class="kv">
                    <dt>Delivery To</dt>
                    <dd>
                        {{ $job->deliveryLocation?->company_name ?? '—' }}
                        @if($job->deliveryLocation?->city)
                            <br><span class="small muted">{{ $job->deliveryLocation->city }}</span>
                        @endif
                    </dd>

                    <dt>Driver</dt>
                    <dd>
                        {{ $job->driver?->name ?? '—' }}
                        @if($job->driver?->driverProfile?->trade_plate)
                            <br><span class="small muted">T-plate: {{ $job->driver->driverProfile->trade_plate }}</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- =============== DAMAGE EVIDENCE =============== --}}
<div class="section">
    <div class="section-header">Damage Evidence ({{ $photoCount }})</div>
    <div class="section-body">
        @if($photoCount === 0)
            <p class="muted small">No damage photographs recorded.</p>
        @else
            {{-- 2-up grid. Chunk(2) pairs photos so Dompdf respects the
                 table-row boundary (it otherwise tries to float cells). --}}
            @php $photoNum = 0; @endphp
            <div class="photos-wrap">
                @foreach($damagePhotos->chunk(2) as $row)
                    <div class="photos-row">
                        @foreach($row as $item)
                            @php $photoNum++; @endphp
                            <div class="photo-card">
                                <div class="photo-header">
                                    <div class="num">#{{ $photoNum }}</div>
                                    <div class="meta">
                                        <span class="time">
                                            @if($item['capturedAt'])
                                                {{ $item['capturedAt']->format('d M Y · H:i') }}
                                            @else
                                                Time not recorded
                                            @endif
                                        </span>
                                        @if($item['uploader'])
                                            &middot; {{ $item['uploader'] }}
                                        @endif
                                        @if($item['lat'] !== null && $item['lng'] !== null)
                                            <br>GPS {{ number_format((float) $item['lat'], 4) }}, {{ number_format((float) $item['lng'], 4) }}
                                        @endif
                                    </div>
                                </div>

                                @if($item['isImage'] && $item['dataUri'])
                                    <img src="{{ $item['dataUri'] }}" alt="Damage {{ $photoNum }}">
                                @else
                                    <div class="placeholder">
                                        @if($item['dataUri'])
                                            (non-image — view digital copy)
                                        @else
                                            (photo unavailable in print — see online record)
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
                        {{-- Fill trailing cell for odd counts so the last row still lays out as 50/50. --}}
                        @if($row->count() === 1)
                            <div class="photo-card" style="border: 0; padding: 0; background: transparent;">&nbsp;</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- =============== SIGN-OFF (compact) =============== --}}
<div class="signoff">
    <div class="signoff-head">Acknowledgement</div>
    <div class="signoff-body">
        <p class="ack">
            The undersigned acknowledges receipt of this Damage Report and the
            photographic evidence attached. Signing does not constitute admission
            of liability; it records that the report has been received and that
            the damage shown was observed at the time and place indicated.
        </p>

        <div class="signoff-row">
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Customer signature &amp; date</div>
            </div>
            <div class="signoff-cell">
                <div class="sig-box"></div>
                <div class="sig-line">Carrier representative &amp; date</div>
            </div>
        </div>
    </div>
</div>

{{-- =============== FOOTER =============== --}}
<div class="footer">
    TRIDENT Control &amp; Dispatch Center · Damage Report {{ $job->job_number ?? $job->uuid }} · Generated {{ $generatedAt->format('d M Y H:i') }} · Confidential — intended recipients only.
</div>

</body>
</html>
