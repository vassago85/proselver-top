<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/*
 * Customer dashboard.
 *
 * For DEALER companies this page is a tablet-first six-card console
 * built on the dealer_stock ledger.  Each card is a tap target that
 * filters an in-page list below.  For non-dealer customer tenants
 * (OEMs, body builders, etc.) the original jobs-based KPI strip is
 * preserved so we don't regress their workflow.
 *
 * Card model (Phase 2 -- 8 cards):
 *   1. At premises               (premises slate)
 *   2. Reserved                  (status=reserved amber)
 *   3. At body builder / fitment (body_builder amber)
 *   4. Scheduled for movement    (joined transport_jobs status, sky)
 *   5. In transit                (in_transit blue)
 *   6. At another storage        (storage indigo)
 *   7. On demo with customer     (on_demo / status=demo teal)
 *   8. Sold — awaiting handover  (status=sold + delivered_at IS NULL emerald)
 *
 * Counts are #[Computed] properties so tapping a card doesn't
 * re-run unrelated data fetches; only the filtered list re-renders.
 */
new #[Layout('components.layouts.app')] class extends Component {
    /**
     * Bucket currently selected by the user.  null = "show all" --
     * the in-page list defaults to every visible stock row.
     */
    #[Url(as: 'card')]
    public ?string $selectedBucket = null;

    /**
     * Toggle one of the six cards.  Re-tapping the active card
     * clears the filter.  Whitelist guard prevents wire payload
     * tampering from setting selectedBucket to a junk string.
     */
    public function selectBucket(?string $bucket): void
    {
        $allowed = [
            'premises', 'body_builder', 'storage', 'in_transit',
            'on_demo', 'scheduled', 'recently_delivered',
            'reserved', 'awaiting_handover',
        ];

        if ($bucket === $this->selectedBucket || $bucket === null) {
            $this->selectedBucket = null;
            return;
        }

        if (!in_array($bucket, $allowed, true)) {
            $this->selectedBucket = null;
            return;
        }

        $this->selectedBucket = $bucket;
    }

    /**
     * Map dashboard card keys to a stock-index URL.  Some cards map
     * to bucket filters; others (reserved, awaiting_handover) map to
     * the status filter instead since they aren't physical buckets.
     *
     * Returns ['bucket' => ...] or ['status' => ...] params, ready
     * to spread into route('customer.stock.index', $params).
     */
    public function stockIndexParams(string $cardKey): array
    {
        return match ($cardKey) {
            'recently_delivered' => ['bucket' => 'recently_sold'],
            'awaiting_handover'  => ['status' => DealerStock::STATUS_SOLD, 'awaiting_handover' => 1],
            'reserved'           => ['status' => DealerStock::STATUS_RESERVED],
            default              => ['bucket' => $cardKey],
        };
    }

    /**
     * Apply the bucket filter to a base DealerStock query.  The same
     * helper drives both #[Computed] counts and the drill-down list,
     * so a tap on "On demo" surfaces exactly the rows the card
     * counted.
     */
    protected function applyBucketScope($query, string $bucket)
    {
        return match ($bucket) {
            'premises'           => $query->atPremises(),
            'body_builder'       => $query->atBodyBuilder(),
            'storage'            => $query->atStorage(),
            'in_transit'         => $query->inTransit(),
            'on_demo'            => $query->onDemo(),
            'scheduled'          => $query->scheduledForMovement(),
            'recently_delivered' => $query->recentlyDelivered(),
            'reserved'           => $query->reserved(),
            'awaiting_handover'  => $query->soldAwaitingHandover(),
            default              => $query,
        };
    }

    /** Base "visible to me, not archived" query for the dealer view. */
    protected function dealerStockBase()
    {
        return DealerStock::visibleTo(auth()->user())->active();
    }

    // ----- Per-card #[Computed] counts ------------------------------
    // Each card hits exactly one of the (dealer_company_id,
    // current_location_type / status) indexes -- O(1) per tap.

    #[Computed]
    public function countPremises(): int
    {
        return $this->dealerStockBase()->atPremises()->count();
    }

    #[Computed]
    public function countBodyBuilder(): int
    {
        return $this->dealerStockBase()->atBodyBuilder()->count();
    }

    #[Computed]
    public function countStorage(): int
    {
        return $this->dealerStockBase()->atStorage()->count();
    }

    #[Computed]
    public function countInTransit(): int
    {
        return $this->dealerStockBase()->inTransit()->count();
    }

    #[Computed]
    public function countOnDemo(): int
    {
        return $this->dealerStockBase()->onDemo()->count();
    }

    #[Computed]
    public function countScheduled(): int
    {
        return $this->dealerStockBase()->scheduledForMovement()->count();
    }

    #[Computed]
    public function countRecentlyDelivered(): int
    {
        return $this->dealerStockBase()->recentlyDelivered()->count();
    }

    #[Computed]
    public function countReserved(): int
    {
        return $this->dealerStockBase()->reserved()->count();
    }

    #[Computed]
    public function countAwaitingHandover(): int
    {
        return $this->dealerStockBase()->soldAwaitingHandover()->count();
    }

    /**
     * Drill-down rows for the currently selected card (or the full
     * stock list when no card is active).  Capped at 50 to keep the
     * payload tablet-friendly -- "View all" routes through to the
     * /stock page for the full paginated list.
     */
    #[Computed]
    public function filteredStock()
    {
        $query = $this->dealerStockBase()
            ->with(['brand:id,name', 'currentLocation:id,company_name,city', 'dealerCompany:id,name', 'salesperson:id,name'])
            ->latest('updated_at');

        if ($this->selectedBucket) {
            $query = $this->applyBucketScope($query, $this->selectedBucket);
        }

        return $query->limit(50)->get();
    }

    public function with(): array
    {
        $user = auth()->user();
        $company = $user?->company();

        if (!$company) {
            return [
                'hasCompany' => false,
                'isDealer' => false,
                'isMultiCompany' => false,
                'visibleCompanyCount' => 0,
                'stats' => [],
                'recentJobs' => collect(),
                'addresses' => collect(),
                'addressCount' => 0,
                'teamCount' => 0,
                'awaitingMine' => 0,
                'requiresConfirmation' => false,
            ];
        }

        $visibleCompanyIds = $user->visibleCompanyIds();
        $isMultiCompany = count($visibleCompanyIds) > 1;
        $isDealer = $company->type === Company::TYPE_DEALER;
        $requiresConfirmation = $company->requiresExternalConfirmation();

        // Non-dealer customers retain the original KPI strip; jobs-
        // based counts live here so the dealer code path doesn't pay
        // for them.
        $stats = [];
        $awaitingMine = 0;
        $recentJobs = collect();
        if (!$isDealer) {
            $locationId = $user->isLocationRestricted() ? $user->assignedLocationId() : null;
            $applyDepotScope = function ($query) use ($locationId) {
                if ($locationId) {
                    $query->where(function ($q) use ($locationId) {
                        $q->where('pickup_location_id', $locationId)
                            ->orWhere('delivery_location_id', $locationId);
                    });
                }
                return $query;
            };

            $stats = [
                'pending' => $applyDepotScope(
                    Job::whereIn('company_id', $visibleCompanyIds)
                        ->whereIn('status', [
                            Job::STATUS_PENDING_VERIFICATION,
                            Job::STATUS_RECEIVED,
                            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                            Job::STATUS_CONFIRMATION_ISSUE,
                        ])
                )->count(),
                'in_transit' => $applyDepotScope(
                    Job::whereIn('company_id', $visibleCompanyIds)
                        ->whereIn('status', [
                            Job::STATUS_DRIVER_ASSIGNED,
                            Job::STATUS_READY_FOR_COLLECTION,
                            Job::STATUS_COLLECTED,
                            Job::STATUS_IN_TRANSIT,
                        ])
                )->count(),
                'delivered_month' => $applyDepotScope(
                    Job::whereIn('company_id', $visibleCompanyIds)
                        ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                        ->where('delivered_at', '>=', now()->startOfMonth())
                )->count(),
                'total_completed' => $applyDepotScope(
                    Job::whereIn('company_id', $visibleCompanyIds)
                        ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                )->count(),
            ];

            $awaitingMine = $requiresConfirmation
                ? $applyDepotScope(
                    Job::whereIn('company_id', $visibleCompanyIds)
                        ->where('status', Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION)
                )->count()
                : 0;
        }

        // Recent orders + address book + team count are shown for
        // every customer tenant -- not specific to dealers.
        $recentJobs = Job::whereIn('company_id', $visibleCompanyIds)
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'inventory:id,chassis_number,vin',
                'company:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $addresses = Location::whereIn('company_id', $visibleCompanyIds)
            ->orderBy('company_name')
            ->limit(5)
            ->get(['id', 'company_name', 'city', 'province', 'customer_name', 'company_id']);

        $addressCount = Location::whereIn('company_id', $visibleCompanyIds)->count();
        $teamCount = User::whereHas('companies', fn($q) => $q->whereIn('companies.id', $visibleCompanyIds))->count();

        return [
            'hasCompany' => true,
            'isDealer' => $isDealer,
            'isMultiCompany' => $isMultiCompany,
            'visibleCompanyCount' => count($visibleCompanyIds),
            'stats' => $stats,
            'recentJobs' => $recentJobs,
            'addresses' => $addresses,
            'addressCount' => $addressCount,
            'teamCount' => $teamCount,
            'awaitingMine' => $awaitingMine,
            'requiresConfirmation' => $requiresConfirmation,
        ];
    }
};
?>

<div>
    <x-slot:header>Dashboard</x-slot:header>

    @if(!$hasCompany)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
            <h2 class="text-lg font-semibold text-amber-900">No company linked to your account</h2>
            <p class="mt-2 text-sm text-amber-800">Ask your operations controller to attach your user to a company before you can submit orders.</p>
        </div>
    @else

        @if($isMultiCompany)
            <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                <span class="font-semibold">Group view:</span>
                You can see stock and orders across {{ $visibleCompanyCount }} dealerships in your group.
            </div>
        @endif

        {{-- Hero: welcome + primary CTA --}}
        <div class="mb-6 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 text-white shadow-lg overflow-hidden">
            <div class="px-6 py-6 sm:px-8 sm:py-7 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-blue-200/80">Control · Dispatch · Deliver</p>
                    <h2 class="mt-1 text-xl sm:text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ auth()->user()->company()?->name ?? '—' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if(auth()->user()->hasPermission('submit_booking'))
                        <a href="{{ route('customer.orders.create') }}"
                           class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            New Order
                        </a>
                    @endif
                    <a href="{{ route('customer.orders.index') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-white/10 ring-1 ring-white/20 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">
                        View Orders
                    </a>
                    <a href="{{ route('customer.help') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-white/10 ring-1 ring-white/20 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition"
                       title="In-app user guide for the dealer portal">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                        Help
                    </a>
                </div>
            </div>
        </div>

        {{-- Awaiting confirmation banner (non-dealer customers) --}}
        @if(!$isDealer && $requiresConfirmation && $awaitingMine > 0)
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100">
                    <svg viewBox="0 0 24 24" class="h-4.5 w-4.5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-amber-900">{{ $awaitingMine }} {{ \Illuminate\Support\Str::plural('order', $awaitingMine) }} waiting for your confirmation</p>
                    <p class="text-xs text-amber-800">Confirm so we can dispatch.</p>
                </div>
                <a href="{{ route('customer.orders.index', ['statusFilter' => \App\Models\Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION]) }}"
                   class="shrink-0 rounded-md bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-amber-700 transition">
                    Review now
                </a>
            </div>
        @endif

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        @if($isDealer)
            {{-- ============================================================
                 Dealer dashboard (Phase 2): six tablet-first tap cards
                 backed by dealer_stock.  One signature motion: the cards
                 fade+slide in on mount with a 40ms stagger.  Respects
                 prefers-reduced-motion via Tailwind's motion-safe variant.
                 ============================================================ --}}
            @php
                // 8-card grid: top row mirrors the commercial funnel
                // (Available → Reserved → BB/fitment → Scheduled), bottom
                // row tracks the physical / post-sale journey.
                $cards = [
                    ['key' => 'premises',           'label' => 'At premises',              'count' => $this->countPremises,           'accent' => 'slate',    'icon' => 'office'],
                    ['key' => 'reserved',           'label' => 'Reserved',                 'count' => $this->countReserved,           'accent' => 'amber',    'icon' => 'bookmark'],
                    ['key' => 'body_builder',       'label' => 'At body builder',          'count' => $this->countBodyBuilder,        'accent' => 'amber',    'icon' => 'wrench'],
                    ['key' => 'scheduled',          'label' => 'Scheduled for movement',   'count' => $this->countScheduled,          'accent' => 'sky',      'icon' => 'calendar'],
                    ['key' => 'in_transit',         'label' => 'In transit',               'count' => $this->countInTransit,          'accent' => 'blue',     'icon' => 'truck'],
                    ['key' => 'storage',            'label' => 'At another storage',       'count' => $this->countStorage,            'accent' => 'indigo',   'icon' => 'box'],
                    ['key' => 'on_demo',            'label' => 'On demo with customer',    'count' => $this->countOnDemo,             'accent' => 'teal',     'icon' => 'user'],
                    ['key' => 'awaiting_handover', 'label'  => 'Sold — awaiting handover', 'count' => $this->countAwaitingHandover,   'accent' => 'emerald',  'icon' => 'check'],
                ];
                $accentMap = [
                    'slate'   => ['ring' => 'ring-slate-300',   'chip' => 'bg-slate-100   text-slate-700',   'active' => 'border-slate-900   bg-slate-50',   'count' => 'text-slate-900'],
                    'amber'   => ['ring' => 'ring-amber-300',   'chip' => 'bg-amber-100   text-amber-700',   'active' => 'border-amber-600   bg-amber-50',   'count' => 'text-amber-700'],
                    'blue'    => ['ring' => 'ring-blue-300',    'chip' => 'bg-blue-100    text-blue-700',    'active' => 'border-blue-600    bg-blue-50',    'count' => 'text-blue-700'],
                    'sky'     => ['ring' => 'ring-sky-300',     'chip' => 'bg-sky-100     text-sky-700',     'active' => 'border-sky-600     bg-sky-50',     'count' => 'text-sky-700'],
                    'indigo'  => ['ring' => 'ring-indigo-300',  'chip' => 'bg-indigo-100  text-indigo-700',  'active' => 'border-indigo-600  bg-indigo-50',  'count' => 'text-indigo-700'],
                    'teal'    => ['ring' => 'ring-teal-300',    'chip' => 'bg-teal-100    text-teal-700',    'active' => 'border-teal-600    bg-teal-50',    'count' => 'text-teal-700'],
                    'emerald' => ['ring' => 'ring-emerald-300', 'chip' => 'bg-emerald-100 text-emerald-700', 'active' => 'border-emerald-600 bg-emerald-50', 'count' => 'text-emerald-700'],
                ];
                $emptyMessages = [
                    'premises'           => 'No vehicles sitting at your dealership right now.',
                    'reserved'           => 'No vehicles reserved for a customer.',
                    'body_builder'       => 'No vehicles at a body builder right now.',
                    'scheduled'          => 'Nothing scheduled for movement.',
                    'in_transit'         => 'No vehicles on the road right now.',
                    'storage'            => 'No vehicles at another storage location.',
                    'on_demo'            => 'No vehicles out on demo with customers.',
                    'recently_delivered' => 'No vehicles marked sold in the last 30 days.',
                    'awaiting_handover'  => 'No sold vehicles awaiting customer handover.',
                ];
                $cardTooltips = [
                    'premises'           => 'Physically at your dealership',
                    'reserved'           => 'Held for a customer; salesperson and contact captured',
                    'body_builder'       => 'Parked at a body builder or fitment centre',
                    'scheduled'          => 'Transport booked — collection not started yet',
                    'in_transit'         => 'On the road with an active transport job',
                    'storage'            => 'Parked at another storage yard',
                    'on_demo'            => 'Out on demo with a customer',
                    'recently_delivered' => 'Marked sold in the last 30 days (may still be in transit)',
                    'awaiting_handover'  => 'Sold but the customer has not taken delivery yet',
                ];
            @endphp

            <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach($cards as $i => $c)
                    @php
                        $isActive = $selectedBucket === $c['key'];
                        $accent = $accentMap[$c['accent']];
                        $base = 'group relative flex flex-col gap-3 rounded-2xl border-2 bg-white p-4 sm:p-5 text-left transition focus:outline-none focus-visible:ring-4 motion-safe:animate-card-in';
                        $stateCls = $isActive
                            ? $accent['active'] . ' shadow-sm'
                            : 'border-slate-200 hover:border-slate-300 hover:shadow-sm';
                    @endphp
                    <a href="{{ route('customer.stock.index', $this->stockIndexParams($c['key'])) }}"
                       title="{{ $cardTooltips[$c['key']] ?? $c['label'] }}"
                       class="{{ $base }} {{ $stateCls }} {{ $accent['ring'] }}"
                       style="animation-delay: {{ $i * 40 }}ms">
                        <div class="flex items-center justify-between">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl {{ $accent['chip'] }}">
                                @switch($c['icon'])
                                    @case('office')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9h.01"/><path d="M9 13h.01"/><path d="M9 17h.01"/></svg>
                                        @break
                                    @case('wrench')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                        @break
                                    @case('calendar')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                                        @break
                                    @case('truck')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
                                        @break
                                    @case('box')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                                        @break
                                    @case('user')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.5a5 5 0 0 1 10 0"/></svg>
                                        @break
                                    @case('check')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/></svg>
                                        @break
                                    @case('bookmark')
                                        <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                                        @break
                                @endswitch
                            </span>
                            @if($isActive)
                                <span class="text-[10px] font-bold uppercase tracking-wider {{ $accent['count'] }}">Viewing</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-4xl sm:text-5xl font-bold tabular-nums {{ $accent['count'] }}">{{ $c['count'] }}</p>
                            <p class="mt-1 text-sm font-medium text-slate-700">{{ $c['label'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Drill-down list --}}
            <div class="mb-6 rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">
                            @if($selectedBucket)
                                {{ collect($cards)->firstWhere('key', $selectedBucket)['label'] ?? 'Stock' }}
                            @else
                                All stock
                            @endif
                        </h3>
                        <p class="text-xs text-slate-500">
                            @if($selectedBucket)
                                {{ $this->filteredStock->count() }} vehicle{{ $this->filteredStock->count() === 1 ? '' : 's' }} — tap the active card again or "Show all" to clear.
                            @else
                                Latest {{ $this->filteredStock->count() }} vehicles across your dealerships.
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($selectedBucket)
                            <button wire:click="selectBucket(null)" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Show all</button>
                        @endif
                        <a href="{{ route('customer.stock.index', $selectedBucket ? $this->stockIndexParams($selectedBucket) : []) }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500">Open full stock →</a>
                    </div>
                </div>

                @if($this->filteredStock->isEmpty())
                    <div class="px-6 py-12 text-center">
                        <p class="text-sm text-slate-600">
                            {{ $selectedBucket ? ($emptyMessages[$selectedBucket] ?? 'Nothing here.') : 'No stock yet.' }}
                        </p>
                        @if(auth()->user()->hasPermission('manage_dealer_stock'))
                            <a href="{{ route('customer.stock.import') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 transition">
                                Import stock
                            </a>
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">VIN</th>
                                    @if($isMultiCompany)
                                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">Dealership</th>
                                    @endif
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">Vehicle</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">Reg</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">Where</th>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">Updated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach($this->filteredStock as $row)
                                    <tr class="text-sm hover:bg-slate-50 cursor-pointer" onclick="window.location='{{ route('customer.stock.show', $row) }}'">
                                        <td class="px-4 py-2 font-mono text-slate-700">{{ $row->vin }}</td>
                                        @if($isMultiCompany)
                                            <td class="px-4 py-2 text-slate-700">{{ $row->dealerCompany?->name }}</td>
                                        @endif
                                        <td class="px-4 py-2 text-slate-700">{{ trim(($row->brand?->name ?? '') . ' ' . ($row->model_name ?? '')) ?: '—' }}</td>
                                        <td class="px-4 py-2 font-mono text-slate-700">{{ $row->registration ?: '—' }}</td>
                                        <td class="px-4 py-2 text-slate-700">{{ str_replace('_', ' ', $row->current_location_type) }}</td>
                                        <td class="px-4 py-2 text-slate-500">{{ $row->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @else
            {{-- Non-dealer customer KPI strip (preserved from before) --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <a href="{{ route('customer.orders.index') }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-amber-300 hover:shadow-sm transition">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-amber-600">Pending</span>
                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-amber-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['pending'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">Awaiting review</p>
                </a>

                <a href="{{ route('customer.orders.index', ['statusFilter' => \App\Models\Job::STATUS_IN_TRANSIT]) }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-blue-300 hover:shadow-sm transition">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-blue-600">In Transit</span>
                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-blue-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['in_transit'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">Assigned &amp; moving</p>
                </a>

                <a href="{{ route('customer.orders.index') }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-emerald-300 hover:shadow-sm transition">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-emerald-600">Delivered {{ now()->format('M') }}</span>
                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-emerald-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['delivered_month'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">This month</p>
                </a>

                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Lifetime</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['total_completed'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">Vehicles delivered</p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
            {{-- Recent orders --}}
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Recent orders</h2>
                    <a href="{{ route('customer.orders.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500">View all →</a>
                </div>

                @if($recentJobs->isEmpty())
                    <div class="px-6 py-12 text-center">
                        <svg viewBox="0 0 24 24" class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                        <p class="mt-3 text-sm font-medium text-slate-900">No orders yet</p>
                        <p class="mt-1 text-xs text-slate-500">Submit your first order to get a vehicle collected.</p>
                        @if(auth()->user()->hasPermission('submit_booking'))
                            <a href="{{ route('customer.orders.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 transition">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                Create first order
                            </a>
                        @endif
                    </div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach($recentJobs as $job)
                            <a href="{{ route('customer.orders.show', $job) }}" class="flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 transition group">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-900 truncate">{{ $job->job_number ?? '—' }}</span>
                                        <span class="text-xs text-slate-500 truncate">· {{ $job->brand?->name }} {{ $job->model_name }}</span>
                                        @if($isMultiCompany)
                                            <span class="shrink-0 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700">{{ $job->company?->name }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500 truncate">
                                        {{ $job->pickupLocation?->company_name ?? ($job->pickupLocation?->city ?? '—') }}
                                        →
                                        {{ $job->deliveryLocation?->company_name ?? ($job->deliveryLocation?->city ?? '—') }}
                                        @if($job->inventory?->chassis_number)
                                            · <span class="text-slate-400">{{ $job->inventory->chassis_number }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="shrink-0 flex items-center gap-3">
                                    <x-status-badge :status="$job->status" />
                                    <span class="text-xs text-slate-400 tabular-nums">{{ $job->scheduled_date?->format('d M') ?? $job->created_at->format('d M') }}</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Address book preview --}}
            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Address Book</h2>
                        <p class="text-[11px] text-slate-500">{{ $addressCount }} saved {{ \Illuminate\Support\Str::plural('location', $addressCount) }}</p>
                    </div>
                    <a href="{{ route('customer.locations.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500">Open →</a>
                </div>

                @if($addresses->isEmpty())
                    <div class="px-5 py-8 text-center">
                        <svg viewBox="0 0 24 24" class="mx-auto h-9 w-9 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        <p class="mt-3 text-xs font-medium text-slate-900">No addresses saved</p>
                        <p class="mt-1 text-[11px] text-slate-500">Save destinations for quicker order creation.</p>
                        <a href="{{ route('customer.locations.index') }}" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Add first address
                        </a>
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($addresses as $loc)
                            <li class="px-5 py-3">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $loc->company_name }}</p>
                                <p class="text-[11px] text-slate-500 truncate">
                                    {{ collect([$loc->city, $loc->province])->filter()->join(' · ') ?: 'No city' }}
                                    @if($loc->customer_name)
                                        · <span class="text-slate-400">{{ $loc->customer_name }}</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-5 py-3 bg-slate-50/60 border-t border-slate-100">
                        <a href="{{ route('customer.locations.index') }}" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900">Manage all {{ $addressCount }} addresses</a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Quick actions grid --}}
        <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-900">Quick actions</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
                @if(auth()->user()->hasPermission('submit_booking'))
                    <a href="{{ route('customer.orders.create') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">New Order</p>
                            <p class="text-[11px] text-slate-500 truncate">Request a vehicle movement</p>
                        </div>
                    </a>
                @endif
                <a href="{{ route('customer.orders.index') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">All Orders</p>
                        <p class="text-[11px] text-slate-500 truncate">Search &amp; filter</p>
                    </div>
                </a>
                <a href="{{ route('customer.locations.index') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">Address Book</p>
                        <p class="text-[11px] text-slate-500 truncate">{{ $addressCount }} {{ \Illuminate\Support\Str::plural('location', $addressCount) }}</p>
                    </div>
                </a>
                @if(auth()->user()->hasAnyRole(['customer_owner', 'customer_admin']))
                    <a href="{{ route('customer.team.index') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">Team</p>
                            <p class="text-[11px] text-slate-500 truncate">{{ $teamCount }} {{ \Illuminate\Support\Str::plural('user', $teamCount) }}</p>
                        </div>
                    </a>
                @endif
            </div>
        </div>

    @endif

    {{-- Card-in animation (motion-safe).  Inlined here to keep the
         dashboard self-contained; respected by reduced-motion users
         because we use Tailwind's motion-safe: variant on the card
         class above. --}}
    <style>
        @keyframes card-in {
            0% { opacity: 0; transform: translateY(8px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .motion-safe\:animate-card-in {
            animation: card-in 320ms cubic-bezier(0.22, 1, 0.36, 1) backwards;
        }
        @media (prefers-reduced-motion: reduce) {
            .motion-safe\:animate-card-in { animation: none; }
        }
    </style>
</div>
