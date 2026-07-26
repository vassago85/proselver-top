<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  FINANCE DASHBOARD                                               ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  The money view of a calendar month.  Answers the four questions ║
 * ║  accounts actually opens the system to ask:                      ║
 * ║                                                                  ║
 * ║    1. What have we delivered that we haven't billed yet?         ║
 * ║    2. Does the petty cash we issued match what drivers spent?    ║
 * ║    3. What do we owe drivers for the month?                      ║
 * ║    4. What does the platform licence cost us this month?         ║
 * ║                                                                  ║
 * ║  Every figure is a link through to the page where the work gets  ║
 * ║  done -- this page is read-only on purpose.  Nothing is captured ║
 * ║  or approved here.                                               ║
 * ║                                                                  ║
 * ║  Definitions are deliberately copied from the pages they link to ║
 * ║  so a number here can never disagree with the number there:      ║
 * ║                                                                  ║
 * ║    billable      admin/invoices/index.blade.php baseQuery()      ║
 * ║    issued/spent  admin/overview.blade.php                        ║
 * ║    driver pay    admin/drivers/pay.blade.php                     ║
 * ║    licence       App\Services\ProselverLicenceBilling            ║
 * ║                                                                  ║
 * ║  SQL here stays portable (no Postgres-only FILTER or ::date) so  ║
 * ║  the page is coverable by the SQLite test suite.  The Operations ║
 * ║  dashboard is Postgres-only and consequently untested -- don't   ║
 * ║  copy its query style into this file.                            ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** Picked month as YYYY-MM.  Defaults to the current month. */
    #[Url] public string $month = '';

    public function mount(): void
    {
        // Accounts is the primary audience.  Owner / developer / super
        // admin get it as part of full-system access.  The operations
        // controller is included because they issue petty-cash advances
        // and are accountable for the variance -- same reasoning that
        // put them on the Petty Cash Overview.
        if (!$this->canView()) {
            abort(403, 'The finance dashboard is restricted to accounts and management.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function canView(): bool
    {
        $u = auth()->user();

        return $u && (
            $u->isAccounts()
            || $u->isOwner()
            || $u->isDeveloper()
            || $u->isSuperAdmin()
            || $u->isOperationsController()
        );
    }

    /**
     * The platform licence figure is owner/developer business only — see
     * User::canViewPlatformLicence(). Accounts and ops can read every other
     * number on this page but not what Trident itself costs.
     */
    public function canSeeLicence(): bool
    {
        return (bool) auth()->user()?->canViewPlatformLicence();
    }

    public function stepMonth(int $delta): void
    {
        $anchor = $this->anchor()->addMonthsNoOverflow($delta);

        // Never walk into the future -- there is nothing to reconcile in
        // a month that hasn't happened.
        if ($anchor->greaterThan(now()->startOfMonth())) {
            return;
        }

        $this->month = $anchor->format('Y-m');
    }

    /** First day of the picked month, falling back to this month. */
    private function anchor(): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();
        } catch (\Throwable $e) {
            return now()->startOfMonth();
        }
    }

    /**
     * Movements we are entitled to bill for a window: ProSelver-executed
     * and actually delivered.  Mirrors the invoicing page's baseQuery()
     * minus its per-view completion filter, which we apply as conditional
     * aggregates instead so one pass answers every bucket.
     */
    private function billableQuery(Carbon $from, Carbon $to): \Illuminate\Database\Eloquent\Builder
    {
        return Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [
                Job::STATUS_DELIVERED,
                Job::STATUS_COMPLETED,
                Job::STATUS_INVOICED,
            ])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to]);
    }

    /**
     * Petty cash issued (advance_total on jobs whose advance was assigned
     * in the window) and spent (slips the business has committed to).
     * Same status set as the Petty Cash Overview so the variance figure
     * on both pages agrees.
     *
     * @return array{issued: float, spent: float, reimbursed: float, pending: int}
     */
    private function pettyCash(Carbon $from, Carbon $to): array
    {
        $issued = (float) Job::query()
            ->whereNotNull('advance_assigned_at')
            ->whereBetween('advance_assigned_at', [$from, $to])
            ->sum('advance_total');

        $slips = PettyCashEntry::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?, ?) THEN amount_cents END), 0) AS spent_cents', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount_cents END), 0) AS reimbursed_cents', [
                PettyCashEntry::STATUS_REIMBURSED,
            ])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS pending_count', [
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->first();

        return [
            'issued' => $issued,
            'spent' => (float) ($slips->spent_cents ?? 0) / 100,
            'reimbursed' => (float) ($slips->reimbursed_cents ?? 0) / 100,
            'pending' => (int) ($slips->pending_count ?? 0),
        ];
    }

    public function with(): array
    {
        $anchor = $this->anchor();
        $from = $anchor->copy()->startOfMonth();
        $to = $anchor->copy()->endOfMonth();
        $prevFrom = $anchor->copy()->subMonthNoOverflow()->startOfMonth();
        $prevTo = $prevFrom->copy()->endOfMonth();

        // ─── Invoicing, in one pass over the month's billable rows ──────
        // '' is passed as a bound parameter rather than inlined so the
        // empty-invoice-number test is identical to the invoicing page's.
        $invoicing = $this->billableQuery($from, $to)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_excluded_at IS NOT NULL THEN 1 END) AS excluded_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_completed_at IS NOT NULL AND invoicing_excluded_at IS NULL THEN 1 END) AS done_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN 1 END) AS open_count')
            ->selectRaw('COUNT(CASE WHEN (invoice_number IS NULL OR invoice_number = ?) AND invoicing_excluded_at IS NULL THEN 1 END) AS missing_number_count', [''])
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN invoice_amount END), 0) AS invoice_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN extras_amount END), 0) AS extras_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN total_sell_price END), 0) AS unbilled_sell_sum')
            ->first();

        $billableTotal = (int) ($invoicing->total_count ?? 0);
        $excludedCount = (int) ($invoicing->excluded_count ?? 0);
        $doneCount = (int) ($invoicing->done_count ?? 0);
        $openCount = (int) ($invoicing->open_count ?? 0);

        // "In scope" excludes the not-required pile, so the capture
        // percentage measures work accounts can actually finish.
        $inScope = max(0, $billableTotal - $excludedCount);
        $capturedPct = $inScope > 0 ? (int) round(($doneCount / $inScope) * 100) : 0;

        $prevOpen = (int) ($this->billableQuery($prevFrom, $prevTo)
            ->whereNull('invoicing_completed_at')
            ->whereNull('invoicing_excluded_at')
            ->count());

        // ─── Petty cash ────────────────────────────────────────────────
        $cash = $this->pettyCash($from, $to);
        $prevCash = $this->pettyCash($prevFrom, $prevTo);
        $variance = round($cash['spent'] - $cash['issued'], 2);
        $prevVariance = round($prevCash['spent'] - $prevCash['issued'], 2);

        // ─── Driver pay ────────────────────────────────────────────────
        // Movements delivered in the month by platform drivers, plus the
        // rate coverage check: a driver with movements but no configured
        // rate is money we can't calculate, which is the single most
        // common month-end blocker.
        $driverRates = User::query()
            ->platformDrivers()
            ->with('driverProfile:user_id,rate_per_movement_cents')
            ->get(['id', 'name']);

        $rateByDriver = $driverRates->mapWithKeys(
            fn (User $d) => [$d->id => $d->driverProfile?->rate_per_movement_cents]
        );

        $driverMoves = Job::query()
            ->whereIn('driver_user_id', $rateByDriver->keys()->all() ?: [0])
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereBetween('delivered_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COUNT(*) AS moves')
            ->pluck('moves', 'driver_user_id');

        $driverEarnings = 0.0;
        $driversMissingRate = 0;
        $totalMoves = 0;

        foreach ($driverMoves as $driverId => $moves) {
            $totalMoves += (int) $moves;
            $rateCents = $rateByDriver->get($driverId);

            if ($rateCents === null) {
                $driversMissingRate++;
                continue;
            }

            $driverEarnings += ((int) $moves) * ((float) $rateCents / 100);
        }

        // ─── Platform licence ──────────────────────────────────────────
        // Counted directly rather than via ProselverLicenceBilling::
        // summarise(), which hydrates every billable job just to count
        // them -- we only need the number here.
        $licence = null;
        if ($this->canSeeLicence()) {
            $billing = app(ProselverLicenceBilling::class);

            if ($billing->isEnabled()) {
                $moves = Job::query()
                    ->where('executor_type', Job::EXECUTOR_PROSELVER)
                    ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                    ->whereNotNull('delivered_at')
                    ->whereBetween('delivered_at', [$from, $to])
                    ->count();

                $excl = $moves * $billing->perMoveFee();

                $licence = [
                    'moves' => $moves,
                    'per_move' => $billing->perMoveFee(),
                    'total_incl_vat' => $excl + round($excl * ProselverLicenceBilling::VAT_RATE, 2),
                ];
            }
        }

        // ─── Unbilled value by customer ────────────────────────────────
        $unbilledRows = $this->billableQuery($from, $to)
            ->whereNull('invoicing_completed_at')
            ->whereNull('invoicing_excluded_at')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS moves, COALESCE(SUM(total_sell_price), 0) AS sell_sum')
            ->orderByDesc('moves')
            ->limit(8)
            ->get();

        $companyNames = $unbilledRows->isEmpty()
            ? collect()
            : Company::whereIn('id', $unbilledRows->pluck('company_id'))->pluck('name', 'id');

        $unbilledRows->each(fn ($r) => $r->setAttribute(
            'company_name',
            $companyNames->get($r->company_id) ?? 'Unknown customer'
        ));

        return [
            'anchor' => $anchor,
            'from' => $from,
            'to' => $to,
            'atCurrentMonth' => $anchor->equalTo(now()->startOfMonth()),

            'billableTotal' => $billableTotal,
            'openCount' => $openCount,
            'doneCount' => $doneCount,
            'excludedCount' => $excludedCount,
            'inScope' => $inScope,
            'capturedPct' => $capturedPct,
            'missingNumberCount' => (int) ($invoicing->missing_number_count ?? 0),
            'capturedValue' => (float) ($invoicing->invoice_sum ?? 0) + (float) ($invoicing->extras_sum ?? 0),
            'unbilledValue' => (float) ($invoicing->unbilled_sell_sum ?? 0),
            'openTrend' => $this->trend($openCount, $prevOpen),

            'cash' => $cash,
            'variance' => $variance,
            'prevVariance' => $prevVariance,

            'totalMoves' => $totalMoves,
            'driverEarnings' => $driverEarnings,
            'driversMissingRate' => $driversMissingRate,

            'licence' => $licence,
            'unbilledRows' => $unbilledRows,
        ];
    }

    /**
     * Percentage movement vs the previous month.  Note that for the
     * open-invoicing count a DROP is good, so the caller decides how to
     * colour it -- this only reports direction and magnitude.
     */
    private function trend(int $current, int $previous): ?array
    {
        if ($previous === 0 && $current === 0) {
            return null;
        }
        if ($previous === 0) {
            return ['dir' => 'up', 'label' => 'new'];
        }

        $delta = (int) round((($current - $previous) / $previous) * 100);

        return [
            'dir' => $delta >= 0 ? 'up' : 'down',
            'label' => ($delta >= 0 ? '+' : '') . $delta . '%',
        ];
    }
}; ?>

@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $num = fn ($v) => number_format((int) $v);

    // Invoicing links carry the same window and view the card describes,
    // so a click lands on exactly the rows the number counted.
    $invoiceLink = fn (string $completion) => route('admin.invoices.index', [
        'dateFrom' => $from->toDateString(),
        'dateTo' => $to->toDateString(),
        'completion' => $completion,
    ]);
@endphp

<div class="space-y-6">

    <x-page-header
        eyebrow="Finance"
        title="Finance Overview"
        subtitle="Billing, petty cash and driver pay for {{ $anchor->format('F Y') }}.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.invoices.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </x-slot:icon>
                Invoicing
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('admin.overview')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                </x-slot:icon>
                Petty cash
            </x-button>
            <x-button variant="primary" size="sm" :href="route('admin.reports.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/></svg>
                </x-slot:icon>
                Reports
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @include('pages.admin._partials.dashboard-tabs')

    {{-- Month stepper.  Finance works a month at a time, so this replaces
         the ops dashboard's multi-filter strip entirely. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="stepMonth(-1)"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                aria-label="Previous month">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>

            <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
                class="rounded-lg border-slate-300 text-sm font-semibold text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">

            <button type="button" wire:click="stepMonth(1)" @disabled($atCurrentMonth)
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Next month">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>

        <p class="text-[11px] font-medium text-slate-500">
            {{ $from->format('d M Y') }} &rarr; {{ $to->format('d M Y') }}
            &middot; delivered-date basis
        </p>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- KPI ROW                                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <x-dash.kpi
            label="Awaiting invoicing"
            :value="$num($openCount)"
            :color="$openCount > 0 ? 'orange' : 'green'"
            :href="$invoiceLink('incomplete')"
            :trend="$openTrend"
            :helper="$openCount > 0 ? $money($unbilledValue) . ' of delivered work not yet captured' : 'Everything delivered has been captured'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Captured"
            :value="$capturedPct . '%'"
            :color="$capturedPct >= 100 ? 'green' : ($capturedPct >= 50 ? 'teal' : 'amber')"
            :href="$invoiceLink('complete')"
            :helper="$num($doneCount) . ' of ' . $num($inScope) . ' billable movements signed off'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Missing invoice no."
            :value="$num($missingNumberCount)"
            :color="$missingNumberCount > 0 ? 'red' : 'green'"
            :href="$invoiceLink('all')"
            helper="Billable movements with no invoice number captured">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Invoiced value"
            :value="$money($capturedValue)"
            color="purple"
            :href="$invoiceLink('all')"
            helper="Invoice amounts plus extras captured this month">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/><line x1="8" x2="16" y1="17" y2="17"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Petty cash variance"
            :value="($variance >= 0 ? '+' : '−') . $money(abs($variance))"
            :color="abs($variance) < 1 ? 'green' : ($variance > 0 ? 'red' : 'amber')"
            :href="route('admin.overview')"
            :helper="$variance > 0 ? 'Drivers spent more than was issued' : ($variance < 0 ? 'Issued more than drivers spent' : 'Issued and spent are balanced')">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        @if($licence)
            <x-dash.kpi
                label="Platform licence"
                :value="$money($licence['total_incl_vat'])"
                color="indigo"
                :href="route('admin.billing')"
                :helper="$num($licence['moves']) . ' moves × ' . $money($licence['per_move']) . ' incl. VAT'">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </x-slot:icon>
            </x-dash.kpi>
        @else
            <x-dash.kpi
                label="Driver earnings"
                :value="$money($driverEarnings)"
                color="blue"
                :href="route('admin.drivers.pay', ['month' => $anchor->format('Y-m')])"
                :helper="$num($totalMoves) . ' movements completed this month'">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </x-slot:icon>
            </x-dash.kpi>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- INVOICING PROGRESS + PETTY CASH                                --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <x-dash.panel
            title="Invoicing progress"
            :subtitle="$anchor->format('F Y') . ' · by delivered date'">
            <x-slot:actions>
                <a href="{{ $invoiceLink('incomplete') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Capture &rarr;</a>
            </x-slot:actions>

            @if($billableTotal === 0)
                <div class="py-8 text-center">
                    <p class="text-sm font-medium text-slate-500">No ProSelver movements were delivered in {{ $anchor->format('F Y') }}.</p>
                    <p class="mt-1 text-xs text-slate-400">Nothing to bill for this month.</p>
                </div>
            @else
                {{-- Stacked progress bar: captured / open / excluded. --}}
                @php
                    $pct = fn (int $n) => $billableTotal > 0 ? ($n / $billableTotal) * 100 : 0;
                @endphp
                <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="bg-emerald-500" style="width: {{ $pct($doneCount) }}%"></div>
                    <div class="bg-orange-400" style="width: {{ $pct($openCount) }}%"></div>
                    <div class="bg-slate-300" style="width: {{ $pct($excludedCount) }}%"></div>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Captured</p>
                        </div>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-700">{{ $num($doneCount) }}</p>
                    </div>
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-orange-400"></span>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Outstanding</p>
                        </div>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-orange-700">{{ $num($openCount) }}</p>
                    </div>
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Not required</p>
                        </div>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-slate-500">{{ $num($excludedCount) }}</p>
                    </div>
                </div>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-xs">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Value captured</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">{{ $money($capturedValue) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Still to bill (at sell price)</dt>
                        <dd class="font-semibold tabular-nums {{ $unbilledValue > 0 ? 'text-orange-700' : 'text-slate-900' }}">{{ $money($unbilledValue) }}</dd>
                    </div>
                </dl>
            @endif

            <x-slot:footer>
                Excluded rows are movements the owner marked as not billable — they don't count against the capture percentage.
            </x-slot:footer>
        </x-dash.panel>

        <x-dash.panel
            title="Petty cash reconciliation"
            :subtitle="'Issued vs spent · ' . $anchor->format('F Y')">
            <x-slot:actions>
                @if($cash['pending'] > 0)
                    <x-dash.pill variant="amber">{{ $num($cash['pending']) }} pending</x-dash.pill>
                @endif
                <a href="{{ route('admin.petty-cash.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Slips &rarr;</a>
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Issued</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($cash['issued']) }}</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">Advances assigned this month</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Spent</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($cash['spent']) }}</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">Submitted, approved &amp; reimbursed slips</p>
                </div>
            </div>

            @php
                // How much of what drivers spent has actually been paid
                // back to them.  100% means nobody is out of pocket.
                $reimbursedPct = $cash['spent'] > 0
                    ? min(100, (int) round(($cash['reimbursed'] / $cash['spent']) * 100))
                    : 0;
            @endphp

            <div class="mt-4">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-medium text-slate-600">Reimbursed to drivers</span>
                    <span class="font-semibold tabular-nums text-slate-900">{{ $money($cash['reimbursed']) }} · {{ $reimbursedPct }}%</span>
                </div>
                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full {{ $reimbursedPct >= 100 ? 'bg-emerald-500' : 'bg-blue-500' }}" style="width: {{ $reimbursedPct }}%"></div>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between rounded-lg border p-3
                {{ abs($variance) < 1 ? 'border-emerald-200 bg-emerald-50/60' : ($variance > 0 ? 'border-rose-200 bg-rose-50/60' : 'border-amber-200 bg-amber-50/60') }}">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Variance</p>
                    <p class="mt-0.5 text-xs text-slate-600">
                        @if(abs($variance) < 1)
                            Issued and spent are balanced.
                        @elseif($variance > 0)
                            Drivers spent {{ $money(abs($variance)) }} more than was issued.
                        @else
                            {{ $money(abs($variance)) }} issued but not spent.
                        @endif
                    </p>
                </div>
                <p class="text-xl font-semibold tabular-nums {{ abs($variance) < 1 ? 'text-emerald-700' : ($variance > 0 ? 'text-rose-700' : 'text-amber-700') }}">
                    {{ $variance >= 0 ? '+' : '−' }}{{ $money(abs($variance)) }}
                </p>
            </div>

            <x-slot:footer>
                Last month's variance was {{ $prevVariance >= 0 ? '+' : '−' }}{{ $money(abs($prevVariance)) }}.
                <a href="{{ route('admin.overview') }}" class="font-semibold text-blue-600 hover:text-blue-700">Full breakdown &rarr;</a>
            </x-slot:footer>
        </x-dash.panel>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- DRIVER PAY + UNBILLED BY CUSTOMER                              --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        <x-dash.panel
            title="Driver pay"
            :subtitle="'Movements × rate · ' . $anchor->format('F Y')">
            <x-slot:actions>
                <a href="{{ route('admin.drivers.pay', ['month' => $anchor->format('Y-m')]) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Full report &rarr;</a>
            </x-slot:actions>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Movements</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $num($totalMoves) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Earnings</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-blue-700">{{ $money($driverEarnings) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Advances out</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($cash['issued']) }}</p>
                </div>
            </div>

            @if($driversMissingRate > 0)
                <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-amber-900">
                            {{ $driversMissingRate }} {{ \Illuminate\Support\Str::plural('driver', $driversMissingRate) }} completed movements with no rate set
                        </p>
                        <p class="mt-0.5 text-[11px] text-amber-800">
                            Their earnings are excluded from the total above until a rate per movement is captured on their profile.
                            <a href="{{ route('admin.drivers.index') }}" class="font-semibold underline">Set rates &rarr;</a>
                        </p>
                    </div>
                </div>
            @else
                <p class="mt-4 text-xs text-slate-500">Every driver who moved a vehicle this month has a rate configured.</p>
            @endif

            <x-slot:footer>
                Earnings are movements completed &times; the driver's rate per movement. Advances are cash issued, not earnings.
            </x-slot:footer>
        </x-dash.panel>

        <x-dash.panel
            title="Still to bill by customer"
            subtitle="Top 8 by outstanding movements"
            :tight="true">
            @if($unbilledRows->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-slate-500">Nothing outstanding.</p>
                    <p class="mt-1 text-xs text-slate-400">Every delivered movement in {{ $anchor->format('F Y') }} has been captured.</p>
                </div>
            @else
                <table class="w-full text-xs">
                    <thead class="bg-slate-50/80 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-left font-semibold">Customer</th>
                            <th class="px-3 py-2 text-right font-semibold">Movements</th>
                            <th class="px-5 py-2 text-right font-semibold">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($unbilledRows as $row)
                            <tr class="transition hover:bg-slate-50/60">
                                <td class="px-5 py-2.5">
                                    <a href="{{ route('admin.invoices.index', [
                                            'companyId' => $row->company_id,
                                            'dateFrom' => $from->toDateString(),
                                            'dateTo' => $to->toDateString(),
                                            'completion' => 'incomplete',
                                        ]) }}"
                                        class="font-medium text-slate-900 hover:text-blue-700">
                                        {{ $row->company_name }}
                                    </a>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $num($row->moves) }}</td>
                                <td class="px-5 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ $money($row->sell_sum) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <x-slot:footer>
                Value is the movement sell price on record, not a captured invoice amount — treat it as an estimate until accounts captures the invoice.
            </x-slot:footer>
        </x-dash.panel>
    </div>
</div>
