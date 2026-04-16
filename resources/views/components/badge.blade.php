@props(['color' => 'gray', 'dot' => false, 'size' => 'md'])

@php
    $colors = [
        'gray'    => ['bg' => 'bg-slate-100',   'text' => 'text-slate-700',   'dot' => 'bg-slate-500'],
        'slate'   => ['bg' => 'bg-slate-100',   'text' => 'text-slate-700',   'dot' => 'bg-slate-500'],
        'blue'    => ['bg' => 'bg-blue-50',     'text' => 'text-blue-700',    'dot' => 'bg-blue-500'],
        'green'   => ['bg' => 'bg-emerald-50',  'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500'],
        'emerald' => ['bg' => 'bg-emerald-50',  'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500'],
        'red'     => ['bg' => 'bg-rose-50',     'text' => 'text-rose-700',    'dot' => 'bg-rose-500'],
        'rose'    => ['bg' => 'bg-rose-50',     'text' => 'text-rose-700',    'dot' => 'bg-rose-500'],
        'yellow'  => ['bg' => 'bg-amber-50',    'text' => 'text-amber-800',   'dot' => 'bg-amber-500'],
        'amber'   => ['bg' => 'bg-amber-50',    'text' => 'text-amber-800',   'dot' => 'bg-amber-500'],
        'orange'  => ['bg' => 'bg-orange-50',   'text' => 'text-orange-700',  'dot' => 'bg-orange-500'],
        'purple'  => ['bg' => 'bg-purple-50',   'text' => 'text-purple-700',  'dot' => 'bg-purple-500'],
        'indigo'  => ['bg' => 'bg-indigo-50',   'text' => 'text-indigo-700',  'dot' => 'bg-indigo-500'],
        'cyan'    => ['bg' => 'bg-cyan-50',     'text' => 'text-cyan-700',    'dot' => 'bg-cyan-500'],
        'teal'    => ['bg' => 'bg-teal-50',     'text' => 'text-teal-700',    'dot' => 'bg-teal-500'],
    ];
    $c = $colors[$color] ?? $colors['gray'];

    $sizeClasses = match($size) {
        'sm' => 'px-2 py-0.5 text-[10px]',
        'lg' => 'px-3 py-1 text-xs',
        default => 'px-2.5 py-0.5 text-[11px]',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset ring-black/5 ' . $c['bg'] . ' ' . $c['text'] . ' ' . $sizeClasses]) }}>
    @if($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $c['dot'] }}"></span>
    @endif
    {{ $slot }}
</span>
