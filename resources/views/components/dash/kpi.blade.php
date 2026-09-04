{{--
    Canonical KPI tile. All dashboards (exec, driver ops, dispatch,
    deliveries, yard, customer) should use this so KPI rows feel
    identical across pages.

        <x-dash.kpi
            label="In Transit"
            :value="42"
            color="blue"
            href="{{ route('admin.movements') }}"
            helper="Live on the road"
            :trend="['dir' => 'up', 'label' => '+8%']"
        >
            <x-slot:icon>
                <svg viewBox="0 0 24 24" ... />
            </x-slot:icon>
        </x-dash.kpi>

    Color is semantic — use it consistently:
      blue   = active / in progress / in transit
      green  = completed / healthy / on-time / paid
      amber  = warning / at risk / soon-expiring
      red    = critical / delayed / blocked / overdue
      teal   = throughput / distribution / neutral-positive
      indigo = planning / queued
      purple = invoicing / finance-adjacent
      orange = receivables / attention
      slate  = neutral / inactive / archived
--}}
@props([
    'label' => null,
    'value' => null,
    'color' => 'slate',
    'href' => null,
    'helper' => null,
    'trend' => null,
    // Compact tiles pack the same information into a ~98px-tall card so
    // a 6-across strip fits without dominating the viewport.  The icon
    // slot is intentionally hidden in compact mode -- the accent dot
    // already colour-codes the tile, and the icon becomes visual noise
    // at this size.
    'compact' => false,
])

@php
    $accentText = [
        'blue'   => 'text-blue-700',
        'green'  => 'text-emerald-700',
        'amber'  => 'text-amber-700',
        'red'    => 'text-rose-700',
        'teal'   => 'text-teal-700',
        'indigo' => 'text-indigo-700',
        'purple' => 'text-purple-700',
        'orange' => 'text-orange-700',
        'slate'  => 'text-slate-900',
    ][$color] ?? 'text-slate-900';

    $accentDot = [
        'blue'   => 'bg-blue-500',
        'green'  => 'bg-emerald-500',
        'amber'  => 'bg-amber-500',
        'red'    => 'bg-rose-500',
        'teal'   => 'bg-teal-500',
        'indigo' => 'bg-indigo-500',
        'purple' => 'bg-purple-500',
        'orange' => 'bg-orange-500',
        'slate'  => 'bg-slate-400',
    ][$color] ?? 'bg-slate-400';

    $accentIcon = [
        'blue'   => 'text-blue-400',
        'green'  => 'text-emerald-400',
        'amber'  => 'text-amber-400',
        'red'    => 'text-rose-400',
        'teal'   => 'text-teal-400',
        'indigo' => 'text-indigo-400',
        'purple' => 'text-purple-400',
        'orange' => 'text-orange-400',
        'slate'  => 'text-slate-300',
    ][$color] ?? 'text-slate-300';

    $trendClass = isset($trend) && ($trend['dir'] ?? null) === 'down'
        ? 'text-rose-600'
        : 'text-emerald-600';

    $padding = $compact ? 'p-4' : 'p-5';
    $valueSize = $compact ? 'text-[22px] leading-none' : 'text-3xl';
    $valueMargin = $compact ? 'mt-2.5' : 'mt-3';
    $helperMargin = $compact ? 'mt-1' : 'mt-1.5';
@endphp

@if($href)
    <a href="{{ $href }}"
       class="group relative block rounded-xl border border-slate-200 bg-white {{ $padding }} shadow-sm transition-all hover:border-slate-300 hover:shadow-md hover:-translate-y-0.5">
@else
    <div class="group relative rounded-xl border border-slate-200 bg-white {{ $padding }} shadow-sm">
@endif

    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
            <span class="h-1.5 w-1.5 rounded-full {{ $accentDot }}"></span>
            <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500 truncate">{{ $label }}</p>
        </div>
        @if(!$compact)
            @isset($icon)
                <span class="{{ $accentIcon }} transition-colors group-hover:text-slate-500">{{ $icon }}</span>
            @endisset
        @endif
    </div>

    <div class="{{ $valueMargin }} flex items-baseline gap-2">
        <span class="{{ $valueSize }} font-semibold tracking-tight tabular-nums {{ $accentText }}">{{ $value }}</span>
        @if($trend)
            <span class="inline-flex items-center gap-0.5 text-[11px] font-semibold {{ $trendClass }}">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    @if(($trend['dir'] ?? 'up') === 'up')
                        <path d="m6 9 6-6 6 6"/><path d="M12 3v18"/>
                    @else
                        <path d="m6 15 6 6 6-6"/><path d="M12 3v18"/>
                    @endif
                </svg>
                {{ $trend['label'] ?? '' }}
            </span>
        @endif
    </div>

    @if($helper)
        <p class="{{ $helperMargin }} text-[11px] font-medium text-slate-500 {{ $compact ? 'line-clamp-2' : 'line-clamp-2' }}">{{ $helper }}</p>
    @endif

@if($href)
    </a>
@else
    </div>
@endif
