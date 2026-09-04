<?php

use App\Models\AuditLog;
use App\Models\BodyBuilderRequest;
use App\Models\BookingChangeRequest;
use App\Models\Company;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\PettyCashPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use App\Services\Tfn\TfnClient;
use App\Services\Tfn\TfnDemoFixtures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  OWNER BUSINESS COMMAND CENTRE                                    ║
 * ╠══════════════════════════════════════════════════════════════════╣
 * ║  One screen the owner opens in the morning: what needs a          ║
 * ║  signature, where the money is at for the picked month, how much  ║
 * ║  fuel we've burnt / how much credit is left on the card, and who  ║
 * ║  moved the most metal.  Read-only -- every number links to the    ║
 * ║  page that owns the work.                                         ║
 * ║                                                                  ║
 * ║  Restricted to the business owner and the developer who maintains ║
 * ║  the platform.  super_admin has full sidebar reach but not this   ║
 * ║  page, because the oversight numbers here are the owner's book.   ║
 * ║                                                                  ║
 * ║  Every money figure agrees, definition-for-definition, with the   ║
 * ║  Finance dashboard for the same month -- billable is ProSelver +  ║
 * ║  delivered, petty cash matches Petty Cash Overview, licence is    ║
 * ║  the same per-move × count calc.  If a number drifts between the  ║
 * ║  two pages, one of them copied a scope wrong.                     ║
 * ║                                                                  ║
 * ║  SQL is kept portable (no Postgres-only FILTER or ::date) so this ║
 * ║  page is coverable by the SQLite test suite.                      ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** Picked month as YYYY-MM.  Defaults to the current month. */
    #[Url] public string $month = '';

    public function mount(): void
    {
        $u = auth()->user();

        if (!$u || (!$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'The owner command centre is restricted to the business owner.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function stepMonth(int $delta): void
    {
        $anchor = $this->anchor()->addMonthsNoOverflow($delta);

        // Never walk into the future -- the oversight numbers only make
        // sense for months that have actually happened.
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
     * ProSelver movements that are billable in the window.  Mirrors
     * Finance dashboard::billableQuery() so the two pages can never
     * disagree.
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
     * Petty cash issued vs spent for a window.  Same definitions as
     * the Finance dashboard so the variance figures agree.
     *
     * @return array{issued: float, spent: float}
     */
    private function pettyCash(Carbon $from, Carbon $to): array
    {
        $issued = (float) Job::query()
            ->excludingTransferredAdvances()
            ->whereNotNull('advance_assigned_at')
            ->whereBetween('advance_assigned_at', [$from, $to])
            ->sum('advance_total');

        $spent = (float) PettyCashEntry::query()
            ->whereIn('status', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_cents') / 100;

        return ['issued' => $issued, 'spent' => $spent];
    }

    /**
     * Percentage movement vs the previous month.  Matches the Finance
     * dashboard's trend() helper -- the caller decides whether an "up"
     * is good news or bad, this only reports direction and magnitude.
     *
     * Works on int or float inputs; percentages are rounded to whole
     * points so the tile stays glanceable.
     */
    private function trend(int|float $current, int|float $previous): ?array
    {
        if ((float) $previous === 0.0 && (float) $current === 0.0) {
            return null;
        }
        if ((float) $previous === 0.0) {
            return ['dir' => 'up', 'label' => 'new'];
        }

        $delta = (int) round((($current - $previous) / $previous) * 100);

        return [
            'dir' => $delta >= 0 ? 'up' : 'down',
            'label' => ($delta >= 0 ? '+' : '') . $delta . '%',
        ];
    }

    /**
     * Deliveries per calendar day of the picked month.  A dense-array
     * shape (one row per day, zeros filled in) keeps the SVG loop simple
     * on the view side.
     *
     * @return list<array{date: Carbon, count: int}>
     */
    private function deliveriesByDay(Carbon $from, Carbon $to): array
    {
        // COUNT + DATE(delivered_at) is portable across MySQL / Postgres /
        // SQLite (the test connection).  A raw ::date cast would not be.
        $rows = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->selectRaw('DATE(delivered_at) AS d, COUNT(*) AS cnt')
            ->groupBy('d')
            ->pluck('cnt', 'd');

        $out = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $out[] = [
                'date' => $day->copy(),
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Petty cash spent per category in the month.  Filters to the same
     * committed-money status set as the KPI so the totals reconcile.
     *
     * @return array<string, float>  category slug => rand amount
     */
    private function pettyCashByCategory(Carbon $from, Carbon $to): array
    {
        $rows = PettyCashEntry::query()
            ->whereIn('status', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('category, COALESCE(SUM(amount_cents), 0) AS total_cents')
            ->groupBy('category')
            ->pluck('total_cents', 'category');

        // Present every category so a zero bar is visible.  Order fixed
        // for readability -- fuel first, "other" last.
        $order = [
            PettyCashEntry::CATEGORY_FUEL,
            PettyCashEntry::CATEGORY_TOLL,
            PettyCashEntry::CATEGORY_FOOD,
            PettyCashEntry::CATEGORY_ACCOMMODATION,
            PettyCashEntry::CATEGORY_PARKING,
            PettyCashEntry::CATEGORY_OTHER,
        ];

        $out = [];
        foreach ($order as $cat) {
            $out[$cat] = (float) ($rows[$cat] ?? 0) / 100;
        }

        return $out;
    }

    /**
     * Live fuel snapshot from TFN, with a safe fallback to demo fixtures.
     * Wrapped end-to-end so the dashboard never 500s on a flaky API --
     * a broken TFN gives us "—" on the tile and a note in the banner,
     * not a dead page.
     *
     * The month-aggregate reads whichever month the picker sits on; if
     * that call fails we degrade the tile to slip-based fuel only.
     *
     * @return array{
     *     available: ?float,
     *     balance: ?float,
     *     tfn_litres: ?float,
     *     source: 'live'|'demo',
     *     ok: bool
     * }
     */
    private function fuelSnapshot(Carbon $anchor): array
    {
        $client = app(TfnClient::class);
        $fixtures = app(TfnDemoFixtures::class);

        $isLive = false;
        try {
            $isLive = $client->isLive();
        } catch (\Throwable $e) {
            $isLive = false;
        }

        $balancePayload = null;
        $aggregatePayload = null;
        $ok = true;

        if ($isLive) {
            try {
                $balancePayload = $client->subAccountBalance();
            } catch (\Throwable $e) {
                $balancePayload = $fixtures->balance();
                $ok = false;
            }
            try {
                $aggregatePayload = $client->subAccountAggregateLitres($anchor);
            } catch (\Throwable $e) {
                $aggregatePayload = $fixtures->aggregateLitres();
                $ok = false;
            }
        } else {
            $balancePayload = $fixtures->balance();
            $aggregatePayload = $fixtures->aggregateLitres();
        }

        $available = $balancePayload['AccountAvailableBalance']
            ?? $balancePayload['AvailableCredit']
            ?? null;
        $balance = $balancePayload['AccountBalance']
            ?? $balancePayload['Balance']
            ?? null;

        $litres = 0.0;
        foreach ((array) $aggregatePayload as $row) {
            $litres += (float) ($row['Litres'] ?? 0);
        }

        return [
            'available' => $available !== null ? (float) $available : null,
            'balance' => $balance !== null ? (float) $balance : null,
            'tfn_litres' => $litres > 0 ? $litres : null,
            'source' => $isLive ? 'live' : 'demo',
            'ok' => $ok,
        ];
    }

    /**
     * Range shortcuts into the audit log.  Owner asked to be able to
     * pull every change for the current week, this month and last month
     * without touching a date picker.
     *
     * @return list<array{label: string, href: string}>
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

    /**
     * Yesterday's activity, summarised. The owner's first question after being
     * away is "what moved while I wasn't looking", and the audit log answers it
     * but only if you already know what to filter for.
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

    public function with(): array
    {
        $anchor = $this->anchor();
        $from = $anchor->copy()->startOfMonth();
        $to = $anchor->copy()->endOfMonth();
        $prevAnchor = $anchor->copy()->subMonthNoOverflow();
        $prevFrom = $prevAnchor->copy()->startOfMonth();
        $prevTo = $prevAnchor->copy()->endOfMonth();
        $atCurrentMonth = $anchor->equalTo(now()->startOfMonth());

        // ─── Money for the picked month ────────────────────────────────
        // One pass over the month's billable rows -- same aggregates the
        // Finance dashboard uses, so the two pages can't drift.
        $billing = $this->billableQuery($from, $to)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN 1 END) AS open_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN total_sell_price END), 0) AS unbilled_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN invoice_amount END), 0) AS invoiced_sum')
            ->selectRaw('COUNT(CASE WHEN (invoice_number IS NULL OR invoice_number = ?) AND invoicing_excluded_at IS NULL THEN 1 END) AS missing_number_count', [''])
            ->first();

        $prevBilling = $this->billableQuery($prevFrom, $prevTo)
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_completed_at IS NULL AND invoicing_excluded_at IS NULL THEN total_sell_price END), 0) AS unbilled_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN invoice_amount END), 0) AS invoiced_sum')
            ->first();

        // Vehicles delivered in the picked month -- one number, one scope,
        // shared by the KPI, the leaderboard and the licence figure.
        $deliveredMonth = (int) Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();

        $prevDelivered = (int) Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$prevFrom, $prevTo])
            ->count();

        // ─── Petty cash for the month ──────────────────────────────────
        $cash = $this->pettyCash($from, $to);
        $prevCash = $this->pettyCash($prevFrom, $prevTo);
        $variance = round($cash['spent'] - $cash['issued'], 2);

        // ─── Fuel snapshot (live TFN or fixtures) ──────────────────────
        $fuel = $this->fuelSnapshot($anchor);

        // Slip-based fuel spend for the picked month -- always available
        // even when TFN is down, and used as helper text on the KPI.
        $slipFuelMonth = (float) PettyCashEntry::query()
            ->where('category', PettyCashEntry::CATEGORY_FUEL)
            ->whereIn('status', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_cents') / 100;

        $prevSlipFuel = (float) PettyCashEntry::query()
            ->where('category', PettyCashEntry::CATEGORY_FUEL)
            ->whereIn('status', [
                PettyCashEntry::STATUS_APPROVED,
                PettyCashEntry::STATUS_REIMBURSED,
                PettyCashEntry::STATUS_SUBMITTED,
            ])
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->sum('amount_cents') / 100;

        // ─── Platform licence (owner + developer only, gated again) ────
        $licenceService = app(ProselverLicenceBilling::class);
        $licence = null;
        if ($licenceService->isEnabled()) {
            $excl = $deliveredMonth * $licenceService->perMoveFee();
            $licence = [
                'moves' => $deliveredMonth,
                'per_move' => $licenceService->perMoveFee(),
                'total_incl_vat' => $excl + round($excl * ProselverLicenceBilling::VAT_RATE, 2),
            ];
        }

        // ─── At-risk pipeline (live, not month-scoped) ─────────────────
        $atRisk = $this->atRiskCount();

        // ─── Charts ────────────────────────────────────────────────────
        $deliveriesSeries = $this->deliveriesByDay($from, $to);
        $deliveriesPeak = max(1, collect($deliveriesSeries)->max('count') ?? 0);

        $cashByCategory = $this->pettyCashByCategory($from, $to);
        $cashPeak = max(0.01, max($cashByCategory ?: [0]));

        // ─── Customer leaderboards (top 8 for the month) ───────────────
        $volumeRows = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS moves')
            ->orderByDesc('moves')
            ->orderBy('company_id')
            ->limit(8)
            ->get();

        $valueRows = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->whereNull('invoicing_excluded_at')
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS moves, COALESCE(SUM(invoice_amount), 0) AS invoiced_sum')
            ->orderByDesc('invoiced_sum')
            ->orderBy('company_id')
            ->limit(8)
            ->get();

        // Batch the name lookup so we hit companies once rather than 16 times.
        $companyIds = $volumeRows->pluck('company_id')->merge($valueRows->pluck('company_id'))->unique();
        $companyNames = $companyIds->isEmpty()
            ? collect()
            : Company::whereIn('id', $companyIds)->pluck('name', 'id');

        $volumeRows->each(fn ($r) => $r->setAttribute('company_name', $companyNames->get($r->company_id) ?? 'Unknown customer'));
        $valueRows->each(fn ($r) => $r->setAttribute('company_name', $companyNames->get($r->company_id) ?? 'Unknown customer'));

        // ─── Waiting on you ────────────────────────────────────────────
        // Live (not month-scoped) -- an owner opens the page to clear
        // whatever is blocking right now, regardless of the picker.
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
                    'dateFrom' => $from->toDateString(),
                    'dateTo' => $to->toDateString(),
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

        $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];

        $attention = collect($attention)
            ->filter(fn ($row) => $row['count'] > 0)
            ->sortBy(fn ($row) => (($severityRank[$row['severity']] ?? 3) * 1_000_000)
                - min($row['count'], 999_999))
            ->values();

        return [
            'anchor' => $anchor,
            'from' => $from,
            'to' => $to,
            'atCurrentMonth' => $atCurrentMonth,

            // KPI values
            'cashSpent' => $cash['spent'],
            'cashIssued' => $cash['issued'],
            'variance' => $variance,
            'cashTrend' => $this->trend($cash['spent'], $prevCash['spent']),

            'fuel' => $fuel,
            'slipFuelMonth' => $slipFuelMonth,
            'fuelTrend' => $this->trend(
                (float) ($fuel['tfn_litres'] ?? $slipFuelMonth),
                (float) $prevSlipFuel,
            ),

            'deliveredMonth' => $deliveredMonth,
            'deliveredTrend' => $this->trend($deliveredMonth, $prevDelivered),

            'invoicedValue' => (float) ($billing->invoiced_sum ?? 0),
            'invoicedTrend' => $this->trend(
                (float) ($billing->invoiced_sum ?? 0),
                (float) ($prevBilling->invoiced_sum ?? 0),
            ),

            'unbilledValue' => (float) ($billing->unbilled_sum ?? 0),
            'openInvoicing' => (int) ($billing->open_count ?? 0),
            'unbilledTrend' => $this->trend(
                (float) ($billing->unbilled_sum ?? 0),
                (float) ($prevBilling->unbilled_sum ?? 0),
            ),

            'licence' => $licence,
            'atRisk' => $atRisk,

            // Charts
            'deliveriesSeries' => $deliveriesSeries,
            'deliveriesPeak' => $deliveriesPeak,
            'cashByCategory' => $cashByCategory,
            'cashPeak' => $cashPeak,

            // Leaderboards
            'volumeRows' => $volumeRows,
            'valueRows' => $valueRows,

            // Attention + digest
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
        'high' => ['dot' => 'bg-rose-500', 'count' => 'text-rose-700'],
        'medium' => ['dot' => 'bg-amber-500', 'count' => 'text-amber-700'],
        'low' => ['dot' => 'bg-slate-400', 'count' => 'text-slate-700'],
    ];

    $categoryLabels = [
        'fuel_slip' => 'Fuel',
        'toll_slip' => 'Tolls',
        'food_slip' => 'Food',
        'accommodation_slip' => 'Accommodation',
        'parking_slip' => 'Parking',
        'other' => 'Other',
    ];
@endphp

<div class="space-y-6">

    <x-page-header
        eyebrow="Owner"
        title="Business Command Centre"
        subtitle="Where the money and the metal are for {{ $anchor->format('F Y') }}, and what's waiting on you.">
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
    {{-- First, always. Owner opens the page to answer "is anything
         blocked on me" before they read money. Renders live counts,
         never month-scoped. --}}
    @if($attention->isNotEmpty())
        <x-dash.panel
            title="Waiting on you"
            :subtitle="$attention->count() . ' ' . \Illuminate\Support\Str::plural('item', $attention->count()) . ' need attention'"
            :tight="true">

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
        </x-dash.panel>
    @endif

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- MONTH PICKER                                                   --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Same pattern as the Finance dashboard so the two behave
         identically -- stepper + native month input, capped at "now". --}}
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
            &middot; MTD, delivered-date basis
        </p>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- KPI ROW -- money, fuel and metal at a glance, with MoM deltas  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">

        {{-- Petty cash spent MTD --}}
        <x-dash.kpi
            label="Petty cash spent"
            :value="$money($cashSpent)"
            :color="abs($variance) < 1 ? 'green' : ($variance > 0 ? 'red' : 'amber')"
            :href="route('admin.overview')"
            :trend="$cashTrend"
            :helper="$money($cashIssued) . ' issued · ' . (abs($variance) < 1 ? 'balanced' : ($variance > 0 ? $money(abs($variance)) . ' over' : $money(abs($variance)) . ' under'))">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        {{-- Fuel spend MTD -- litres from TFN if we have them, slip rand as helper.
             When TFN is unreachable we still surface the slip figure so the tile
             is never blank. --}}
        <x-dash.kpi
            label="Fuel MTD"
            :value="$fuel['tfn_litres'] !== null ? $num($fuel['tfn_litres']) . ' L' : $money($slipFuelMonth)"
            color="orange"
            :href="route('admin.fuel')"
            :trend="$fuelTrend"
            :helper="$fuel['tfn_litres'] !== null
                ? 'TFN litres this month · ' . $money($slipFuelMonth) . ' cash fuel slips'
                : ($fuel['ok'] ? 'From driver fuel slips (TFN not connected)' : 'TFN unreachable — showing cash fuel slips')">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="15" y1="22" y2="22"/><line x1="4" x2="14" y1="9" y2="9"/><path d="M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18"/><path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2 2 2 0 0 0 2-2V9.83a2 2 0 0 0-.59-1.42L18 5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        {{-- Fuel available credit -- live snapshot, not month-scoped. --}}
        <x-dash.kpi
            label="Fuel credit available"
            :value="$fuel['available'] !== null ? $money($fuel['available']) : '—'"
            :color="$fuel['available'] === null ? 'slate' : ($fuel['available'] < 20000 ? 'red' : ($fuel['available'] < 50000 ? 'amber' : 'green'))"
            :href="route('admin.fuel')"
            :helper="$fuel['available'] === null
                ? 'TFN account not connected'
                : ($fuel['source'] === 'live' && $fuel['ok']
                    ? 'On the TFN sub-account right now'
                    : 'TFN reachable but stale — refresh on the Fuel page')">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M6 15h.01"/><path d="M10 15h.01"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        {{-- Vehicles delivered MTD --}}
        <x-dash.kpi
            label="Vehicles delivered"
            :value="$num($deliveredMonth)"
            color="green"
            :href="route('admin.deliveries')"
            :trend="$deliveredTrend"
            helper="ProSelver-executed, delivered this month">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        {{-- Invoiced MTD --}}
        <x-dash.kpi
            label="Invoiced"
            :value="$money($invoicedValue)"
            color="purple"
            :href="route('admin.invoices.index', ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString()])"
            :trend="$invoicedTrend"
            helper="Captured invoice value this month">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        {{-- Still to bill MTD --}}
        <x-dash.kpi
            label="Still to bill"
            :value="$money($unbilledValue)"
            :color="$openInvoicing > 0 ? 'orange' : 'green'"
            :href="route('admin.dashboard.finance')"
            :trend="$unbilledTrend"
            :helper="$num($openInvoicing) . ' delivered movements not yet captured'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </x-slot:icon>
        </x-dash.kpi>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- CHARTS ROW                                                     --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- Deliveries per day (inline SVG, same style as Ops activity) --}}
        <x-dash.panel
            class="lg:col-span-2"
            title="Deliveries per day"
            :subtitle="$anchor->format('F Y') . ' · ProSelver-executed'">
            <x-slot:actions>
                <a href="{{ route('admin.reports.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Full report &rarr;</a>
            </x-slot:actions>

            @if($deliveredMonth === 0)
                <div class="flex h-56 items-center justify-center text-sm text-slate-400">
                    No deliveries in {{ $anchor->format('F Y') }}
                </div>
            @else
                @php
                    $count = count($deliveriesSeries);
                    $chartH = 180;
                    $chartW = max(600, $count * 22);
                    $groupW = $chartW / max($count, 1);
                    $innerW = max(1, $groupW - 6);
                    $barW = max(2, $innerW * 0.7);
                    $labelEvery = $count > 15 ? (int) ceil($count / 15) : 1;
                @endphp
                <div class="overflow-x-auto">
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 30 }}" class="h-60 w-full min-w-[600px]" preserveAspectRatio="none">
                        @for($i = 1; $i <= 4; $i++)
                            <line x1="0" x2="{{ $chartW }}" y1="{{ $chartH - ($chartH / 4) * $i }}" y2="{{ $chartH - ($chartH / 4) * $i }}" stroke="#f1f5f9" stroke-width="1"/>
                        @endfor
                        @foreach($deliveriesSeries as $i => $d)
                            @php
                                $gx = $i * $groupW + (($innerW - $barW) / 2) + 3;
                                $h = $deliveriesPeak > 0 ? ($d['count'] / $deliveriesPeak) * ($chartH - 6) : 0;
                            @endphp
                            <rect x="{{ $gx }}" y="{{ $chartH - $h }}" width="{{ $barW }}" height="{{ $h }}" fill="#10b981" rx="1.5"/>
                            @if($i % $labelEvery === 0)
                                <text x="{{ $gx + $barW / 2 }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9" fill="#64748b" font-family="ui-sans-serif,system-ui">{{ $d['date']->format('d') }}</text>
                            @endif
                        @endforeach
                    </svg>
                </div>
            @endif

            <x-slot:footer>
                Peak day: {{ $num($deliveriesPeak) }} {{ \Illuminate\Support\Str::plural('delivery', $deliveriesPeak) }}. Total for the month: {{ $num($deliveredMonth) }}.
            </x-slot:footer>
        </x-dash.panel>

        {{-- Petty cash by category (horizontal bars) --}}
        <x-dash.panel
            title="Petty cash by category"
            :subtitle="$money($cashSpent) . ' spent · ' . $anchor->format('F Y')">
            @php $totalCash = array_sum($cashByCategory); @endphp

            @if($totalCash < 1)
                <div class="flex h-56 items-center justify-center text-sm text-slate-400">
                    No petty cash spent in {{ $anchor->format('F Y') }}
                </div>
            @else
                <ul class="space-y-3">
                    @foreach($cashByCategory as $slug => $amount)
                        @php
                            $pct = $cashPeak > 0 ? min(100, ($amount / $cashPeak) * 100) : 0;
                            $sharePct = $totalCash > 0 ? (int) round(($amount / $totalCash) * 100) : 0;
                        @endphp
                        <li>
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium text-slate-700">{{ $categoryLabels[$slug] ?? Str::of($slug)->replace('_', ' ')->title() }}</span>
                                <span class="tabular-nums text-slate-500">{{ $money($amount) }} <span class="text-slate-400">· {{ $sharePct }}%</span></span>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-orange-400" style="width: {{ $pct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-slot:footer>
                <a href="{{ route('admin.overview') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Petty cash overview &rarr;</a>
            </x-slot:footer>
        </x-dash.panel>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- CUSTOMER LEADERBOARDS                                          --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- By volume -- vehicles delivered --}}
        <x-dash.panel
            title="Top customers by volume"
            :subtitle="'Vehicles delivered · ' . $anchor->format('F Y')"
            :tight="true">
            <x-slot:actions>
                <a href="{{ route('admin.reports.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Full report &rarr;</a>
            </x-slot:actions>

            @if($volumeRows->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-slate-500">No deliveries yet in {{ $anchor->format('F Y') }}.</p>
                </div>
            @else
                @php $topVolume = $volumeRows->max('moves'); @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach($volumeRows as $i => $row)
                        @php $pct = $topVolume > 0 ? ($row->moves / $topVolume) * 100 : 0; @endphp
                        <li class="px-5 py-2.5">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-500">{{ $i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ $row->company_name }}</span>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $num($row->moves) }}</span>
                            </div>
                            <div class="mt-1.5 ml-9 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>

        {{-- By value -- invoiced rand --}}
        <x-dash.panel
            title="Top customers by value"
            :subtitle="'Invoiced this month · ' . $anchor->format('F Y')"
            :tight="true">
            <x-slot:actions>
                <a href="{{ route('admin.dashboard.finance') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Finance &rarr;</a>
            </x-slot:actions>

            @if($valueRows->isEmpty() || $valueRows->max('invoiced_sum') <= 0)
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-slate-500">Nothing captured yet in {{ $anchor->format('F Y') }}.</p>
                    <p class="mt-1 text-xs text-slate-400">Value updates as accounts captures invoice amounts.</p>
                </div>
            @else
                @php $topValue = (float) $valueRows->max('invoiced_sum'); @endphp
                <ul class="divide-y divide-slate-100">
                    @foreach($valueRows as $i => $row)
                        @php $pct = $topValue > 0 ? ((float) $row->invoiced_sum / $topValue) * 100 : 0; @endphp
                        <li class="px-5 py-2.5">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-500">{{ $i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ $row->company_name }}</span>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $money($row->invoiced_sum) }}</span>
                            </div>
                            <div class="mt-1.5 ml-9 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-purple-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash.panel>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- SUPPORTING ROW -- licence, at-risk, yesterday digest            --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- Platform licence for the month --}}
        <x-dash.panel
            title="Platform licence"
            :subtitle="$anchor->format('F Y')">
            @if($licence)
                <div class="flex items-baseline justify-between">
                    <p class="text-3xl font-semibold tabular-nums text-slate-900">{{ $money($licence['total_incl_vat']) }}</p>
                    <p class="text-xs text-slate-500">incl. VAT</p>
                </div>
                <p class="mt-1.5 text-xs text-slate-500">
                    {{ $num($licence['moves']) }} moves &times; {{ $money($licence['per_move']) }}
                </p>
                <x-slot:footer>
                    <a href="{{ route('admin.billing') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Licence billing &rarr;</a>
                </x-slot:footer>
            @else
                <div class="flex h-24 flex-col items-center justify-center text-center">
                    <p class="text-sm font-medium text-slate-500">Licence metering is currently disabled</p>
                    <a href="{{ route('admin.billing') }}" class="mt-1 text-xs font-semibold text-blue-600 hover:text-blue-700">Manage &rarr;</a>
                </div>
            @endif
        </x-dash.panel>

        {{-- At-risk snapshot -- live, not month-scoped --}}
        <x-dash.panel
            title="Movements at risk"
            subtitle="Live · past their stage threshold">
            <div class="flex items-baseline justify-between">
                <p class="text-3xl font-semibold tabular-nums {{ $atRisk > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $num($atRisk) }}</p>
                <p class="text-xs text-slate-500">{{ $atRisk > 0 ? 'needs a look' : 'all clear' }}</p>
            </div>
            <p class="mt-1.5 text-xs text-slate-500">
                Thresholds tuned on the Operations dashboard's settings.
            </p>
            <x-slot:footer>
                <a href="{{ route('admin.dashboard.ops') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Operations dashboard &rarr;</a>
            </x-slot:footer>
        </x-dash.panel>

        {{-- What changed yesterday.  A digest -- every count links straight
             into the audit log with the filter already applied, and the
             footer carries one-click ranges for the week / month / previous
             month so the owner never has to touch a date picker. --}}
        <x-dash.panel
            title="What changed yesterday"
            :subtitle="$digest['date']->format('D d M')">
            @if($digest['total'] === 0)
                <div class="flex h-24 flex-col items-center justify-center text-center">
                    <p class="text-sm font-medium text-slate-500">Nothing was recorded yesterday.</p>
                </div>
            @else
                <div class="flex items-baseline justify-between">
                    <p class="text-3xl font-semibold tabular-nums text-slate-900">{{ $num($digest['total']) }}</p>
                    <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::plural('change', $digest['total']) }} &middot; {{ $num($digest['people']) }} {{ \Illuminate\Support\Str::plural('person', $digest['people']) }}</p>
                </div>
                @if($digest['actions']->isNotEmpty())
                    <ul class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                        @foreach($digest['actions']->take(3) as $action)
                            <li>
                                <a href="{{ $action['href'] }}" class="group flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate text-slate-600 group-hover:text-slate-900">{{ $action['label'] }}</span>
                                    <span class="shrink-0 font-semibold tabular-nums text-slate-900">{{ $num($action['count']) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach($changeRanges as $r)
                        <a href="{{ $r['href'] }}" class="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600 transition-colors hover:border-slate-300 hover:text-slate-900">
                            {{ $r['label'] }}
                        </a>
                    @endforeach
                    <a href="{{ route('admin.audit-log') }}" class="ml-auto text-xs font-semibold text-blue-600 hover:text-blue-700">Audit log &rarr;</a>
                </div>
            </x-slot:footer>
        </x-dash.panel>
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
