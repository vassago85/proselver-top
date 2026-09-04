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
     * a broken TFN degrades the tile rather than killing the page.
     *
     * Two independent litres sources are read: the sub-account aggregate
     * rollup (server-batched, can lag) and the transactions list since
     * month-start (fresh but capped at 100 rows).  We keep whichever is
     * higher so a stale aggregate can't hide new fills -- same reasoning
     * as the Fuel operations page.
     *
     * @return array{
     *     available: ?float,
     *     balance: ?float,
     *     tfn_litres: ?float,
     *     source: 'live'|'demo',
     *     configured: bool,
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
        $txPayload = [];
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
            // Only pull transactions when scoped to the current month --
            // TFN's transactions endpoint has a 3-month lookback ceiling
            // and asking for a prior month here would 400.
            //
            // Two pulls, merged: month-start (can be truncated at TFN's
            // 100-row cap) and the last 24h (keeps today's fills even
            // when the month page is full).  Same reasoning as the Fuel
            // operations page Litres MTD tile.
            if ($anchor->equalTo(now()->startOfMonth())) {
                try {
                    $fromMonth = $client->transactions($anchor->copy()->startOfMonth()->toDateTimeImmutable());
                    $recent = $client->transactions(now()->subDay()->toDateTimeImmutable());
                    $txPayload = $this->mergeFuelTransactions($fromMonth, $recent);
                } catch (\Throwable $e) {
                    $txPayload = [];
                    $ok = false;
                }
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

        // Only count liquid fuel as "litres" -- OS is nights, WSH is washes,
        // etc.  Include every diesel / petrol grade, not just the D0
        // reconciliation default, so a 500ppm fill still moves the tile.
        $litreProducts = array_map('strtoupper', array_values(array_unique(array_merge(
            (array) config('tfn.reconciliation_products', ['D0']),
            ['D0', 'D1', 'D3', 'ULP93', 'ULP95'],
        ))));
        $isLitreCode = fn ($code) => in_array(strtoupper((string) $code), $litreProducts, true);

        $rowLitres = fn (array $r) => (float) (
            $r['Litres']
                ?? $r['Quantity']
                ?? $r['TotalLitres']
                ?? $r['Volume']
                ?? 0
        );

        $aggregateLitres = 0.0;
        foreach ((array) $aggregatePayload as $row) {
            if ($isLitreCode($row['ProductCode'] ?? '')) {
                $aggregateLitres += $rowLitres($row);
            }
        }

        $txLitres = 0.0;
        foreach ((array) $txPayload as $row) {
            if (!$isLitreCode($row['ProductCode'] ?? '')) {
                continue;
            }
            // Payments / credits carry non-zero amounts but no litres.
            if (($row['TransactionType'] ?? '') === 'Payment') {
                continue;
            }
            $txLitres += $rowLitres($row);
        }

        $litres = max($aggregateLitres, $txLitres);

        return [
            'available' => $available !== null ? (float) $available : null,
            'balance' => $balance !== null ? (float) $balance : null,
            'tfn_litres' => $isLive ? $litres : null,
            'source' => $isLive ? 'live' : 'demo',
            'configured' => $isLive,
            'ok' => $ok,
        ];
    }

    /**
     * Union TFN transaction lists keyed by TransactionID so a fill that
     * appears in both the month-start pull and the recent window is
     * counted once.  Mirrors Fuel page::mergeTransactions().
     *
     * @param  array<int, array<string, mixed>>  ...$lists
     * @return list<array<string, mixed>>
     */
    private function mergeFuelTransactions(array ...$lists): array
    {
        $out = [];
        $anon = 0;
        foreach ($lists as $list) {
            foreach ($list as $t) {
                $key = (string) ($t['TransactionID'] ?? $t['TransactionId'] ?? '');
                if ($key === '') {
                    $out['anon:' . $anon++] = $t;
                    continue;
                }
                $out[$key] = $t;
            }
        }

        return array_values($out);
    }

    /**
     * Yesterday's activity, summarised for the Business status 2x2 grid.
     * `updated` and `created` are surfaced separately because they are
     * the two action types the owner reads at a glance every morning;
     * every other action type is rolled into `total`.
     *
     * @return array{date: Carbon, total: int, people: int, updated: int, created: int}
     */
    private function yesterdayDigest(): array
    {
        $from = now()->subDay()->startOfDay();
        $to = now()->subDay()->endOfDay();

        $scoped = fn () => AuditLog::query()->whereBetween('created_at', [$from, $to]);

        // One pass, three counters -- portable across MySQL / SQLite (the
        // test connection) and Postgres alike, so this keeps agreeing
        // with the audit-log page's own totals for the same day.
        $counts = $scoped()
            ->selectRaw('COUNT(*) AS total_cnt')
            ->selectRaw("COUNT(CASE WHEN action_type IN ('updated','update') THEN 1 END) AS updated_cnt")
            ->selectRaw("COUNT(CASE WHEN action_type IN ('created','create') THEN 1 END) AS created_cnt")
            ->first();

        return [
            'date' => $from,
            'total' => (int) ($counts->total_cnt ?? 0),
            'people' => (int) $scoped()->whereNotNull('actor_user_id')->distinct()->count('actor_user_id'),
            'updated' => (int) ($counts->updated_cnt ?? 0),
            'created' => (int) ($counts->created_cnt ?? 0),
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

        // ─── Customer leaderboards (top 8 for the month) ───────────────
        $volumeRows = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->whereNotNull('company_id')
            ->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS moves, COALESCE(SUM(CASE WHEN invoicing_excluded_at IS NULL THEN invoice_amount END), 0) AS invoiced_sum')
            ->orderByDesc('moves')
            ->orderBy('company_id')
            ->limit(5)
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
            ->limit(5)
            ->get();

        // Batch the name lookup so we hit companies once rather than 10 times.
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

            // Chart series
            'deliveriesSeries' => $deliveriesSeries,
            'deliveriesPeak' => $deliveriesPeak,

            // Leaderboards (top-5 volume; valueRows kept exposed for tests
            // and future callers, even though the compact page renders a
            // single combined table sorted by volume).
            'volumeRows' => $volumeRows,
            'valueRows' => $valueRows,

            // Attention + digest
            'attention' => $attention,
            'digest' => $this->yesterdayDigest(),
        ];
    }
}; ?>


@php
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $num = fn ($v) => number_format((int) $v);

    // Attention rows re-use the alerts palette from the mockup -- a small
    // dot in the severity colour, and the count rendered in the same
    // family.  Colours match the site design tokens (rose / amber / slate).
    $severityStyles = [
        'high'   => ['dot' => 'bg-rose-500',  'count' => 'text-rose-700'],
        'medium' => ['dot' => 'bg-amber-500', 'count' => 'text-amber-700'],
        'low'    => ['dot' => 'bg-slate-400', 'count' => 'text-slate-700'],
    ];
@endphp

<div class="space-y-3">

    {{-- HERO ---------------------------------------------------------- --}}
    <x-page-header
        eyebrow="Owner"
        title="Business Command Centre"
        subtitle="What needs attention, what moved, and where the money stands."
        class="mb-2">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('admin.reports.index')">Reports</x-button>
            <x-button variant="secondary" size="sm" :href="route('admin.audit-log')">Audit log</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- PERIOD BAR ---------------------------------------------------- --}}
    {{-- Compact stepper: previous / month input / next.  Sits directly
         under the heading so the owner never has to hunt for it, and
         renders the resolved window on the right in plain English. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="stepMonth(-1)"
                class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                aria-label="Previous month">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
                class="h-7 rounded-md border-slate-300 px-2 text-xs font-semibold text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <button type="button" wire:click="stepMonth(1)" @disabled($atCurrentMonth)
                class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Next month">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <p class="text-[11px] font-medium text-slate-500">
            {{ $from->format('d M Y') }} &ndash; {{ $to->format('d M Y') }} &middot; MTD &middot; delivered-date basis
        </p>
    </div>

    {{-- NEEDS ATTENTION ----------------------------------------------- --}}
    {{-- Single compact card with severity-dot rows.  Hidden entirely
         when nothing is outstanding -- an empty alerts panel on the
         morning check was noise the previous roll-up carried and the
         owner did not want. --}}
    @if($attention->isNotEmpty())
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <p class="text-[13px] font-semibold text-slate-900">Needs attention</p>
                <p class="text-[11px] text-slate-500">{{ $attention->count() }} {{ \Illuminate\Support\Str::plural('item', $attention->count()) }}</p>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach($attention as $row)
                    @php $style = $severityStyles[$row['severity']] ?? $severityStyles['low']; @endphp
                    <li>
                        <a href="{{ $row['href'] }}" class="grid grid-cols-[10px_1fr_auto] items-center gap-3 px-4 py-2 transition hover:bg-slate-50/70">
                            <span class="h-1.5 w-1.5 rounded-full {{ $style['dot'] }}"></span>
                            <span class="min-w-0">
                                <span class="block truncate text-[12px] font-semibold text-slate-900">{{ $row['label'] }}</span>
                                <span class="block truncate text-[10.5px] text-slate-500">{{ $row['note'] }}</span>
                            </span>
                            <span class="text-[18px] font-bold tabular-nums {{ $style['count'] }}">{{ $num($row['count']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- KPI STRIP ----------------------------------------------------- --}}
    {{-- Six compact tiles: delivered / invoiced / still to bill / fuel
         / petty cash / licence.  Fuel credit lives as helper text on
         the Fuel MTD tile per the mockup -- it is a balance, not a
         primary KPI, and a standalone tile was wasteful. --}}
    @php
        $unclaimed = max(0.0, $cashIssued - $cashSpent);
        $overspend = max(0.0, $cashSpent - $cashIssued);
    @endphp
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">

        <x-dash.kpi
            :compact="true"
            label="Deliveries"
            :value="$num($deliveredMonth)"
            color="green"
            :href="route('admin.deliveries')"
            :trend="$deliveredTrend"
            :helper="'ProSelver-executed &middot; ' . $anchor->format('F')" />

        <x-dash.kpi
            :compact="true"
            label="Invoiced"
            :value="$money($invoicedValue)"
            color="purple"
            :href="route('admin.invoices.index', ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString()])"
            :trend="$invoicedTrend"
            helper="Captured invoice value - MTD" />

        <x-dash.kpi
            :compact="true"
            label="Still to bill"
            :value="$money($unbilledValue)"
            :color="$openInvoicing > 0 ? 'orange' : 'green'"
            :href="route('admin.dashboard.finance')"
            :trend="$unbilledTrend"
            :helper="$num($openInvoicing) . ' delivered movements pending invoice capture'" />

        {{-- Fuel MTD: rand from cash slips (or TFN litres when the
             sub-account has them), with the TFN credit balance as
             supporting helper text so it stays on the strip without
             eating a whole tile. --}}
        <x-dash.kpi
            :compact="true"
            label="Fuel MTD"
            :value="$fuel['configured'] && $fuel['tfn_litres'] > 0 ? $num($fuel['tfn_litres']) . ' L' : $money($slipFuelMonth)"
            color="orange"
            :href="route('admin.fuel')"
            :trend="$fuelTrend"
            :helper="$fuel['available'] !== null
                ? ('Fuel credit available: ' . $money($fuel['available']))
                : (!$fuel['configured']
                    ? 'From driver fuel slips - TFN not configured'
                    : 'TFN unreachable - showing cash fuel slips')" />

        <x-dash.kpi
            :compact="true"
            label="Petty cash"
            :value="$money($cashSpent)"
            :color="$unclaimed > 100 ? 'amber' : ($overspend > 100 ? 'red' : 'green')"
            :href="route('admin.overview')"
            :trend="$cashTrend"
            :helper="$money($cashIssued) . ' issued - '
                . ($unclaimed > 1 ? $money($unclaimed) . ' unclaimed by drivers'
                    : ($overspend > 1 ? $money($overspend) . ' spent over issued'
                    : 'balanced'))" />

        <x-dash.kpi
            :compact="true"
            label="Platform licence"
            :value="$licence ? $money($licence['total_incl_vat']) : '-'"
            color="blue"
            :href="route('admin.billing')"
            :helper="$licence
                ? $num($licence['moves']) . ' moves x ' . $money($licence['per_move']) . ' - incl. VAT'
                : 'Licence metering is currently disabled'" />
    </div>

    {{-- MAIN ROW: 70/30 ----------------------------------------------- --}}
    {{-- Deliveries mini-chart on the left; billing exceptions on the
         right.  Chart height capped so the whole page stays inside
         approximately 1.2 desktop viewports. --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1.7fr_.85fr]">

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-start justify-between border-b border-slate-100 px-4 py-2.5">
                <div>
                    <h2 class="text-[13px] font-semibold text-slate-900">Deliveries</h2>
                    <p class="mt-0.5 text-[10.5px] text-slate-500">{{ $anchor->format('F Y') }} &middot; daily completed movements</p>
                </div>
                <a href="{{ route('admin.reports.index') }}" class="text-[10.5px] font-semibold text-blue-600 hover:text-blue-700">Full report &rarr;</a>
            </div>
            <div class="px-4 pb-3 pt-2.5">
                <div class="flex items-baseline gap-4">
                    <span class="text-[27px] font-bold tabular-nums text-slate-900">{{ $num($deliveredMonth) }}</span>
                    <span class="text-[11px] text-slate-500">delivered this month</span>
                    <span class="ml-auto text-[11px] text-slate-500">Peak day: <b class="text-slate-900">{{ $num($deliveriesPeak) }}</b></span>
                </div>

                @if($deliveredMonth === 0)
                    <div class="flex h-[190px] items-center justify-center text-[11px] text-slate-400">No deliveries in {{ $anchor->format('F Y') }}</div>
                @else
                    @php
                        $count = count($deliveriesSeries);
                        $chartH = 190;
                        $chartW = max(480, $count * 22);
                        $groupW = $chartW / max($count, 1);
                        $barW = max(3, $groupW - 6);
                        $labelStep = $count >= 30 ? 5 : ($count >= 15 ? 3 : 1);
                    @endphp
                    <div class="mt-2 overflow-x-auto">
                        <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 22 }}" class="h-[210px] w-full min-w-[480px]" preserveAspectRatio="none">
                            @for($i = 1; $i <= 4; $i++)
                                <line x1="0" x2="{{ $chartW }}" y1="{{ $chartH - ($chartH / 4) * $i }}" y2="{{ $chartH - ($chartH / 4) * $i }}" stroke="#f1f5f9" stroke-width="1"/>
                            @endfor
                            @foreach($deliveriesSeries as $i => $d)
                                @php
                                    $gx = $i * $groupW + (($groupW - $barW) / 2);
                                    $h = $d['count'] > 0 ? ($d['count'] / $deliveriesPeak) * ($chartH - 6) : 2;
                                    $fill = $d['count'] > 0 ? '#0aae78' : '#e9eef5';
                                @endphp
                                <rect x="{{ $gx }}" y="{{ $chartH - $h }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="2"/>
                                @if($i === 0 || ($i + 1) % $labelStep === 0 || $i === $count - 1)
                                    <text x="{{ $gx + $barW / 2 }}" y="{{ $chartH + 14 }}" text-anchor="middle" font-size="9" fill="#8190a5" font-family="ui-sans-serif,system-ui">{{ $d['date']->format('d') }}</text>
                                @endif
                            @endforeach
                        </svg>
                    </div>
                @endif
            </div>
        </section>

        {{-- Billing & reconciliation - condensed exception list.
             Combines the four financially important indicators into one
             card so the owner reads the money position at a glance. --}}
        @php
            $missingNumber = collect($attention)->firstWhere('label', 'Movements missing an invoice number');
            $reconQueries = collect($attention)->firstWhere('label', 'Open reconciliation queries');
            $changesPending = collect($attention)->firstWhere('label', 'Booking change requests pending');
        @endphp
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-start justify-between border-b border-slate-100 px-4 py-2.5">
                <div>
                    <h2 class="text-[13px] font-semibold text-slate-900">Billing &amp; reconciliation</h2>
                    <p class="mt-0.5 text-[10.5px] text-slate-500">Items affecting cash collection</p>
                </div>
            </div>
            <ul class="px-4 py-1.5 text-[12px]">
                <li class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <a href="{{ route('admin.invoices.index', ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString(), 'completion' => 'all']) }}" class="text-slate-700 hover:text-slate-900">Missing invoice numbers</a>
                    <span class="font-bold tabular-nums {{ ($missingNumber['count'] ?? 0) > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $num($missingNumber['count'] ?? 0) }}</span>
                </li>
                <li class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <a href="{{ route('admin.petty-cash.reconciliation') }}" class="text-slate-700 hover:text-slate-900">Open reconciliation queries</a>
                    <span class="font-bold tabular-nums {{ ($reconQueries['count'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ $num($reconQueries['count'] ?? 0) }}</span>
                </li>
                <li class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <a href="{{ route('admin.change-requests.index') }}" class="text-slate-700 hover:text-slate-900">Booking changes pending</a>
                    <span class="font-bold tabular-nums {{ ($changesPending['count'] ?? 0) > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $num($changesPending['count'] ?? 0) }}</span>
                </li>
                <li class="flex items-center justify-between border-b border-slate-100 py-2.5">
                    <span class="text-slate-700">Invoiced this month</span>
                    <span class="font-bold tabular-nums text-slate-900">{{ $money($invoicedValue) }}</span>
                </li>
                <li class="flex items-center justify-between py-2.5">
                    <a href="{{ route('admin.fuel') }}" class="text-slate-700 hover:text-slate-900">Fuel credit available</a>
                    <span class="font-bold tabular-nums text-slate-900">{{ $fuel['available'] !== null ? $money($fuel['available']) : '-' }}</span>
                </li>
            </ul>
        </section>
    </div>

    {{-- BOTTOM ROW: 65/35 --------------------------------------------- --}}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1.3fr_.7fr]">

        {{-- Top customers: compact table, no giant progress bars.
             Sorted by deliveries; captured invoice value shown alongside
             so the two questions ("who's busiest" / "who paid") answer
             in one glance instead of two panels. --}}
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-start justify-between border-b border-slate-100 px-4 py-2.5">
                <div>
                    <h2 class="text-[13px] font-semibold text-slate-900">Top customers</h2>
                    <p class="mt-0.5 text-[10.5px] text-slate-500">{{ $anchor->format('F Y') }} &middot; deliveries and captured invoice value</p>
                </div>
                <a href="{{ route('admin.reports.index') }}" class="text-[10.5px] font-semibold text-blue-600 hover:text-blue-700">Customer report &rarr;</a>
            </div>
            @if($volumeRows->isEmpty())
                <div class="px-4 py-8 text-center text-[12px] text-slate-500">No deliveries yet in {{ $anchor->format('F Y') }}.</div>
            @else
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="border-b border-slate-100 px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Customer</th>
                            <th class="border-b border-slate-100 px-4 py-2 text-right text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Deliveries</th>
                            <th class="border-b border-slate-100 px-4 py-2 text-right text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Invoiced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($volumeRows as $i => $row)
                            <tr class="border-b border-slate-100 last:border-b-0">
                                <td class="px-4 py-2.5 text-[11.5px]">
                                    <span class="mr-2 inline-grid h-[22px] w-[22px] place-items-center rounded-md bg-slate-100 text-[10px] font-semibold text-slate-500">{{ $i + 1 }}</span>
                                    <span class="font-semibold text-slate-900">{{ $row->company_name }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right text-[11.5px] font-semibold tabular-nums text-slate-900">{{ $num($row->moves) }}</td>
                                <td class="px-4 py-2.5 text-right text-[11.5px] tabular-nums {{ (float) $row->invoiced_sum > 0 ? 'font-semibold text-slate-900' : 'text-slate-400' }}">{{ (float) $row->invoiced_sum > 0 ? $money($row->invoiced_sum) : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- Business status: 2x2 mini-stat card.  Folds at-risk +
             yesterday's audit summary into one card, replacing three
             separate panels the previous page carried. --}}
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-4 py-2.5">
                <h2 class="text-[13px] font-semibold text-slate-900">Business status</h2>
                <p class="mt-0.5 text-[10.5px] text-slate-500">Owner-level health check</p>
            </div>
            <div class="grid grid-cols-2 gap-2.5 p-3.5">
                <a href="{{ route('admin.dashboard.ops') }}" class="block rounded-lg border border-slate-100 p-3 transition hover:border-slate-200 hover:bg-slate-50/50">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.09em] text-slate-500">Movements at risk</p>
                    <p class="mt-1.5 text-[22px] font-bold tabular-nums {{ $atRisk > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $num($atRisk) }}</p>
                    <p class="text-[10.5px] text-slate-500">{{ $atRisk > 0 ? 'Needs a look' : 'All clear' }}</p>
                </a>
                <a href="{{ route('admin.audit-log', ['dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="block rounded-lg border border-slate-100 p-3 transition hover:border-slate-200 hover:bg-slate-50/50">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.09em] text-slate-500">Changes yesterday</p>
                    <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['total']) }}</p>
                    <p class="text-[10.5px] text-slate-500">{{ $num($digest['people']) }} {{ \Illuminate\Support\Str::plural('person', $digest['people']) }}</p>
                </a>
                <a href="{{ route('admin.audit-log', ['actionType' => 'updated', 'dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="block rounded-lg border border-slate-100 p-3 transition hover:border-slate-200 hover:bg-slate-50/50">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.09em] text-slate-500">Updated</p>
                    <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['updated']) }}</p>
                    <p class="text-[10.5px] text-slate-500">Yesterday</p>
                </a>
                <a href="{{ route('admin.audit-log', ['actionType' => 'created', 'dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="block rounded-lg border border-slate-100 p-3 transition hover:border-slate-200 hover:bg-slate-50/50">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.09em] text-slate-500">Created</p>
                    <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['created']) }}</p>
                    <p class="text-[10.5px] text-slate-500">Yesterday</p>
                </a>
            </div>
        </section>
    </div>
</div>
