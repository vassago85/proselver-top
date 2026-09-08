{{--
    Priority movements, grouped by lane (origin province → destination
    province). A lane heading is the dispatch decision; the jobs below
    it are the passengers. Ops acts on the lane, not the row.
--}}
<div wire:poll.60s
     class="ow-card overflow-hidden">
    <header class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <div>
            <p class="text-[13px] font-semibold text-slate-900">Priority movements</p>
            <p class="mt-0.5 text-[10.5px] text-slate-500">Top {{ $jobs->count() }} stuck jobs, oldest first · grouped by lane.</p>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] text-slate-500">
            <span class="ow-live-dot" aria-hidden="true"></span>
            <span class="font-semibold tabular-nums">Live · updated {{ $updatedAt->format('H:i:s') }}</span>
        </div>
    </header>

    @if($lanes->isEmpty())
        <div class="px-4 py-6 text-center text-[13px] text-slate-500">
            No stuck jobs — every active movement is inside its stage threshold.
        </div>
    @else
        <div class="divide-y divide-slate-100">
            @foreach($lanes as $lane)
                <details class="group" open>
                    <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="text-[13px] font-semibold text-slate-900">{{ $lane['lane'] }}</p>
                            <p class="text-[10.5px] text-slate-500">
                                {{ $lane['count'] }} {{ Str::plural('job', $lane['count']) }} waiting
                                @if($lane['customers'] > 0)
                                    · {{ $lane['customers'] }} {{ Str::plural('customer', $lane['customers']) }}
                                @endif
                                @if($lane['oldest_hours'] !== null)
                                    · oldest {{ $lane['oldest_hours'] < 24 ? $lane['oldest_hours'].'h' : intdiv($lane['oldest_hours'], 24).'d' }}
                                @endif
                            </p>
                        </div>
                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </summary>
                    <ul class="divide-y divide-slate-100 bg-slate-50/40">
                        @foreach($lane['jobs'] as $job)
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
                                    <div class="mt-0.5 text-[10.5px] text-slate-500">
                                        <span class="font-semibold text-slate-700">{{ \App\Enums\JobStatus::tryFrom($job->status)?->label() ?? $job->status }}</span>
                                        @php $enteredAt = $job->status_entered_at ?? $job->updated_at; @endphp
                                        · in stage for
                                        @if($job->hours_in_stage < 24)
                                            {{ $job->hours_in_stage }}h
                                        @else
                                            {{ $job->days_in_stage }}d
                                        @endif
                                        @if($job->driver?->name)
                                            · {{ $job->driver->name }}
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
