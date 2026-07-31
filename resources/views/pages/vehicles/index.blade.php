<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Vehicles overview — cross-role card feed.
 *
 * One shared Volt page mounted at BOTH /admin/vehicles and /oem/vehicles.
 * Scoping is applied via Job::scopeVisibleTo($user): internal/owner see
 * every vehicle in the system, OEM/dealer users see only movements
 * where their own company is the booking customer OR the executing
 * transporter. Everyone hits the same UI, RBAC does the data fence.
 *
 * Filters are all URL-bound via #[Url] so ops can copy/paste a
 * deep-link into a ticket and the recipient lands on exactly the same
 * view. All filters reset pagination when changed.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]       public string $search = '';
    #[Url(as: 'status', except: '')]  public string $statusFilter = '';
    #[Url(as: 'bucket', except: 'all')] public string $bucket = 'all';
    #[Url(as: 'brand', except: '')]   public string $brandFilter = '';
    #[Url(as: 'company', except: '')] public string $companyFilter = '';
    #[Url(as: 'from', except: '')]    public string $fromDate = '';
    #[Url(as: 'to', except: '')]      public string $toDate = '';
    #[Url(as: 'view', except: 'cards')] public string $viewMode = 'cards';
    #[Url(as: 'sort', except: 'newest')] public string $sort = 'newest';

    // Status buckets roll a dozen raw statuses into 4 human-sized groups
    // so the filter strip fits on one row without lying about what it
    // actually filters on. `all` bypasses the bucket filter entirely.
    //
    // BUCKET_LIVE is intentionally wide: everything from the moment a
    // driver is assigned up to the point ops ticks "complete". It's the
    // single view a dispatcher needs open all day — replaces the old
    // /admin/tracking page.
    private const BUCKET_OPEN      = 'open';      // pre-driver: queue
    private const BUCKET_LIVE      = 'live';      // in-flight keys
    private const BUCKET_COMPLETED = 'completed'; // ops has signed off
    private const BUCKET_CANCELLED = 'cancelled';

    public function mount(): void
    {
        // Preserve bookmarks from before the /tracking merge. The old
        // names 'in_transit' and 'delivered' still show up in saved URLs
        // and emailed deep-links — silently remap them to the new
        // semantics instead of bouncing the user to "All".
        $this->bucket = match ($this->bucket) {
            'in_transit' => self::BUCKET_LIVE,
            'delivered'  => self::BUCKET_COMPLETED,
            default      => $this->bucket,
        };
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'bucket', 'brandFilter', 'companyFilter', 'fromDate', 'toDate', 'sort'])) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'bucket', 'brandFilter', 'companyFilter', 'fromDate', 'toDate']);
        $this->resetPage();
    }

    private function bucketStatuses(string $bucket): array
    {
        return match ($bucket) {
            // Everything before a driver takes responsibility — the
            // planner's queue. Deliberately excludes driver_assigned;
            // once a driver has the job, it belongs to Live.
            self::BUCKET_OPEN => [
                Job::STATUS_PENDING_VERIFICATION, Job::STATUS_RECEIVED,
                Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMATION_ISSUE,
                Job::STATUS_VERIFIED, Job::STATUS_APPROVED, Job::STATUS_CONFIRMED,
                Job::STATUS_PLANNED, Job::STATUS_ASSIGNED,
            ],
            // Keys are out. Covers driver assigned all the way through
            // "delivered" (because delivered-but-ops-hasn't-closed-out
            // is still dispatch's problem, not accounting's).
            self::BUCKET_LIVE => self::LIVE_STATUSES,
            // Ops has signed off / invoiced. Archive view.
            self::BUCKET_COMPLETED => [
                Job::STATUS_COMPLETED, Job::STATUS_READY_FOR_INVOICING, Job::STATUS_INVOICED,
            ],
            self::BUCKET_CANCELLED => [Job::STATUS_CANCELLED, Job::STATUS_REJECTED],
            default => [],
        };
    }

    /**
     * In-flight statuses — kept as a constant so the sub-tile strip and
     * the bucket filter stay in lockstep. The "Delivered" status sits
     * here intentionally until ops completes the job; the delivered
     * tile label makes that explicit.
     */
    public const LIVE_STATUSES = [
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_IN_PROGRESS,
        Job::STATUS_DELIVERED,
    ];

    public function with(): array
    {
        $user = auth()->user();

        $base = Job::query()
            ->visibleTo($user)
            ->with([
                'brand:id,name',
                'company:id,name',
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'driver:id,name',
                'driver.driverProfile:user_id,tracker_id',
            ]);

        // === Search ===
        if ($this->search !== '') {
            $s = trim($this->search);
            $base->where(function ($q) use ($s) {
                $q->where('job_number', 'ilike', "%{$s}%")
                    ->orWhere('vin', 'ilike', "%{$s}%")
                    ->orWhere('registration', 'ilike', "%{$s}%")
                    ->orWhere('model_name', 'ilike', "%{$s}%")
                    ->orWhereHas('brand', fn ($q) => $q->where('name', 'ilike', "%{$s}%"))
                    ->orWhereHas('company', fn ($q) => $q->where('name', 'ilike', "%{$s}%"))
                    ->orWhereHas('pickupLocation', fn ($q) => $q->where('company_name', 'ilike', "%{$s}%"))
                    ->orWhereHas('deliveryLocation', fn ($q) => $q->where('company_name', 'ilike', "%{$s}%"))
                    ->orWhereHas('driver', fn ($q) => $q->where('name', 'ilike', "%{$s}%"));
            });
        }

        // === Bucket (fast roll-ups) ===
        if ($this->bucket !== 'all') {
            $statuses = $this->bucketStatuses($this->bucket);
            if (!empty($statuses)) {
                $base->whereIn('status', $statuses);
            }
        }

        // === Specific status (narrower than bucket) ===
        if ($this->statusFilter !== '') {
            $base->whereIn('status', Job::expandStatusFilter($this->statusFilter));
        }

        if ($this->brandFilter !== '') {
            $base->where('brand_id', $this->brandFilter);
        }

        if ($this->companyFilter !== '' && ($user->isInternal() || $user->belongsToPlatformOwner())) {
            $base->where('company_id', $this->companyFilter);
        }

        if ($this->fromDate !== '') {
            $base->whereDate('created_at', '>=', $this->fromDate);
        }
        if ($this->toDate !== '') {
            $base->whereDate('created_at', '<=', $this->toDate);
        }

        // === Sort ===
        match ($this->sort) {
            'oldest'  => $base->orderBy('created_at'),
            'brand'   => $base->join('brands', 'brands.id', '=', 'jobs.brand_id')
                               ->orderBy('brands.name')->select('jobs.*'),
            'status'  => $base->orderBy('status'),
            default   => $base->orderByDesc('created_at'),
        };

        // === Bucket counts (headline strip — respect all *other* filters
        // so counts move sensibly when you narrow by search/brand/date) ===
        $countsQuery = fn () => (clone $base);
        // re-run count independently per bucket by building from the raw
        // unscoped query with the same non-bucket filters applied. Simpler:
        // use a subquery without the bucket/status narrowing.
        $countsBase = Job::query()->visibleTo($user);
        if ($this->search !== '') {
            $s = trim($this->search);
            $countsBase->where(function ($q) use ($s) {
                $q->where('job_number', 'ilike', "%{$s}%")
                    ->orWhere('vin', 'ilike', "%{$s}%")
                    ->orWhere('registration', 'ilike', "%{$s}%")
                    ->orWhere('model_name', 'ilike', "%{$s}%");
            });
        }
        if ($this->brandFilter !== '') {
            $countsBase->where('brand_id', $this->brandFilter);
        }
        if ($this->companyFilter !== '' && ($user->isInternal() || $user->belongsToPlatformOwner())) {
            $countsBase->where('company_id', $this->companyFilter);
        }
        if ($this->fromDate !== '') {
            $countsBase->whereDate('created_at', '>=', $this->fromDate);
        }
        if ($this->toDate !== '') {
            $countsBase->whereDate('created_at', '<=', $this->toDate);
        }

        $total = (clone $countsBase)->count();
        $openCount      = (clone $countsBase)->whereIn('status', $this->bucketStatuses(self::BUCKET_OPEN))->count();
        $liveCount      = (clone $countsBase)->whereIn('status', $this->bucketStatuses(self::BUCKET_LIVE))->count();
        $completedCount = (clone $countsBase)->whereIn('status', $this->bucketStatuses(self::BUCKET_COMPLETED))->count();
        $cancelledCount = (clone $countsBase)->whereIn('status', $this->bucketStatuses(self::BUCKET_CANCELLED))->count();

        // When bucket=live, break the count down by exact status so ops
        // still see the "how many keys are stuck at pickup vs moving"
        // breakdown they had on the old /tracking page.
        $liveStatusCounts = [];
        if ($this->bucket === self::BUCKET_LIVE) {
            foreach (self::LIVE_STATUSES as $s) {
                $liveStatusCounts[$s] = (clone $countsBase)->where('status', $s)->count();
            }
        }

        // Lookups for the filter dropdowns. Brands are global; companies
        // are only shown to ops/owner because OEMs don't need to (and
        // shouldn't) filter across other tenants.
        $brands = Brand::orderBy('name')->get(['id', 'name']);
        $companies = ($user->isInternal() || $user->belongsToPlatformOwner())
            ? Company::orderBy('name')->get(['id', 'name'])
            : collect();

        // Detail route. The /admin/vehicles page is gated by
        // EnsureInternalAccess so every viewer here is an ops /
        // owner / super admin / developer — they all land on the
        // admin orders detail page.  The old "OEMs land on the
        // OEM portal" branch was retired with the /oem/* prefix.
        $detailRoute = 'admin.orders.show';

        // Tile labels for the Live sub-strip. Override "Delivered" so
        // ops understand the tile counts vehicles the driver has
        // dropped off but that ops hasn't closed out yet — finished
        // jobs move to the Completed bucket.
        $liveTileLabels = [];
        foreach (self::LIVE_STATUSES as $s) {
            $liveTileLabels[$s] = Job::PHASE1_STATUS_LABELS[$s] ?? Str::title(str_replace('_', ' ', $s));
        }
        $liveTileLabels[Job::STATUS_DELIVERED] = 'Delivered (awaiting completion)';

        return [
            'jobs'            => $base->paginate(24),
            'brands'          => $brands,
            'companies'       => $companies,
            'total'           => $total,
            'openCount'       => $openCount,
            'liveCount'       => $liveCount,
            'completedCount'  => $completedCount,
            'cancelledCount'  => $cancelledCount,
            'liveStatusCounts' => $liveStatusCounts,
            'liveTileLabels'  => $liveTileLabels,
            'statusLabels'    => Job::phase1FilterOptions(),
            'detailRoute'     => $detailRoute,
            'canFilterCompany' => $user->isInternal() || $user->belongsToPlatformOwner(),
        ];
    }
};
?>

@php
    // Status → accent colour map for the card left border + chip. Kept in
    // the Blade layer so designers can tweak without touching PHP.
    $statusAccent = [
        App\Models\Job::STATUS_COLLECTED         => ['border' => 'border-l-blue-500',    'chip' => 'bg-blue-100 text-blue-800'],
        App\Models\Job::STATUS_IN_TRANSIT        => ['border' => 'border-l-blue-500',    'chip' => 'bg-blue-100 text-blue-800'],
        App\Models\Job::STATUS_IN_PROGRESS       => ['border' => 'border-l-blue-500',    'chip' => 'bg-blue-100 text-blue-800'],
        App\Models\Job::STATUS_DELIVERED         => ['border' => 'border-l-emerald-500', 'chip' => 'bg-emerald-100 text-emerald-800'],
        App\Models\Job::STATUS_COMPLETED         => ['border' => 'border-l-emerald-500', 'chip' => 'bg-emerald-100 text-emerald-800'],
        App\Models\Job::STATUS_READY_FOR_INVOICING => ['border' => 'border-l-emerald-500', 'chip' => 'bg-emerald-100 text-emerald-800'],
        App\Models\Job::STATUS_INVOICED          => ['border' => 'border-l-emerald-600', 'chip' => 'bg-emerald-100 text-emerald-800'],
        App\Models\Job::STATUS_CANCELLED         => ['border' => 'border-l-slate-400',   'chip' => 'bg-slate-100 text-slate-700'],
        App\Models\Job::STATUS_REJECTED          => ['border' => 'border-l-rose-500',    'chip' => 'bg-rose-100 text-rose-800'],
        App\Models\Job::STATUS_DRIVER_ASSIGNED   => ['border' => 'border-l-indigo-500',  'chip' => 'bg-indigo-100 text-indigo-800'],
        App\Models\Job::STATUS_READY_FOR_COLLECTION => ['border' => 'border-l-indigo-500', 'chip' => 'bg-indigo-100 text-indigo-800'],
    ];
    $defaultAccent = ['border' => 'border-l-amber-500', 'chip' => 'bg-amber-100 text-amber-800'];
@endphp

<div wire:loading.class="opacity-60">
    <x-slot:header>Vehicles</x-slot:header>

    {{-- ============================================================ --}}
    {{-- Hero: summary + bucket pills                                  --}}
    {{-- ============================================================ --}}
    <div class="mb-6 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-900 text-white shadow-lg overflow-hidden">
        <div class="px-6 py-5 sm:px-8 sm:py-6 flex flex-col gap-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-200/80">Fleet Overview</p>
                    <h2 class="mt-1 text-xl sm:text-2xl font-semibold">{{ number_format($total) }} {{ Str::plural('vehicle', $total) }}</h2>
                    <p class="mt-0.5 text-xs text-slate-300">Live movements and full fleet history &mdash; filter by status, brand or date.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="$set('viewMode', 'cards')" type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition
                        {{ $viewMode === 'cards' ? 'bg-white text-slate-900' : 'bg-white/10 text-white hover:bg-white/15 ring-1 ring-white/20' }}">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                        Cards
                    </button>
                    <button wire:click="$set('viewMode', 'table')" type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition
                        {{ $viewMode === 'table' ? 'bg-white text-slate-900' : 'bg-white/10 text-white hover:bg-white/15 ring-1 ring-white/20' }}">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="6" y2="6"/><line x1="3" x2="21" y1="12" y2="12"/><line x1="3" x2="21" y1="18" y2="18"/></svg>
                        Table
                    </button>
                </div>
            </div>

            {{-- Bucket pills. "Live" has a pulse dot to echo the old
                 /tracking page and draw the eye — it's the bucket
                 dispatch lives in.                                    --}}
            <div class="flex flex-wrap gap-2">
                @php
                    $bucketPills = [
                        ['k' => 'all',       'label' => 'All',        'count' => $total,          'accent' => 'bg-white/15 ring-white/25',            'pulse' => false],
                        ['k' => 'open',      'label' => 'Open',       'count' => $openCount,      'accent' => 'bg-amber-500/25 ring-amber-300/50',    'pulse' => false],
                        ['k' => 'live',      'label' => 'Live',       'count' => $liveCount,      'accent' => 'bg-blue-500/25 ring-blue-300/50',      'pulse' => true],
                        ['k' => 'completed', 'label' => 'Completed',  'count' => $completedCount, 'accent' => 'bg-emerald-500/25 ring-emerald-300/50','pulse' => false],
                        ['k' => 'cancelled', 'label' => 'Cancelled',  'count' => $cancelledCount, 'accent' => 'bg-slate-500/25 ring-slate-300/40',    'pulse' => false],
                    ];
                @endphp
                @foreach($bucketPills as $b)
                    <button wire:click="$set('bucket', '{{ $b['k'] }}')" type="button"
                        class="inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition
                        {{ $bucket === $b['k'] ? 'bg-white text-slate-900 ring-white' : $b['accent'] . ' text-white hover:bg-white/25' }}">
                        @if($b['pulse'])
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $bucket === $b['k'] ? 'bg-blue-400' : 'bg-white' }} opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full {{ $bucket === $b['k'] ? 'bg-blue-500' : 'bg-white' }}"></span>
                            </span>
                        @endif
                        {{ $b['label'] }}
                        <span class="rounded-full bg-white/30 px-1.5 py-0.5 text-[10px] tabular-nums
                            {{ $bucket === $b['k'] ? '!bg-slate-900 !text-white' : '' }}">{{ number_format($b['count']) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- Live sub-tiles — per-status breakdown when bucket=live.       --}}
    {{-- Replaces the old /admin/tracking page. Ops uses these to      --}}
    {{-- tell at a glance how many keys are stuck at pickup vs moving  --}}
    {{-- vs delivered-but-not-closed.                                   --}}
    {{-- ============================================================ --}}
    @if($bucket === 'live' && !empty($liveStatusCounts))
        <div class="mb-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
            @foreach($liveStatusCounts as $status => $count)
                <button wire:click="$set('statusFilter', '{{ $statusFilter === $status ? '' : $status }}')" type="button"
                    class="text-left rounded-lg border px-3 py-2.5 transition-colors
                    {{ $statusFilter === $status ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-100' : 'border-slate-200 bg-white hover:border-slate-300' }}">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500 leading-tight">{{ $liveTileLabels[$status] ?? $status }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-900 tabular-nums">{{ number_format($count) }}</p>
                </button>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- Filters                                                       --}}
    {{-- ============================================================ --}}
    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2 sm:gap-3">
            <div class="sm:col-span-2 lg:col-span-2">
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Search</label>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="VIN · Reg · Order # · Driver · Route…"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Brand</label>
                <select wire:model.live="brandFilter" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All brands</option>
                    @foreach($brands as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Status</label>
                <select wire:model.live="statusFilter" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Any status</option>
                    @foreach($statusLabels as $k => $label)
                        <option value="{{ $k }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if($canFilterCompany)
            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Customer</label>
                <select wire:model.live="companyFilter" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All customers</option>
                    @foreach($companies as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="{{ $canFilterCompany ? '' : 'lg:col-span-2' }}">
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Sort</label>
                <select wire:model.live="sort" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="status">By status</option>
                    <option value="brand">By brand</option>
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2 sm:gap-3">
            <div class="flex items-center gap-2">
                <label class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">From</label>
                <input wire:model.live.debounce.400ms="fromDate" type="date" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">To</label>
                <input wire:model.live.debounce.400ms="toDate" type="date" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs focus:border-blue-500 focus:ring-blue-500">
            </div>

            @if($search || $statusFilter || $bucket !== 'all' || $brandFilter || $companyFilter || $fromDate || $toDate)
                <button wire:click="clearFilters" type="button"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    Clear all filters
                </button>
            @endif
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- Results                                                       --}}
    {{-- ============================================================ --}}
    @if($jobs->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/60 p-12 text-center">
            <svg viewBox="0 0 24 24" class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
            <p class="mt-3 text-sm font-medium text-slate-900">No vehicles match those filters</p>
            <p class="mt-1 text-xs text-slate-500">Try broadening your search or clearing a filter above.</p>
        </div>
    @elseif($viewMode === 'cards')

        {{-- ============================================================ --}}
        {{-- CARDS                                                         --}}
        {{-- ============================================================ --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
            @foreach($jobs as $job)
                @php
                    $accent = $statusAccent[$job->status] ?? $defaultAccent;
                    $isInFlight = in_array($job->status, [
                        App\Models\Job::STATUS_COLLECTED,
                        App\Models\Job::STATUS_IN_TRANSIT,
                        App\Models\Job::STATUS_IN_PROGRESS,
                    ], true);
                @endphp
                <a href="{{ route($detailRoute, $job) }}"
                   class="group relative block rounded-xl border border-slate-200 bg-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-150 border-l-4 {{ $accent['border'] }} overflow-hidden">

                    {{-- Top row: status + order # + age --}}
                    <div class="px-4 pt-3.5 pb-2 flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $accent['chip'] }}">
                                {{ $statusLabels[$job->status] ?? Str::title(str_replace('_', ' ', $job->status)) }}
                            </span>
                            <span class="text-xs font-semibold text-slate-700 truncate">{{ $job->job_number ?? '—' }}</span>
                        </div>
                        <span class="shrink-0 text-[10px] tabular-nums text-slate-400" title="{{ $job->created_at?->toDayDateTimeString() }}">
                            {{ $job->created_at?->diffForHumans(null, true) }}
                        </span>
                    </div>

                    {{-- Vehicle identity block --}}
                    <div class="px-4 pb-3">
                        <p class="text-sm font-semibold text-slate-900 truncate">
                            {{ $job->brand?->name }} {{ $job->model_name ?: '—' }}
                        </p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                            @if($job->vin)
                                <span class="inline-flex items-center gap-1 text-slate-600" title="VIN">
                                    <span class="text-[9px] font-bold uppercase tracking-wide text-slate-400">VIN</span>
                                    <span class="font-mono tabular-nums">{{ $job->vin }}</span>
                                </span>
                            @endif
                            @if($job->registration)
                                <span class="inline-flex items-center gap-1 rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono font-semibold text-yellow-900">
                                    {{ $job->registration }}
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Route --}}
                    <div class="px-4 py-3 bg-slate-50/70 border-t border-slate-100">
                        <div class="flex items-center gap-2 text-xs">
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Pickup</p>
                                <p class="text-slate-800 truncate">{{ $job->pickupLocation?->company_name ?: ($job->pickup_address ?: '—') }}</p>
                                @if($job->pickupLocation?->city)
                                    <p class="text-[10px] text-slate-400 truncate">{{ $job->pickupLocation->city }}</p>
                                @endif
                            </div>
                            <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            <div class="min-w-0 flex-1 text-right">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Delivery</p>
                                <p class="text-slate-800 truncate">{{ $job->deliveryLocation?->company_name ?: ($job->delivery_address ?: '—') }}</p>
                                @if($job->deliveryLocation?->city)
                                    <p class="text-[10px] text-slate-400 truncate">{{ $job->deliveryLocation->city }}</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Footer: driver + tracker + customer --}}
                    <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between gap-2 text-[11px]">
                        <div class="min-w-0 flex-1">
                            @if($job->driver)
                                <div class="flex items-center gap-1.5 text-slate-700">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span class="truncate font-medium">{{ $job->driver->name }}</span>
                                </div>
                                @if($isInFlight && $job->driver->driverProfile?->tracker_id)
                                    <div class="mt-1 inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200" title="Live tracker">
                                        <svg viewBox="0 0 24 24" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                                        {{ $job->driver->driverProfile->tracker_id }}
                                    </div>
                                @endif
                            @else
                                <span class="text-slate-400 italic">Unassigned</span>
                            @endif
                        </div>
                        @if($canFilterCompany && $job->company)
                            <span class="shrink-0 max-w-[40%] truncate text-slate-500" title="{{ $job->company->name }}">{{ $job->company->name }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

    @else

        {{-- ============================================================ --}}
        {{-- TABLE                                                         --}}
        {{-- ============================================================ --}}
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">Order</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Vehicle</th>
                            <th class="px-4 py-2.5 text-left font-semibold">VIN</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Reg</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Route</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Driver</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Status</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white text-sm">
                        @foreach($jobs as $job)
                            @php $accent = $statusAccent[$job->status] ?? $defaultAccent; @endphp
                            <tr class="hover:bg-slate-50 cursor-pointer" onclick="window.location='{{ route($detailRoute, $job) }}'">
                                <td class="px-4 py-2.5 font-semibold text-blue-600 whitespace-nowrap">{{ $job->job_number ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-900 whitespace-nowrap">{{ $job->brand?->name }} {{ $job->model_name }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-600">
                                    {{ $job->vin ?: '—' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($job->registration)
                                        <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-yellow-900">{{ $job->registration }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500 truncate max-w-[280px]">
                                    {{ $job->pickupLocation?->company_name ?: '—' }} → {{ $job->deliveryLocation?->company_name ?: '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-700">
                                    <div class="flex flex-col leading-tight">
                                        <span>{{ $job->driver?->name ?? '—' }}</span>
                                        @if($job->driver?->driverProfile?->tracker_id)
                                            <span class="font-mono text-[10px] text-emerald-700">{{ $job->driver->driverProfile->tracker_id }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $accent['chip'] }}">
                                        {{ $statusLabels[$job->status] ?? Str::title(str_replace('_', ' ', $job->status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right text-[11px] tabular-nums text-slate-500 whitespace-nowrap" title="{{ $job->created_at?->toDayDateTimeString() }}">
                                    {{ $job->created_at?->diffForHumans(null, true) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    @endif

    {{-- Pagination --}}
    @if($jobs->hasPages())
        <div class="mt-5">
            {{ $jobs->links() }}
        </div>
    @endif
</div>
