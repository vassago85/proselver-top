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
 * ever issued to the driver, broken down by category (tolls /
 * accommodation / taxi / food), reconciled against the slips the
 * driver actually submitted.  Variance > 0 means cash is unaccounted
 * for; variance that's consistently high in a given category is the
 * tell for collusion between ops and the driver.
 *
 * The known skim vector is TAXI: ops policy is "no slip needed" for
 * taxi, so a R500 taxi advance on every trip is R500 of untraceable
 * cash in the driver's pocket -- unless we surface the pattern.  This
 * page flags it explicitly.
 *
 * Access: accounts / owner / developer only -- mount() 403s everyone
 * else, same gate as the payslip.
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
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'The cash audit is restricted to accounts.');
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
        // card grid.  Variance = issued - slipped; positive means cash
        // the driver got but hasn't accounted for.
        $categoryRows = [
            [
                'key'      => 'tolls',
                'label'    => 'Tolls',
                'issued'   => $issuedByCategory['tolls'],
                'slipped'  => $slipTotalByCategory['tolls'],
                'slips'    => $slipCountByCategory['tolls'],
                'variance' => $issuedByCategory['tolls'] - $slipTotalByCategory['tolls'],
                'note'     => 'Toll plazas on route. Variance should be near R0.',
            ],
            [
                'key'      => 'accommodation',
                'label'    => 'Accommodation',
                'issued'   => $issuedByCategory['accommodation'],
                'slipped'  => $slipTotalByCategory['accommodation'],
                'slips'    => $slipCountByCategory['accommodation'],
                'variance' => $issuedByCategory['accommodation'] - $slipTotalByCategory['accommodation'],
                'note'     => 'Overnight stays. Slip evidence required.',
            ],
            [
                'key'      => 'food',
                'label'    => 'Food',
                'issued'   => $issuedByCategory['food'],
                'slipped'  => $slipTotalByCategory['food'],
                'slips'    => $slipCountByCategory['food'],
                'variance' => $issuedByCategory['food'] - $slipTotalByCategory['food'],
                'note'     => 'Meal allowance. Slip evidence required.',
            ],
            [
                'key'      => 'taxi',
                'label'    => 'Taxi',
                'issued'   => $issuedByCategory['taxi'],
                'slipped'  => 0.0, // no slip category -- "no slip needed" policy
                'slips'    => 0,
                'variance' => $issuedByCategory['taxi'], // ALL of it is "variance" by definition
                'note'     => 'KNOWN SKIM VECTOR: ops policy is "no slip needed" for taxi, so every rand issued here is untraceable. Repeated taxi advances with no operational reason are the pattern to watch for.',
                'is_skim_risk' => true,
            ],
        ];

        // Totals for the summary strip.
        $issuedTotal = (float) $jobs->sum(fn (Job $j) => (float) ($j->advance_total ?? 0));
        $slippedTotal = (float) collect($slipTotalByCategory)->sum();
        // Taxi variance pulled out because it's the "always 100%
        // untraced" baseline -- the headline variance should exclude
        // it so other variances stand out.
        $slippableVariance = ($issuedTotal - $issuedByCategory['taxi']) - $slippedTotal;

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

        // Pattern flag: taxi advances issued to this driver.  If the
        // frequency is high (more than, say, 25% of all advances
        // include a taxi slice) that's worth questioning given the
        // "no slip needed" exposure.
        $tripsWithTaxiAdvance = $jobs
            ->filter(fn (Job $j) => (float) ($j->advance_taxi ?? 0) > 0)
            ->count();
        $taxiFrequencyPct = $jobs->count() > 0
            ? round(($tripsWithTaxiAdvance / $jobs->count()) * 100, 1)
            : 0.0;

        return [
            'jobs'               => $jobs,
            'from'               => $from,
            'to'                 => $to,
            'issuedTotal'        => $issuedTotal,
            'slippedTotal'       => $slippedTotal,
            'slippableVariance'  => $slippableVariance,
            'categoryRows'       => $categoryRows,
            'tripsCompleted'     => $tripsCompleted,
            'tripsCancelled'     => $tripsCancelled,
            'tripsOpen'          => $tripsOpen,
            'openQueries'        => $openQueries,
            'openQueriesTotal'   => $openQueriesTotal,
            'tripsWithTaxiAdvance' => $tripsWithTaxiAdvance,
            'taxiFrequencyPct'   => $taxiFrequencyPct,
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

        {{-- Headline totals --}}
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-4 sm:grid-cols-6">
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Cash issued</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">R {{ number_format($issuedTotal, 2) }}</p>
                <p class="mt-0.5 text-[10px] text-amber-700">across {{ $jobs->count() }} advance{{ $jobs->count() === 1 ? '' : 's' }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-600">Slips submitted</p>
                <p class="mt-1 text-lg font-bold text-slate-800 tabular-nums">R {{ number_format($slippedTotal, 2) }}</p>
                <p class="mt-0.5 text-[10px] text-slate-500">reconciliation evidence</p>
            </div>
            <div class="rounded-lg border {{ $slippableVariance > 0 ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50' }} p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider {{ $slippableVariance > 0 ? 'text-rose-800' : 'text-emerald-800' }}">Variance (ex-taxi)</p>
                <p class="mt-1 text-lg font-bold tabular-nums {{ $slippableVariance > 0 ? 'text-rose-900' : 'text-emerald-900' }}">R {{ number_format($slippableVariance, 2) }}</p>
                <p class="mt-0.5 text-[10px] {{ $slippableVariance > 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                    @if($slippableVariance > 0)
                        unslipped (excl. taxi)
                    @else
                        clean
                    @endif
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

    {{-- Per-category breakdown: where did the cash go? --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Breakdown by category &middot; issued vs slipped</h3>
            <p class="text-xs text-slate-500">
                Each card compares the rand value issued in that category against the rand value of slips the driver submitted.
                A positive variance means cash issued but not evidenced on paper.
            </p>
        </div>
        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($categoryRows as $cat)
                @php
                    $isTaxi = ($cat['is_skim_risk'] ?? false);
                    $isDirty = !$isTaxi && $cat['variance'] > 0.01;
                    $pct = $cat['issued'] > 0 ? round(($cat['variance'] / $cat['issued']) * 100, 1) : 0;
                @endphp
                <div class="rounded-lg border {{ $isTaxi ? 'border-rose-300 bg-rose-50' : ($isDirty ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50') }} p-3">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-wider {{ $isTaxi ? 'text-rose-800' : ($isDirty ? 'text-amber-800' : 'text-slate-600') }}">
                            {{ $cat['label'] }}
                        </p>
                        @if($isTaxi)
                            <span class="rounded-full border border-rose-300 bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-rose-800">Skim risk</span>
                        @elseif($isDirty)
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
                        <div class="flex justify-between">
                            <span class="text-slate-500">Slipped</span>
                            <span class="font-semibold tabular-nums text-slate-700">R {{ number_format($cat['slipped'], 2) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-1 {{ $isTaxi ? 'text-rose-800' : ($isDirty ? 'text-amber-800' : 'text-emerald-700') }}">
                            <span class="font-semibold">Variance</span>
                            <span class="font-bold tabular-nums">
                                R {{ number_format($cat['variance'], 2) }}
                                @if($cat['issued'] > 0 && !$isTaxi)
                                    <span class="text-[10px] font-normal">({{ $pct }}%)</span>
                                @endif
                            </span>
                        </div>
                        <p class="text-[10px] text-slate-600 pt-1">{{ $cat['note'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Taxi frequency pattern flag --}}
        @if($jobs->count() > 0)
            <div class="border-t border-slate-100 px-5 py-3 text-xs">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <strong class="text-slate-800">Taxi advance pattern:</strong>
                        <span class="text-slate-600">
                            {{ $tripsWithTaxiAdvance }} of {{ $jobs->count() }} advances
                            ({{ $taxiFrequencyPct }}%) included a taxi allocation.
                        </span>
                    </div>
                    @if($taxiFrequencyPct > 25)
                        <span class="rounded-full border border-rose-300 bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700">
                            Elevated &mdash; worth questioning
                        </span>
                    @elseif($taxiFrequencyPct > 10)
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                            Watch
                        </span>
                    @else
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                            Within policy expectation
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </div>

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
