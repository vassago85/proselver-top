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
     *     tfn_spend: ?float,
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
            // TFN /api/Transactions: 100-row cap, 3-month lookback.
            // Month-start pull for the picked month, plus a 24h merge when
            // viewing the current month so today's fills aren't dropped
            // once the month page is full (same as Fuel ops Litres MTD).
            try {
                $fromMonth = $client->transactions($anchor->copy()->startOfMonth()->toDateTimeImmutable());
                if ($anchor->equalTo(now()->startOfMonth())) {
                    $recent = $client->transactions(now()->subDay()->toDateTimeImmutable());
                    $txPayload = $this->mergeFuelTransactions($fromMonth, $recent);
                } else {
                    $txPayload = $fromMonth;
                }
            } catch (\Throwable $e) {
                $txPayload = [];
                $ok = false;
            }
        } else {
            $balancePayload = $fixtures->balance();
            $aggregatePayload = $fixtures->aggregateLitres();
            $txPayload = $fixtures->transactions();
        }

        $available = $balancePayload['AccountAvailableBalance']
            ?? $balancePayload['AvailableCredit']
            ?? null;
        $balance = $balancePayload['AccountBalance']
            ?? $balancePayload['Balance']
            ?? null;

        // Only count liquid fuel as "litres" / spend -- OS is nights,
        // WSH is washes, etc.  Include every diesel / petrol grade.
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

        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd = $anchor->copy()->endOfMonth();

        $aggregateLitres = 0.0;
        foreach ((array) $aggregatePayload as $row) {
            if ($isLitreCode($row['ProductCode'] ?? '')) {
                $aggregateLitres += $rowLitres($row);
            }
        }

        $txLitres = 0.0;
        $txSpend = 0.0;
        foreach ((array) $txPayload as $row) {
            if (!$isLitreCode($row['ProductCode'] ?? '')) {
                continue;
            }
            // Skip account payments / credits (TFN puts them on the same feed).
            $type = strtoupper(trim((string) ($row['TransactionTypeCode'] ?? $row['TransactionType'] ?? '')));
            if (in_array($type, ['CC', 'CD', 'CX', 'PAYMENT'], true)) {
                continue;
            }
            if (strtoupper(trim((string) ($row['ProductCode'] ?? ''))) === 'EW') {
                continue;
            }

            // Keep the picked month only (24h merge can spill prior days
            // across a month boundary at month-start).
            $raw = $row['CapturedDate'] ?? $row['TransactionDate'] ?? null;
            if ($raw) {
                try {
                    $when = Carbon::parse($raw);
                    if ($when->lt($monthStart) || $when->gt($monthEnd)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Keep the row if the date is unparseable.
                }
            }

            $litres = $rowLitres($row);
            if ($litres <= 0) {
                continue;
            }

            $txLitres += $litres;
            // TFN convention: purchases are negative Amount; spend is abs().
            $txSpend += abs((float) ($row['Amount'] ?? 0));
        }

        $litres = max($aggregateLitres, $txLitres);

        return [
            'available' => $available !== null ? (float) $available : null,
            'balance' => $balance !== null ? (float) $balance : null,
            'tfn_litres' => $isLive ? $litres : null,
            // Rand spent at the pump this month from TFN fills.  Primary
            // figure on the Owner Fuel MTD tile; litres ride as helper.
            'tfn_spend' => $isLive ? $txSpend : null,
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
            'cashIssuedTrend' => $this->trend($cash['issued'], $prevCash['issued']),

            'fuel' => $fuel,
            'slipFuelMonth' => $slipFuelMonth,
            'fuelTrend' => $this->trend(
                (float) ($fuel['tfn_spend'] ?? $slipFuelMonth),
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

    $fuelSpend = ($fuel['tfn_spend'] ?? 0) > 0 ? (float) $fuel['tfn_spend'] : (float) $slipFuelMonth;
    $unclaimed = max(0.0, $cashIssued - $cashSpent);
    $overspend = max(0.0, $cashSpent - $cashIssued);

    $severityDot = [
        'high'   => 'bg-rose-500',
        'medium' => 'bg-amber-500',
        'low'    => 'bg-slate-400',
    ];
    $severityCount = [
        'high'   => 'text-rose-600',
        'medium' => 'text-amber-600',
        'low'    => 'text-slate-600',
    ];

    $trendArrow = function (?array $t) {
        if (!$t) return '';
        $up = ($t['dir'] ?? '') === 'up';
        $color = $up ? 'text-emerald-600' : 'text-rose-600';
        $glyph = $up ? '↑' : '↓';
        return '<span class="ml-2 text-[13px] font-semibold ' . $color . '">' . $glyph . ' ' . e($t['label'] ?? '') . '</span>';
    };
@endphp

{{-- Light Live Ops wall — same dense layout as the dark mock, but Trident
     white/slate so it sits with the sidebar instead of fighting it. --}}
<div class="owner-wall space-y-3.5">

    <style>
        .owner-wall .ow-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
        .owner-wall .ow-label{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:700}
        .owner-wall .ow-hero{font-size:clamp(2.1rem,3.2vw,3.1rem);line-height:1;font-weight:780;letter-spacing:-.02em;font-variant-numeric:tabular-nums;color:#0f172a}
        .owner-wall .ow-bar{animation:ow-grow .7s ease-out both}
        @keyframes ow-grow{from{transform:scaleY(0);transform-origin:bottom}to{transform:scaleY(1);transform-origin:bottom}}
        @keyframes ow-pulse{0%,100%{opacity:1}50%{opacity:.45}}
        .owner-wall .ow-alert-pulse{animation:ow-pulse 1.6s ease-in-out infinite}
    </style>

    {{-- TOP BAR --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ow-label text-blue-600">Owner</p>
            <h1 class="mt-1 text-[26px] sm:text-[30px] font-bold tracking-tight text-slate-900">Business Command Centre</h1>
            <p class="mt-1 text-[13px] text-slate-500">What needs attention, what moved, and where the money stands.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 py-1.5 shadow-sm">
                <button type="button" wire:click="stepMonth(-1)"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50"
                    aria-label="Previous month">‹</button>
                <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
                    class="h-7 rounded-md border-slate-300 px-2 text-xs font-semibold text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <button type="button" wire:click="stepMonth(1)" @disabled($atCurrentMonth)
                    class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-35 disabled:cursor-not-allowed"
                    aria-label="Next month">›</button>
            </div>
            <a href="{{ route('admin.reports.index') }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Reports</a>
            <a href="{{ route('admin.audit-log') }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Audit log</a>
        </div>
    </div>

    <p class="text-[11px] text-slate-500 -mt-1">
        {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} · MTD · delivered-date basis
    </p>

    <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-3.5">

        <div class="flex flex-col gap-3.5 min-w-0">

            {{-- HERO: Fuel spend + Petty cash issued --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <a href="{{ route('admin.fuel') }}" class="ow-card p-5 block transition hover:border-slate-300 hover:shadow-md group">
                    <div class="flex items-center justify-between">
                        <p class="ow-label">Fuel spend MTD</p>
                        <span class="h-2 w-2 rounded-full bg-cyan-500 group-hover:scale-125 transition"></span>
                    </div>
                    <p class="ow-hero mt-4">
                        {{ $money($fuelSpend) }}
                        {!! $trendArrow($fuelTrend) !!}
                    </p>
                    <p class="mt-3 text-[12px] text-slate-500">
                        @if(($fuel['tfn_litres'] ?? 0) > 0)
                            <span class="text-cyan-700 font-semibold tabular-nums">{{ $num($fuel['tfn_litres']) }} L</span>
                            <span class="mx-1.5 text-slate-300">·</span>
                        @endif
                        @if($fuel['available'] !== null)
                            credit <span class="text-slate-900 font-semibold tabular-nums">{{ $money($fuel['available']) }}</span>
                        @elseif(($fuel['tfn_spend'] ?? 0) <= 0 && $slipFuelMonth > 0)
                            from cash fuel slips
                        @else
                            TFN pump activity
                        @endif
                    </p>
                </a>

                <a href="{{ route('admin.overview') }}" class="ow-card p-5 block transition hover:border-slate-300 hover:shadow-md group">
                    <div class="flex items-center justify-between">
                        <p class="ow-label">Petty cash issued</p>
                        <span class="h-2 w-2 rounded-full {{ $unclaimed > 100 ? 'bg-amber-500' : 'bg-emerald-500' }} group-hover:scale-125 transition"></span>
                    </div>
                    <p class="ow-hero mt-4 {{ $unclaimed > 100 ? 'text-amber-700' : '' }}">
                        {{ $money($cashIssued) }}
                        {!! $trendArrow($cashIssuedTrend ?? null) !!}
                    </p>
                    <p class="mt-3 text-[12px] text-slate-500">
                        {{ $money($cashSpent) }} claimed
                        @if($unclaimed > 1)
                            <span class="mx-1.5 text-slate-300">·</span>
                            <span class="text-amber-700 font-semibold tabular-nums">{{ $money($unclaimed) }} unclaimed by drivers</span>
                        @elseif($overspend > 1)
                            <span class="mx-1.5 text-slate-300">·</span>
                            <span class="text-rose-600 font-semibold">{{ $money($overspend) }} over issued</span>
                        @else
                            <span class="mx-1.5 text-slate-300">·</span>
                            balanced
                        @endif
                    </p>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_.9fr] gap-3.5">
                <section class="ow-card overflow-hidden flex flex-col">
                    <div class="flex items-start justify-between px-4 py-3 border-b border-slate-100">
                        <div>
                            <h2 class="text-[13px] font-semibold text-slate-900">Daily deliveries</h2>
                            <p class="mt-0.5 text-[10.5px] text-slate-500">
                                <span class="font-semibold text-slate-900 tabular-nums">{{ $num($deliveredMonth) }}</span> this month
                                · peak <span class="font-semibold text-slate-900 tabular-nums">{{ $num($deliveriesPeak) }}</span>
                                @if($deliveredTrend)
                                    · {!! $trendArrow($deliveredTrend) !!}
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('admin.deliveries') }}" class="text-[10.5px] font-semibold text-blue-600 hover:text-blue-700">Deliveries →</a>
                    </div>
                    <div class="px-4 py-3 flex-1">
                        @if($deliveredMonth === 0)
                            <div class="flex h-[180px] items-center justify-center text-[12px] text-slate-400">No deliveries in {{ $anchor->format('F Y') }}</div>
                        @else
                            @php
                                $count = count($deliveriesSeries);
                                $chartH = 160;
                                $chartW = max(420, $count * 18);
                                $groupW = $chartW / max($count, 1);
                                $barW = max(3, $groupW - 5);
                                $labelStep = $count >= 30 ? 5 : ($count >= 15 ? 3 : 1);
                            @endphp
                            <div class="overflow-x-auto">
                                <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 22 }}" class="h-[180px] w-full min-w-[420px]" preserveAspectRatio="none">
                                    @for($i = 1; $i <= 4; $i++)
                                        <line x1="0" x2="{{ $chartW }}" y1="{{ $chartH - ($chartH / 4) * $i }}" y2="{{ $chartH - ($chartH / 4) * $i }}" stroke="#f1f5f9" stroke-width="1"/>
                                    @endfor
                                    @foreach($deliveriesSeries as $i => $d)
                                        @php
                                            $gx = $i * $groupW + (($groupW - $barW) / 2);
                                            $h = $d['count'] > 0 ? ($d['count'] / $deliveriesPeak) * ($chartH - 4) : 2;
                                            $fill = $d['count'] > 0 ? (($i % 2 === 0) ? '#10b981' : '#06b6d4') : '#e2e8f0';
                                        @endphp
                                        <rect class="ow-bar" style="animation-delay: {{ min(0.6, $i * 0.012) }}s" x="{{ $gx }}" y="{{ $chartH - $h }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="2"/>
                                        @if($i === 0 || ($i + 1) % $labelStep === 0 || $i === $count - 1)
                                            <text x="{{ $gx + $barW / 2 }}" y="{{ $chartH + 14 }}" text-anchor="middle" font-size="9" fill="#94a3b8" font-family="ui-sans-serif,system-ui">{{ $d['date']->format('d') }}</text>
                                        @endif
                                    @endforeach
                                </svg>
                            </div>
                        @endif
                    </div>
                </section>

                <div class="grid grid-cols-2 gap-2.5 content-start">
                    <a href="{{ route('admin.invoices.index', ['dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString()]) }}" class="ow-card p-3.5 block hover:border-slate-300 transition">
                        <p class="ow-label">Invoiced</p>
                        <p class="mt-2 text-[22px] font-bold tabular-nums text-slate-900">{{ $money($invoicedValue) }}</p>
                        <p class="mt-1 text-[10.5px] text-slate-500">Captured MTD</p>
                    </a>
                    <a href="{{ route('admin.dashboard.finance') }}" class="ow-card p-3.5 block hover:border-slate-300 transition">
                        <p class="ow-label">Still to bill</p>
                        <p class="mt-2 text-[22px] font-bold tabular-nums {{ $openInvoicing > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $money($unbilledValue) }}</p>
                        <p class="mt-1 text-[10.5px] text-slate-500">{{ $num($openInvoicing) }} pending capture</p>
                    </a>
                    <a href="{{ route('admin.deliveries') }}" class="ow-card p-3.5 block hover:border-slate-300 transition">
                        <p class="ow-label">Deliveries</p>
                        <p class="mt-2 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($deliveredMonth) }}</p>
                        <p class="mt-1 text-[10.5px] text-slate-500">ProSelver · {{ $anchor->format('F') }}</p>
                    </a>
                    <a href="{{ route('admin.billing') }}" class="ow-card p-3.5 block hover:border-slate-300 transition">
                        <p class="ow-label">Platform licence</p>
                        @if($licence)
                            <p class="mt-2 text-[22px] font-bold tabular-nums text-slate-900">{{ $money($licence['total_incl_vat']) }}</p>
                            <p class="mt-1 text-[10.5px] text-slate-500">{{ $num($licence['moves']) }} × {{ $money($licence['per_move']) }} incl. VAT</p>
                        @else
                            <p class="mt-2 text-[14px] font-semibold text-slate-500">Licence metering is currently disabled</p>
                        @endif
                    </a>
                </div>
            </div>

            <section class="ow-card p-3.5">
                <div class="mb-3">
                    <h2 class="text-[13px] font-semibold text-slate-900">Business status</h2>
                    <p class="text-[10.5px] text-slate-500">Owner-level health · yesterday</p>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                    <a href="{{ route('admin.dashboard.ops') }}" class="rounded-lg border border-slate-100 bg-slate-50/80 p-3 hover:border-slate-200 transition">
                        <p class="ow-label">At risk</p>
                        <p class="mt-1.5 text-[22px] font-bold tabular-nums {{ $atRisk > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $num($atRisk) }}</p>
                        <p class="text-[10.5px] text-slate-500">{{ $atRisk > 0 ? 'Needs a look' : 'All clear' }}</p>
                    </a>
                    <a href="{{ route('admin.audit-log', ['dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="rounded-lg border border-slate-100 bg-slate-50/80 p-3 hover:border-slate-200 transition">
                        <p class="ow-label">Changes</p>
                        <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['total']) }}</p>
                        <p class="text-[10.5px] text-slate-500">{{ $num($digest['people']) }} people</p>
                    </a>
                    <a href="{{ route('admin.audit-log', ['actionType' => 'updated', 'dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="rounded-lg border border-slate-100 bg-slate-50/80 p-3 hover:border-slate-200 transition">
                        <p class="ow-label">Updated</p>
                        <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['updated']) }}</p>
                        <p class="text-[10.5px] text-slate-500">Yesterday</p>
                    </a>
                    <a href="{{ route('admin.audit-log', ['actionType' => 'created', 'dateFrom' => $digest['date']->toDateString(), 'dateTo' => $digest['date']->toDateString()]) }}" class="rounded-lg border border-slate-100 bg-slate-50/80 p-3 hover:border-slate-200 transition">
                        <p class="ow-label">Created</p>
                        <p class="mt-1.5 text-[22px] font-bold tabular-nums text-slate-900">{{ $num($digest['created']) }}</p>
                        <p class="text-[10.5px] text-slate-500">Yesterday</p>
                    </a>
                </div>
            </section>
        </div>

        <aside class="flex flex-col gap-3.5 min-h-0">
            <section class="ow-card overflow-hidden flex flex-col {{ $attention->isNotEmpty() && $attention->contains(fn ($r) => $r['severity'] === 'high') ? 'ring-1 ring-rose-200' : '' }}">
                <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        @if($attention->isNotEmpty())
                            <span class="ow-alert-pulse inline-flex h-6 w-6 items-center justify-center rounded-full bg-rose-50 text-rose-600 text-sm font-bold">!</span>
                        @endif
                        <div>
                            <h2 class="text-[13px] font-semibold text-slate-900">Needs attention</h2>
                            <p class="text-[10.5px] text-slate-500">
                                @if($attention->isEmpty())
                                    All clear
                                @else
                                    {{ $attention->count() }} {{ \Illuminate\Support\Str::plural('item', $attention->count()) }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                @if($attention->isEmpty())
                    <div class="px-4 py-8 text-center text-[12px] text-slate-400">Nothing waiting on you.</div>
                @else
                    <ul class="divide-y divide-slate-100 max-h-[280px] overflow-y-auto">
                        @foreach($attention as $row)
                            <li>
                                <a href="{{ $row['href'] }}" class="grid grid-cols-[8px_1fr_auto] gap-2.5 items-center px-4 py-2.5 hover:bg-slate-50/80 transition">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $severityDot[$row['severity']] ?? $severityDot['low'] }}"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-[12px] font-semibold text-slate-900">{{ $row['label'] }}</span>
                                        <span class="block truncate text-[10px] text-slate-500">{{ $row['note'] }}</span>
                                    </span>
                                    <span class="text-[18px] font-bold tabular-nums {{ $severityCount[$row['severity']] ?? $severityCount['low'] }}">{{ $num($row['count']) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="ow-card overflow-hidden flex-1 flex flex-col min-h-0">
                <div class="flex items-start justify-between px-4 py-3 border-b border-slate-100">
                    <div>
                        <h2 class="text-[13px] font-semibold text-slate-900">Top customers</h2>
                        <p class="mt-0.5 text-[10.5px] text-slate-500">{{ $anchor->format('F') }} · deliveries</p>
                    </div>
                    <a href="{{ route('admin.reports.index') }}" class="text-[10.5px] font-semibold text-blue-600 hover:text-blue-700">Report →</a>
                </div>
                @if($volumeRows->isEmpty())
                    <div class="px-4 py-8 text-center text-[12px] text-slate-400">No deliveries yet.</div>
                @else
                    <ul class="divide-y divide-slate-100 flex-1">
                        @foreach($volumeRows as $i => $row)
                            <li class="flex items-center gap-2.5 px-4 py-2.5">
                                <span class="inline-grid h-6 w-6 place-items-center rounded-md bg-slate-100 text-[10px] font-bold text-slate-500">{{ $i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate text-[12px] font-semibold text-slate-900">{{ $row->company_name }}</span>
                                <span class="text-[13px] font-bold tabular-nums text-emerald-600">{{ $num($row->moves) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
</div>
