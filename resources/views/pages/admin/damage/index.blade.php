<?php

use App\Models\Job;
use App\Models\JobDocument;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * /admin/damage — single pane of glass for every damage incident on the
 * system. Each row represents one *job* with damage photos against it
 * (not one photo) so ops can review the incident as a whole, download
 * the PDF report, and see what the driver actually wrote in his note.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    // Default to all-time / all-status so the page never reads "No damage
    // reported" while damage photos exist on older or already-completed
    // jobs. Damage is routinely discovered on delivered/completed vehicles,
    // and a 30-day + open-only default hid exactly those rows. Ops can still
    // narrow with the range pills and bucket tabs.
    #[Url]
    public string $range = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $bucket = 'all';

    public const ALLOWED_RANGES  = ['last_7_days', 'last_30_days', 'this_month', 'all'];
    public const ALLOWED_BUCKETS = ['open', 'pending_release', 'all'];

    public function mount(): void
    {
        $this->authorize('viewAny', Job::class);

        if (!in_array($this->range, self::ALLOWED_RANGES, true)) {
            $this->range = 'all';
        }
        if (!in_array($this->bucket, self::ALLOWED_BUCKETS, true)) {
            $this->bucket = 'all';
        }
    }

    /**
     * Operator-driven release directly from the incidents list.
     * Policy-gated so junior users can't bulk-release without review.
     */
    public function releaseDamageReport(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        $this->authorize('releaseDamageReport', $job);

        $job->damage_report_released_at = now();
        $job->damage_report_released_by = auth()->id();
        $job->save();

        \App\Services\AuditService::log('damage_report_released', 'job', $job->id, null, [
            'released_to_company_id' => $job->company_id,
            'source' => 'damage_index',
        ]);

        session()->flash('success', "Damage report released for {$job->job_number}.");
    }

    public function setRange(string $range): void
    {
        if (in_array($range, self::ALLOWED_RANGES, true)) {
            $this->range = $range;
            $this->resetPage();
        }
    }

    public function setBucket(string $bucket): void
    {
        if (in_array($bucket, self::ALLOWED_BUCKETS, true)) {
            $this->bucket = $bucket;
            $this->resetPage();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    protected function resolveRange(): ?array
    {
        return match ($this->range) {
            'last_7_days'  => [today()->subDays(6)->startOfDay(), today()->endOfDay()],
            'last_30_days' => [today()->subDays(29)->startOfDay(), today()->endOfDay()],
            'this_month'   => [now()->startOfMonth(), now()->endOfMonth()],
            default        => null,
        };
    }

    public function with(): array
    {
        // Find every job that has at least one damage photo captured
        // inside the range window. We derive the window from the photo's
        // captured_at (falling back to created_at) so filters match the
        // operator's mental model of "when was the damage seen", not
        // "when was the job booked".
        $photoQuery = JobDocument::query()
            ->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO);

        if ($range = $this->resolveRange()) {
            [$start, $end] = $range;
            $photoQuery->whereBetween(
                \Illuminate\Support\Facades\DB::raw('COALESCE(captured_at, created_at)'),
                [$start, $end]
            );
        }
        $jobIds = (clone $photoQuery)->distinct()->pluck('job_id');

        $jobs = Job::query()
            ->with([
                'company:id,name',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'driver:id,name',
            ])
            ->whereIn('id', $jobIds)
            ->withCount(['documents as damage_photos_count' => function ($q) {
                $q->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO);
            }]);

        if ($this->bucket === 'open') {
            $jobs->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED]);
        } elseif ($this->bucket === 'pending_release') {
            $jobs->whereNull('damage_report_released_at');
        }

        if (trim($this->search) !== '') {
            $needle = '%' . trim($this->search) . '%';
            $jobs->where(function ($q) use ($needle) {
                $q->where('job_number', 'ilike', $needle)
                  ->orWhere('registration', 'ilike', $needle)
                  ->orWhere('vin', 'ilike', $needle)
                  ->orWhere('model_name', 'ilike', $needle)
                  ->orWhereHas('company', fn($cq) => $cq->where('name', 'ilike', $needle));
            });
        }

        $paginator = $jobs->orderByDesc('updated_at')->paginate(20);

        // Attach the 3 most recent damage photos to each row for the
        // thumbnail strip. Cheaper than an eager-load + reject because
        // we'd only want 3 per job anyway.
        $paginator->through(function (Job $job) {
            $job->setRelation(
                'damageThumbs',
                JobDocument::where('job_id', $job->id)
                    ->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
                    ->orderByDesc('created_at')
                    ->limit(3)
                    ->get()
            );
            return $job;
        });

        // Headline metrics for the page header strip.
        $allDamageJobIds = JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)->distinct()->pluck('job_id');
        $metrics = [
            'open_jobs' => Job::query()
                ->whereIn('id', $allDamageJobIds)
                ->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED])
                ->count(),
            'pending_release' => Job::query()
                ->whereIn('id', $allDamageJobIds)
                ->whereNull('damage_report_released_at')
                ->count(),
            'photos_30d' => JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];

        return [
            'jobs'    => $paginator,
            'metrics' => $metrics,
        ];
    }
};

?>

<div>
    <x-slot:header>Damage Reports</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Headline metric strip --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
        <div class="rounded-xl bg-white border border-rose-200 p-4 shadow-sm">
            <p class="text-[11px] uppercase tracking-wider font-semibold text-rose-700">Open incidents</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $metrics['open_jobs'] }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Jobs with damage still in flight</p>
        </div>
        <div class="rounded-xl bg-white border border-amber-200 p-4 shadow-sm">
            <p class="text-[11px] uppercase tracking-wider font-semibold text-amber-700">Pending release</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $metrics['pending_release'] }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Awaiting operator sign-off before customer can download</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] uppercase tracking-wider font-semibold text-slate-600">New photos (30d)</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $metrics['photos_30d'] }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Damage photos uploaded in the last month</p>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            {{-- Range pills --}}
            <div class="flex flex-wrap items-center gap-2">
                @php
                    $rangeLabels = [
                        'last_7_days'  => 'Last 7 days',
                        'last_30_days' => 'Last 30 days',
                        'this_month'   => 'This month',
                        'all'          => 'All time',
                    ];
                @endphp
                @foreach($rangeLabels as $key => $label)
                    <button wire:click="setRange('{{ $key }}')"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors
                               {{ $range === $key ? 'bg-rose-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Bucket + search --}}
            <div class="flex flex-col sm:flex-row gap-2">
                <div class="inline-flex rounded-lg bg-gray-100 p-1 text-xs font-semibold">
                    @foreach([
                        'open'            => 'Open only',
                        'pending_release' => 'Pending release',
                        'all'             => 'All incidents',
                    ] as $key => $label)
                        <button wire:click="setBucket('{{ $key }}')"
                            class="px-3 py-1.5 rounded-md transition-colors
                                   {{ $bucket === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <div class="relative">
                    <input type="text" wire:model.live.debounce.300ms="search"
                        placeholder="Search job, VIN, reg, customer…"
                        class="w-full sm:w-72 rounded-lg border border-gray-200 pl-9 pr-8 py-2 text-sm focus:ring-2 focus:ring-rose-500 focus:border-transparent">
                    <svg class="absolute left-3 top-2.5 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    @if($search !== '')
                        <button wire:click="clearSearch" class="absolute right-2 top-2.5 text-gray-400 hover:text-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Results --}}
    @if($jobs->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 mb-3">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <h3 class="text-base font-semibold text-slate-900">No damage reported</h3>
            <p class="mt-1 text-sm text-slate-500">Nothing matches the filters you've selected. Widen the time range or clear the search.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($jobs as $job)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:border-rose-300 transition-colors">
                <div class="px-5 py-4 flex flex-col lg:flex-row gap-4">
                    {{-- Left: identifier + vehicle --}}
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <a href="{{ route('admin.orders.show', $job) }}#damage-section"
                               class="text-sm font-semibold text-slate-900 hover:text-rose-700">
                                {{ $job->job_number ?? ('#' . $job->id) }}
                            </a>
                            <span class="inline-flex items-center rounded-full bg-rose-100 border border-rose-200 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                                {{ $job->damage_photos_count }} {{ $job->damage_photos_count === 1 ? 'photo' : 'photos' }}
                            </span>
                            <x-status-badge :status="$job->status" />
                            @if($job->damage_report_released_at)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    Released
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Pending review
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600">
                            <span class="font-medium">{{ $job->company?->name ?? '—' }}</span>
                            @if($job->brand || $job->model_name)
                                <span class="text-slate-400"> · </span>
                                {{ $job->brand?->name }} {{ $job->model_name }}
                            @endif
                            @if($job->registration)
                                <span class="text-slate-400"> · </span>
                                <span class="font-mono uppercase">{{ $job->registration }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500 mt-1">
                            @if($job->pickupLocation)
                                {{ $job->pickupLocation->city ?? $job->pickupLocation->company_name }}
                            @endif
                            @if($job->deliveryLocation)
                                &rarr; {{ $job->deliveryLocation->city ?? $job->deliveryLocation->company_name }}
                            @endif
                            @if($job->driver)
                                <span class="text-slate-400"> · driver </span>{{ $job->driver->name }}
                            @endif
                            <span class="text-slate-400"> · updated </span>{{ $job->updated_at?->diffForHumans() }}
                        </p>
                    </div>

                    {{-- Middle: thumbnail strip --}}
                    <div class="flex items-center gap-1.5 shrink-0">
                        @foreach($job->damageThumbs as $thumb)
                            @can('view', $thumb)
                            <a href="{{ route('documents.view', $thumb) }}" target="_blank" rel="noopener"
                               class="block h-16 w-16 rounded-md overflow-hidden border border-rose-200 bg-rose-50 hover:border-rose-400 transition">
                                @if(str_starts_with((string) $thumb->mime_type, 'image/'))
                                    <img src="{{ route('documents.view', $thumb) }}"
                                         alt="Damage photo"
                                         class="h-full w-full object-cover"
                                         loading="lazy">
                                @else
                                    <div class="h-full w-full flex items-center justify-center text-[10px] text-rose-400 p-1 text-center">file</div>
                                @endif
                            </a>
                            @endcan
                        @endforeach
                        @if($job->damage_photos_count > $job->damageThumbs->count())
                            <div class="h-16 w-16 rounded-md border border-dashed border-rose-300 bg-white flex items-center justify-center text-[11px] font-semibold text-rose-700">
                                +{{ $job->damage_photos_count - $job->damageThumbs->count() }}
                            </div>
                        @endif
                    </div>

                    {{-- Right: actions --}}
                    <div class="flex flex-col gap-2 shrink-0 lg:w-48">
                        <a href="{{ route('admin.orders.show', $job) }}#damage-section"
                           class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Review incident
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                        <a href="{{ route('damage-report.download', $job) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-500">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                            {{ $job->damage_report_released_at ? 'PDF Report' : 'Preview (ops)' }}
                        </a>
                        @can('releaseDamageReport', $job)
                            @if(!$job->damage_report_released_at)
                                <button wire:click="releaseDamageReport({{ $job->id }})"
                                        wire:confirm="Release this damage report to the customer?"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-emerald-600 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    <span wire:loading.remove wire:target="releaseDamageReport({{ $job->id }})">Release to customer</span>
                                    <span wire:loading wire:target="releaseDamageReport({{ $job->id }})">Releasing…</span>
                                </button>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $jobs->links() }}</div>
    @endif
</div>
