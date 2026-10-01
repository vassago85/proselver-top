<?php

use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Per-driver forensic cash audit.
 *
 * Different from the month-end payslip page: this one is a long-horizon
 * audit view designed to spot skimming patterns.  Shows every advance
 * ever issued to the driver, broken down by category, with each
 * category framed by how it actually works in the business:
 *
 *   - TOLLS: auto-calculated from the route (RouteCalculationService
 *     + toll_plazas table).  Driver didn't request them; the number is
 *     the system's.  Slip evidence is proof-of-payment, so variance
 *     here means "driver paid the toll but didn't submit the slip" --
 *     a minor housekeeping issue, not a skim vector.
 *
 *   - FOOD: per diem allowance (fixed R/day).  No slips expected --
 *     drivers don't account for food receipts.  Variance = R0 by
 *     definition because we never expect a reconciling slip.  Shown
 *     for context (total R out as allowance) but NOT a red flag.
 *
 *   - ACCOMMODATION: evidence-based.  Driver books a bed, slip proves
 *     it.  Variance > 0 means cash out but no stay recorded.
 *
 *   - TAXI: the ONE skim vector per ops' own concern.  Ops types a
 *     taxi amount when they judge it's required (driver needs to
 *     cab-it somewhere).  No slip is expected (no-slip policy).  The
 *     user has specifically flagged "sometimes they have been giving
 *     the guys taxi money when its not needed" -- so EVERY taxi
 *     advance deserves scrutiny.  This page lists every taxi-advance
 *     trip and flags elevated frequency per driver.
 *
 * Access: OWNER + DEVELOPER ONLY -- NOT accounts, NOT ops, NOT
 * super_admin.  This is a shareholder-level forensic surface and the
 * boss has specifically asked that ops and accounts cannot see it or
 * even know it exists.  Entry points on /admin/drivers/pay and the
 * per-driver payslip header are hidden with the same owner/dev gate;
 * if an accounts user types the URL directly they get a hard 403.
 *
 * Scope: all-time by default, with an optional date-from/date-to
 * filter so an auditor can zoom in on a specific window.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public User $user;

    /** Date-from filter (YYYY-MM-DD, URL-bound); empty means "all time". */
    #[Url] public string $from = '';

    /** Date-to filter (YYYY-MM-DD, URL-bound); empty means "today". */
    #[Url] public string $to = '';

    public function mount(User $user): void
    {
        $this->assertAuthorised();

        if (!$user->isDriver()) {
            abort(404, 'Not a driver.');
        }

        $this->user = $user;
    }

    private function assertAuthorised(): void
    {
        // Owner + developer ONLY.  Not accounts, not ops, not super_admin.
        // The boss has asked that this surface stay invisible to ops and
        // accounts so they can't game around the audit.  Any other role
        // typing the URL directly gets a hard 403.
        $u = auth()->user();
        if (!$u || (!$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'The cash audit is restricted to the owner.');
        }
    }

    /**
     * Resolve the from/to filters to Carbon bounds.  Empty "from" ==
     * beginning of time (represented by a very old date); empty "to"
     * == end of today.  This mirrors how /admin/petty-cash/overview
     * handles its date range, so an auditor who knows one knows both.
     */
    private function range(): array
    {
        $from = $this->from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from)
            ? Carbon::parse($this->from)->startOfDay()
            : Carbon::createFromDate(2000, 1, 1)->startOfDay();

        $to = $this->to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to)
            ? Carbon::parse($this->to)->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    /**
     * Apply a quick-pick preset to the range filters.  Mirrors the
     * presets accounts uses on the reconciliation report so the ops
     * team can grab the common windows without typing.
     */
    public function applyRange(string $preset): void
    {
        $now = now();
        [$from, $to] = match ($preset) {
            'this_month'   => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month'   => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_90_days' => [$now->copy()->subDays(90)->startOfDay(), $now->copy()->endOfDay()],
            'ytd'          => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'all_time'     => [null, null],
            default        => [null, null],
        };
        $this->from = $from?->toDateString() ?? '';
        $this->to   = $to?->toDateString() ?? '';
    }

    public function with(): array
    {
        [$from, $to] = $this->range();

        // Load every job assigned to this driver where an advance was
        // issued in the window.  excludingTransferredAdvances() drops
        // the receiving half of a transferred advance so the same
        // physical cash-out is not counted twice.
        //
        // We filter on advance_issued_at (not scheduled_date) so an
        // advance issued today for a trip scheduled next month shows
        // up in today's audit view.  Cash-out date is what matters.
        $jobs = Job::query()
            ->where('driver_user_id', $this->user->id)
            ->whereNotNull('advance_issued_at')
            ->whereBetween('advance_issued_at', [$from, $to])
            ->excludingTransferredAdvances()
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'advanceIssuedBy:id,name',
                'issuedCancellationClearedBy:id,name',
                'advanceTransferredToJob:id,job_number',
            ])
            ->orderByDesc('advance_issued_at')
            ->get();

        // Load every slip the driver captured in the window, grouped
        // by category and summed in rand.  This is the reconciliation
        // side of the ledger -- what the driver claims to have spent
        // the advances on.  We bucket into the same four categories
        // the advance breakdown uses, plus a bag for everything else.
        $slipAggregate = PettyCashEntry::query()
            ->forDriver($this->user)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', PettyCashEntry::STATUS_REJECTED)
            ->groupBy('category')
            ->selectRaw('category, COALESCE(SUM(amount_cents), 0) AS cents_sum, COUNT(*) AS slip_count')
            ->get()
            ->keyBy('category');

        $slipTotalByCategory = [
            'tolls'         => (float) ($slipAggregate->get(PettyCashEntry::CATEGORY_TOLL)->cents_sum ?? 0) / 100,
            'accommodation' => (float) ($slipAggregate->get(PettyCashEntry::CATEGORY_ACCOMMODATION)->cents_sum ?? 0) / 100,
            'food'          => (float) ($slipAggregate->get(PettyCashEntry::CATEGORY_FOOD)->cents_sum ?? 0) / 100,
            // Taxi has NO corresponding slip category -- ops policy is
            // "no slip needed" for taxi, which is precisely the skim
            // vector this screen is designed to surface.
            'taxi'          => 0.0,
            // Catch-all for parking / fuel / other slips not tied to
            // the four advance buckets.  Shown separately so the
            // issued-vs-slipped math stays honest.
            'other'         => (float) (
                    ($slipAggregate->get(PettyCashEntry::CATEGORY_FUEL)->cents_sum ?? 0)
                    + ($slipAggregate->get(PettyCashEntry::CATEGORY_PARKING)->cents_sum ?? 0)
                    + ($slipAggregate->get(PettyCashEntry::CATEGORY_OTHER)->cents_sum ?? 0)
                ) / 100,
        ];
        $slipCountByCategory = [
            'tolls'         => (int) ($slipAggregate->get(PettyCashEntry::CATEGORY_TOLL)->slip_count ?? 0),
            'accommodation' => (int) ($slipAggregate->get(PettyCashEntry::CATEGORY_ACCOMMODATION)->slip_count ?? 0),
            'food'          => (int) ($slipAggregate->get(PettyCashEntry::CATEGORY_FOOD)->slip_count ?? 0),
            'taxi'          => 0,
            'other'         => (int) (
                    ($slipAggregate->get(PettyCashEntry::CATEGORY_FUEL)->slip_count ?? 0)
                    + ($slipAggregate->get(PettyCashEntry::CATEGORY_PARKING)->slip_count ?? 0)
                    + ($slipAggregate->get(PettyCashEntry::CATEGORY_OTHER)->slip_count ?? 0)
                ),
        ];

        // Aggregate the issued-side totals, broken down the same way.
        $issuedByCategory = [
            'tolls'         => (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_tolls ?? 0)),
            'accommodation' => (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_accommodation ?? 0)),
            'food'          => (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_food ?? 0)),
            'taxi'          => (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_taxi ?? 0)),
        ];

        // Per-category reconciliation rows for the "where did it go?"
        // card grid.  Each category has its own semantic -- see the
        // class-level docblock for the business rules.
        $categoryRows = [
            [
                'key'       => 'tolls',
                'label'     => 'Tolls',
                'kind'      => 'evidence',
                'issued'    => $issuedByCategory['tolls'],
                'slipped'   => $slipTotalByCategory['tolls'],
                'slips'     => $slipCountByCategory['tolls'],
                'variance'  => $issuedByCategory['tolls'] - $slipTotalByCategory['tolls'],
                'note'      => 'Auto-calculated from route (toll plazas detected on the pickup→delivery pair). Driver didn\'t request this -- the number comes from the system. Slip evidence is proof-of-payment; variance here is a housekeeping gap, not a skim concern.',
            ],
            [
                'key'       => 'accommodation',
                'label'     => 'Accommodation',
                'kind'      => 'evidence',
                'issued'    => $issuedByCategory['accommodation'],
                'slipped'   => $slipTotalByCategory['accommodation'],
                'slips'     => $slipCountByCategory['accommodation'],
                'variance'  => $issuedByCategory['accommodation'] - $slipTotalByCategory['accommodation'],
                'note'      => 'Overnight stays. Driver books a bed, slip proves it. Variance means cash out but no stay logged -- worth questioning.',
            ],
            [
                'key'       => 'food',
                'label'     => 'Food (per diem)',
                'kind'      => 'per_diem',
                'issued'    => $issuedByCategory['food'],
                'slipped'   => 0.0,  // per diem allowance -- NO slip reconciliation
                'slips'     => 0,
                'variance'  => 0.0,  // zero by definition; shown for context only
                'note'      => 'Fixed per diem allowance (R/day). Drivers do NOT submit food slips -- this is an allowance, not an expense. Shown for context only; variance is not a meaningful signal here.',
            ],
            [
                'key'       => 'taxi',
                'label'     => 'Taxi',
                'kind'      => 'skim_vector',
                'issued'    => $issuedByCategory['taxi'],
                'slipped'   => 0.0,  // no-slip-needed policy
                'slips'     => 0,
                'variance'  => $issuedByCategory['taxi'],
                'note'      => 'Only when ops judges a cab is needed. No slip expected. Staff concern: taxi money has been given out when it wasn\'t actually needed. Every taxi advance should be verifiable against a real need -- review the per-trip list below.',
                'is_skim_risk' => true,
            ],
        ];

        // Totals for the summary strip.
        $issuedTotal   = (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_total ?? 0));
        $slippedTotal  = (float) collect($slipTotalByCategory)->sum();

        // The ONE honest "unaccounted-for" variance: tolls + accom
        // issued minus their slips.  Food is per diem (no slips) and
        // taxi is no-slip-policy, so neither can produce a meaningful
        // variance -- including them would inflate the number with
        // things that are never slipped by design.
        $evidenceVariance = ($issuedByCategory['tolls'] - $slipTotalByCategory['tolls'])
            + ($issuedByCategory['accommodation'] - $slipTotalByCategory['accommodation']);

        // Taxi exposure headline: total rand of taxi advances in the
        // window.  Not a variance (there's nothing to compare it
        // against) -- it's just "how much untraceable cash went out
        // through the taxi line".  High number = investigate.
        $taxiExposure = $issuedByCategory['taxi'];

        // Trip-status counts for the summary strip.
        $tripsCompleted = $jobs
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->count();
        $tripsCancelled = $jobs->where('status', Job::STATUS_CANCELLED)->count();
        $tripsOpen = $jobs
            ->whereNotIn('status', [
                Job::STATUS_DELIVERED,
                Job::STATUS_COMPLETED,
                Job::STATUS_INVOICED,
                Job::STATUS_CANCELLED,
            ])
            ->count();

        // Open cancellation queries (cash out, no reconciliation
        // explanation yet) -- highlighted at the top as the
        // "unaccounted-for" callout.
        $openQueries = $jobs
            ->filter(fn (Job $j) =>
                $j->status === Job::STATUS_CANCELLED
                && is_null($j->issued_cancellation_cleared_at)
                && (float) ($j->advance_total ?? 0) > 0
            )
            ->values();
        $openQueriesTotal = (float) $openQueries->sum(fn (Job $j) => (float) $j->advance_total);

        // Pattern flag: taxi advances issued to this driver.  Ops has
        // flagged taxi as the known skim vector (they've been giving
        // guys taxi money when it wasn't needed), so even a modest
        // frequency is worth flagging.
        $taxiTrips = $jobs
            ->filter(fn (Job $j) => (float) ($j->advance_taxi ?? 0) > 0)
            ->values();
        $tripsWithTaxiAdvance = $taxiTrips->count();
        $taxiFrequencyPct = $jobs->count() > 0
            ? round(($tripsWithTaxiAdvance / $jobs->count()) * 100, 1)
            : 0.0;

        // Issuer breakdown: group the window's advances by the ops
        // person who physically handed over the cash (advance_issued_by)
        // so the boss can spot patterns like "this ops person issues
        // taxi to this driver every time".  Collusion detection -- if
        // one ops person is consistently the one approving suspicious
        // advances to the same driver that's the pattern to see.
        //
        // Sorted by total rand issued descending so the biggest
        // enabler floats to the top.  Unknown issuer (NULL
        // advance_issued_by_user_id -- unusual but possible if the
        // cash was issued pre-column-creation) buckets as "Unknown".
        $issuerBreakdown = $jobs
            ->groupBy(fn (Job $j) => $j->advance_issued_by_user_id ?? 0)
            ->map(function ($group, $issuerId) {
                $firstJob = $group->first();
                $taxiCount = $group->filter(fn (Job $j) => (float) ($j->advance_taxi ?? 0) > 0)->count();
                $totalIssued = (float) $group->sum(fn (Job $j) => (float) ($j->advance_total ?? 0));
                $taxiIssued  = (float) $group->sum(fn (Job $j) => (float) ($j->advance_taxi ?? 0));
                return [
                    'issuer_id'     => $issuerId > 0 ? $issuerId : null,
                    'issuer_name'   => $issuerId > 0
                        ? ($firstJob->advanceIssuedBy?->name ?? 'User #' . $issuerId . ' (deleted)')
                        : 'Unknown / pre-tracking',
                    'advance_count' => $group->count(),
                    'total_issued'  => $totalIssued,
                    'taxi_issued'   => $taxiIssued,
                    'taxi_count'    => $taxiCount,
                    'taxi_pct'      => $totalIssued > 0 ? round(($taxiIssued / $totalIssued) * 100, 1) : 0.0,
                    'taxi_freq_pct' => $group->count() > 0 ? round(($taxiCount / $group->count()) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('total_issued')
            ->values();

        return [
            'jobs'               => $jobs,
            'from'               => $from,
            'to'                 => $to,
            'issuedTotal'        => $issuedTotal,
            'slippedTotal'       => $slippedTotal,
            'evidenceVariance'   => $evidenceVariance,
            'taxiExposure'       => $taxiExposure,
            'categoryRows'       => $categoryRows,
            'tripsCompleted'     => $tripsCompleted,
            'tripsCancelled'     => $tripsCancelled,
            'tripsOpen'          => $tripsOpen,
            'openQueries'        => $openQueries,
            'openQueriesTotal'   => $openQueriesTotal,
            'taxiTrips'          => $taxiTrips,
            'tripsWithTaxiAdvance' => $tripsWithTaxiAdvance,
            'taxiFrequencyPct'   => $taxiFrequencyPct,
            'issuerBreakdown'    => $issuerBreakdown,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Cash audit: {{ $user->name }}</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    {{-- Header card: driver meta + date filter + headline numbers --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <div class="space-y-0.5">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $user->name }}</h2>
                    <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-rose-700">
                        Forensic audit
                    </span>
                </div>
                <p class="text-xs text-slate-500">
                    Issued-vs-slipped reconciliation across every advance ever given to this driver.
                    Positive variance in a category means cash the driver received hasn't been accounted for.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.drivers.payslip', ['user' => $user->id]) }}"
                    class="text-xs font-medium text-slate-500 hover:text-slate-900">
                    &larr; Monthly payslip
                </a>
            </div>
        </div>

        {{-- Date-range filter --}}
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-3">
            <label class="flex flex-col gap-1 text-xs text-slate-600">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">From</span>
                <input type="date" wire:model.live="from" class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </label>
            <label class="flex flex-col gap-1 text-xs text-slate-600">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">To</span>
                <input type="date" wire:model.live="to" class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </label>
            <div class="flex flex-wrap items-center gap-1">
                <button type="button" wire:click="applyRange('this_month')" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">This month</button>
                <button type="button" wire:click="applyRange('last_month')" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">Last month</button>
                <button type="button" wire:click="applyRange('last_90_days')" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">Last 90 days</button>
                <button type="button" wire:click="applyRange('ytd')" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">YTD</button>
                <button type="button" wire:click="applyRange('all_time')" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50">All time</button>
            </div>
            <div class="ml-auto text-[11px] text-slate-500">
                Showing {{ $from->format('d M Y') }} &rarr; {{ $to->format('d M Y') }}
            </div>
        </div>

        {{-- Headline totals.  Two different "concern" numbers:
             1. evidenceVariance = tolls + accommodation issued minus
                their slips.  Only these two categories expect slips,
                so this is the honest "cash out, no proof" number.
             2. taxiExposure = total taxi advances.  Not a variance
                (no slips expected by policy), but the number that
                ops has specifically asked to be scrutinised. --}}
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-4 sm:grid-cols-6">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Cash issued</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">R {{ number_format($issuedTotal, 2) }}</p>
                <p class="mt-0.5 text-[10px] text-amber-700">across {{ $jobs->count() }} advance{{ $jobs->count() === 1 ? '' : 's' }}</p>
            </div>
            <div class="rounded-lg border {{ $taxiExposure > 0 ? 'border-rose-300 bg-rose-100' : 'border-slate-200 bg-white' }} p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider {{ $taxiExposure > 0 ? 'text-rose-800' : 'text-slate-600' }}">Taxi exposure</p>
                <p class="mt-1 text-lg font-bold tabular-nums {{ $taxiExposure > 0 ? 'text-rose-900' : 'text-slate-800' }}">R {{ number_format($taxiExposure, 2) }}</p>
                <p class="mt-0.5 text-[10px] {{ $taxiExposure > 0 ? 'text-rose-700' : 'text-slate-500' }}">
                    untraceable &middot; review each one
                </p>
            </div>
            <div class="rounded-lg border {{ $evidenceVariance > 0 ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }} p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider {{ $evidenceVariance > 0 ? 'text-amber-800' : 'text-emerald-800' }}">Toll + accom variance</p>
                <p class="mt-1 text-lg font-bold tabular-nums {{ $evidenceVariance > 0 ? 'text-amber-900' : 'text-emerald-900' }}">R {{ number_format($evidenceVariance, 2) }}</p>
                <p class="mt-0.5 text-[10px] {{ $evidenceVariance > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                    issued &minus; slipped
                </p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-800">Delivered</p>
                <p class="mt-1 text-lg font-bold text-emerald-900 tabular-nums">{{ $tripsCompleted }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-600">Cancelled</p>
                <p class="mt-1 text-lg font-bold text-slate-800 tabular-nums">{{ $tripsCancelled }}</p>
            </div>
            <div class="rounded-lg border {{ $openQueriesTotal > 0 ? 'border-rose-300 bg-rose-100' : 'border-slate-200 bg-white' }} p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider {{ $openQueriesTotal > 0 ? 'text-rose-800' : 'text-slate-600' }}">Open queries</p>
                <p class="mt-1 text-lg font-bold tabular-nums {{ $openQueriesTotal > 0 ? 'text-rose-900' : 'text-slate-800' }}">R {{ number_format($openQueriesTotal, 2) }}</p>
                <p class="mt-0.5 text-[10px] {{ $openQueriesTotal > 0 ? 'text-rose-700' : 'text-slate-500' }}">
                    {{ $openQueries->count() }} cancelled, cash unaccounted
                </p>
            </div>
        </div>
    </div>

    {{-- Per-category breakdown: four cards, each framed by how that
         category actually works in the business.  Not every category
         is a reconciliation line -- food is per diem, taxi is a
         judgement call.  Framing is in each card's "note". --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Breakdown by category &middot; what the money was for</h3>
            <p class="text-xs text-slate-500">
                Tolls and accommodation expect slip evidence; food is a fixed per diem allowance;
                taxi is ops-judgment only. Each category is read differently &mdash; see the note on each card.
            </p>
        </div>
        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($categoryRows as $cat)
                @php
                    $kind    = $cat['kind'];
                    $isTaxi  = $kind === 'skim_vector';
                    $isPerDiem = $kind === 'per_diem';
                    $isEvidence = $kind === 'evidence';
                    $hasVariance = $isEvidence && $cat['variance'] > 0.01;
                    $pct = $cat['issued'] > 0 ? round(($cat['variance'] / $cat['issued']) * 100, 1) : 0;

                    // Border / background per card role
                    $cardCls = $isTaxi
                        ? 'border-rose-300 bg-rose-50'
                        : ($hasVariance
                            ? 'border-amber-300 bg-amber-50'
                            : ($isPerDiem ? 'border-slate-200 bg-slate-50' : 'border-slate-200 bg-slate-50'));
                @endphp
                <div class="rounded-lg border {{ $cardCls }} p-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider {{ $isTaxi ? 'text-rose-800' : ($hasVariance ? 'text-amber-800' : 'text-slate-600') }}">
                            {{ $cat['label'] }}
                        </p>
                        @if($isTaxi)
                            <span class="rounded-full border border-rose-300 bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-rose-800">Skim risk</span>
                        @elseif($isPerDiem)
                            <span class="rounded-full border border-slate-300 bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-700">Allowance</span>
                        @elseif($hasVariance)
                            <span class="rounded-full border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-amber-800">Unslipped</span>
                        @elseif($cat['issued'] > 0)
                            <span class="rounded-full border border-emerald-300 bg-emerald-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-emerald-800">Reconciled</span>
                        @endif
                    </div>
                    <div class="mt-2 space-y-1 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Issued</span>
                            <span class="font-semibold tabular-nums text-slate-900">R {{ number_format($cat['issued'], 2) }}</span>
                        </div>
                        @if($isEvidence)
                            <div class="flex justify-between">
                                <span class="text-slate-500">Slipped</span>
                                <span class="font-semibold tabular-nums text-slate-700">R {{ number_format($cat['slipped'], 2) }}</span>
                            </div>
                            <div class="flex justify-between border-t border-slate-200 pt-1 {{ $hasVariance ? 'text-amber-800' : 'text-emerald-700' }}">
                                <span class="font-semibold">Variance</span>
                                <span class="font-bold tabular-nums">
                                    R {{ number_format($cat['variance'], 2) }}
                                    @if($cat['issued'] > 0)
                                        <span class="text-[10px] font-normal">({{ $pct }}%)</span>
                                    @endif
                                </span>
                            </div>
                        @elseif($isPerDiem)
                            <div class="flex justify-between border-t border-slate-200 pt-1 text-slate-600">
                                <span class="font-semibold">Reconciliation</span>
                                <span class="text-[11px] italic">Not applicable</span>
                            </div>
                        @elseif($isTaxi)
                            <div class="flex justify-between border-t border-rose-200 pt-1 text-rose-800">
                                <span class="font-semibold">Untraceable</span>
                                <span class="font-bold tabular-nums">R {{ number_format($cat['issued'], 2) }}</span>
                            </div>
                        @endif
                        <p class="text-[10px] text-slate-600 pt-1">{{ $cat['note'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Taxi frequency pattern flag.  Staff concern is specifically
             that taxi money has been handed out when not needed, so
             the thresholds are deliberately strict: any taxi usage at
             all is already Worth-reviewing, elevated frequency gets
             a red flag. --}}
        @if($jobs->count() > 0)
            <div class="border-t border-slate-100 px-5 py-3 text-xs">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <strong class="text-slate-800">Taxi advance pattern:</strong>
                        <span class="text-slate-600">
                            {{ $tripsWithTaxiAdvance }} of {{ $jobs->count() }} advances
                            ({{ $taxiFrequencyPct }}%) included a taxi allocation.
                        </span>
                    </div>
                    @if($taxiFrequencyPct >= 20)
                        <span class="rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700">
                            Elevated &mdash; investigate pattern
                        </span>
                    @elseif($taxiFrequencyPct > 0)
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                            Review each &mdash; see list below
                        </span>
                    @else
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                            No taxi advances in this window
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Taxi-advance line-item list (every rand of the skim-vector
         category laid out trip by trip, so an auditor can click through
         and ask ops "why did this one need a cab?"). --}}
    @if($taxiTrips->isNotEmpty())
        <div class="rounded-xl border border-rose-200 bg-rose-50/50 shadow-sm">
            <div class="border-b border-rose-200 px-5 py-3">
                <h3 class="text-sm font-semibold text-rose-900">Taxi advances &middot; review each one</h3>
                <p class="text-xs text-rose-800">
                    Every rand of taxi cash issued to {{ $user->name }} in the window.
                    Each entry should be verifiable against a real operational need (driver had to cab back from a drop-off, etc.).
                    No slip evidence exists for these amounts.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-rose-100 text-[10px] uppercase tracking-wide text-rose-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Issued</th>
                            <th class="px-3 py-2 text-left">Job #</th>
                            <th class="px-3 py-2 text-left">Route</th>
                            <th class="px-3 py-2 text-right">Taxi amount</th>
                            <th class="px-3 py-2 text-left">Trip status</th>
                            <th class="px-3 py-2 text-left">Issued by</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rose-200">
                        @foreach($taxiTrips as $job)
                            <tr>
                                <td class="px-3 py-2 text-rose-900">{{ $job->advance_issued_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-[11px]">
                                    <a href="{{ route('admin.orders.show', $job) }}" class="text-blue-700 hover:underline">{{ $job->job_number }}</a>
                                </td>
                                <td class="px-3 py-2 text-rose-900">
                                    {{ $job->pickupLocation?->shortDisplay() ?? '—' }} &rarr; {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-bold text-rose-900">
                                    R {{ number_format((float) $job->advance_taxi, 2) }}
                                </td>
                                <td class="px-3 py-2">
                                    @php
                                        $cancelled = $job->status === Job::STATUS_CANCELLED;
                                        $delivered = in_array($job->status, [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED], true);
                                    @endphp
                                    @if($delivered)
                                        <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Delivered</span>
                                    @elseif($cancelled)
                                        <span class="inline-flex rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700">Cancelled</span>
                                    @else
                                        <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                            {{ $job->phase1StatusLabel() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-rose-900">{{ $job->advanceIssuedBy?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-rose-100 text-[11px] font-semibold text-rose-900">
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right">Total taxi exposure</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($taxiExposure, 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- Open queries callout, if any --}}
    @if($openQueries->isNotEmpty())
        <div class="rounded-xl border border-rose-300 bg-rose-50 shadow-sm">
            <div class="border-b border-rose-200 px-5 py-3">
                <h3 class="text-sm font-semibold text-rose-900">Unaccounted-for cash &middot; cancelled trips with open queries</h3>
                <p class="text-xs text-rose-700">
                    Trips below were cancelled with cash already issued, and nobody has signed off an explanation of where the money went.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-rose-100 text-[10px] uppercase tracking-wide text-rose-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Cancelled</th>
                            <th class="px-3 py-2 text-left">Job #</th>
                            <th class="px-3 py-2 text-left">Collection &rarr; delivery</th>
                            <th class="px-3 py-2 text-right">Cash out</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                            <th class="px-3 py-2 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rose-200">
                        @foreach($openQueries as $job)
                            <tr>
                                <td class="px-3 py-2 text-rose-900">{{ $job->cancelled_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-[11px] text-rose-900">
                                    <a href="{{ route('admin.orders.show', $job) }}" class="text-blue-700 hover:underline">{{ $job->job_number }}</a>
                                </td>
                                <td class="px-3 py-2 text-rose-900">
                                    {{ $job->pickupLocation?->shortDisplay() ?? '—' }} &rarr; {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-rose-900">
                                    R {{ number_format((float) $job->advance_total, 2) }}
                                </td>
                                <td class="px-3 py-2 text-rose-800">{{ $job->cancellation_reason ?? '—' }}</td>
                                <td class="px-3 py-2 text-center">
                                    <a href="{{ route('admin.petty-cash.reconciliation', ['openTransfer' => $job->id]) }}"
                                        class="text-[11px] font-medium text-blue-700 hover:underline">
                                        Resolve
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-rose-100 text-[11px] font-semibold text-rose-900">
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right">Total unaccounted</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($openQueriesTotal, 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- Issuer breakdown: who in ops is physically handing cash over
         to this driver, and how often is it taxi?  Collusion pattern
         detector -- if one ops person consistently issues suspicious
         amounts to the same driver, it floats to the top of this
         table. --}}
    @if($issuerBreakdown->isNotEmpty())
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Who issued the cash?</h3>
                <p class="text-xs text-slate-500">
                    Ops people who physically handed cash to {{ $user->name }} in the window, sorted by total rand issued.
                    Elevated taxi frequency from a single issuer-to-driver pairing is the collusion pattern to watch for.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Issuer</th>
                            <th class="px-3 py-2 text-right">Advances</th>
                            <th class="px-3 py-2 text-right">Total issued</th>
                            <th class="px-3 py-2 text-right">Taxi issued</th>
                            <th class="px-3 py-2 text-right">Taxi share</th>
                            <th class="px-3 py-2 text-left">Pattern</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($issuerBreakdown as $row)
                            @php
                                // Pattern flag thresholds mirror the per-driver taxi
                                // ones below.  "Elevated" = this ops person has given
                                // this driver taxi money on >= 20% of their advances.
                                $isElevated = $row['taxi_freq_pct'] >= 20;
                                $isWatch    = !$isElevated && $row['taxi_count'] > 0;
                            @endphp
                            <tr class="{{ $isElevated ? 'bg-rose-50/60' : ($isWatch ? 'bg-amber-50/40' : '') }}">
                                <td class="px-3 py-2 font-medium text-slate-900">
                                    {{ $row['issuer_name'] }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                    {{ $row['advance_count'] }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-amber-800">
                                    R {{ number_format($row['total_issued'], 2) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $row['taxi_issued'] > 0 ? 'font-bold text-rose-700' : 'text-slate-400' }}">
                                    @if($row['taxi_issued'] > 0)
                                        R {{ number_format($row['taxi_issued'], 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $row['taxi_count'] > 0 ? 'text-rose-700' : 'text-slate-400' }}">
                                    @if($row['taxi_count'] > 0)
                                        {{ $row['taxi_count'] }} of {{ $row['advance_count'] }}
                                        <span class="text-[10px] font-normal">({{ $row['taxi_freq_pct'] }}%)</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($isElevated)
                                        <span class="inline-flex items-center rounded-full border border-rose-300 bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-800">
                                            Elevated taxi frequency
                                        </span>
                                    @elseif($isWatch)
                                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                            Has issued taxi &mdash; verify
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                            Clean
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-[11px] font-semibold text-slate-700">
                        <tr>
                            <td class="px-3 py-2 text-right">Totals</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $issuerBreakdown->sum('advance_count') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-amber-800">R {{ number_format($issuerBreakdown->sum('total_issued'), 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-rose-700">R {{ number_format($issuerBreakdown->sum('taxi_issued'), 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-500">
                <p>
                    Elevated taxi frequency = this ops person has included a taxi allocation on 20%+ of the advances
                    they issued to {{ $user->name }}.  Cross-reference with other drivers' audit pages to spot an
                    issuer with the same pattern across multiple drivers.
                </p>
            </div>
        </div>
    @endif

    {{-- Per-trip detail: every advance ever issued to this driver in the window --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Per-trip ledger</h3>
            <p class="text-xs text-slate-500">
                Every advance issued in the window, newest first.  Columns show how the total was budgeted
                across tolls / accommodation / taxi / food, plus the current trip status.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Issued</th>
                        <th class="px-3 py-2 text-left">Job #</th>
                        <th class="px-3 py-2 text-left">Route</th>
                        <th class="px-3 py-2 text-right">Tolls</th>
                        <th class="px-3 py-2 text-right">Accom.</th>
                        <th class="px-3 py-2 text-right">Taxi</th>
                        <th class="px-3 py-2 text-right">Food</th>
                        <th class="px-3 py-2 text-right">Total</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Issued by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($jobs as $job)
                        @php
                            $tollsVal = (float) ($job->advance_tolls ?? 0);
                            $accomVal = (float) ($job->advance_accommodation ?? 0);
                            $taxiVal  = (float) ($job->advance_taxi ?? 0);
                            $foodVal  = (float) ($job->advance_food ?? 0);
                            $total    = (float) ($job->advance_total ?? 0);
                            $isCancelled   = $job->status === Job::STATUS_CANCELLED;
                            $isDelivered   = in_array($job->status, [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED], true);
                            $isCancelledOpen = $isCancelled && is_null($job->issued_cancellation_cleared_at);
                        @endphp
                        <tr class="{{ $isCancelledOpen ? 'bg-rose-50/60' : ($taxiVal > 0 ? 'bg-amber-50/40' : '') }}">
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->advance_issued_at?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 font-mono text-[11px] text-slate-700">
                                <a href="{{ route('admin.orders.show', $job) }}" class="text-blue-600 hover:underline">{{ $job->job_number }}</a>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                {{ $job->pickupLocation?->shortDisplay() ?? '—' }}
                                &rarr; {{ $job->deliveryLocation?->shortDisplay() ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                {{ $tollsVal > 0 ? 'R ' . number_format($tollsVal, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                {{ $accomVal > 0 ? 'R ' . number_format($accomVal, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $taxiVal > 0 ? 'font-semibold text-rose-700' : 'text-slate-400' }}">
                                {{ $taxiVal > 0 ? 'R ' . number_format($taxiVal, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                {{ $foodVal > 0 ? 'R ' . number_format($foodVal, 2) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums font-bold text-amber-800">
                                R {{ number_format($total, 2) }}
                            </td>
                            <td class="px-3 py-2">
                                @if($isDelivered)
                                    <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                        Delivered
                                    </span>
                                @elseif($isCancelledOpen)
                                    <span class="inline-flex rounded-full border border-rose-300 bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-800">
                                        Cancelled &middot; open query
                                    </span>
                                @elseif($isCancelled)
                                    <span class="inline-flex rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700">
                                        Cancelled &middot; cleared
                                    </span>
                                    @if($job->advanceTransferredToJob)
                                        <div class="mt-0.5 text-[10px] text-blue-700">
                                            &rarr; {{ $job->advanceTransferredToJob->job_number }}
                                        </div>
                                    @endif
                                @else
                                    <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                                        {{ $job->phase1StatusLabel() }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-600">
                                {{ $job->advanceIssuedBy?->name ?? '—' }}
                                @if($job->advance_issue_reference)
                                    <div class="text-[10px] text-slate-400">{{ $job->advance_issue_reference }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-10 text-center text-sm text-slate-500">
                                No advances issued to {{ $user->name }} in this window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($jobs->isNotEmpty())
                    <tfoot class="bg-slate-50 text-[11px] font-semibold text-slate-700">
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right">Totals</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($categoryRows[0]['issued'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($categoryRows[1]['issued'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-rose-700">R {{ number_format($categoryRows[3]['issued'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($categoryRows[2]['issued'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-amber-800">R {{ number_format($issuedTotal, 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-500">
            <p>
                Amber row = advance includes a taxi allocation (no-slip-required category).
                Red row = cancelled with an open reconciliation query.
                Totals row aggregates every rand of advance in the window broken down by purpose.
            </p>
        </div>
    </div>
</div>
