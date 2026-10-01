<?php

use App\Models\DriverBusTicket;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Per-driver, per-month payslip detail page.
 *
 * This is the long-form of /admin/drivers/pay (the all-driver summary
 * table).  The summary tells you WHO earned WHAT; this screen is the
 * line-by-line "why" + the surface where accounts captures a per-trip
 * remuneration override, resolves bus-ticket outcomes, and prints the
 * PDF the driver gets to see.
 *
 * Access: accounts / owner / developer only (same gate as the pay
 * summary).  Anybody else 403s.
 *
 * Lifecycle for the inline pay override:
 *   - transport_jobs.driver_pay_amount is NULL by default.  When null,
 *     the driver's rate_per_movement_cents applies.
 *   - Accounts types a rand value on this page and tabs out; savePay()
 *     writes driver_pay_amount + driver_pay_note + set_by + set_at.
 *   - Clearing the input (empty string) restores NULL -- back to using
 *     the profile rate.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public User $user;

    /** Picked month as YYYY-MM; defaults to previous month. */
    #[Url] public string $month = '';

    /**
     * Per-row inline edit buffer, keyed by job id.  Populated from the
     * database in with() so blur-saves have something to update, and
     * cleared whenever the month changes.
     */
    public array $rows = [];

    public function mount(User $user): void
    {
        $this->assertAuthorised();

        if (!$user->isDriver()) {
            abort(404, 'Not a driver.');
        }

        $this->user = $user;

        if ($this->month === '' || !preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->subMonthNoOverflow()->format('Y-m');
        }
    }

    /**
     * Only accounts / owner / developer may view salary data.  Mirror
     * of the drivers.pay gate; both call-sites must agree.
     */
    private function assertAuthorised(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'Payslips are restricted to accounts.');
        }
    }

    /**
     * When the month picker moves, drop the row buffer so blur-saves
     * can't accidentally rewrite the previous month's row with an
     * input that was captured against a different job id in that view.
     */
    public function updatedMonth(): void
    {
        $this->rows = [];
    }

    /**
     * Resolve the picked month to a start / end / anchor triple,
     * defensively falling back to last month if the URL param is bad.
     */
    private function monthRange(): array
    {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $this->month);
        } catch (\Throwable $e) {
            $anchor = now()->subMonthNoOverflow()->startOfMonth();
        }
        return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), $anchor];
    }

    /**
     * Persist a single pay-override row.  Called on blur from each
     * movement line.  Empty amount -> NULL (fall back to profile rate).
     * A non-numeric string is silently rejected to avoid 0.00 drift.
     */
    public function savePay(int $jobId, ?string $amount, ?string $note = null): void
    {
        $this->assertAuthorised();

        $job = Job::query()
            ->where('driver_user_id', $this->user->id)
            ->whereKey($jobId)
            ->first();

        if (!$job) {
            session()->flash('error', 'Movement not found on this driver.');
            return;
        }

        $trimmed = is_string($amount) ? trim($amount) : null;
        $next = null;
        if ($trimmed !== null && $trimmed !== '') {
            if (!is_numeric($trimmed)) {
                session()->flash('error', 'Pay amount must be a number.');
                $this->rows[$jobId]['pay'] = $job->driver_pay_amount !== null
                    ? number_format((float) $job->driver_pay_amount, 2, '.', '')
                    : '';
                return;
            }
            $next = round((float) $trimmed, 2);
        }

        $noteNext = is_string($note) && trim($note) !== '' ? trim($note) : null;

        $before = [
            'driver_pay_amount' => $job->driver_pay_amount !== null ? (float) $job->driver_pay_amount : null,
            'driver_pay_note'   => $job->driver_pay_note,
        ];

        $job->driver_pay_amount        = $next;
        $job->driver_pay_note          = $noteNext;
        $job->driver_pay_set_by_user_id = auth()->id();
        $job->driver_pay_set_at         = now();
        $job->save();

        AuditService::log(
            'driver_pay_amount_updated',
            'transport_job',
            $job->id,
            $before,
            [
                'driver_pay_amount' => $next,
                'driver_pay_note'   => $noteNext,
                'driver_user_id'    => $this->user->id,
            ],
        );
    }

    public function with(): array
    {
        [$from, $to, $anchor] = $this->monthRange();

        $driverProfile = $this->user->driverProfile;
        $profileRate   = $driverProfile?->rate_per_movement_cents !== null
            ? (float) $driverProfile->rate_per_movement_cents / 100
            : null;

        // 1. Movements: jobs assigned to the driver and delivered in
        //    the month, in a status that reflects a completed trip.
        //    Cancelled rows live in a separate section below.
        $movements = Job::query()
            ->where('driver_user_id', $this->user->id)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereBetween('delivered_at', [$from, $to])
            ->with([
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'brand:id,name',
                'driverPaySetBy:id,name',
            ])
            ->orderBy('delivered_at')
            ->get();

        // Seed the inline-edit buffer so wire:model.blur has something
        // to overwrite; only touch rows we haven't already captured
        // (user may have an unsaved edit in flight when a redraw hits).
        foreach ($movements as $job) {
            if (!array_key_exists($job->id, $this->rows)) {
                $this->rows[$job->id] = [
                    'pay'  => $job->driver_pay_amount !== null
                        ? number_format((float) $job->driver_pay_amount, 2, '.', '')
                        : '',
                    'note' => $job->driver_pay_note ?? '',
                ];
            }
        }

        // 2. Cancelled trips the driver was on in this month.  Shows
        //    context only -- no payslip impact, just so accounts can
        //    see why the gross number is lower than they expected.
        $cancelled = Job::query()
            ->where('driver_user_id', $this->user->id)
            ->where('status', Job::STATUS_CANCELLED)
            ->whereBetween('cancelled_at', [$from, $to])
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
            ])
            ->orderBy('cancelled_at')
            ->get();

        // 3. Petty cash entries submitted in the month.  Reference
        //    only -- the payslip net doesn't move; this is here so
        //    accounts can see what the driver has outstanding.
        $pettyCash = PettyCashEntry::query()
            ->forDriver($this->user)
            ->whereBetween('created_at', [$from, $to])
            ->with(['job:id,job_number'])
            ->orderByDesc('spent_at')
            ->get();

        // 4. Bus tickets with a travel_date in the month.  Resolved
        //    "charged to driver" rows are the deduction line on the
        //    totals footer.
        $busTickets = DriverBusTicket::query()
            ->forDriver($this->user)
            ->inMonth($anchor)
            ->with([
                'job:id,job_number',
                'originLocation:id,company_name,city',
                'destinationLocation:id,company_name,city',
                'resolvedBy:id,name',
            ])
            ->orderBy('travel_date')
            ->get();

        // Totals.
        $grossEarnings = 0.0;
        foreach ($movements as $job) {
            $job->setRelation('driver', $this->user->setRelation('driverProfile', $driverProfile));
            $grossEarnings += $job->driverPayForPayslip();
        }

        $busDeductions = $busTickets
            ->where('status', DriverBusTicket::STATUS_NOT_USED)
            ->where('not_used_outcome', DriverBusTicket::OUTCOME_CHARGED_TO_DRIVER)
            ->sum(fn (DriverBusTicket $t) => $t->amountRand());

        $pettyCashApproved = $pettyCash
            ->whereIn('status', [PettyCashEntry::STATUS_APPROVED, PettyCashEntry::STATUS_REIMBURSED])
            ->sum(fn (PettyCashEntry $e) => $e->amountRand());

        $pettyCashPending = $pettyCash
            ->where('status', PettyCashEntry::STATUS_SUBMITTED)
            ->sum(fn (PettyCashEntry $e) => $e->amountRand());

        return [
            'movements'        => $movements,
            'cancelled'        => $cancelled,
            'pettyCash'        => $pettyCash,
            'busTickets'       => $busTickets,
            'profileRate'      => $profileRate,
            'grossEarnings'    => (float) $grossEarnings,
            'busDeductions'    => (float) $busDeductions,
            'netPay'           => (float) ($grossEarnings - $busDeductions),
            'pettyCashApproved'=> (float) $pettyCashApproved,
            'pettyCashPending' => (float) $pettyCashPending,
            'from'             => $from,
            'to'               => $to,
            'anchor'           => $anchor,
        ];
    }

    /**
     * Resolve a bus ticket inline on this page (used tab -> green, voided
     * -> grey, charged-to-driver -> red deduction).  Mirrors the actions
     * on the dedicated /admin/drivers/bus-tickets screen so accounts
     * doesn't need to jump pages to close out a payslip.
     */
    public function markBusTicketUsed(int $ticketId): void
    {
        $this->assertAuthorised();
        $t = $this->busTicket($ticketId);
        if ($t) {
            $t->markUsed(auth()->user());
        }
    }

    public function markBusTicketVoided(int $ticketId, string $reason): void
    {
        $this->assertAuthorised();
        $t = $this->busTicket($ticketId);
        if ($t && !$t->markVoided(auth()->user(), $reason)) {
            session()->flash('error', 'Voiding a ticket requires a non-empty reason.');
        }
    }

    public function markBusTicketCharged(int $ticketId, string $reason): void
    {
        $this->assertAuthorised();
        $t = $this->busTicket($ticketId);
        if ($t && !$t->markChargedToDriver(auth()->user(), $reason)) {
            session()->flash('error', 'Charging a ticket requires a non-empty reason.');
        }
    }

    private function busTicket(int $id): ?DriverBusTicket
    {
        return DriverBusTicket::query()
            ->where('driver_user_id', $this->user->id)
            ->whereKey($id)
            ->first();
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Payslip: {{ $user->name }}</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    @if(session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Header card: driver meta, month picker, totals strip, PDF button --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <div class="space-y-0.5">
                <h2 class="text-sm font-semibold text-slate-900">{{ $user->name }}</h2>
                <p class="text-xs text-slate-500">
                    Payslip for {{ $anchor->format('F Y') }}
                    &middot; {{ $from->format('d M') }} &rarr; {{ $to->format('d M Y') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Month</span>
                    <input type="month"
                        wire:model.live="month"
                        max="{{ now()->format('Y-m') }}"
                        class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </label>
                <a href="{{ route('admin.drivers.payslip.pdf', ['user' => $user->id, 'month' => $month]) }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Download PDF
                </a>
                <a href="{{ route('admin.drivers.pay', ['month' => $month]) }}"
                    class="text-xs font-medium text-slate-500 hover:text-slate-900">
                    &larr; All drivers
                </a>
            </div>
        </div>

        {{-- Totals strip --}}
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-4 sm:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Movements</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums">{{ $movements->count() }}</p>
                @if($profileRate !== null)
                    <p class="mt-0.5 text-[10px] text-slate-400">Rate R {{ number_format($profileRate, 2) }} / move</p>
                @else
                    <p class="mt-0.5 text-[10px] text-amber-600">No rate on profile</p>
                @endif
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Gross earnings</p>
                <p class="mt-1 text-lg font-bold text-emerald-900 tabular-nums">R {{ number_format($grossEarnings, 2) }}</p>
            </div>
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-rose-700">Bus deductions</p>
                <p class="mt-1 text-lg font-bold text-rose-900 tabular-nums">R {{ number_format($busDeductions, 2) }}</p>
                <p class="mt-0.5 text-[10px] text-rose-700">tickets charged to driver</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Net pay</p>
                <p class="mt-1 text-lg font-bold text-blue-900 tabular-nums">R {{ number_format($netPay, 2) }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Petty cash</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">R {{ number_format($pettyCashApproved, 2) }}</p>
                <p class="mt-0.5 text-[10px] text-amber-700">
                    approved + reimbursed
                    @if($pettyCashPending > 0)
                        &middot; R {{ number_format($pettyCashPending, 2) }} pending
                    @endif
                </p>
            </div>
        </div>

        {{-- Movements table (editable pay) --}}
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Job #</th>
                        <th class="px-3 py-2 text-left">Collection date</th>
                        <th class="px-3 py-2 text-left">Delivered</th>
                        <th class="px-3 py-2 text-left">Collection</th>
                        <th class="px-3 py-2 text-left">Delivery</th>
                        <th class="px-3 py-2 text-left">Vehicle</th>
                        <th class="px-3 py-2 text-right">Default rate</th>
                        <th class="px-3 py-2 text-right">Pay override</th>
                        <th class="px-3 py-2 text-left">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($movements as $job)
                        @php
                            $final = $job->driver_pay_amount !== null
                                ? (float) $job->driver_pay_amount
                                : ($profileRate ?? 0);
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">
                                <a href="{{ route('admin.orders.show', $job) }}" class="text-blue-600 hover:underline">
                                    {{ $job->job_number }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->collected_at?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->delivered_at?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->pickupLocation?->shortDisplay() ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div class="flex flex-col">
                                    <span>{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}</span>
                                    @if($job->registration)
                                        <span class="text-[10px] text-slate-400">{{ $job->registration }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-500">
                                @if($profileRate !== null)
                                    R {{ number_format($profileRate, 2) }}
                                @else
                                    <span class="text-amber-600" title="No rate on driver profile">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <input type="number" step="0.01" min="0"
                                    wire:model.blur="rows.{{ $job->id }}.pay"
                                    wire:change="savePay({{ $job->id }}, $event.target.value, $wire.rows[{{ $job->id }}].note)"
                                    placeholder="{{ $profileRate !== null ? number_format($profileRate, 2, '.', '') : '0.00' }}"
                                    class="w-24 rounded-md border-slate-300 py-1 text-right text-xs tabular-nums shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @if($job->driver_pay_amount !== null)
                                    <div class="mt-0.5 text-[10px] text-emerald-700">
                                        override &middot; R {{ number_format((float) $job->driver_pay_amount, 2) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <input type="text"
                                    wire:model.blur="rows.{{ $job->id }}.note"
                                    wire:change="savePay({{ $job->id }}, $wire.rows[{{ $job->id }}].pay, $event.target.value)"
                                    placeholder="e.g. double-haul"
                                    maxlength="255"
                                    class="w-full rounded-md border-slate-300 py-1 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @if($job->driverPaySetBy)
                                    <div class="mt-0.5 text-[10px] text-slate-400">
                                        by {{ $job->driverPaySetBy->name }}
                                        @if($job->driver_pay_set_at)
                                            &middot; {{ $job->driver_pay_set_at->format('d M') }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-10 text-center text-sm text-slate-500">
                                No movements delivered by {{ $user->name }} in {{ $anchor->format('F Y') }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($movements->count() > 0)
                    <tfoot class="bg-slate-50 text-[11px] font-semibold text-slate-700">
                        <tr>
                            <td colspan="7" class="px-3 py-2 text-right">Gross earnings</td>
                            <td colspan="2" class="px-3 py-2 text-right tabular-nums text-emerald-700">
                                R {{ number_format($grossEarnings, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Cancelled trips --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Cancelled trips</h3>
                <p class="text-xs text-slate-500">
                    Movements this driver was assigned to that were cancelled in the window.  Reference only &mdash; no payslip impact.
                </p>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">{{ $cancelled->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Cancelled</th>
                        <th class="px-3 py-2 text-left">Job #</th>
                        <th class="px-3 py-2 text-left">Collection</th>
                        <th class="px-3 py-2 text-left">Delivery</th>
                        <th class="px-3 py-2 text-left">Vehicle</th>
                        <th class="px-3 py-2 text-left">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($cancelled as $job)
                        <tr>
                            <td class="px-3 py-2 text-slate-700">{{ $job->cancelled_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">
                                <a href="{{ route('admin.orders.show', $job) }}" class="text-blue-600 hover:underline">{{ $job->job_number }}</a>
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ $job->pickupLocation?->shortDisplay() ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-700">{{ $job->deliveryLocation?->shortDisplay() ?? '—' }}</td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-slate-600">{{ $job->cancellation_reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-500">
                                No cancelled trips in this window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Petty cash --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Petty cash</h3>
                <p class="text-xs text-slate-500">
                    Entries submitted by the driver in this window.  Reference only &mdash; approvals happen on the Petty Cash queue.
                </p>
            </div>
            <a href="{{ route('admin.petty-cash.index') }}" class="text-xs font-medium text-blue-600 hover:underline">Open queue &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Spent</th>
                        <th class="px-3 py-2 text-left">Category</th>
                        <th class="px-3 py-2 text-left">Merchant</th>
                        <th class="px-3 py-2 text-left">Job</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($pettyCash as $entry)
                        <tr>
                            <td class="px-3 py-2 text-slate-700">{{ $entry->spent_at?->format('d M Y') ?? $entry->created_at->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-slate-700">{{ $entry->categoryLabel() }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $entry->merchant_name ?: '—' }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">{{ $entry->job?->job_number ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-800">{{ $entry->amountForDisplay() }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $entry->statusBadgeClasses() }}">
                                    {{ $entry->statusLabel() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-500">
                                No petty cash submitted in this window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Bus tickets --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm" x-data="{ reasonFor: null, reasonAction: null, reasonText: '' }">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Bus tickets</h3>
                <p class="text-xs text-slate-500">
                    Tickets issued for travel in {{ $anchor->format('F Y') }}.  "Charged to driver" rows deduct from the payslip above.
                </p>
            </div>
            <a href="{{ route('admin.drivers.bus-tickets', ['driver' => $user->id, 'month' => $month]) }}"
                class="text-xs font-medium text-blue-600 hover:underline">
                Open bus tickets &rarr;
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Travel</th>
                        <th class="px-3 py-2 text-left">From &rarr; To</th>
                        <th class="px-3 py-2 text-left">Linked job</th>
                        <th class="px-3 py-2 text-left">Carrier / Ref</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-3 py-2 text-left">Resolution</th>
                        <th class="px-3 py-2 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($busTickets as $t)
                        <tr>
                            <td class="px-3 py-2 text-slate-700">{{ $t->travel_date->format('d M Y') }}</td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $t->originForDisplay() }} &rarr; {{ $t->destinationForDisplay() }}
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">
                                @if($t->job)
                                    <a href="{{ route('admin.orders.show', $t->job) }}" class="text-blue-600 hover:underline">{{ $t->job->job_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                {{ $t->bus_company ?: '—' }}
                                @if($t->reference_number)
                                    <div class="text-[10px] text-slate-400">{{ $t->reference_number }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-800">
                                {{ $t->amountForDisplay() }}
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $t->statusBadgeClasses() }}">
                                    {{ $t->statusLabel() }}
                                    @if($t->outcomeLabel())
                                        &middot; {{ $t->outcomeLabel() }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                @if($t->not_used_reason)
                                    <div class="max-w-xs text-[11px]">{{ $t->not_used_reason }}</div>
                                    @if($t->resolvedBy)
                                        <div class="text-[10px] text-slate-400">by {{ $t->resolvedBy->name }}</div>
                                    @endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if($t->isOpen())
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button type="button"
                                            wire:click="markBusTicketUsed({{ $t->id }})"
                                            class="rounded-md border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 hover:bg-emerald-100">
                                            Used
                                        </button>
                                        <button type="button"
                                            @click="reasonFor = {{ $t->id }}; reasonAction = 'void'; reasonText = ''"
                                            class="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-700 hover:bg-slate-50">
                                            Void
                                        </button>
                                        <button type="button"
                                            @click="reasonFor = {{ $t->id }}; reasonAction = 'charge'; reasonText = ''"
                                            class="rounded-md border border-rose-300 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 hover:bg-rose-100">
                                            Charge driver
                                        </button>
                                    </div>
                                @else
                                    <span class="text-[10px] text-slate-400">resolved</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-6 text-center text-sm text-slate-500">
                                No bus tickets for {{ $user->name }} in {{ $anchor->format('F Y') }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Reason modal (shared between Void + Charge actions) --}}
        <div x-show="reasonFor !== null"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @click.outside="reasonFor = null">
                <h4 class="text-sm font-semibold text-slate-900">
                    <span x-text="reasonAction === 'void' ? 'Void ticket' : 'Charge driver for ticket'"></span>
                </h4>
                <p class="mt-1 text-xs text-slate-500">
                    Capture the reason -- it stays on the ticket for the owner audit.
                </p>
                <textarea x-model="reasonText"
                    rows="4"
                    placeholder="What happened?"
                    class="mt-3 w-full rounded-md border-slate-300 py-2 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                <div class="mt-4 flex items-center justify-end gap-2">
                    <button type="button"
                        @click="reasonFor = null"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="button"
                        @click="
                            if (!reasonText.trim()) return;
                            if (reasonAction === 'void') {
                                $wire.markBusTicketVoided(reasonFor, reasonText);
                            } else {
                                $wire.markBusTicketCharged(reasonFor, reasonText);
                            }
                            reasonFor = null;
                        "
                        :class="reasonAction === 'void'
                            ? 'bg-slate-800 hover:bg-slate-900 text-white'
                            : 'bg-rose-600 hover:bg-rose-700 text-white'"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <p class="text-[11px] text-slate-500">
        <strong>How this is calculated:</strong> each movement pays either the per-trip override (if set) or the driver's
        default rate from their profile.  Bus tickets marked <em>charged to driver</em> are deducted; <em>voided</em>
        tickets are not.  Petty cash and cancelled trips are shown for context and do not affect net pay.
    </p>
</div>
