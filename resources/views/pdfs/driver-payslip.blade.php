<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payslip — {{ $driver->name }} — {{ $anchor->format('F Y') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #0f172a; margin: 30px; }
        h1 { font-size: 20px; font-weight: bold; color: #1e40af; margin: 0; }
        h2 { font-size: 13px; font-weight: bold; color: #0f172a; margin: 22px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
        .muted { color: #64748b; }
        .small { font-size: 10px; }
        .right { text-align: right; }
        .center { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; vertical-align: top; }
        th { background: #f1f5f9; text-transform: uppercase; letter-spacing: 0.03em; font-size: 9px; color: #475569; border-bottom: 1px solid #cbd5e1; }
        tr.row { border-bottom: 1px solid #e2e8f0; }
        .tabular { font-variant-numeric: tabular-nums; }
        .totals-strip { margin-top: 10px; }
        .totals-strip td { padding: 10px 12px; border: 1px solid #e2e8f0; background: #f8fafc; vertical-align: top; }
        .pill { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 9px; font-weight: 600; border: 1px solid; }
        .pill-amber { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .pill-green { background: #ecfdf5; color: #065f46; border-color: #86efac; }
        .pill-red   { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .pill-slate { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .pill-blue  { background: #eff6ff; color: #1e3a8a; border-color: #bfdbfe; }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <table style="margin-bottom: 16px;">
        <tr>
            <td style="padding: 0; width: 60%;">
                <h1>TRIDENT</h1>
                <div class="muted small">Control &amp; Dispatch Center</div>
            </td>
            <td style="padding: 0; text-align: right;">
                <div style="font-size: 15px; font-weight: bold;">Driver Payslip</div>
                <div class="muted small">{{ $anchor->format('F Y') }}</div>
                <div class="muted small">{{ $from->format('d M') }} &ndash; {{ $to->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <table style="margin-bottom: 10px;">
        <tr>
            <td style="padding: 0; width: 60%;">
                <div><strong>{{ $driver->name }}</strong></div>
                @if($driver->driverProfile?->cellphone)
                    <div class="muted small">Cell: {{ $driver->driverProfile->cellphone }}</div>
                @endif
                @if($driver->driverProfile?->id_number)
                    <div class="muted small">{{ $driver->driverProfile->idDocumentLabel() }}: {{ $driver->driverProfile->id_number }}</div>
                @endif
                @if($driver->driverProfile?->base_location)
                    <div class="muted small">Base: {{ $driver->driverProfile->base_location }}</div>
                @endif
            </td>
            <td style="padding: 0; text-align: right; vertical-align: top;">
                <div class="muted small">Default rate per movement</div>
                <div class="tabular" style="font-weight: 600;">
                    @if($profileRate !== null)
                        R {{ number_format($profileRate, 2) }}
                    @else
                        <span class="pill pill-amber">Not set</span>
                    @endif
                </div>
                <div class="muted small" style="margin-top: 8px;">Generated {{ $generatedAt->format('d M Y H:i') }}</div>
            </td>
        </tr>
    </table>

    {{-- Totals strip --}}
    <table class="totals-strip">
        <tr>
            <td style="width: 16.66%;">
                <div class="muted small">Movements</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold;">{{ $lines->count() }}</div>
            </td>
            <td style="width: 16.66%;">
                <div class="muted small">Gross earnings</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold; color: #065f46;">R {{ number_format($grossEarnings, 2) }}</div>
            </td>
            <td style="width: 16.66%;">
                <div class="muted small">Bus deductions</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold; color: #991b1b;">R {{ number_format($busDeductions, 2) }}</div>
            </td>
            <td style="width: 16.66%;">
                <div class="muted small">Net pay</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold; color: #1e3a8a;">R {{ number_format($netPay, 2) }}</div>
            </td>
            <td style="width: 16.66%;">
                <div class="muted small">Petty cash advanced</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold; color: #92400e;">R {{ number_format($advancesIssued, 2) }}</div>
                <div class="muted" style="font-size: 8px;">issued per trip</div>
            </td>
            <td style="width: 16.66%;">
                <div class="muted small">Slips submitted</div>
                <div class="tabular" style="font-size: 14px; font-weight: bold; color: #334155;">R {{ number_format($slipsSubmitted, 2) }}</div>
                <div class="muted" style="font-size: 8px;">reconciliation</div>
            </td>
        </tr>
    </table>

    <h2>Movements</h2>
    <table>
        <thead>
            <tr>
                <th>Job #</th>
                <th>Collection</th>
                <th>Delivered</th>
                <th>From</th>
                <th>To</th>
                <th>Vehicle</th>
                <th class="right">Advance</th>
                <th class="right">Pay</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                @php $job = $line['job']; @endphp
                <tr class="row">
                    <td class="small">{{ $job->job_number }}</td>
                    <td class="small">{{ $job->collected_at?->format('d M Y') ?? '—' }}</td>
                    <td class="small">{{ $job->delivered_at?->format('d M Y') ?? '—' }}</td>
                    <td class="small">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="small">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                    <td class="small">
                        {{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}
                        @if($job->registration)
                            <div class="muted" style="font-size: 9px;">{{ $job->registration }}</div>
                        @endif
                    </td>
                    <td class="right tabular">
                        @if($job->advance_total !== null && (float) $job->advance_total > 0)
                            <span style="color: #92400e; font-weight: 600;">R {{ number_format((float) $job->advance_total, 2) }}</span>
                            @if($job->advance_assigned_at)
                                <div class="muted" style="font-size: 9px;">{{ $job->advance_assigned_at->format('d M') }}</div>
                            @endif
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="right tabular">
                        R {{ number_format($line['pay'], 2) }}
                        @if($line['is_override'])
                            <div><span class="pill pill-green">Override</span></div>
                            @if($line['note'])
                                <div class="muted" style="font-size: 9px;">{{ $line['note'] }}</div>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted center" style="padding: 20px;">No movements delivered in this window.</td></tr>
            @endforelse
        </tbody>
        @if($lines->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="6" class="right"><strong>Totals</strong></td>
                    <td class="right tabular" style="color: #92400e; font-weight: bold;">R {{ number_format($advancesIssued, 2) }}</td>
                    <td class="right tabular" style="color: #065f46; font-weight: bold;">R {{ number_format($grossEarnings, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <h2>Bus tickets</h2>
    <table>
        <thead>
            <tr>
                <th>Travel</th>
                <th>From &rarr; To</th>
                <th>Linked job</th>
                <th>Carrier / Ref</th>
                <th class="right">Amount</th>
                <th>Status</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
            @forelse($busTickets as $t)
                <tr class="row">
                    <td class="small">{{ $t->travel_date->format('d M Y') }}</td>
                    <td class="small">{{ $t->originForDisplay() }} &rarr; {{ $t->destinationForDisplay() }}</td>
                    <td class="small">{{ $t->job?->job_number ?? '—' }}</td>
                    <td class="small">
                        {{ $t->bus_company ?: '—' }}
                        @if($t->reference_number)
                            <div class="muted" style="font-size: 9px;">{{ $t->reference_number }}</div>
                        @endif
                    </td>
                    <td class="right tabular">R {{ number_format($t->amountRand(), 2) }}</td>
                    <td class="small">
                        @php
                            $cls = match (true) {
                                $t->status === \App\Models\DriverBusTicket::STATUS_USED => 'pill-green',
                                $t->isChargedToDriver() => 'pill-red',
                                $t->status === \App\Models\DriverBusTicket::STATUS_NOT_USED => 'pill-slate',
                                default => 'pill-amber',
                            };
                        @endphp
                        <span class="pill {{ $cls }}">
                            {{ $t->statusLabel() }}
                            @if($t->outcomeLabel()) &middot; {{ $t->outcomeLabel() }}@endif
                        </span>
                    </td>
                    <td class="small">{{ $t->not_used_reason ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted center" style="padding: 16px;">No bus tickets in this window.</td></tr>
            @endforelse
        </tbody>
        @if($busDeductions > 0)
            <tfoot>
                <tr>
                    <td colspan="4" class="right"><strong>Charged to driver</strong></td>
                    <td class="right tabular" style="color: #991b1b; font-weight: bold;">&minus; R {{ number_format($busDeductions, 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>

    <h2>Petty cash slips submitted (reference)</h2>
    <table>
        <thead>
            <tr>
                <th>Spent</th>
                <th>Category</th>
                <th>Merchant</th>
                <th>Job</th>
                <th class="right">Amount</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pettyCash as $e)
                <tr class="row">
                    <td class="small">{{ ($e->spent_at ?? $e->created_at)->format('d M Y') }}</td>
                    <td class="small">{{ $e->categoryLabel() }}</td>
                    <td class="small">{{ $e->merchant_name ?: '—' }}</td>
                    <td class="small">{{ $e->job?->job_number ?? '—' }}</td>
                    <td class="right tabular">R {{ number_format($e->amountRand(), 2) }}</td>
                    <td class="small">{{ $e->statusLabel() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted center" style="padding: 16px;">No petty cash entries in this window.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($cancelled->count() > 0)
        <h2>Cancelled trips &middot; petty cash reconciliation</h2>
        <div class="muted small" style="margin-bottom: 6px;">
            Any advance issued for a trip that didn't run must be reconciled -- refunded, transferred, or
            written off with a reason.  Rows flagged as <strong>Open query</strong> are still unaccounted for.
            @if($cancelledAdvanceOpen > 0)
                <strong style="color: #991b1b;">
                    Open: R {{ number_format($cancelledAdvanceOpen, 2) }} across unresolved rows.
                </strong>
            @endif
        </div>
        <table>
            <thead>
                <tr>
                    <th>Cancelled</th>
                    <th>Job #</th>
                    <th>From &rarr; To</th>
                    <th>Vehicle</th>
                    <th class="right">Petty cash</th>
                    <th>Where did it go?</th>
                    <th>Cancellation reason</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cancelled as $job)
                    @php
                        $advance = (float) ($job->advance_total ?? 0);
                        $cleared = !is_null($job->issued_cancellation_cleared_at);
                        $transferred = !is_null($job->advance_transferred_to_job_id);
                    @endphp
                    <tr class="row">
                        <td class="small">{{ $job->cancelled_at?->format('d M Y') ?? '—' }}</td>
                        <td class="small">{{ $job->job_number }}</td>
                        <td class="small">
                            {{ $job->pickupLocation?->shortDisplay() ?? '—' }}
                            &rarr; {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                        </td>
                        <td class="small">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</td>
                        <td class="right tabular">
                            @if($advance > 0)
                                <span style="color: {{ $cleared ? '#334155' : '#991b1b' }}; font-weight: 600;">
                                    R {{ number_format($advance, 2) }}
                                </span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($advance <= 0)
                                <span class="muted">No advance issued</span>
                            @elseif($transferred)
                                <span class="pill pill-blue">
                                    Transferred &rarr; {{ $job->advanceTransferredToJob?->job_number ?? '—' }}
                                </span>
                                @if($job->issuedCancellationClearedBy)
                                    <div class="muted" style="font-size: 9px;">
                                        by {{ $job->issuedCancellationClearedBy->name }}
                                        @if($job->issued_cancellation_cleared_at)
                                            &middot; {{ $job->issued_cancellation_cleared_at->format('d M') }}
                                        @endif
                                    </div>
                                @endif
                            @elseif($cleared)
                                <span class="pill pill-green">Cleared</span>
                                @if($job->issuedCancellationClearedBy)
                                    <div class="muted" style="font-size: 9px;">
                                        by {{ $job->issuedCancellationClearedBy->name }}
                                        @if($job->issued_cancellation_cleared_at)
                                            &middot; {{ $job->issued_cancellation_cleared_at->format('d M') }}
                                        @endif
                                    </div>
                                @endif
                                @if($job->issued_cancellation_cleared_note)
                                    <div class="muted" style="font-size: 9px; font-style: italic;">
                                        "{{ $job->issued_cancellation_cleared_note }}"
                                    </div>
                                @endif
                            @else
                                <span class="pill pill-red">Open query &middot; R {{ number_format($advance, 2) }}</span>
                            @endif
                        </td>
                        <td class="small">{{ $job->cancellation_reason ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if($cancelledAdvanceTotal > 0)
                <tfoot>
                    <tr>
                        <td colspan="4" class="right"><strong>Petty cash out on cancelled trips</strong></td>
                        <td class="right tabular" style="color: #92400e; font-weight: bold;">R {{ number_format($cancelledAdvanceTotal, 2) }}</td>
                        <td colspan="2" class="small">
                            @if($cancelledAdvanceOpen > 0)
                                <strong style="color: #991b1b;">Still open: R {{ number_format($cancelledAdvanceOpen, 2) }}</strong>
                            @else
                                <strong style="color: #065f46;">All reconciled</strong>
                            @endif
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif

    {{-- Open petty cash still out against the driver (all time) --}}
    <h2>Open petty cash against {{ $driver->name }}</h2>
    <div class="muted small" style="margin-bottom: 6px;">
        Jobs where cash was issued to {{ $driver->name }} and the trip hasn't been delivered or reconciled.
        Includes trips still in-flight, trips never finished, and cancelled trips with an unresolved query.
        <strong>All-time</strong> &mdash; not scoped to {{ $anchor->format('F Y') }}.
        @if($openCashTotal > 0)
            <strong style="color: #92400e;">Total open: R {{ number_format($openCashTotal, 2) }}</strong>
        @endif
    </div>
    <table>
        <thead>
            <tr>
                <th>Scheduled</th>
                <th>Job #</th>
                <th>From &rarr; To</th>
                <th>Vehicle</th>
                <th class="right">Cash out</th>
                <th>Issued</th>
                <th>Current state</th>
            </tr>
        </thead>
        <tbody>
            @forelse($openCashExposure as $job)
                @php
                    $isCancelled = $job->status === \App\Models\Job::STATUS_CANCELLED;
                    $ageDays = $job->advance_issued_at ? (int) $job->advance_issued_at->diffInDays(now()) : null;
                @endphp
                <tr class="row">
                    <td class="small">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                    <td class="small">{{ $job->job_number }}</td>
                    <td class="small">
                        {{ $job->pickupLocation?->shortDisplay() ?? '—' }}
                        &rarr; {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                    </td>
                    <td class="small">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</td>
                    <td class="right tabular" style="color: #92400e; font-weight: 600;">
                        R {{ number_format((float) $job->advance_total, 2) }}
                    </td>
                    <td class="small">
                        {{ $job->advance_issued_at?->format('d M Y') ?? '—' }}
                        @if($ageDays !== null)
                            <div class="muted" style="font-size: 9px;">{{ $ageDays }}d ago</div>
                        @endif
                    </td>
                    <td class="small">
                        @if($isCancelled)
                            <span class="pill pill-red">Cancelled &middot; open query</span>
                        @else
                            <span class="pill pill-slate">{{ $job->phase1StatusLabel() }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="muted center" style="padding: 16px; color: #065f46;">
                        Nothing open &mdash; every advance issued to {{ $driver->name }} has either delivered or been reconciled.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($openCashTotal > 0)
            <tfoot>
                <tr>
                    <td colspan="4" class="right"><strong>Total petty cash still open</strong></td>
                    <td class="right tabular" style="color: #92400e; font-weight: bold;">R {{ number_format($openCashTotal, 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- Final summary --}}
    <table style="margin-top: 20px;">
        <tr>
            <td style="padding: 0; width: 60%;">
                <div class="muted small">
                    Gross earnings = sum of per-movement pay (per-trip override, else the default rate per movement from your profile).
                    Bus deductions = tickets issued but not used where the cost was charged to the driver.
                    Petty cash advanced = cash issued to the driver per trip; slips submitted = the reconciliation
                    paperwork the driver captured against those advances.  Neither petty-cash figure, nor cancelled trips,
                    affect net pay directly &mdash; they're shown for context and audit.
                </div>
            </td>
            <td style="padding: 0; text-align: right;">
                <table style="margin-left: auto;">
                    <tr>
                        <td style="padding: 2px 10px;">Gross</td>
                        <td class="right tabular" style="padding: 2px 10px;">R {{ number_format($grossEarnings, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 10px; color: #991b1b;">Less: bus charged to driver</td>
                        <td class="right tabular" style="padding: 2px 10px; color: #991b1b;">&minus; R {{ number_format($busDeductions, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 10px; border-top: 2px solid #0f172a; font-size: 13px; font-weight: bold;">Net pay</td>
                        <td class="right tabular" style="padding: 6px 10px; border-top: 2px solid #0f172a; font-size: 13px; font-weight: bold; color: #1e3a8a;">R {{ number_format($netPay, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="footer">
        TRIDENT Control &amp; Dispatch Center &mdash; Generated {{ $generatedAt->format('d M Y H:i') }}
    </div>
</body>
</html>
