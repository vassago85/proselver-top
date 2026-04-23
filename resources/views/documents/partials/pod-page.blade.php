{{--
    POD page — rendered twice (pages 3 & 4) as Customer Copy + Office Copy.
    Layout is deliberately similar to the collection note so staff can scan it
    quickly, but every value field is a blank line — this is the paper record
    captured at the destination, signed on delivery.
--}}

{{-- Masthead --}}
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
            <div class="doc-title">Proof of Delivery</div>
            <div class="doc-number">{{ $job->job_number }}</div>
            <div class="doc-copy"><span class="copy-badge">{{ $copyLabel }}</span> &nbsp; Page {{ $copyNum }} of 5</div>
        </td>
    </tr>
</table>

{{-- Brand band --}}
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

{{-- Movement + Vehicle reference (compact, same data as page 1) --}}
<table class="grid">
    <tr>
        <td>
            <div class="section-title">Movement Reference</div>
            <table class="detail">
                <tr><td class="label">Job No.</td><td class="value">{{ $job->job_number }}</td></tr>
                <tr><td class="label">Customer</td><td class="value">{{ $job->company?->name ?? '—' }}</td></tr>
                <tr><td class="label">Carrier</td><td class="value">Proselver Technologies</td></tr>
                <tr><td class="label">Driver</td><td class="value">{{ $driver?->name ?? '—' }}</td></tr>
            </table>
        </td>
        <td>
            <div class="section-title">Vehicle</div>
            <table class="detail">
                <tr><td class="label">Brand / Model</td><td class="value">{{ $job->brand?->name }} {{ $job->model_name ?? '' }}</td></tr>
                <tr><td class="label">VIN</td><td class="value">{{ $job->vin ?? '—' }}</td></tr>
                <tr><td class="label">Registration</td><td class="value">{{ $job->registration ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Delivery site (full width) --}}
@php
    $deliveryLoc = $job->deliveryLocation;
    $formatLoc = function ($loc) {
        if (!$loc) return '—';
        return implode("\n", array_filter([
            $loc->company_name,
            $loc->address,
            trim(implode(', ', array_filter([$loc->city, $loc->province]))),
        ]));
    };
@endphp
<div class="section-title">Delivered To</div>
<table class="detail">
    <tr>
        <td class="value" style="white-space: pre-line; padding: 4px 6px; width: 60%;">{{ $formatLoc($deliveryLoc) }}</td>
        <td style="padding: 0; vertical-align: top; width: 40%;">
            <table class="detail">
                <tr><td class="label">Contact</td><td class="value">{{ $job->delivery_contact_name ?? '—' }}</td></tr>
                <tr><td class="label">Phone</td><td class="value">{{ $job->delivery_contact_phone ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Delivery values — left: driver fills on arrival (odometer / fuel / date / time);
     right: pre-filled driver contact card so the receiving customer knows who is
     delivering without the driver having to rewrite any of it. --}}
<table class="pod-values">
    <tr>
        <td>
            <div class="pod-box">
                <div class="lbl">Delivery &mdash; captured by driver on arrival</div>
                <table class="pod-line-row">
                    <tr><td class="name">Odometer on delivery (km)</td><td class="line"></td></tr>
                </table>
                <div style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin: 6px 0 2px 0;">
                    Fuel Level &mdash; circle one (1=empty, 10=full)
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
                    <tr><td class="name">Arrival date</td><td class="line"></td></tr>
                    <tr><td class="name">Arrival time</td><td class="line"></td></tr>
                </table>
            </div>
        </td>
        <td>
            <div class="pod-box">
                <div class="lbl">Delivering Driver</div>
                <table class="detail" style="margin-top: 2px;">
                    <tr><td class="label">Name</td><td class="value">{{ $driver?->name ?? '—' }}</td></tr>
                    <tr><td class="label">Licence</td><td class="value">{{ $profile?->drivers_licence_number ?? '—' }}</td></tr>
                    <tr><td class="label">Cellphone</td><td class="value">{{ $profile?->cellphone ?? $driver?->phone ?? '—' }}</td></tr>
                    <tr><td class="label">Carrier</td><td class="value">Proselver Technologies</td></tr>
                </table>
                <div style="font-size: 8px; color: #6b7280; margin-top: 6px; line-height: 1.3;">
                    Tick each line on the Condition on Delivery list below. Anything
                    unacceptable or different from the collection sheet must be noted in
                    the Damage &amp; Missing Items box.
                </div>
            </div>
        </td>
    </tr>
</table>

{{-- Condition-on-delivery checklist (two columns) --}}
<div class="section-title" style="margin-top: 8px;">Condition on Delivery</div>
<div style="font-size: 8px; color: #6b7280; margin-bottom: 3px; font-style: italic;">
    Tick each item if it is acceptable on delivery. Note anything unacceptable in the Damage &amp; Missing Items box below.
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
            </table>
        </td>
        <td>
            <table class="pod-cond-row">
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Spare key handed over (if applicable)</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Spare wheel, jack &amp; tools present</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">Owner's manual / service book present</td></tr>
                <tr><td class="check"><span class="fb-box"></span></td><td class="name">No active dash warning lights</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Damage & missing items on delivery (scope: everything noted at delivery, both pre-existing and new) --}}
<div class="pod-damage">
    <div class="lbl">Damage &amp; Missing Items noted at Delivery &mdash; include any items different to the collection sheet</div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
    <div class="pod-damage-line"></div>
</div>

{{-- Signatures: driver (Delivered By) + customer (Received By, with stamp) --}}
<table class="pod-sig-row">
    <tr>
        <td>
            <div class="pod-sig-box">
                <div class="sig-title">Delivered By (Driver)</div>
                <div class="sig-sub">I confirm the vehicle was delivered to the site and contact above.</div>
                <div class="sig-line filled" style="margin-top: 14px;">{{ $driver?->name ?? '' }}</div>
                <div class="sig-label">Driver print name</div>
                <div class="sig-line" style="margin-top: 12px;"></div>
                <div class="sig-label">Driver signature</div>
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
                            <div class="sig-line" style="margin-top: 4px;"></div>
                            <div class="sig-label">Recipient print name</div>
                            <div class="sig-line" style="margin-top: 8px;"></div>
                            <div class="sig-label">Recipient signature</div>
                            <div class="sig-line" style="margin-top: 8px;"></div>
                            <div class="sig-label">Date &amp; time</div>
                        </td>
                        <td class="stamp">
                            <div class="stamp-box" style="height: 88px;"></div>
                            <div class="stamp-label">Company Stamp</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>
