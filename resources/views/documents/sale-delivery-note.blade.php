@php
    /*
     * Sale delivery pack -- four-page PDF rendered straight off a
     * dealer_stock row for a vehicle sold off the floor with no
     * transport leg.  Same shape as the ProSelver / OEM pack used
     * for transport jobs but with the collection note removed --
     * a sale from the dealership floor doesn't need a collection
     * record because the dealer was already the custodian.
     *
     *   Page 1  Sale cover  -- vehicle / buyer / sale summary
     *   Page 2  Customer Copy  -- comprehensive POD: odometer,
     *                             fuel scale, condition checklist,
     *                             damage box, dual signatures.
     *   Page 3  Intentionally blank  -- mirrors the OEM pack so
     *                                   the Customer Copy comes off
     *                                   the printer as a clean
     *                                   single-sided sheet.
     *   Page 4  Dealer Copy  -- identical layout to page 2;
     *                           dealer keeps the signed dupe.
     *
     * $issuer is an App\Support\Documents\IssuerProfile carrying
     * the dealer's letterhead (logo + address + VAT + reg).
     */
    $fmt = fn ($v) => filled($v) ? $v : '—';
    $totalPages = 4;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Delivery Note - {{ $docNumber }}</title>
    <style>
        @page { margin: 28px 32px 56px 32px; }

        * { box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }

        /* ---------- Masthead (shared by every page) ---------- */
        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .masthead td { border: none; padding: 0; vertical-align: top; }
        .carrier-logo { height: 38px; }
        .carrier-fallback { font-size: 16px; font-weight: bold; color: #111827; letter-spacing: 0.3px; }
        .doc-title { font-size: 18px; font-weight: bold; color: #1e40af; text-transform: uppercase; text-align: right; line-height: 1; }
        .doc-number { font-size: 11px; color: #6b7280; text-align: right; margin-top: 3px; }
        .doc-copy { font-size: 9.5px; color: #6b7280; text-align: right; margin-top: 1px; text-transform: uppercase; letter-spacing: 0.2px; }
        .issuer-block { font-size: 8.5px; color: #6b7280; text-align: right; margin-top: 4px; line-height: 1.35; }
        .issuer-block .issuer-name { font-weight: bold; color: #374151; font-size: 9.5px; }

        .brand-band { background: #0f172a; color: #fff; padding: 6px 10px; border-radius: 4px; margin-bottom: 14px; }
        .brand-band .name { font-size: 12px; font-weight: bold; letter-spacing: 1px; }
        .brand-band .tagline { font-size: 9px; color: #cbd5e1; }

        .section-title { font-size: 10px; font-weight: bold; color: #1e40af; text-transform: uppercase; letter-spacing: 0.4px; margin: 14px 0 5px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }

        .grid { width: 100%; border-collapse: collapse; }
        .grid > tbody > tr > td { width: 50%; vertical-align: top; border: none; padding: 0 8px 0 0; }

        table.detail { width: 100%; border-collapse: collapse; }
        table.detail td { border: none; padding: 2px 0; vertical-align: top; }
        table.detail td.label { color: #6b7280; width: 38%; font-size: 9.5px; }
        table.detail td.value { color: #111827; font-weight: bold; }

        .sign-row { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .sign-row td { border: none; width: 50%; padding: 0 12px; vertical-align: bottom; }
        .sign-line { border-top: 1px solid #9ca3af; padding-top: 4px; font-size: 9px; color: #6b7280; text-align: center; }

        .footer { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 5px; }

        .page-break { page-break-before: always; }

        /* ---------- POD pages (2 + 4) ---------- */
        .pod-values { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 6px -10px 0 -10px; }
        .pod-values > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .pod-box { border: 1px solid #d1d5db; padding: 8px 10px; }
        .pod-box .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }

        .pod-line-row { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        .pod-line-row td { border: none; padding: 3px 0; vertical-align: bottom; }
        .pod-line-row td.name { color: #6b7280; font-size: 8.5px; width: 42%; padding-right: 6px; }
        .pod-line-row td.line { border-bottom: 1px solid #9ca3af; height: 13px; }

        .fuel-scale { border-collapse: collapse; margin-top: 2px; }
        .fuel-scale td {
            border: 1px solid #374151; width: 18px; height: 18px;
            text-align: center; font-size: 9px; font-weight: bold; color: #374151;
        }
        .fuel-scale td.header { font-weight: normal; color: #6b7280; background: #f3f4f6; border-color: #d1d5db; font-size: 8px; }

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

        .pod-sig-row { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 10px -12px 0 -12px; }
        .pod-sig-row > tbody > tr > td { border: none; padding: 0; vertical-align: top; width: 50%; }

        .pod-sig-box { border: 1px solid #d1d5db; padding: 10px 12px; height: 170px; }
        .pod-sig-box .sig-title { font-size: 10.5px; font-weight: bold; color: #1e40af; text-transform: uppercase; margin-bottom: 2px; }
        .pod-sig-box .sig-sub { font-size: 9px; color: #6b7280; line-height: 1.3; margin-bottom: 6px; }

        .pod-customer-grid { width: 100%; border-collapse: collapse; }
        .pod-customer-grid td { border: none; padding: 0; vertical-align: top; }
        .pod-customer-grid td.lines { width: 55%; padding-right: 8px; }
        .pod-customer-grid td.stamp { width: 45%; padding-left: 8px; }

        .sig-line { border-bottom: 1px solid #9ca3af; height: 16px; }
        .sig-line.filled { font-size: 10px; color: #111827; padding-bottom: 1px; line-height: 16px; border-bottom: 1px solid #9ca3af; }
        .sig-label { font-size: 8px; color: #6b7280; margin-top: 1px; }

        .stamp-box { border: 1px dashed #9ca3af; height: 74px; display: block; }
        .stamp-label { font-size: 8px; color: #6b7280; text-align: center; margin-top: 2px; }

        .copy-badge { display: inline-block; padding: 2px 8px; border: 1px solid #1e40af; color: #1e40af; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; border-radius: 2px; }
    </style>
</head>
<body>

    {{-- =========================================================
         PAGE 1 -- SALE COVER (summary the dealer keeps on file)
         ========================================================= --}}

    {{-- Masthead --}}
    <table class="masthead">
        <tr>
            <td style="width: 58%;">
                @if($issuer->logoUri)
                    <img src="{{ $issuer->logoUri }}" alt="{{ $issuer->name }}" class="carrier-logo">
                @else
                    <div class="carrier-fallback">{{ $issuer->name }}</div>
                @endif
            </td>
            <td style="width: 42%;">
                <div class="doc-title">{{ $issuer->docTitle }}</div>
                <div class="doc-number">{{ $docNumber }}</div>
                <div class="doc-copy"><span class="copy-badge">Sale Cover</span> &nbsp; Page 1 of {{ $totalPages }}</div>
                @if($issuer->hasLetterhead())
                    <div class="issuer-block">
                        <span class="issuer-name">{{ $issuer->name }}</span>
                        @if($issuer->address)<br>{!! nl2br(e($issuer->address)) !!}@endif
                        @if($issuer->phone || $issuer->email)
                            <br>@if($issuer->phone)Tel {{ $issuer->phone }}@endif@if($issuer->phone && $issuer->email) · @endif@if($issuer->email){{ $issuer->email }}@endif
                        @endif
                        @if($issuer->vatNumber || $issuer->registrationNumber)
                            <br>@if($issuer->vatNumber)VAT {{ $issuer->vatNumber }}@endif@if($issuer->vatNumber && $issuer->registrationNumber) · @endif@if($issuer->registrationNumber)Reg {{ $issuer->registrationNumber }}@endif
                        @endif
                    </div>
                @endif
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

    {{-- Vehicle + Sale (two columns) --}}
    <table class="grid">
        <tr>
            <td>
                <div class="section-title">Vehicle</div>
                <table class="detail">
                    <tr><td class="label">VIN</td><td class="value">{{ $fmt($stock->vin) }}</td></tr>
                    <tr><td class="label">Brand</td><td class="value">{{ $fmt($stock->brand?->name) }}</td></tr>
                    <tr><td class="label">Model</td><td class="value">{{ $fmt($stock->model_name) }}</td></tr>
                    <tr><td class="label">Suffix</td><td class="value">{{ $fmt($stock->suffix) }}</td></tr>
                    <tr><td class="label">Variant</td><td class="value">{{ $fmt($stock->variant) }}</td></tr>
                    <tr><td class="label">Description</td><td class="value">{{ $fmt($stock->description) }}</td></tr>
                    <tr><td class="label">Engine No.</td><td class="value">{{ $fmt($stock->engine_number) }}</td></tr>
                    <tr><td class="label">Colour</td><td class="value">{{ $fmt($stock->colour) }}</td></tr>
                    <tr><td class="label">Registration</td><td class="value">{{ $fmt($stock->registration) }}</td></tr>
                </table>
            </td>
            <td>
                <div class="section-title">Buyer</div>
                <table class="detail">
                    <tr><td class="label">Name</td><td class="value">{{ $fmt($stock->sale_customer_name) }}</td></tr>
                    <tr><td class="label">Phone</td><td class="value">{{ $fmt($stock->sale_customer_phone) }}</td></tr>
                    <tr><td class="label">Email</td><td class="value">{{ $fmt($stock->sale_customer_email) }}</td></tr>
                </table>

                <div class="section-title">Sale</div>
                <table class="detail">
                    <tr><td class="label">Salesperson</td><td class="value">{{ $fmt($stock->salesperson?->name) }}</td></tr>
                    <tr><td class="label">Sold on</td><td class="value">{{ $stock->sold_at?->format('d M Y H:i') ?? '—' }}</td></tr>
                    <tr><td class="label">Dealership</td><td class="value">{{ $fmt($stock->dealerCompany?->name) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- What's in this pack --}}
    <div style="margin-top: 18px; border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 12px; background: #f8fafc; font-size: 9.5px; color: #374151; line-height: 1.4;">
        <strong style="color: #1e40af; text-transform: uppercase; letter-spacing: 0.4px; font-size: 8.5px;">What's in this pack</strong><br>
        <strong>Page 1</strong> &mdash; this cover (vehicle + buyer + sale summary).
        <strong>Page 2</strong> &mdash; Customer Copy of the handover record: odometer, fuel level, condition checklist, damage / missing items, dual signatures.
        <strong>Page 3</strong> &mdash; blank backside so the Customer Copy prints clean when double-sided.
        <strong>Page 4</strong> &mdash; Dealer Copy of the same handover record for your file.
    </div>

    {{-- Cover-page signatures (light-touch -- the real signing happens on pages 2/4). --}}
    <table class="sign-row">
        <tr>
            <td><div class="sign-line">Dealer representative &mdash; name &amp; signature</div></td>
            <td><div class="sign-line">Customer &mdash; received in good order, name &amp; signature</div></td>
        </tr>
    </table>


    {{-- =========================================================
         PAGE 2 -- CUSTOMER COPY (comprehensive POD)
         ========================================================= --}}
    <div class="page-break"></div>
    @include('documents.partials.dealer-sale-pod-page', [
        'copyLabel' => 'Customer Copy',
        'copyNum'   => 2,
        'totalPages' => $totalPages,
    ])


    {{-- =========================================================
         PAGE 3 -- INTENTIONALLY BLANK
         Mirror of the OEM pack -- gives the Customer Copy a clean
         back when printed double-sided.
         ========================================================= --}}
    <div class="page-break"></div>
    <div style="height: 100%; display: table; width: 100%; color: #cbd5e1; text-align: center; font-size: 9pt;">
        <div style="display: table-cell; vertical-align: middle;">
            This page intentionally left blank.
        </div>
    </div>


    {{-- =========================================================
         PAGE 4 -- DEALER COPY (comprehensive POD, retained on file)
         ========================================================= --}}
    <div class="page-break"></div>
    @include('documents.partials.dealer-sale-pod-page', [
        'copyLabel' => 'Dealer Copy',
        'copyNum'   => 4,
        'totalPages' => $totalPages,
    ])


    <div class="footer">{{ $issuer->footer }}</div>
</body>
</html>
