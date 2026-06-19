<?php

use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Owner petty-cash overview.  Developer + owner roles only.
 *
 * Layout sections:
 *   1. Range chips (today / week / month / year / all).
 *   2. Snapshot strip: issued, spent, reconciled, variance — with a
 *      visual reconciliation progress bar showing how much of what's
 *      been spent has actually been paid out.
 *   3. Slip status mix — pending / approved / reimbursed / rejected
 *      as a stacked bar so the owner sees the queue health at a glance.
 *   4. Needs-attention panel — pending slips waiting on ops, drivers
 *      missing cellphones (can't be paid), trips over-budget.
 *   5. Category breakdown with inline bars.
 *   6. Top variance trips.
 *   7. Per-driver totals with bank-send readiness check.
 *   8. Scan incentive earnings.
 *
 * Read-only by design.  Drill-down links go to the order or driver
 * page rather than letting the owner change state here.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url] public string $range = 'this_month';

    /**
     * Modal state for the "Clear reconciliation query" action.  Lives on
     * the dashboard so Accounts/Owner can sign off the issued-on-cancelled
     * query without having to bounce through the order detail page.
     */
    public ?int $clearQueryJobId = null;
    public string $clearQueryNote = '';
    public bool $showClearQueryModal = false;

    public const RANGES = ['today', 'this_week', 'this_month', 'this_year', 'all'];

    public function mount(): void
    {
        // Accounts is allowed alongside owner+developer because the petty
        // cash + reconciliation queries on this page are Accounts' day-to-day
        // work (matches the new accounts-first sign-off model elsewhere).
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }
        if (!in_array($this->range, self::RANGES, true)) {
            $this->range = 'this_month';
        }
    }

    /**
     * Open the "Clear reconciliation query" modal for a specific job.
     * Gated on roles up front so a crafted Livewire call can't bypass
     * the UI guard.
     */
    public function openClearQuery(int $jobId): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }
        $this->clearQueryJobId = $jobId;
        $this->clearQueryNote = '';
        $this->showClearQueryModal = true;
    }

    public function cancelClearQuery(): void
    {
        $this->clearQueryJobId = null;
        $this->clearQueryNote = '';
        $this->showClearQueryModal = false;
    }

    /**
     * Persist the clearance + write the audit log entry.  Mirrors the
     * order-page action so either entry point produces the same trail.
     */
    public function submitClearQuery(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        $this->validate([
            'clearQueryJobId' => 'required|integer|exists:transport_jobs,id',
            'clearQueryNote'  => 'required|string|min:5|max:2000',
        ], [
            'clearQueryNote.required' => 'Please describe how the cash was reconciled.',
            'clearQueryNote.min' => 'Explanation needs to be at least 5 characters so the audit trail makes sense.',
        ]);

        $job = Job::find($this->clearQueryJobId);
        if (!$job || !$job->hasOpenIssuedCancellationQuery()) {
            session()->flash('error', 'That query is no longer open.');
            $this->cancelClearQuery();
            return;
        }

        $job->issued_cancellation_cleared_at = now();
        $job->issued_cancellation_cleared_by_user_id = $u->id;
        $job->issued_cancellation_cleared_note = trim($this->clearQueryNote);
        $job->save();

        AuditService::log('issued_cancellation_query_cleared', 'job', $job->id, null, [
            'note' => $job->issued_cancellation_cleared_note,
            'cleared_by_roles' => $u->roles->pluck('slug')->values()->all(),
            'advance_total' => (float) ($job->advance_total ?? 0),
            'source' => 'overview_dashboard',
        ]);

        session()->flash('success', "Reconciliation query cleared on {$job->job_number}.");
        $this->cancelClearQuery();
    }

    public function setRange(string $range): void
    {
        if (in_array($range, self::RANGES, true)) {
            $this->range = $range;
        }
    }

    /**
     * Build [from, to] for the current range plus [prevFrom, prevTo]
     * one window back, so every headline can show a delta vs the
     * immediately preceding period.  "all" is unbounded back so we
     * don't try to compute a meaningless previous-of-all delta.
     */
    private function window(): array
    {
        $now = now();
        return match ($this->range) {
            'today' => [
                'from' => $now->copy()->startOfDay(),  'to' => $now->copy()->endOfDay(),
                'prevFrom' => $now->copy()->subDay()->startOfDay(), 'prevTo' => $now->copy()->subDay()->endOfDay(),
                'label' => 'today vs yesterday',
            ],
            'this_week' => [
                'from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek(),
                'prevFrom' => $now->copy()->subWeek()->startOfWeek(), 'prevTo' => $now->copy()->subWeek()->endOfWeek(),
                'label' => 'this week vs last week',
            ],
            'this_year' => [
                'from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear(),
                'prevFrom' => $now->copy()->subYear()->startOfYear(), 'prevTo' => $now->copy()->subYear()->endOfYear(),
                'label' => 'this year vs last year',
            ],
            'all' => [
                'from' => null, 'to' => $now->copy()->endOfDay(),
                'prevFrom' => null, 'prevTo' => null,
                'label' => 'all time',
            ],
            default => [
                'from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth(),
                'prevFrom' => $now->copy()->subMonth()->startOfMonth(), 'prevTo' => $now->copy()->subMonth()->endOfMonth(),
                'label' => 'this month vs last month',
            ],
        };
    }

    /** Sum approved+submitted+reimbursed slips for a window (cents → rand). */
    private function spentInWindow(?Carbon $from, ?Carbon $to): float
    {
        $q = PettyCashEntry::query()
            ->whereIn('status', [PettyCashEntry::STATUS_APPROVED, PettyCashEntry::STATUS_REIMBURSED, PettyCashEntry::STATUS_SUBMITTED]);
        if ($from) $q->where('created_at', '>=', $from);
        if ($to) $q->where('created_at', '<=', $to);
        return (float) $q->sum('amount_cents') / 100;
    }

    /** Sum advances issued in a window. */
    private function issuedInWindow(?Carbon $from, ?Carbon $to): float
    {
        $q = Job::query()->whereNotNull('advance_assigned_at');
        if ($from) $q->where('advance_assigned_at', '>=', $from);
        if ($to) $q->where('advance_assigned_at', '<=', $to);
        return (float) $q->sum('advance_total');
    }

    public function with(): array
    {
        $win = $this->window();

        // Slips slice (window).
        $slipsQ = PettyCashEntry::query();
        if ($win['from']) $slipsQ->where('created_at', '>=', $win['from']);
        $slipsQ->where('created_at', '<=', $win['to']);

        // Advances slice (window).
        $advanceQ = Job::query()->whereNotNull('advance_assigned_at');
        if ($win['from']) $advanceQ->where('advance_assigned_at', '>=', $win['from']);
        $advanceQ->where('advance_assigned_at', '<=', $win['to']);

        // -- Headline numbers --
        $totalIssued = (float) (clone $advanceQ)->sum('advance_total');
        $advancesCount = (clone $advanceQ)->count();
        $totalSpent = $this->spentInWindow($win['from'], $win['to']);
        $totalReimbursed = (float) (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_REIMBURSED)
            ->sum('amount_cents') / 100;
        $totalApproved = (float) (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->sum('amount_cents') / 100;
        $totalSubmitted = (float) (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_SUBMITTED)
            ->sum('amount_cents') / 100;
        $totalRejected = (float) (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_REJECTED)
            ->sum('amount_cents') / 100;
        $reconciledPct = $totalSpent > 0 ? round(($totalReimbursed / $totalSpent) * 100, 1) : 0.0;
        $variance = round($totalSpent - $totalIssued, 2);

        // Slip count by status (drives the stacked bar).
        $statusCounts = (clone $slipsQ)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount_cents) as cents'))
            ->get()
            ->keyBy('status');

        // -- Deltas vs previous window --
        $prevIssued = $win['prevFrom'] ? $this->issuedInWindow($win['prevFrom'], $win['prevTo']) : null;
        $prevSpent = $win['prevFrom'] ? $this->spentInWindow($win['prevFrom'], $win['prevTo']) : null;

        $issuedDelta = $prevIssued !== null && $prevIssued > 0 ? round((($totalIssued - $prevIssued) / $prevIssued) * 100, 1) : null;
        $spentDelta = $prevSpent !== null && $prevSpent > 0 ? round((($totalSpent - $prevSpent) / $prevSpent) * 100, 1) : null;

        // -- Category breakdown --
        $approvedByCategory = (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->select('category', DB::raw('SUM(amount_cents) as cents'))
            ->groupBy('category')
            ->pluck('cents', 'category');

        $categorySpend = [
            'tolls'         => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_TOLL] ?? 0) / 100,
            'accommodation' => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_ACCOMMODATION] ?? 0) / 100,
            'food'          => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_FOOD] ?? 0) / 100,
            'taxi'          => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_PARKING] ?? 0) / 100,
            'fuel'          => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_FUEL] ?? 0) / 100,
            'other'         => (float) ($approvedByCategory[PettyCashEntry::CATEGORY_OTHER] ?? 0) / 100,
        ];
        $categoryIssued = [
            'tolls'         => (float) (clone $advanceQ)->sum('advance_tolls'),
            'accommodation' => (float) (clone $advanceQ)->sum('advance_accommodation'),
            'food'          => (float) (clone $advanceQ)->sum('advance_food'),
            'taxi'          => (float) (clone $advanceQ)->sum('advance_taxi'),
            'fuel'          => 0.0,
            'other'         => 0.0,
        ];
        $maxCategoryValue = max(1.0, max(array_merge(array_values($categorySpend), array_values($categoryIssued))));

        // -- Variance trips --
        $jobIdsInWindow = (clone $advanceQ)->pluck('id');
        $spendPerJob = PettyCashEntry::query()
            ->whereIn('job_id', $jobIdsInWindow)
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->groupBy('job_id')
            ->select('job_id', DB::raw('SUM(amount_cents) as cents'))
            ->pluck('cents', 'job_id');

        $topVariance = Job::query()
            ->whereIn('id', $jobIdsInWindow)
            ->with(['company:id,name', 'driver:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name'])
            ->get()
            ->map(function ($job) use ($spendPerJob) {
                $spent = (float) (($spendPerJob[$job->id] ?? 0) / 100);
                $issued = (float) ($job->advance_total ?? 0);
                $job->setAttribute('_spent', $spent);
                $job->setAttribute('_variance', round($spent - $issued, 2));
                $job->setAttribute('_overage', $spent > $issued + 0.5);
                return $job;
            })
            ->sortByDesc('_variance')
            ->take(10)
            ->values();

        $overageCount = $topVariance->where('_overage', true)->count();

        // -- Per-driver --
        $driverRollup = (clone $slipsQ)
            ->whereIn('status', [PettyCashEntry::STATUS_APPROVED, PettyCashEntry::STATUS_REIMBURSED])
            ->groupBy('driver_user_id')
            ->select('driver_user_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount_cents) as cents'))
            ->orderByDesc('cents')
            ->get();
        $driverIds = $driverRollup->pluck('driver_user_id')->filter()->values()->all();
        $drivers = User::query()
            ->whereIn('id', $driverIds)
            ->with('driverProfile:user_id,cellphone')
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');
        $perDriver = $driverRollup->map(function ($r) use ($drivers) {
            $u = $drivers->get($r->driver_user_id);
            return [
                'name' => $u?->name ?? 'Unknown',
                'phone' => $u?->phone ?: $u?->driverProfile?->cellphone,
                'count' => (int) $r->cnt,
                'total' => round($r->cents / 100, 2),
            ];
        });

        $missingPhoneCount = $perDriver->filter(fn ($r) => !$r['phone'])->count();

        // -- Scan incentive --
        $incentiveRate = (float) SystemSetting::get('slip_scan_incentive_amount', 5);
        $incentiveEnabled = (bool) SystemSetting::get('slip_scan_incentive_enabled', true);
        $approvedSlipCount = (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->count();
        $incentiveEarned = round($approvedSlipCount * $incentiveRate, 2);

        // -- Pending slip count (queue depth for ops) --
        $pendingSlipCount = (clone $slipsQ)
            ->where('status', PettyCashEntry::STATUS_SUBMITTED)
            ->count();

        // -- Open "advance issued + trip cancelled" reconciliation queries --
        // Always shown regardless of range (the reconciliation obligation
        // doesn't expire) so old open queries can't quietly drop off.
        $openQueries = Job::query()
            ->issuedCancellationQueryOpen()
            ->with([
                'company:id,name',
                'driver:id,name',
                'advanceIssuedBy:id,name',
            ])
            ->orderByDesc('cancelled_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();
        $openQueryTotal = (float) $openQueries->sum('advance_total');

        return compact(
            'win',
            'totalIssued', 'totalSpent', 'totalReimbursed', 'totalApproved', 'totalSubmitted',
            'totalRejected', 'reconciledPct', 'variance', 'advancesCount',
            'statusCounts',
            'issuedDelta', 'spentDelta',
            'categorySpend', 'categoryIssued', 'maxCategoryValue',
            'topVariance', 'overageCount',
            'perDriver', 'missingPhoneCount',
            'incentiveRate', 'incentiveEnabled', 'approvedSlipCount', 'incentiveEarned',
            'pendingSlipCount',
            'openQueries', 'openQueryTotal',
        );
    }
}; ?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <span>Petty Cash Overview</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800">Owner</span>
        </div>
    </x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    {{-- ──────────────  Range selector  ────────────── --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 text-xs">
        <span class="text-slate-500 mr-1">Range:</span>
        @foreach (['today' => 'Today', 'this_week' => 'Week', 'this_month' => 'Month', 'this_year' => 'Year', 'all' => 'All'] as $val => $lbl)
            <button type="button" wire:click="setRange('{{ $val }}')"
                    @class([
                        'rounded-full px-3 py-1 font-semibold',
                        'bg-blue-600 text-white' => $range === $val,
                        'bg-slate-100 text-slate-600 hover:bg-slate-200' => $range !== $val,
                    ])>{{ $lbl }}</button>
        @endforeach
        <span class="ml-3 text-[11px] text-slate-500">
            @if($win['from'])
                {{ $win['from']->format('d M Y') }} → {{ $win['to']->format('d M Y') }}
            @else
                Up to {{ $win['to']->format('d M Y') }}
            @endif
            <span class="ml-1 text-slate-400">· {{ $win['label'] }}</span>
        </span>
    </div>

    {{-- ──────────────  Snapshot tiles  ────────────── --}}
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        @php
            $tile = function (string $label, string $rand, string $color, ?float $delta, string $note = '') {
                $deltaTag = '';
                if ($delta !== null) {
                    $cls = $delta > 0 ? 'text-rose-600' : ($delta < 0 ? 'text-emerald-600' : 'text-slate-400');
                    $sign = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '·');
                    $deltaTag = '<span class="ml-2 text-[10px] font-semibold ' . $cls . '">' . $sign . ' ' . number_format(abs($delta), 1) . '%</span>';
                }
                return [$label, $rand, $color, $deltaTag, $note];
            };
        @endphp

        {{-- Issued --}}
        <div class="rounded-xl bg-gradient-to-br from-emerald-50 to-white border border-emerald-200 p-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <p class="text-[11px] uppercase tracking-wide text-emerald-800 font-semibold">Issued (advances)</p>
            </div>
            <p class="mt-1.5 text-2xl font-bold tabular-nums text-emerald-900">R {{ number_format($totalIssued, 2) }}
                @if($issuedDelta !== null)
                    <span class="ml-2 text-[10px] font-semibold {{ $issuedDelta > 0 ? 'text-emerald-700' : ($issuedDelta < 0 ? 'text-amber-600' : 'text-slate-400') }}">
                        {{ $issuedDelta > 0 ? '↑' : ($issuedDelta < 0 ? '↓' : '·') }} {{ number_format(abs($issuedDelta), 1) }}%
                    </span>
                @endif
            </p>
            <p class="mt-0.5 text-[11px] text-emerald-700/80">{{ $advancesCount }} {{ Str::plural('trip', $advancesCount) }}</p>
        </div>

        {{-- Spent --}}
        <div class="rounded-xl bg-gradient-to-br from-slate-50 to-white border border-slate-200 p-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>
                <p class="text-[11px] uppercase tracking-wide text-slate-700 font-semibold">Spent</p>
            </div>
            <p class="mt-1.5 text-2xl font-bold tabular-nums text-slate-900">R {{ number_format($totalSpent, 2) }}
                @if($spentDelta !== null)
                    <span class="ml-2 text-[10px] font-semibold {{ $spentDelta > 0 ? 'text-rose-600' : ($spentDelta < 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                        {{ $spentDelta > 0 ? '↑' : ($spentDelta < 0 ? '↓' : '·') }} {{ number_format(abs($spentDelta), 1) }}%
                    </span>
                @endif
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">{{ ($statusCounts[\App\Models\PettyCashEntry::STATUS_SUBMITTED]->cnt ?? 0) + ($statusCounts[\App\Models\PettyCashEntry::STATUS_APPROVED]->cnt ?? 0) + ($statusCounts[\App\Models\PettyCashEntry::STATUS_REIMBURSED]->cnt ?? 0) }} slips</p>
        </div>

        {{-- Reconciled --}}
        <div class="rounded-xl bg-gradient-to-br from-blue-50 to-white border border-blue-200 p-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-blue-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <p class="text-[11px] uppercase tracking-wide text-blue-800 font-semibold">Reconciled</p>
            </div>
            <p class="mt-1.5 text-2xl font-bold tabular-nums text-blue-900">R {{ number_format($totalReimbursed, 2) }}</p>
            <p class="mt-0.5 text-[11px] text-blue-700/80">{{ number_format($reconciledPct, 1) }}% of spend cleared</p>
            {{-- Progress bar --}}
            <div class="mt-2 h-1.5 w-full rounded-full bg-blue-100 overflow-hidden">
                <div class="h-full bg-blue-600" style="width: {{ min(100, $reconciledPct) }}%;"></div>
            </div>
        </div>

        {{-- Variance --}}
        <div class="rounded-xl bg-gradient-to-br {{ $variance > 0 ? 'from-rose-50' : ($variance < 0 ? 'from-emerald-50' : 'from-slate-50') }} to-white border {{ $variance > 0 ? 'border-rose-200' : ($variance < 0 ? 'border-emerald-200' : 'border-slate-200') }} p-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 {{ $variance > 0 ? 'text-rose-700' : ($variance < 0 ? 'text-emerald-700' : 'text-slate-700') }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    @if($variance > 0)<polyline points="6 9 12 15 18 9"/><line x1="12" x2="12" y1="3" y2="15"/>
                    @elseif($variance < 0)<polyline points="6 15 12 9 18 15"/><line x1="12" x2="12" y1="21" y2="9"/>
                    @else<line x1="5" x2="19" y1="12" y2="12"/>@endif
                </svg>
                <p class="text-[11px] uppercase tracking-wide {{ $variance > 0 ? 'text-rose-800' : ($variance < 0 ? 'text-emerald-800' : 'text-slate-700') }} font-semibold">Variance</p>
            </div>
            <p class="mt-1.5 text-2xl font-bold tabular-nums {{ $variance > 0 ? 'text-rose-900' : ($variance < 0 ? 'text-emerald-900' : 'text-slate-900') }}">
                {{ $variance > 0 ? '+' : '' }}R {{ number_format($variance, 2) }}
            </p>
            <p class="mt-0.5 text-[11px] {{ $variance > 0 ? 'text-rose-700/80' : ($variance < 0 ? 'text-emerald-700/80' : 'text-slate-500') }}">spent − issued</p>
        </div>
    </section>

    {{-- ──────────────  Needs attention  ────────────── --}}
    @php
        $alerts = [];
        if ($pendingSlipCount > 0)  $alerts[] = ['icon' => 'M12 8v4M12 16h.01', 'color' => 'amber',  'text' => "$pendingSlipCount slip" . ($pendingSlipCount === 1 ? '' : 's') . " waiting on ops to approve or reject"];
        if ($missingPhoneCount > 0) $alerts[] = ['icon' => 'M22 16.92v3a2 2 0 0 1-2.18 2', 'color' => 'rose',  'text' => "$missingPhoneCount driver" . ($missingPhoneCount === 1 ? '' : 's') . " with claims have no cellphone on file — bank-send can't be routed"];
        if ($overageCount > 0)      $alerts[] = ['icon' => 'M12 9v2m0 4h.01', 'color' => 'rose',   'text' => "$overageCount trip" . ($overageCount === 1 ? '' : 's') . " spent more than the issued advance"];
        if ($openQueries->count() > 0) $alerts[] = ['icon' => 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', 'color' => 'rose', 'text' => $openQueries->count() . ' cancelled trip' . ($openQueries->count() === 1 ? '' : 's') . " with an issued advance awaiting Accounts sign-off (R " . number_format($openQueryTotal, 2) . ')'];
    @endphp
    @if(!empty($alerts))
        <section class="mb-4 rounded-xl bg-white border border-amber-300 p-3">
            <p class="text-[11px] uppercase tracking-wide text-amber-800 font-semibold mb-2">Needs attention</p>
            <ul class="space-y-1.5">
                @foreach($alerts as $a)
                    <li class="flex items-center gap-2 text-sm">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-{{ $a['color'] }}-100 text-{{ $a['color'] }}-700">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="{{ $a['icon'] }}"/></svg>
                        </span>
                        <span class="text-slate-800">{{ $a['text'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ──────────────────────────────────────────────────────────────
         Open reconciliation queries — advance issued + trip cancelled

         Every cancelled trip whose advance had already been issued
         (cash physically out of the till) shows up here until Accounts
         or Owner records how it was reconciled.  Window-independent: a
         legitimate query from three months ago must still be cleared.
         ────────────────────────────────────────────────────────────── --}}
    @if($openQueries->count() > 0)
        <section class="mb-5 rounded-xl bg-white border-2 border-rose-300 overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3 bg-rose-50/60 border-b border-rose-200">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                    <h2 class="text-sm font-semibold text-rose-900">Reconciliation queries — advance issued, trip cancelled</h2>
                </div>
                <div class="text-xs text-rose-900/80">
                    <span class="font-semibold tabular-nums">{{ $openQueries->count() }}</span> open ·
                    <span class="font-semibold tabular-nums">R {{ number_format($openQueryTotal, 2) }}</span> outstanding
                </div>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Job number</th>
                        <th class="text-left px-4 py-2 font-medium">Customer</th>
                        <th class="text-left px-4 py-2 font-medium">Driver</th>
                        <th class="text-right px-4 py-2 font-medium">Issued</th>
                        <th class="text-left px-4 py-2 font-medium">Issued by</th>
                        <th class="text-left px-4 py-2 font-medium">Cancelled</th>
                        <th class="text-left px-4 py-2 font-medium">Reason</th>
                        <th class="text-right px-4 py-2 font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($openQueries as $q)
                        <tr class="hover:bg-rose-50/40">
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.orders.show', $q) }}" class="font-semibold text-blue-700 hover:underline">{{ $q->job_number }}</a>
                            </td>
                            <td class="px-4 py-2 text-slate-700">{{ $q->company?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ $q->driver?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums font-semibold text-rose-700">R {{ number_format((float) $q->advance_total, 2) }}</td>
                            <td class="px-4 py-2 text-slate-600 text-xs">{{ $q->advanceIssuedBy?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-600 text-xs">
                                {{ $q->cancelled_at?->format('d M Y') ?? '—' }}
                                @if($q->cancelled_at)
                                    <span class="block text-[10px] text-slate-400">{{ $q->cancelled_at->diffForHumans() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-slate-600 text-xs max-w-xs">
                                <span class="line-clamp-2">{{ $q->cancellation_reason ?: '—' }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="openClearQuery({{ $q->id }})"
                                    class="rounded-lg bg-rose-600 hover:bg-rose-500 px-3 py-1.5 text-[11px] font-semibold text-white transition-colors">
                                    Clear with note
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ──────────────  Slip status mix  ────────────── --}}
    @php
        $totalSlipCount = collect($statusCounts)->sum(fn ($r) => (int) $r->cnt);
        $statusMix = [
            ['key' => 'submitted',  'label' => 'Pending',    'cnt' => (int) ($statusCounts[\App\Models\PettyCashEntry::STATUS_SUBMITTED]->cnt ?? 0),  'rand' => $totalSubmitted,  'color' => 'amber'],
            ['key' => 'approved',   'label' => 'Approved',   'cnt' => (int) ($statusCounts[\App\Models\PettyCashEntry::STATUS_APPROVED]->cnt ?? 0),   'rand' => $totalApproved,   'color' => 'emerald'],
            ['key' => 'reimbursed', 'label' => 'Reimbursed', 'cnt' => (int) ($statusCounts[\App\Models\PettyCashEntry::STATUS_REIMBURSED]->cnt ?? 0), 'rand' => $totalReimbursed, 'color' => 'blue'],
            ['key' => 'rejected',   'label' => 'Rejected',   'cnt' => (int) ($statusCounts[\App\Models\PettyCashEntry::STATUS_REJECTED]->cnt ?? 0),   'rand' => $totalRejected,   'color' => 'rose'],
        ];
    @endphp
    @if($totalSlipCount > 0)
        <section class="mb-5 rounded-xl bg-white border border-slate-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-slate-700">Slip status mix</h2>
                <span class="text-xs text-slate-500">{{ $totalSlipCount }} {{ Str::plural('slip', $totalSlipCount) }} in range</span>
            </div>

            <div class="flex h-3 w-full rounded-full overflow-hidden">
                @foreach($statusMix as $s)
                    @if($s['cnt'] > 0)
                        @php $pct = round(($s['cnt'] / max(1, $totalSlipCount)) * 100, 1); @endphp
                        <div class="bg-{{ $s['color'] }}-500" style="width: {{ $pct }}%" title="{{ $s['label'] }} — {{ $s['cnt'] }}"></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach($statusMix as $s)
                    <div class="flex items-start gap-2">
                        <span class="mt-1 inline-block h-2 w-2 rounded-full bg-{{ $s['color'] }}-500 shrink-0"></span>
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">{{ $s['label'] }}</p>
                            <p class="text-sm font-bold tabular-nums text-slate-900">{{ $s['cnt'] }}<span class="ml-1 text-[10px] font-normal text-slate-500">slips</span></p>
                            <p class="text-[10px] tabular-nums text-slate-600">R {{ number_format($s['rand'], 2) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ──────────────  Category breakdown with bars  ────────────── --}}
    <section class="mb-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-2">Categories — issued vs spent</h2>
        <div class="rounded-xl bg-white border border-slate-200 overflow-hidden">
            <div class="p-4 space-y-3">
                @foreach (['tolls' => 'Tolls', 'accommodation' => 'Accommodation', 'taxi' => 'Taxi', 'food' => 'Food', 'fuel' => 'Fuel', 'other' => 'Other'] as $k => $lbl)
                    @php
                        $i = (float) ($categoryIssued[$k] ?? 0);
                        $s = (float) ($categorySpend[$k] ?? 0);
                        $v = round($s - $i, 2);
                        $ipct = round(($i / $maxCategoryValue) * 100, 1);
                        $spct = round(($s / $maxCategoryValue) * 100, 1);
                    @endphp
                    @if($i > 0 || $s > 0)
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-semibold text-slate-700">{{ $lbl }}</span>
                                <span class="tabular-nums {{ $v > 0.5 ? 'text-rose-700 font-semibold' : ($v < -0.5 ? 'text-emerald-700' : 'text-slate-500') }}">
                                    {{ $v > 0 ? '+' : '' }}R {{ number_format($v, 2) }}
                                </span>
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 text-[10px]">
                                    <span class="w-20 text-slate-500">Issued</span>
                                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full bg-emerald-500" style="width: {{ $ipct }}%;"></div>
                                    </div>
                                    <span class="w-24 text-right tabular-nums text-slate-700">R {{ number_format($i, 2) }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-[10px]">
                                    <span class="w-20 text-slate-500">Spent</span>
                                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full {{ $v > 0.5 ? 'bg-rose-500' : 'bg-slate-500' }}" style="width: {{ $spct }}%;"></div>
                                    </div>
                                    <span class="w-24 text-right tabular-nums text-slate-700">R {{ number_format($s, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
                @if(array_sum($categoryIssued) + array_sum($categorySpend) === 0.0)
                    <p class="text-center text-sm text-slate-500 py-6">No categorised activity in this range.</p>
                @endif
            </div>
        </div>
    </section>

    {{-- ──────────────  Scan incentive  ────────────── --}}
    <section class="mb-5 rounded-xl bg-gradient-to-br from-purple-50 to-white border border-purple-200 p-4">
        <div class="flex flex-wrap items-center gap-4">
            <div>
                <p class="text-[11px] uppercase tracking-wide text-purple-800 font-semibold">Scan incentive earned this range</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-purple-900">R {{ number_format($incentiveEarned, 2) }}</p>
                <p class="text-[11px] text-purple-700/80">{{ $approvedSlipCount }} approved slips × R {{ number_format($incentiveRate, 2) }}/slip @if(!$incentiveEnabled) — <span class="text-rose-700 font-semibold">scheme disabled</span> @endif</p>
            </div>
            <div class="ml-auto text-[11px] text-purple-700">
                Pay-out is manual.
                <a href="{{ route('admin.petty-cash.index', ['view' => 'incentives']) }}" class="text-purple-800 hover:underline font-semibold">See per-driver →</a>
            </div>
        </div>
    </section>

    {{-- ──────────────  Top variance trips  ────────────── --}}
    <section class="mb-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-2">Biggest variance trips</h2>
        <div class="rounded-xl bg-white border border-slate-200 overflow-hidden">
            @if($topVariance->isEmpty())
                <p class="p-6 text-center text-sm text-slate-500">No advances issued in this range.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Job number</th>
                            <th class="text-left px-4 py-2 font-medium">Customer / Route</th>
                            <th class="text-left px-4 py-2 font-medium">Driver</th>
                            <th class="text-right px-4 py-2 font-medium">Issued</th>
                            <th class="text-right px-4 py-2 font-medium">Spent</th>
                            <th class="text-right px-4 py-2 font-medium">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($topVariance as $job)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2">
                                    <a href="{{ route('admin.orders.show', $job) }}" class="font-semibold text-blue-700 hover:underline">{{ $job->job_number }}</a>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    {{ $job->company?->name ?? '—' }}
                                    <span class="block text-[11px] text-slate-500">
                                        {{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-slate-600">{{ $job->driver?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">R {{ number_format((float) $job->advance_total, 2) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">R {{ number_format((float) $job->_spent, 2) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-semibold {{ $job->_variance > 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                                    {{ $job->_variance > 0 ? '+' : '' }}R {{ number_format((float) $job->_variance, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- ──────────────  Per-driver  ────────────── --}}
    <section class="mb-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-2">By driver</h2>
        <div class="rounded-xl bg-white border border-slate-200 overflow-hidden">
            @if($perDriver->isEmpty())
                <p class="p-6 text-center text-sm text-slate-500">No driver activity in this range.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Driver</th>
                            <th class="text-left px-4 py-2 font-medium">Bank-send</th>
                            <th class="text-right px-4 py-2 font-medium">Slips</th>
                            <th class="text-right px-4 py-2 font-medium">Spend</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($perDriver as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2 font-semibold text-slate-900">{{ $row['name'] }}</td>
                                <td class="px-4 py-2">
                                    @if($row['phone'])
                                        <span class="inline-flex items-center gap-1 text-emerald-700">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span class="font-mono text-xs">{{ $row['phone'] }}</span>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-rose-600 font-semibold text-xs">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                                            no phone
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['count'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-semibold">R {{ number_format($row['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- Clear-query modal (dashboard entry point — mirrors the order page one). --}}
    @if($showClearQueryModal)
        @php
            $modalJob = $clearQueryJobId ? $openQueries->firstWhere('id', $clearQueryJobId) : null;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" wire:click.self="cancelClearQuery">
            <div class="relative w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Clear Reconciliation Query</h3>
                    @if($modalJob)
                        <p class="text-sm text-gray-500 mt-0.5">{{ $modalJob->job_number }} — R {{ number_format((float) $modalJob->advance_total, 2) }} issued before cancellation</p>
                    @endif
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="rounded-lg bg-rose-50 border border-rose-200 p-3 text-sm text-rose-900">
                        Describe how the cash was reconciled. The explanation is permanent and visible on the order's audit trail.
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Explanation</label>
                        <textarea wire:model="clearQueryNote" rows="4"
                            placeholder="e.g. Driver returned cash on 27 May, booked back into petty cash float."
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea>
                        @error('clearQueryNote') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3 bg-gray-50">
                    <button wire:click="cancelClearQuery" type="button" class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button wire:click="submitClearQuery" type="button" class="rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 transition-colors">
                        Clear query
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
