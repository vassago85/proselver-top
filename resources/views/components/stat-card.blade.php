@props([
    'label',
    'value',
    'color' => 'slate',
    'href' => null,
    'icon' => null,
    'helper' => null,       // secondary line e.g. "3 with issues"
    'helperColor' => 'slate',
    'trend' => null,        // 'up' | 'down' | null
    'trendValue' => null,   // e.g. '+12%'
])

@php
    $accentMap = [
        'slate'   => 'text-slate-900',
        'blue'    => 'text-blue-700',
        'green'   => 'text-emerald-700',
        'emerald' => 'text-emerald-700',
        'red'     => 'text-rose-700',
        'yellow'  => 'text-amber-700',
        'amber'   => 'text-amber-700',
        'orange'  => 'text-orange-700',
        'purple'  => 'text-purple-700',
        'indigo'  => 'text-indigo-700',
        'cyan'    => 'text-cyan-700',
        'teal'    => 'text-teal-700',
    ];
    $dotMap = [
        'slate' => 'bg-slate-400', 'blue' => 'bg-blue-500', 'green' => 'bg-emerald-500', 'emerald' => 'bg-emerald-500',
        'red' => 'bg-rose-500', 'yellow' => 'bg-amber-500', 'amber' => 'bg-amber-500', 'orange' => 'bg-orange-500',
        'purple' => 'bg-purple-500', 'indigo' => 'bg-indigo-500', 'cyan' => 'bg-cyan-500', 'teal' => 'bg-teal-500',
    ];
    $helperTextMap = [
        'slate' => 'text-slate-500', 'blue' => 'text-blue-600', 'green' => 'text-emerald-600', 'emerald' => 'text-emerald-600',
        'red' => 'text-rose-600', 'yellow' => 'text-amber-700', 'amber' => 'text-amber-700', 'orange' => 'text-orange-600',
        'purple' => 'text-purple-600', 'indigo' => 'text-indigo-600', 'cyan' => 'text-cyan-600', 'teal' => 'text-teal-600',
    ];

    $valueClass = $accentMap[$color] ?? $accentMap['slate'];
    $dotClass = $dotMap[$color] ?? $dotMap['slate'];
    $helperClass = $helperTextMap[$helperColor] ?? $helperTextMap['slate'];

    $tag = $href ? 'a' : 'div';
    $hoverClasses = $href ? 'hover:border-slate-300 hover:shadow-md hover:-translate-y-0.5' : '';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
    class="group relative block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all {{ $hoverClasses }}">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
            <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}"></span>
            <p class="text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500 truncate">{{ $label }}</p>
        </div>
        @if($icon)
            <span class="text-slate-300 group-hover:text-slate-400 transition-colors">{{ $icon }}</span>
        @elseif($href)
            <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
        @endif
    </div>
    <div class="mt-3 flex items-baseline gap-2">
        <span class="text-3xl font-semibold tracking-tight tabular-nums {{ $valueClass }}">{{ $value }}</span>
        @if($trend && $trendValue)
            <span class="inline-flex items-center gap-0.5 text-xs font-semibold {{ $trend === 'up' ? 'text-emerald-600' : 'text-rose-600' }}">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    @if($trend === 'up')<path d="m6 9 6-6 6 6"/><path d="M12 3v18"/>@else<path d="m6 15 6 6 6-6"/><path d="M12 3v18"/>@endif
                </svg>
                {{ $trendValue }}
            </span>
        @endif
    </div>
    @if($helper)
        <p class="mt-1.5 text-xs font-medium {{ $helperClass }}">{{ $helper }}</p>
    @endif
</{{ $tag }}>
