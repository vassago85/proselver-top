{{--
    Single card on the live wallboard.  Used by /live-display for all
    three lanes (Waiting / In Transit / Delivered Today).

    Required vars:
      $job         App\Models\Job
      $style       [ringClasses, statusTextClass, statusLabel]
      $flags       ['classes' => string[], 'label' => ?string]   (exception flags)
      $isInternal  bool                                            (show owning company name)
      $lane        'waiting' | 'transit' | 'delivered'
      $accentText  Tailwind text-* class for the route-line arrow

    `data-job-id` + `data-updated-at` let the Alpine `wallboard` Snapshot
    diff identify newly-arrived and freshly-updated rows after each
    Livewire poll and play the card-enter / card-pulse animation on
    only the deltas (the rest of the lane stays calm).
--}}
@php
    // Prefer city for the route line because it reads from across the
    // room better than a 50-character company name.  Fall back to the
    // company_name on the location when no city is set, then to an em-
    // dash so the layout doesn't collapse.
    $fromShort = $job->pickupLocation?->city
        ?: $job->pickupLocation?->company_name
        ?: '—';
    $toShort = $job->deliveryLocation?->city
        ?: $job->deliveryLocation?->company_name
        ?: '—';

    // Full names for the secondary line under the route — read these
    // up close.  Skipped if they're the same as the short label (no
    // point in repeating "Cape Town · Cape Town").
    $fromLong = $job->pickupLocation?->company_name;
    $toLong   = $job->deliveryLocation?->company_name;
@endphp

<div
    wire:key="lane-card-{{ $lane }}-{{ $job->id }}"
    data-job-id="{{ $job->id }}"
    data-updated-at="{{ $job->updated_at?->getTimestamp() }}"
    @class([
        'rounded-xl ring-1 p-3 transition-transform',
        $style[0],
        implode(' ', $flags['classes'] ?? []),
    ])
>
    {{-- Top row: movement number + status pill ---------------------- --}}
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="text-xl font-extrabold tracking-tight text-white truncate" title="{{ $job->job_number }}">
                {{ $job->job_number ?? '—' }}
            </div>
            <div class="text-xs text-slate-300 truncate">
                {{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: 'Vehicle TBD' }}
            </div>
            @if($isInternal)
                <div class="mt-0.5 text-[10px] uppercase tracking-[0.15em] text-cyan-300/80 truncate">
                    {{ $job->company?->name ?? '—' }}
                </div>
            @endif
        </div>
        <div class="flex shrink-0 flex-col items-end gap-1">
            <span class="rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $style[1] }}">
                {{ $style[2] }}
            </span>
            @if(!empty($flags['label']))
                {{-- Exception badge — only one rendered per card,
                     chosen by severity (overdue > unassigned > stale)
                     in the parent's cardFlags() callback. --}}
                <span @class([
                        'rounded-md px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider',
                        'bg-red-500/20 text-red-200 ring-1 ring-red-500/50'        => $flags['label'] === 'OVERDUE',
                        'bg-amber-500/20 text-amber-100 ring-1 ring-amber-500/50' => $flags['label'] === 'NO DRIVER',
                        'bg-slate-500/20 text-slate-200 ring-1 ring-slate-500/50' => $flags['label'] === 'STALE',
                ])>
                    {{ $flags['label'] }}
                </span>
            @endif
        </div>
    </div>

    {{-- Route line: origin → destination, sized for distance reading --}}
    <div class="mt-2.5 flex items-center gap-2">
        <div class="min-w-0 flex-1 truncate text-right text-sm text-slate-300" title="{{ $fromLong }}">
            {{ $fromShort }}
        </div>
        <div class="flex shrink-0 items-center {{ $accentText }}">
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            <span class="h-px w-5 bg-current opacity-60"></span>
            <svg class="-ml-px h-3 w-3" viewBox="0 0 8 8" fill="currentColor" aria-hidden="true">
                <polygon points="0,0.8 7.6,4 0,7.2"/>
            </svg>
        </div>
        <div class="min-w-0 flex-1 truncate text-base font-bold text-white" title="{{ $toLong }}">
            {{ $toShort }}
        </div>
    </div>

    {{-- Optional second line with full location names — only shown
         when they differ from the city short labels (avoids "Sandton ·
         Sandton" looking like a typo). --}}
    @if(($fromLong && $fromLong !== $fromShort) || ($toLong && $toLong !== $toShort))
        <div class="mt-1 flex items-center gap-2 text-[10px] text-slate-500">
            <div class="min-w-0 flex-1 truncate text-right">{{ $fromLong && $fromLong !== $fromShort ? $fromLong : '' }}</div>
            <span class="opacity-30">·</span>
            <div class="min-w-0 flex-1 truncate">{{ $toLong && $toLong !== $toShort ? $toLong : '' }}</div>
        </div>
    @endif

    {{-- Footer row: driver / status meta (lane-specific layout) ---- --}}
    <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-400">
        @if($lane === 'waiting')
            <div class="truncate">
                <span class="text-slate-500">Driver</span>
                @if($job->driver?->name)
                    <span class="text-slate-200">{{ $job->driver->name }}</span>
                @else
                    <span class="font-semibold text-amber-300">— unassigned</span>
                @endif
            </div>
            <div class="truncate text-right tabular-nums">
                @if($job->scheduled_date)
                    {{ \Carbon\Carbon::parse($job->scheduled_date)->format('d M') }}@if($job->scheduled_ready_time) · {{ \Carbon\Carbon::parse($job->scheduled_ready_time)->format('H:i') }}@endif
                @else
                    <span class="text-slate-600">no date</span>
                @endif
            </div>
        @elseif($lane === 'transit')
            <div class="truncate">
                <span class="text-slate-500">Driver</span>
                <span class="text-slate-200">{{ $job->driver?->name ?? '—' }}</span>
            </div>
            <div class="truncate text-right tabular-nums">
                <span class="text-slate-500">Updated</span>
                {{ $job->updated_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }} ago
            </div>
        @else {{-- delivered --}}
            <div class="truncate">
                <span class="text-slate-500">By</span>
                <span class="text-slate-200">{{ $job->driver?->name ?? '—' }}</span>
            </div>
            <div class="truncate text-right tabular-nums text-emerald-300/80">
                {{ $job->updated_at?->format('H:i') }}
            </div>
        @endif
    </div>
</div>
