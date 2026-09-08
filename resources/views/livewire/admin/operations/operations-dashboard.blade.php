@php
    use App\Domain\Operations\Queries\ThroughputQuery;
    use App\Domain\Operations\Queries\LaneSummaryQuery;
    use App\Domain\Operations\Queries\OnTimeQuery;
    use App\Domain\Operations\Queries\FlowChartQuery;
    use App\Domain\Operations\Queries\DwellDistributionQuery;

    // Range-bound queries live in the shell for now. Later phases can
    // pull them into their own lazy panels; the split is architectural,
    // not reactive — these values only change when the date range does.
    $throughput = (new ThroughputQuery())->get($filters);
    $onTime     = (new OnTimeQuery())->get($filters);
    $lanes      = (new LaneSummaryQuery())->get($filters);
    $flow       = (new FlowChartQuery())->get($filters);
    $dwell      = (new DwellDistributionQuery())->get($filters);

    // Longest dwell across all groups drives the shared x-axis on the
    // p50/p90 bar chart so bars in different groups stay comparable.
    $dwellMax = max(1, collect($dwell)->max('max_hours'));
@endphp

<div>
    <x-owner-wall.styles />

    @include('pages.admin._partials.dashboard-tabs')

    <div class="owner-wall space-y-3.5">
        {{-- ── Header ─────────────────────────────────────────────── --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="ow-label text-blue-600">Operations</p>
                <h1 class="mt-1 text-[26px] sm:text-[30px] font-bold tracking-tight text-slate-900">
                    Operations Command Centre
                </h1>
                <p class="mt-1 text-[13px] text-slate-500">
                    What's live, what's stuck, and what's slipping — every number is a link to the queue behind it.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.dispatch') }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 shadow-sm hover:border-slate-300">
                    Dispatch queue
                </a>
                <a href="{{ route('admin.orders.index', ['statusFilter' => 'delivered']) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 shadow-sm hover:border-slate-300">
                    Deliveries
                </a>
            </div>
        </div>

        {{-- ── Entity filter bar (does not touch the performance date) ── --}}
        <div class="ow-card flex flex-wrap items-center gap-2 px-3 py-2.5">
            <span class="ow-label pr-1">Filter</span>

            {{-- Customer picker is a searchable combobox — 60+ dupe
                 customer records mean the native <select> was a
                 scrolling contest. Company-record dedupe is a
                 separate follow-up. --}}
            @php
                $companyOptions = collect($companies)
                    ->filter(fn ($c) => (bool) $c->id)
                    ->map(fn ($c) => ['value' => (string) $c->id, 'label' => (string) $c->name])
                    ->values()
                    ->all();
                $selectedCustomerLabel = $companyId
                    ? optional(collect($companies)->firstWhere('id', (int) $companyId))->name
                    : null;
            @endphp
            <div class="w-56 min-w-[200px]">
                <x-searchable-select
                    wire:model.live="companyId"
                    :options="$companyOptions"
                    :selected-label="$selectedCustomerLabel"
                    placeholder="All customers"
                    search-placeholder="Search customers…"
                    empty-text="No matching customer."
                />
            </div>

            <select wire:model.live="transporterId"
                    class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[12px] font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                <option value="">All transporters</option>
                @foreach($transporters as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="brandId"
                    class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[12px] font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                <option value="">All brands</option>
                @foreach($brands as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="region"
                    class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[12px] font-medium text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                <option value="">All provinces</option>
                @foreach($provinces as $p)
                    <option value="{{ $p }}">{{ $p }}</option>
                @endforeach
            </select>

            @if($companyId || $transporterId || $brandId || $region)
                <button type="button" wire:click="resetFilters"
                        class="ml-auto text-[11px] font-semibold text-slate-500 hover:text-slate-900">
                    Clear filters
                </button>
            @endif
        </div>

        {{-- ── SECTION: Performance ──────────────────────────────── --}}
        {{-- Performance sits at the top so the answer to "how are we
             doing this week" is the first thing ops sees on load.
             Live pipeline (state right now) follows underneath. --}}
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="ow-label">Performance</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">Window: <span class="font-semibold text-slate-700">{{ $windowLabel }}</span></p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach($presets as $key => $label)
                        <button type="button"
                                wire:click="applyPreset('{{ $key }}')"
                                class="rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition-colors
                                       {{ $preset === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                    <span class="text-slate-300">|</span>
                    <input type="date" wire:model.live="dateFrom"
                           class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-700">
                    <input type="date" wire:model.live="dateTo"
                           class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-700">
                </div>
            </div>

            <div class="grid gap-3.5 lg:grid-cols-4">
                {{-- Delivered hero. Progress bar removed — the ratio
                     "N of M scheduled · %" is the whole story; a fake
                     0—100 meter added visual weight without meaning. --}}
                @php
                    $pct = min(100, $throughput['throughput_pct']);
                    $pctTone = match(true) {
                        $pct >= 90 => 'text-emerald-600',
                        $pct >= 70 => 'text-amber-700',
                        default    => 'text-rose-600',
                    };
                @endphp
                <div class="ow-card p-5">
                    <p class="ow-label">Delivered</p>
                    <p class="ow-hero mt-2">{{ number_format($throughput['delivered']) }}</p>
                    <p class="mt-1 text-[12px] text-slate-500">
                        of <span class="font-semibold text-slate-700 tabular-nums">{{ number_format($throughput['scheduled']) }}</span> scheduled
                        · <span class="font-semibold tabular-nums {{ $pctTone }}">{{ $pct }}%</span>
                    </p>
                </div>

                {{-- On-time (with coverage gate). Coverage line only
                     shows when it's less than 100% — a "coverage 100%"
                     footnote adds nothing but chart junk. --}}
                <div class="ow-card p-5">
                    <p class="ow-label">On-time</p>
                    @if($onTime['is_measurable'])
                        @php $otFill = match(true) { $onTime['on_time_pct'] >= 90 => 'text-emerald-600', $onTime['on_time_pct'] >= 75 => 'text-amber-600', default => 'text-rose-600' }; @endphp
                        <p class="ow-hero mt-2 {{ $otFill }}">{{ $onTime['on_time_pct'] }}%</p>
                        <p class="mt-1 text-[12px] text-slate-500">
                            <span class="font-semibold text-slate-700 tabular-nums">{{ number_format($onTime['on_time']) }}</span>
                            of {{ number_format($onTime['measurable']) }} measurable deliveries met their target
                        </p>
                        @if($onTime['coverage_pct'] < 100)
                            <p class="mt-2 text-[10.5px] text-slate-400">
                                Coverage {{ $onTime['coverage_pct'] }}% ({{ number_format($onTime['measurable']) }} of {{ number_format($onTime['delivered']) }} deliveries)
                            </p>
                        @endif
                    @else
                        <p class="ow-hero mt-2 text-slate-300 tabular-nums">—</p>
                        <p class="mt-1 text-[12px] text-slate-500">Not measurable this window.</p>
                        <p class="mt-2 text-[11px] text-slate-500">
                            Only <span class="font-semibold tabular-nums">{{ $onTime['coverage_pct'] }}%</span> of
                            {{ number_format($onTime['delivered']) }} deliveries have a target set — need
                            <span class="font-semibold">≥ {{ (int) (OnTimeQuery::COVERAGE_THRESHOLD * 100) }}%</span>.
                        </p>
                        @if($onTime['missing_target'] > 0)
                            <a href="{{ route('admin.orders.index', ['statusFilter' => 'delivered']) }}"
                               class="mt-3 inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:border-slate-300">
                                {{ number_format($onTime['missing_target']) }} deliveries missing a target →
                            </a>
                        @endif
                    @endif
                </div>

                {{-- Gap: single number, wrapped in a link that lands
                     on the exact same rows via ?exception=scheduled_not_delivered.
                     The 1–7d / 8–30d / 30d+ triplet is gone — those
                     buckets were rarely acted on and the raw gap is
                     the number ops actually cares about. --}}
                <a href="{{ route('admin.orders.index', ['exception' => 'scheduled_not_delivered']) }}"
                   class="ow-card block p-5 transition-colors hover:border-slate-300">
                    <p class="ow-label">Gap</p>
                    <p class="mt-2 text-[28px] font-bold tabular-nums text-slate-900">{{ number_format($throughput['gap']) }}</p>
                    <p class="mt-1 text-[12px] text-slate-500">
                        scheduled but not delivered
                        <span class="ml-1 text-slate-400">→ open</span>
                    </p>
                </a>

                {{-- Lane volumes. Two numbers per row so the widget
                     stops disagreeing with the ops queue below it:
                     `jobs` = still dispatchable (Intake + Ready,
                     the consolidation opportunity the widget exists
                     for); `in_flight` = Dispatched → POD-pending.
                     Sum matches the queue's lane row exactly. --}}
                <div class="ow-card p-5">
                    <p class="ow-label">Top waiting lanes</p>
                    <p class="mt-1 text-[11px] text-slate-500">
                        Pickup province → destination
                        <span class="text-slate-400">·</span>
                        <span class="text-slate-700 font-semibold">awaiting dispatch</span>
                        <span class="text-slate-400">/ in flight</span>
                    </p>
                    @if($lanes->isEmpty())
                        <p class="mt-3 text-[13px] text-slate-500">No waiting jobs.</p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach($lanes as $lane)
                                <li class="flex items-center justify-between gap-2 text-[12.5px]">
                                    <span class="text-slate-700 truncate">
                                        {{ $lane->origin_province ?? '—' }}
                                        <span class="text-slate-400">→</span>
                                        {{ $lane->destination_province ?? '—' }}
                                    </span>
                                    <span class="shrink-0 tabular-nums font-semibold"
                                          title="{{ $lane->jobs }} awaiting dispatch (Intake + Ready) · {{ $lane->in_flight }} in flight (Dispatched → POD-pending)">
                                        <span class="text-slate-900">{{ $lane->jobs }}</span>
                                        @if($lane->in_flight > 0)
                                            <span class="text-slate-400 font-normal">/</span>
                                            <span class="text-slate-500 font-normal">{{ $lane->in_flight }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- ── Flow (throughput by stage per day) + Dwell distribution ── --}}
            <div class="grid gap-3.5 lg:grid-cols-[1.6fr_1fr]">
                {{-- Flow: stacked line per stage group over the window. --}}
                <div class="ow-card p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="ow-label">Flow</p>
                            <p class="mt-0.5 text-[11px] text-slate-500">Jobs entering each stage per day, {{ $flow['window']['days'] }}-day window.</p>
                        </div>
                        <ul class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[10.5px] font-semibold text-slate-600">
                            @foreach($flow['series'] as $s)
                                <li class="flex items-center gap-1.5">
                                    <span class="inline-block h-1.5 w-4 rounded-full" style="background: {{ $s['hue'] }}"></span>
                                    {{ $s['label'] }}
                                    <span class="ml-1 tabular-nums text-slate-400">{{ number_format($flow['totals'][$s['group']->value]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if($flow['max'] <= 1 && collect($flow['totals'])->sum() === 0)
                        <p class="mt-6 text-center text-[13px] text-slate-500">No stage transitions in this window.</p>
                    @else
                        <div class="mt-3">
                            <svg viewBox="0 0 640 180" class="w-full h-auto" preserveAspectRatio="none" aria-label="Flow chart">
                                {{-- Baseline --}}
                                <line x1="0" y1="160" x2="640" y2="160" stroke="#e2e8f0" stroke-width="1"/>
                                @foreach($flow['series'] as $s)
                                    <path d="{{ $s['area'] }}" fill="{{ $s['hue'] }}" fill-opacity="0.08"/>
                                    <path d="{{ $s['path'] }}" fill="none" stroke="{{ $s['hue'] }}" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round"/>
                                @endforeach
                            </svg>
                            <div class="mt-1 flex justify-between text-[10px] tabular-nums text-slate-400">
                                <span>{{ \Carbon\Carbon::parse($flow['window']['from'])->format('d M') }}</span>
                                <span>{{ \Carbon\Carbon::parse($flow['window']['to'])->format('d M') }}</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Dwell p50 / p90 by stage. When a stage has fewer
                     than `trident.min_sample_for_percentiles` rows we
                     hide the percentiles entirely — one job dressed as
                     a statistic is worse than an honest count. --}}
                @php $minSample = (int) config('trident.min_sample_for_percentiles', 5); @endphp
                <div class="ow-card p-5">
                    <p class="ow-label">Dwell (right now)</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">
                        Median + 90th percentile hours in each active stage.
                        <span class="text-slate-400">Percentiles hidden below n={{ $minSample }}.</span>
                    </p>
                    <ul class="mt-3 space-y-3">
                        @foreach($dwell as $key => $d)
                            @php
                                $hasSample = $d['count'] >= $minSample;
                                $p50Width = $hasSample && $dwellMax > 0 ? min(100, ($d['p50_hours'] / $dwellMax) * 100) : 0;
                                $p90Width = $hasSample && $dwellMax > 0 ? min(100, ($d['p90_hours'] / $dwellMax) * 100) : 0;
                                $tone = match($key) {
                                    'intake'     => 'bg-slate-500',
                                    'ready'      => 'bg-cyan-500',
                                    'dispatched' => 'bg-sky-500',
                                    'on_road'    => 'bg-emerald-500',
                                    default      => 'bg-slate-400',
                                };
                            @endphp
                            <li>
                                <div class="flex items-center justify-between text-[11.5px]">
                                    <span class="font-semibold text-slate-700">{{ $d['label'] }}</span>
                                    <span class="tabular-nums text-slate-500">
                                        @if($hasSample)
                                            p50 {{ $d['p50_hours'] }}h · p90 {{ $d['p90_hours'] }}h · n={{ $d['count'] }}
                                        @else
                                            {{ $d['count'] }} {{ Str::plural('job', $d['count']) }} in stage
                                        @endif
                                    </span>
                                </div>
                                @if($hasSample)
                                    <div class="mt-1 relative h-2.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                        <div class="absolute inset-y-0 left-0 {{ $tone }} opacity-30" style="width: {{ $p90Width }}%"></div>
                                        <div class="absolute inset-y-0 left-0 {{ $tone }}" style="width: {{ $p50Width }}%"></div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- ── SECTION: Live ─────────────────────────────────────── --}}
        {{-- Placed below Performance so ops sees the "how are we
             doing" numbers first, then drills into "what's happening
             right now" for the fires that need putting out. --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <p class="ow-label">Live pipeline</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">State right now. Ignores the date range.</p>
                </div>
                <div class="flex items-center gap-1.5 text-[10px] text-slate-500">
                    <span class="ow-live-dot" aria-hidden="true"></span>
                    <span class="font-semibold tabular-nums uppercase tracking-[.14em]">Auto-refresh · 30s</span>
                </div>
            </div>

            <livewire:admin.operations.panels.live-pipeline-panel
                :company-id="$companyId"
                :transporter-id="$transporterId"
                :brand-id="$brandId"
                :region="$region"
                lazy />

            {{-- Full-width ops queue. Exceptions panel is gone — its
                 rows now live inside the queue as Stage badges with
                 "· {n}h over" when overdue. --}}
            <div id="ops-queue" class="scroll-mt-6">
                <livewire:admin.operations.panels.priority-movements-panel
                    :company-id="$companyId"
                    :transporter-id="$transporterId"
                    :brand-id="$brandId"
                    :region="$region"
                    lazy />
            </div>
        </section>
    </div>
</div>
