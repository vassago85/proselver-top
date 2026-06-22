{{--
    Dealer sale POD page -- rendered twice (Customer Copy + Dealer Copy)
    inside sale-delivery-note.blade.php for vehicles sold off the floor
    with no transport leg.  Mirrors the ProSelver/OEM POD page (the
    "comprehensive delivery note" with odometer / fuel / condition
    checklist / damage box / dual signatures) but parameterised for a
    DealerStock + sale data instead of a Job + driver.

    Expected variables (set by the parent template):
      - $stock      DealerStock model
      - $issuer     IssuerProfile DTO (dealer letterhead)
      - $docNumber  String  e.g. "SDN-000042"
      - $copyLabel  String  "Customer Copy" | "Dealer Copy"
      - $copyNum    Integer page number for the "Page X of 4" line
      - $totalPages Integer total pages (currently 4)
--}}

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
            <div class="doc-copy"><span class="copy-badge">{{ $copyLabel }}</span> &nbsp; Page {{ $copyNum }} of {{ $totalPages }}</div>
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

{{-- Sale + Vehicle reference (compact, mirrors the cover page) --}}
<table class="grid">
    <tr>
        <td>
            <div class="section-title">Sale Reference</div>
            <table class="detail">
                <tr><td class="label">Document</td><td class="value">{{ $docNumber }}</td></tr>
                <tr><td class="label">Dealership</td><td class="value">{{ $stock->dealerCompany?->name ?? '—' }}</td></tr>
                <tr><td class="label">Salesperson</td><td class="value">{{ $stock->salesperson?->name ?? '—' }}</td></tr>
                <tr><td class="label">Sold on</td><td class="value">{{ $stock->sold_at?->format('d M Y H:i') ?? '—' }}</td></tr>
            </table>
        </td>
        <td>
            <div class="section-title">Vehicle</div>
            <table class="detail">
                <tr><td class="label">Brand / Model</td><td class="value">{{ $stock->brand?->name }} {{ $stock->model_name ?? '' }}</td></tr>
                <tr><td class="label">VIN</td><td class="value">{{ $stock->vin ?? '—' }}</td></tr>
                <tr><td class="label">Engine No.</td><td class="value">{{ $stock->engine_number ?? '—' }}</td></tr>
                <tr><td class="label">Registration</td><td class="value">{{ $stock->registration ?? '—' }}</td></tr>
                <tr><td class="label">Colour</td><td class="value">{{ $stock->colour ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Buyer block (full width) --}}
<div class="section-title">Delivered To (Buyer)</div>
<table class="detail">
    <tr>
        <td style="padding: 0; vertical-align: top; width: 60%;">
            <table class="detail">
                <tr><td class="label">Name</td><td class="value">{{ $stock->sale_customer_name ?? '—' }}</td></tr>
                <tr><td class="label">Phone</td><td class="value">{{ $stock->sale_customer_phone ?? '—' }}</td></tr>
                <tr><td class="label">Email</td><td class="value">{{ $stock->sale_customer_email ?? '—' }}</td></tr>
            </table>
        </td>
        <td style="padding: 0; vertical-align: top; width: 40%;">
            <table class="detail">
                <tr><td class="label">Releasing rep</td><td class="value">{{ $stock->salesperson?->name ?? '—' }}</td></tr>
                <tr><td class="label">Dealership</td><td class="value">{{ $stock->dealerCompany?->name ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Handover values: odometer / fuel / date / time captured by sales rep on the day. --}}
<table class="pod-values">
    <tr>
        <td>
            <div class="pod-box">
                <div class="lbl">Vehicle handover &mdash; captured by sales rep at release</div>
                <table class="pod-line-row">
                    <tr><td class="name">Odometer at handover (km)</td><td class="line"></td></tr>
                </table>
                <div style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin: 6px 0 2px 0;">
                    Fuel level &mdash; circle one (1=empty, 10=full)
                </div>
                <table class="fuel-scale" style="margin-bottom: 4px;">
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
                <table class="pod-line-row" style="margin-top: 4px;">
                    <tr><td class="name">Handover date</td><td class="line"></td></tr>
                    <tr><td class="name">Handover time</td><td class="line"></td></tr>
                </table>
            </div>
        </td>
        <td>
            <div class="pod-box">
                <div class="lbl">Releasing sales rep</div>
                <table class="detail" style="margin-top: 2px;">
                    <tr><td class="label">Name</td><td class="value">{{ $stock->salesperson?->name ?? '—' }}</td></tr>
                    <tr><td class="label">Dealership</td><td class="value">{{ $stock->dealerCompany?->name ?? '—' }}</td></tr>
                    @if($stock->dealerCompany?->phone)
                        <tr><td class="label">Tel</td><td class="value">{{ $stock->dealerCompany->phone }}</td></tr>
                    @endif
                </table>
                <div style="font-size: 8px; color: #6b7280; margin-top: 6px; line-height: 1.3;">
                    Tick each line on the Condition at Handover list below. Anything
                    that is missing or not acceptable must be noted in the
                    <strong>Damage &amp; Missing Items</strong> box.
                </div>
            </div>
        </td>
    </tr>
</table>

{{-- Condition-at-handover checklist (two columns). Re-uses the same
     items as the ProSelver POD page so the dealer's customer signs off
     against an identical, recognised list. --}}
<div class="section-title" style="margin-top: 8px;">Condition at Handover</div>
<div style="font-size: 8px; color: #6b7280; margin-bottom: 3px; font-style: italic;">
    Tick each item if it is acceptable at handover. Note anything unacceptable in the Damage &amp; Missing Items box below.
</div>
<table class="pod-cond">
    <tr>
        <td>
            <table class="pod-cond-row">
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Body panels &mdash; no new damage</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Windscreen &amp; windows &mdash; intact</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Lights &amp; indicators &mdash; operational</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Interior clean &amp; intact</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Primary ignition key handed over</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Spare key handed over (if applicable)</td></tr>
            </table>
        </td>
        <td>
            <table class="pod-cond-row">
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Spare wheel, jack &amp; tools present</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Owner's manual / service book present</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">No active dash warning lights</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Tyres &mdash; legal tread, no visible damage</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Fuel cap / charge port intact</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Number plates fitted &amp; secure</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Damage & missing items at handover. --}}
<div class="pod-damage">
    <div class="lbl">Damage &amp; Missing Items noted at Handover</div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
</div>

{{-- Signatures: dealer rep (Released By) + customer (Received By, with stamp area). --}}
<table class="pod-sig-row">
    <tr>
        <td>
            <div class="pod-sig-box">
                <div class="sig-title">Released By (Dealer)</div>
                <div class="sig-sub">I confirm the vehicle was released to the buyer in the condition noted above.</div>
                <div class="sig-line filled" style="margin-top: 14px;">{{ $stock->salesperson?->name ?? '' }}</div>
                <div class="sig-label">Sales rep print name</div>
                <div class="sig-line" style="margin-top: 12px;"></div>
                <div class="sig-label">Sales rep signature</div>
                <div class="sig-line" style="margin-top: 12px;"></div>
                <div class="sig-label">Date &amp; time</div>
            </div>
        </td>
        <td>
            <div class="pod-sig-box">
                <div class="sig-title">Received By (Customer)</div>
                <div class="sig-sub">I acknowledge receipt of the vehicle in the condition noted above.</div>
                <table class="pod-customer-grid" style="margin-top: 4px;">
                    <tr>
                        <td class="lines">
                            <div class="sig-line filled" style="margin-top: 4px;">{{ $stock->sale_customer_name ?? '' }}</div>
                            <div class="sig-label">Recipient print name</div>
                            <div class="sig-line" style="margin-top: 8px;"></div>
                            <div class="sig-label">Recipient signature</div>
                            <div class="sig-line" style="margin-top: 8px;"></div>
                            <div class="sig-label">Date &amp; time</div>
                        </td>
                        <td class="stamp">
                            <div class="stamp-box" style="height: 88px;"></div>
                            <div class="stamp-label">ID / Driver's licence stamp</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>
