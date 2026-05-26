<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Trip Report — {{ $job->job_number }}</title>
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: sans-serif; color: #111827; font-size: 10pt; line-height: 1.35; }
    h1, h2, h3 { margin: 0 0 4px; font-weight: 600; }
    h1 { font-size: 16pt; color: #064e3b; }
    h2 { font-size: 12pt; color: #1f2937; margin-top: 14px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
    h3 { font-size: 11pt; color: #374151; margin-top: 10px; }

    .header { width: 100%; display: table; margin-bottom: 12px; }
    .header .h-left { display: table-cell; vertical-align: middle; }
    .header .h-right { display: table-cell; vertical-align: middle; text-align: right; width: 40%; font-size: 9pt; color: #6b7280; }
    .header img { max-height: 36px; }

    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 2px 6px; font-size: 9.5pt; vertical-align: top; }
    table.kv td.k { width: 28%; color: #6b7280; }
    table.kv td.v { color: #111827; }

    table.tbl { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9pt; }
    table.tbl th, table.tbl td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
    table.tbl th { background: #f3f4f6; font-weight: 600; color: #374151; text-transform: uppercase; font-size: 8pt; letter-spacing: 0.04em; }
    table.tbl td.r, table.tbl th.r { text-align: right; font-variant-numeric: tabular-nums; }
    .total-row td { background: #ecfdf5; font-weight: 700; }
    .variance-over { color: #b91c1c; font-weight: 700; }
    .variance-under { color: #047857; font-weight: 700; }
    .variance-flat { color: #6b7280; }

    .slip { display: inline-block; vertical-align: top; width: 32%; margin: 0 0.5% 8px; border: 1px solid #d1d5db; border-radius: 4px; overflow: hidden; }
    .slip .img-box { background: #f3f4f6; height: 110px; display: flex; align-items: center; justify-content: center; }
    .slip img { max-width: 100%; max-height: 110px; }
    .slip .meta { padding: 4px 6px; font-size: 8.5pt; }
    .slip .meta .amount { font-weight: 700; }
    .slip .meta .badge { display: inline-block; padding: 1px 4px; border: 1px solid #d1d5db; border-radius: 3px; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.04em; }
    .badge-approved { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .badge-submitted { background: #fffbeb; color: #92400e; border-color: #fde68a; }
    .badge-rejected { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .badge-reimbursed { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }

    .muted { color: #6b7280; font-size: 9pt; }
    .pill { display: inline-block; padding: 1px 6px; border-radius: 999px; background: #ecfdf5; color: #065f46; font-size: 8pt; font-weight: 700; }
    .pill-muted { background: #f3f4f6; color: #6b7280; }

    .footer { margin-top: 16px; font-size: 8pt; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style>
</head>
<body>

<div class="header">
    <div class="h-left">
        @if($logoUri)
            <img src="{{ $logoUri }}" alt="">
        @endif
        <h1 style="display:inline-block; margin-left:8px; vertical-align:middle;">Trip Petty-Cash Report</h1>
    </div>
    <div class="h-right">
        Generated {{ now()->format('d M Y · H:i') }}<br>
        Order <strong>{{ $job->job_number }}</strong>
    </div>
</div>

<h2>Vehicle &amp; route</h2>
<table class="kv">
    <tr>
        <td class="k">VIN / chassis</td>
        <td class="v" style="font-family: monospace; font-size: 10pt;">{{ $job->vin ?: '—' }}</td>
        <td class="k">Make / model</td>
        <td class="v">{{ trim(($job->brand?->name ?: '') . ' ' . ($job->model_name ?: '')) ?: '—' }}</td>
    </tr>
    <tr>
        <td class="k">Customer</td>
        <td class="v">{{ $job->company?->name ?: '—' }}</td>
        <td class="k">Vehicle class</td>
        <td class="v">{{ $job->vehicleClass?->name ?: '—' }}</td>
    </tr>
    <tr>
        <td class="k">Pickup</td>
        <td class="v" colspan="3">{{ $job->pickupLocation?->company_name ?: '—' }} · {{ $job->pickupLocation?->city ?: '' }}</td>
    </tr>
    <tr>
        <td class="k">Delivery</td>
        <td class="v" colspan="3">{{ $job->deliveryLocation?->company_name ?: '—' }} · {{ $job->deliveryLocation?->city ?: '' }}</td>
    </tr>
    <tr>
        <td class="k">Scheduled date</td>
        <td class="v">{{ $job->scheduled_date?->format('D, d M Y') ?: '—' }}</td>
        <td class="k">Status</td>
        <td class="v">{{ \App\Models\Job::PHASE1_STATUS_LABELS[$job->status] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $job->status)) }}</td>
    </tr>
    <tr>
        <td class="k">Driver</td>
        <td class="v">{{ $job->driver?->name ?: '— unassigned' }}</td>
        <td class="k">Cellphone (bank send)</td>
        <td class="v" style="font-family: monospace;">{{ $driverPhone ?: '— missing' }}</td>
    </tr>
</table>

<h2>Driver advance</h2>
@if($advance['total'] > 0)
    <p class="muted">
        Issued {{ $advance['issued_at']?->format('d M Y · H:i') ?? '—' }}
        @if($advance['issued_by']) by <strong>{{ $advance['issued_by'] }}</strong> @endif.
        @if($advance['increase_reason'])
            <br>Increase reason: <em>{{ $advance['increase_reason'] }}</em>
        @endif
    </p>

    @if(!empty($advance['toll_breakdown']))
        <h3>Toll plazas</h3>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Plaza</th>
                    <th>Road</th>
                    <th>Type</th>
                    <th class="r">Fee (R)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($advance['toll_breakdown'] as $p)
                    <tr>
                        <td>{{ $p['plaza_name'] ?? '—' }}</td>
                        <td>{{ $p['road_name'] ?? '—' }}</td>
                        <td>{{ $p['plaza_type'] ?? '' }}</td>
                        <td class="r">{{ number_format((float) ($p['fee'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3" class="r">Toll subtotal</td>
                    <td class="r">{{ number_format((float) $advance['tolls'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <h3>Advance breakdown</h3>
    <table class="tbl">
        <thead>
            <tr>
                <th>Category</th>
                <th class="r">Issued (R)</th>
                <th class="r">Spent (approved, R)</th>
                <th class="r">Variance (R)</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['tolls' => 'Tolls', 'accommodation' => 'Accommodation', 'taxi' => 'Taxi (no slip needed)', 'food' => 'Food'] as $k => $lbl)
                @php
                    $a = (float) ($advance[$k] ?? 0);
                    $s = (float) ($spent[$k] ?? 0);
                    $v = round($s - $a, 2);
                @endphp
                <tr>
                    <td>{{ $lbl }}</td>
                    <td class="r">{{ number_format($a, 2) }}</td>
                    <td class="r">{{ number_format($s, 2) }}</td>
                    <td class="r {{ $v > 0.5 ? 'variance-over' : ($v < -0.5 ? 'variance-under' : 'variance-flat') }}">
                        {{ $v > 0 ? '+' : '' }}{{ number_format($v, 2) }}
                    </td>
                </tr>
            @endforeach
            @if(($spent['other'] ?? 0) > 0)
                <tr>
                    <td>Other (uncategorised)</td>
                    <td class="r">—</td>
                    <td class="r">{{ number_format((float) $spent['other'], 2) }}</td>
                    <td class="r variance-over">+{{ number_format((float) $spent['other'], 2) }}</td>
                </tr>
            @endif
            <tr class="total-row">
                <td>Total</td>
                <td class="r">{{ number_format((float) $advance['total'], 2) }}</td>
                <td class="r">{{ number_format((float) $spent['total'], 2) }}</td>
                <td class="r {{ $variance > 0.5 ? 'variance-over' : ($variance < -0.5 ? 'variance-under' : 'variance-flat') }}">
                    {{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}
                </td>
            </tr>
        </tbody>
    </table>
@else
    <p class="muted"><em>No advance was issued for this trip.</em> Slips below are unreconciled spend.</p>
@endif

<h2>Slips submitted ({{ $slipPayload->count() }})</h2>
@if($slipPayload->isEmpty())
    <p class="muted">No slips submitted yet.</p>
@else
    @foreach($slipPayload as $sp)
        @php
            $slip = $sp['entry'];
            $img = $sp['imageUri'];
            $badgeClass = 'badge-' . $slip->status;
        @endphp
        <div class="slip">
            <div class="img-box">
                @if($img)
                    <img src="{{ $img }}" alt="">
                @else
                    <span class="muted" style="font-size:8pt;">no image</span>
                @endif
            </div>
            <div class="meta">
                <div class="amount">R {{ number_format($slip->amount_cents / 100, 2) }}</div>
                <div>{{ $slip->categoryLabel() }}@if($slip->merchant_name) · {{ $slip->merchant_name }}@endif</div>
                <div class="muted">{{ $slip->spent_at?->format('d M Y') ?: $slip->created_at->format('d M Y') }}</div>
                <div><span class="badge {{ $badgeClass }}">{{ $slip->statusLabel() }}</span></div>
                @if($slip->status === \App\Models\PettyCashEntry::STATUS_REJECTED && $slip->rejection_reason)
                    <div class="muted" style="font-size:7.5pt;">{{ $slip->rejection_reason }}</div>
                @endif
            </div>
        </div>
    @endforeach
@endif

<div class="footer">
    Confidential. TRIDENT Control &amp; Dispatch Center · Driver-advance reconciliation document.
    Generated for internal accounting / owner review only.
</div>

</body>
</html>
