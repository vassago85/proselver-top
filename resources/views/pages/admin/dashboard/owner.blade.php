<?php

use App\Models\AuditLog;
use App\Models\BodyBuilderRequest;
use App\Models\BookingChangeRequest;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\PettyCashPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  OWNER ROLL-UP                                                   ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  Deliberately thin.  Two strips of headline numbers -- one        ║
 * ║  operational, one financial -- plus the list of things only the   ║
 * ║  owner can clear.  Every number links through to the page that    ║
 * ║  owns it; nothing is captured, approved or edited here.          ║
 * ║                                                                  ║
 * ║  This page is NOT a superset of the Operations and Finance        ║
 * ║  dashboards.  If you find yourself wanting to add a chart, a      ║
 * ║  filter bar or a drill-down table, it belongs on one of those two ║
 * ║  instead -- the value of this page is that it fits on one screen  ║
 * ║  and answers "is anything wrong, and is anything waiting on me".  ║
 * ║                                                                  ║
 * ║  No filters by design.  The window is fixed: "now" for pipeline   ║
 * ║  state, the current calendar month for money, last 30 days for    ║
 * ║  throughput.  An owner who wants to slice the data goes to the    ║
 * ║  dashboard that has the filters.                                  ║
 * ║                                                                  ║
 * ║  SQL is kept portable (no Postgres-only FILTER or ::date) so this ║
 * ║  page is coverable by the SQLite test suite.                      ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** Throughput window for the "delivered" headline. */
    private const THROUGHPUT_DAYS = 30;

    public function mount(): void
    {
        $u = auth()->user();

        if (!$u || (!$u->isOwner() && !$u->isDeveloper() && !$u->isSuperAdmin())) {
            abort(403, 'The owner overview is restricted to the business owner.');
        }
    }

    /**
     * Movements still in flight, ProSelver-executed.  Same scope the
     * Operations dashboard uses for its pipeline KPIs, so the totals
     * on both pages agree.
     */
    private function activeQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [
                Job::STATUS_PENDING_VERIFICATION,
                Job::STATUS_RECEIVED,
                Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                Job::STATUS_CONFIRMATION_ISSUE,
                Job::STATUS_CONFIRMED,
                Job::STATUS_PLANNED,
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
                Job::STATUS_COLLECTED,
                Job::STATUS_IN_TRANSIT,
            ]);
    }

    /**
     * Count of movements sitting past their stage threshold.  Thresholds
     * come from the same SystemSetting keys the Operations dashboard
     * reads, so tuning them there moves this number too.
     */
    private function atRiskCount(): int
    {
        $awaitingDays = (int) SystemSetting::get('ops.alert.awaiting_confirm_days', 2);
        $toDispatchHours = (int) SystemSetting::get('ops.alert.to_dispatch_hours', 24);
        $dispatchedDays = (int) SystemSetting::get('ops.alert.dispatched_days', 2);
        $inTransitDays = (int) SystemSetting::get('ops.alert.in_transit_days', 3);

        return (int) $this->activeQuery()
            ->where(function ($w) use ($awaitingDays, $toDispatchHours, $dispatchedDays, $inTransitDays) {
                $w->where(function ($a) use ($awaitingDays) {
                    $a->whereIn('status', [Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE])
                      ->where('updated_at', '<=', now()->subDays($awaitingDays));
                })->orWhere(function ($a) use ($toDispatchHours) {
                    $a->where('status', Job::STATUS_CONFIRMED)
                      ->where('updated_at', '<=', now()->subHours($toDispatchHours));
                })->orWhere(function ($a) use ($dispatchedDays) {
                    $a->whereIn('status', [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION])
                      ->where('updated_at', '<=', now()->subDays($dispatchedDays));
                })->orWhere(function ($a) use ($inTransitDays) {
                    $a->whereIn('status', [Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT])
                      ->where('updated_at', '<=', now()->subDays($inTransitDays));
                });
            })
            ->count();
    }

    /**
     * Yesterday's activity, summarised. The owner's first question after being
     * away is "what moved while I wasn't looking", and the audit log answers it
     * but only if you already know what to filter for.
     *
     * A digest, deliberately -- counts and the top few actors/actions, every
     * one of them a link into the audit log with the filter already applied.
     * No table and no filters here, per this page's doctrine: the drill-down
     * lives on the page that owns it.
     */
    private function yesterdayDigest(): array
    {
        $from = now()->subDay()->startOfDay();
        $to = now()->subDay()->endOfDay();

        $scoped = fn () => AuditLog::query()->whereBetween('created_at', [$from, $to]);

        $byAction = $scoped()
            ->selectRaw('action_type, COUNT(*) as cnt')
            ->groupBy('action_type')
            ->orderByDesc('cnt')
            ->orderBy('action_type')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'label' => Str::of($row->action_type)->replace('_', ' ')->ucfirst()->toString(),
                'count' => (int) $row->cnt,
                'href' => route('admin.audit-log', [
                    'actionType' => $row->action_type,
                    'dateFrom' => $from->toDateString(),
                    'dateTo' => $to->toDateString(),
                ]),
            ]);

        $actorRows = $scoped()
            ->whereNotNull('actor_user_id')
            ->selectRaw('actor_user_id, COUNT(*) as cnt')
            ->groupBy('actor_user_id')
            ->orderByDesc('cnt')
            ->orderBy('actor_user_id')
            ->limit(3)
            ->get();

        $names = $actorRows->isEmpty()
            ? collect()
            : User::whereIn('id', $actorRows->pluck('actor_user_id'))->pluck('name', 'id');

        return [
            'date' => $from,
            'total' => (int) $scoped()->count(),
            'people' => (int) $scoped()->whereNotNull('actor_user_id')->distinct()->count('actor_user_id'),
            'actions' => $byAction,
            'actors' => $actorRows->map(fn ($row) => [
                'name' => $names[$row->actor_user_id] ?? 'Unknown',
                'count' => (int) $row->cnt,
                'href' => route('admin.audit-log', [
                    'actorId' => $row->actor_user_id,
                    'dateFrom' => $from->toDateString(),
                    'dateTo' => $to->toDateString(),
                ]),
            ]),
        ];
    }

    /**
     * Range shortcuts into the audit log. The owner asked to be able to pull
     * every change for the current week, this month and last month without
     * touching a date picker.
     */
    private function changeRanges(): array
    {
        $now = now();

        $ranges = [
            'Yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'This week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'This month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'Last month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
        ];

        return collect($ranges)->map(fn ($window, $label) => [
            'label' => $label,
            'href' => route('admin.audit-log', [
                'dateFrom' => $window[0]->toDateString(),
                'dateTo' => $window[1]->toDateString(),
            ]),
        ])->values()->all();
    }

    public function with(): array
    {
        $monthFrom = now()->startOfMonth();
        $monthTo = now()->endOfMonth();
        $throughputFrom = now()->subDays(self::THROUGHPUT_DAYS - 1)->startOfDay();

        // ─── Operational headlines ─────────────────────────────────────
        $activeTotal = (int) $this->activeQuery()->count();

        $onRoad = (int) $this->activeQuery()
            ->whereIn('status', [Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT])
            ->count();

        $deliveredRecent = (int) Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$throughputFrom, now()])
            ->count();

        $atRisk = $this->atRiskCount();

        // ─── Financial headlines (current calendar month) ──────────────
        // One pass over the month's billable movements, matching the
        // invoicing page's definition of billable.
        $billing = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$monthFrom, $monthTo])
            ->selectRaw('COUNT(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN 1 END) AS open_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN total_sell_price END), 0) AS unbilled_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN invoice_amount END), 0) AS invoiced_sum')
            ->selectRaw('COUNT(CASE WHEN (invoice_number IS NULL OR invoice_number = ?) AND invoicing_excluded_at IS NULL THEN 1 END) AS missing_number_count', [''])
            ->first();

        // Petty cash issued vs spent for the month -- same definitions as
        // the Petty Cash Overview so the variance figures agree.
        $issued = (float) Job::query()
            ->whereNotNull('advance_assigned_at')
            ->whereBetween('advance_assigned_at', [$monthFrom, $monthTo])
            ->sum('advance_total');

        $spent = (float) PettyCashEntry::query()
            ->whereIn('status', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->whereBetween('created_at', [$monthFrom, $monthTo])
            ->sum('amount_cents') / 100;

        $variance = round($spent - $issued, 2);

        // Platform licence for the month.
        $licenceService = app(ProselverLicenceBilling::class);
        $licence = null;

        if ($licenceService->isEnabled()) {
            $moves = (int) Job::query()
                ->where('executor_type', Job::EXECUTOR_PROSELVER)
                ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                ->whereNotNull('delivered_at')
                ->whereBetween('delivered_at', [$monthFrom, $monthTo])
                ->count();

            $excl = $moves * $licenceService->perMoveFee();

            $licence = [
                'moves' => $moves,
                'total_incl_vat' => $excl + round($excl * ProselverLicenceBilling::VAT_RATE, 2),
            ];
        }

        // ─── Waiting on the owner ──────────────────────────────────────
        // Each row is something the business genuinely cannot progress
        // without an owner decision, plus the two exception counts an
        // owner is expected to chase.  Zero-count rows are filtered out
        // in the view so this list is either empty (all clear) or
        // entirely actionable.
        $attention = [
            [
                'label' => 'Petty cash plans awaiting your sign-off',
                'count' => (int) PettyCashPlan::where('status', PettyCashPlan::STATUS_PENDING)->count(),
                'href' => route('admin.petty-cash.plans'),
                'severity' => 'high',
                'note' => 'Drivers cannot be issued cash until the bundle is approved.',
            ],
            [
                'label' => 'Open reconciliation queries',
                'count' => (int) Job::query()->issuedCancellationQueryOpen()->count(),
                // Points at the dedicated report rather than the Overview: it
                // lists every open query with the clearing action inline, and
                // the settled ones with their explanations underneath.
                'href' => route('admin.petty-cash.reconciliation'),
                'severity' => 'high',
                'note' => 'Trips cancelled after cash was issued, with no explanation signed off.',
            ],
            [
                'label' => 'Driver slips awaiting approval',
                'count' => (int) PettyCashEntry::where('status', PettyCashEntry::STATUS_SUBMITTED)->count(),
                'href' => route('admin.petty-cash.index'),
                'severity' => 'medium',
                'note' => 'Drivers are out of pocket until these are approved and reimbursed.',
            ],
            [
                'label' => 'Movements missing an invoice number',
                'count' => (int) ($billing->missing_number_count ?? 0),
                'href' => route('admin.invoices.index', [
                    'dateFrom' => $monthFrom->toDateString(),
                    'dateTo' => $monthTo->toDateString(),
                    'completion' => 'all',
                ]),
                'severity' => 'medium',
                'note' => 'Delivered and billable this month, but not yet invoiced.',
            ],
            [
                'label' => 'Booking change requests pending',
                'count' => (int) BookingChangeRequest::where('status', 'pending')->count(),
                'href' => route('admin.change-requests.index'),
                'severity' => 'medium',
                'note' => 'Customers waiting on a decision to move a date or destination.',
            ],
            [
                'label' => 'Body builder requests pending',
                'count' => (int) BodyBuilderRequest::where('status', 'pending')->count(),
                'href' => route('admin.body-builder-requests.index'),
                'severity' => 'low',
                'note' => 'New workshops asking to be onboarded onto the platform.',
            ],
            [
                'label' => 'Movements at risk',
                'count' => $atRisk,
                'href' => route('admin.dashboard.ops'),
                'severity' => 'high',
                'note' => 'Sitting past their stage threshold — see the Operations dashboard for detail.',
            ],
        ];

        // Highest-severity, biggest-count first so the top of the list is
        // always the most urgent thing.  A single integer sort key keeps
        // this unambiguous: severity dominates, count breaks ties, and the
        // count is negated so bigger sorts earlier.  Counts are clamped so
        // an extreme value can't bleed into the severity band.
        $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];

        $attention = collect($attention)
            ->filter(fn ($row) => $row['count'] > 0)
            ->sortBy(fn ($row) => (($severityRank[$row['severity']] ?? 3) * 1_000_000)
                - min($row['count'], 999_999))
            ->values();

        return [
            'monthLabel' => $monthFrom->format('F Y'),
            'throughputDays' => self::THROUGHPUT_DAYS,

            'activeTotal' => $activeTotal,
            'onRoad' => $onRoad,
            'deliveredRecent' => $deliveredRecent,
            'atRisk' => $atRisk,

            'openInvoicing' => (int) ($billing->open_count ?? 0),
            'unbilledValue' => (float) ($billing->unbilled_sum ?? 0),
            'invoicedValue' => (float) ($billing->invoiced_sum ?? 0),
            'variance' => $variance,
            'licence' => $licence,

            'attention' => $attention,

            'digest' => $this->yesterdayDigest(),
            'changeRanges' => $this->changeRanges(),
        ];
    }
}; ?>

@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $num = fn ($v) => number_format((int) $v);

    $severityStyles = [
        'high' => ['dot' => 'bg-rose-500', 'count' => 'text-rose-700', 'ring' => 'border-rose-200 bg-rose-50/50'],
        'medium' => ['dot' => 'bg-amber-500', 'count' => 'text-amber-700', 'ring' => 'border-amber-200 bg-amber-50/50'],
        'low' => ['dot' => 'bg-slate-400', 'count' => 'text-slate-700', 'ring' => 'border-slate-200 bg-slate-50/50'],
    ];
@endphp

<div class="space-y-6">

    <x-page-header
        eyebrow="Owner"
        title="Business Overview"
        subtitle="Where the operation and the money stand, and what's waiting on you.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.reports.index')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-6"/></svg>
                </x-slot:icon>
                Reports
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('admin.audit-log')">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </x-slot:icon>
                Audit log
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @include('pages.admin._partials.dashboard-tabs')

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- WAITING ON YOU                                                 --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Deliberately first. An owner opening this page wants to know
         whether anything is blocked on them before they read numbers. --}}
    <x-dash.panel
        title="Waiting on you"
        :subtitle="$attention->isEmpty() ? 'Nothing needs a decision right now' : $attention->count() . ' ' . \Illuminate\Support\Str::plural('item', $attention->count()) . ' need attention'"
        :tight="true">

        @if($attention->isEmpty())
            <div class="flex items-center gap-3 px-5 py-6">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <div>
                    <p class="text-sm font-semibold text-slate-900">All clear.</p>
                    <p class="text-xs text-slate-500">No sign-offs, queries or pending requests are outstanding.</p>
                </div>
            </div>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach($attention as $row)
                    @php $style = $severityStyles[$row['severity']] ?? $severityStyles['low']; @endphp
                    <li>
                        <a href="{{ $row['href'] }}" class="flex items-center gap-4 px-5 py-3 transition hover:bg-slate-50/70">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $style['dot'] }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-slate-900">{{ $row['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $row['note'] }}</span>
                            </span>
                            <span class="shrink-0 text-2xl font-semibold tabular-nums {{ $style['count'] }}">{{ $num($row['count']) }}</span>
                            <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- WHAT CHANGED YESTERDAY                                         --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Second question after "is anything blocked on me": what did the team
         do while I wasn't looking. A digest only — every figure is a link into
         the audit log with the filter pre-applied, and the range row covers
         the week / month / previous month without a date picker. --}}
    <x-dash.panel
        title="What changed yesterday"
        :subtitle="$digest['date']->format('l d F Y')"
        :tight="true">
        <x-slot:actions>
            <x-dash.pill :variant="$digest['total'] > 0 ? 'blue' : 'slate'">
                {{ $num($digest['total']) }} {{ \Illuminate\Support\Str::plural('change', $digest['total']) }}
            </x-dash.pill>
        </x-slot:actions>

        @if($digest['total'] === 0)
            <div class="flex items-center gap-3 px-5 py-6">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </span>
                <div>
                    <p class="text-sm font-semibold text-slate-900">Nothing was recorded yesterday.</p>
                    <p class="text-xs text-slate-500">No orders, cash or settings were touched.</p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0 sm:divide-x">
                <div class="px-5 py-4">
                    <p class="mb-2.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Most changed</p>
                    <ul class="space-y-1.5">
                        @foreach($digest['actions'] as $action)
                            <li>
                                <a href="{{ $action['href'] }}" class="group flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate text-sm text-slate-700 group-hover:text-slate-900">{{ $action['label'] }}</span>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $num($action['count']) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="px-5 py-4">
                    <p class="mb-2.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">
                        Busiest people &middot; {{ $num($digest['people']) }} active
                    </p>
                    @if($digest['actors']->isEmpty())
                        <p class="text-sm text-slate-500">All of yesterday's changes were automated.</p>
                    @else
                        <ul class="space-y-1.5">
                            @foreach($digest['actors'] as $actor)
                                <li>
                                    <a href="{{ $actor['href'] }}" class="group flex items-center justify-between gap-3">
                                        <span class="min-w-0 truncate text-sm text-slate-700 group-hover:text-slate-900">{{ $actor['name'] }}</span>
                                        <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $num($actor['count']) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <x-slot:footer>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">All changes for</span>
                @foreach($changeRanges as $r)
                    <a href="{{ $r['href'] }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 transition-colors hover:border-slate-300 hover:text-slate-900">
                        {{ $r['label'] }}
                    </a>
                @endforeach
                <a href="{{ route('admin.audit-log') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Full audit log &rarr;</a>
            </div>
        </x-slot:footer>
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- OPERATIONS STRIP                                               --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div>
        <div class="mb-3 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">Operations</h2>
                <p class="text-xs text-slate-500">Live pipeline right now &middot; throughput over the last {{ $throughputDays }} days.</p>
            </div>
            <a href="{{ route('admin.dashboard.ops') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Operations dashboard &rarr;</a>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-dash.kpi
                label="In flight"
                :value="$num($activeTotal)"
                color="blue"
                :href="route('admin.orders.index')"
                helper="Movements not yet delivered">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                </x-slot:icon>
            </x-dash.kpi>

            <x-dash.kpi
                label="On the road"
                :value="$num($onRoad)"
                color="teal"
                :href="route('admin.vehicles.index', ['bucket' => 'live'])"
                {{-- Plain "and": the helper is echoed through Blade's escaping,
                     so an &amp; here reaches the page as literal "&amp;". --}}
                helper="Collected and in transit">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
                </x-slot:icon>
            </x-dash.kpi>

            <x-dash.kpi
                label="Delivered"
                :value="$num($deliveredRecent)"
                color="green"
                :href="route('admin.deliveries')"
                :helper="'Last ' . $throughputDays . ' days'">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </x-slot:icon>
            </x-dash.kpi>

            <x-dash.kpi
                label="At risk"
                :value="$num($atRisk)"
                :color="$atRisk > 0 ? 'red' : 'green'"
                :href="route('admin.dashboard.ops')"
                :helper="$atRisk > 0 ? 'Past their stage threshold' : 'Everything inside threshold'">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </x-slot:icon>
            </x-dash.kpi>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- FINANCE STRIP                                                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div>
        <div class="mb-3 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">Finance</h2>
                <p class="text-xs text-slate-500">{{ $monthLabel }} &middot; by delivered date.</p>
            </div>
            {{-- Reconciliation is linked here as well as from "Waiting on you",
                 because that row disappears at zero open queries and the owner
                 still wants the settled-with-explanation history. --}}
            <div class="flex shrink-0 items-center gap-3">
                <a href="{{ route('admin.petty-cash.reconciliation') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Reconciliation &rarr;</a>
                <a href="{{ route('admin.dashboard.finance') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Finance dashboard &rarr;</a>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-dash.kpi
                label="Still to bill"
                :value="$money($unbilledValue)"
                :color="$openInvoicing > 0 ? 'orange' : 'green'"
                :href="route('admin.dashboard.finance')"
                :helper="$num($openInvoicing) . ' delivered movements not yet captured'">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </x-slot:icon>
            </x-dash.kpi>

            <x-dash.kpi
                label="Invoiced"
                :value="$money($invoicedValue)"
                color="purple"
                :href="route('admin.invoices.index')"
                helper="Captured invoice value this month">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/></svg>
                </x-slot:icon>
            </x-dash.kpi>

            <x-dash.kpi
                label="Petty cash variance"
                :value="($variance >= 0 ? '+' : '−') . $money(abs($variance))"
                :color="abs($variance) < 1 ? 'green' : ($variance > 0 ? 'red' : 'amber')"
                :href="route('admin.overview')"
                :helper="$variance > 0 ? 'Spent more than issued' : ($variance < 0 ? 'Issued more than spent' : 'Balanced')">
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
                    :helper="$num($licence['moves']) . ' billable moves incl. VAT'">
                    <x-slot:icon>
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </x-slot:icon>
                </x-dash.kpi>
            @else
                <x-dash.kpi
                    label="Platform licence"
                    value="Off"
                    color="slate"
                    :href="route('admin.billing')"
                    helper="Licence metering is currently disabled">
                    <x-slot:icon>
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </x-slot:icon>
                </x-dash.kpi>
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- GOVERNANCE SHORTCUTS                                           --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.panel title="Governance" subtitle="The setup and oversight pages an owner curates" :tight="true">
        <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0 sm:divide-x lg:grid-cols-4">
            @php
                $shortcuts = [
                    ['label' => 'Team', 'note' => 'Who has access and at what level', 'href' => route('admin.users.index')],
                    ['label' => 'Companies', 'note' => 'Customers, OEMs and body builders', 'href' => route('admin.companies.index')],
                    ['label' => 'Cancellation permissions', 'note' => 'Who may cancel a confirmed order', 'href' => route('admin.settings.cancellation')],
                    ['label' => 'Audit log', 'note' => 'Every change, who made it and when', 'href' => route('admin.audit-log')],
                ];
            @endphp
            @foreach($shortcuts as $s)
                <a href="{{ $s['href'] }}" class="group flex items-start justify-between gap-3 px-5 py-4 transition hover:bg-slate-50/70">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-slate-900">{{ $s['label'] }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ $s['note'] }}</span>
                    </span>
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            @endforeach
        </div>
    </x-dash.panel>
</div>
