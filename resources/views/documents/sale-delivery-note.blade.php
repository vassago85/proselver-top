@php
    /*
     * Sale delivery note (Phase 1B) — single page, printed straight
     * off a dealer_stock row for a vehicle sold off the floor with no
     * transport leg. $issuer is an App\Support\Documents\IssuerProfile.
     */
    $fmt = fn ($v) => filled($v) ? $v : '—';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Delivery Note - {{ $docNumber }}</title>
    <style>
        @page { margin: 32px 36px 56px 36px; }

        * { box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }

        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .masthead td { border: none; padding: 0; vertical-align: top; }
        .carrier-logo { height: 38px; }
        .carrier-fallback { font-size: 16px; font-weight: bold; color: #111827; letter-spacing: 0.3px; }
        .doc-title { font-size: 18px; font-weight: bold; color: #1e40af; text-transform: uppercase; text-align: right; line-height: 1; }
        .doc-number { font-size: 11px; color: #6b7280; text-align: right; margin-top: 3px; }
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
    </style>
</head>
<body>
    {{-- ===== Masthead ===== --}}
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

    {{-- ===== Vehicle + Sale (two columns) ===== --}}
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

    {{-- ===== Signatures ===== --}}
    <table class="sign-row">
        <tr>
            <td><div class="sign-line">Dealer representative — name &amp; signature</div></td>
            <td><div class="sign-line">Customer — received in good order, name &amp; signature</div></td>
        </tr>
    </table>

    <div class="footer">{{ $issuer->footer }}</div>
</body>
</html>
