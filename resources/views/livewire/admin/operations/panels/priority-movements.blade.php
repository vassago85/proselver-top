{{--
    Ops queue: every active row, grouped by dispatch lane, sorted so
    the worst overdue breach floats to the top. Incomplete lanes
    (missing province) pin under a single "Lane not set" heading at
    the top of the list so ops can fix the data debt.

    Each row wears a Stage badge (Intake / Ready to dispatch /
    Dispatched / On the road / POD pending). When overdue, the badge
    turns rose and appends "· {n}h over"; otherwise it's neutral.
--}}
<div wire:poll.60s
     class="ow-card overflow-hidden">
    <header class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <div>
            <p class="text-[13px] font-semibold text-slate-900">Ops queue</p>
            <p class="mt-0.5 text-[10.5px] text-slate-500">
                {{ $jobs->count() }} active
                @if($overdueTotal > 0)
                    · <span class="font-semibold text-rose-600 tabular-nums">{{ $overdueTotal }} overdue</span>
                @endif
                · grouped by lane, worst overdue first.
            </p>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] text-slate-500">
            <span class="ow-live-dot" aria-hidden="true"></span>
            <span class="font-semibold tabular-nums">Live · updated {{ $updatedAt->format('H:i:s') }}</span>
        </div>
    </header>

    @if($lanes->isEmpty())
        <div class="px-4 py-6 text-center text-[13px] text-slate-500">
            No active movements in scope — nothing to dispatch.
        </div>
    @else
        <div class="divide-y divide-slate-100 max-h-[640px] overflow-y-auto">
            @foreach($lanes as $lane)
                @php
                    // Header colour signals the lane's worst breach so
                    // ops can scan without expanding. Incomplete lanes
                    // are always rose — they represent data ops MUST fix.
                    $laneHeaderTone = match(true) {
                        ! $lane['is_complete']       => 'bg-rose-50/70',
                        $lane['worst_overdue'] > 24  => 'bg-rose-50/60',
                        $lane['worst_overdue'] > 0   => 'bg-amber-50/60',
                        default                      => '',
                    };
                @endphp
                <details class="group" open>
                    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50 {{ $laneHeaderTone }}">
                        <div class="min-w-0">
                            <p class="text-[13px] font-semibold text-slate-900">
                                {{ $lane['lane'] }}
                                @if(! $lane['is_complete'])
                                    <span class="ml-1 inline-flex items-center rounded-md bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">Fix province</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-[10.5px] text-slate-500">
                                {{ $lane['count'] }} {{ Str::plural('job', $lane['count']) }} waiting
                                @if($lane['customers'] > 0)
                                    · {{ $lane['customers'] }} {{ Str::plural('customer', $lane['customers']) }}
                                @endif
                                @if($lane['overdue_count'] > 0)
                                    · <span class="font-semibold text-rose-600">{{ $lane['overdue_count'] }} overdue</span>
                                    @if($lane['worst_overdue'] > 0)
                                        · worst {{ $lane['worst_overdue'] < 24 ? $lane['worst_overdue'].'h' : intdiv($lane['worst_overdue'], 24).'d' }} over
                                    @endif
                                @elseif($lane['oldest_hours'] > 0)
                                    · oldest {{ $lane['oldest_hours'] < 24 ? $lane['oldest_hours'].'h' : intdiv($lane['oldest_hours'], 24).'d' }}
                                @endif
                            </p>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </summary>
                    <ul class="divide-y divide-slate-100 bg-slate-50/40">
                        @foreach($lane['jobs'] as $job)
                            @php
                                $badgeTone = $job->is_overdue
                                    ? 'bg-rose-100 text-rose-700 ring-1 ring-inset ring-rose-200'
                                    : 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200';
                            @endphp
                            <li class="grid grid-cols-[1fr_auto] items-center gap-3 px-6 py-2 hover:bg-white">
                                <a href="{{ route('admin.orders.show', $job) }}" class="min-w-0">
                                    <div class="flex items-center gap-2 text-[12.5px]">
                                        <span class="font-semibold text-slate-900">{{ $job->job_number ?? '#'.$job->id }}</span>
                                        <span class="text-slate-500 truncate">
                                            {{ $job->company?->name }}
                                            @if($job->brand?->name)
                                                · {{ $job->brand->name }}
                                                @if($job->model_name) {{ $job->model_name }} @endif
                                            @endif
                                        </span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10.5px] text-slate-500">
                                        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold tabular-nums {{ $badgeTone }}">
                                            {{ $job->stage_label }}
                                            @if($job->is_overdue)
                                                <span class="ml-1">· {{ $job->overdue_by }}h over</span>
                                            @endif
                                        </span>
                                        <span>
                                            in stage
                                            @if($job->hours_in_stage < 24)
                                                {{ $job->hours_in_stage }}h
                                            @else
                                                {{ $job->days_in_stage }}d
                                            @endif
                                        </span>
                                        @if($job->driver?->name)
                                            <span>· {{ $job->driver->name }}</span>
                                        @endif
                                    </div>
                                </a>
                                <a href="{{ route('admin.orders.show', $job) }}"
                                   class="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-900">
                                    Open →
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    @endif
</div>
