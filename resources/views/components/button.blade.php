@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger | success
    'size' => 'md',           // sm | md | lg
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconRight' => false,
    'loading' => null,        // wire:target attribute for Livewire loading state
])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-semibold rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

    $variants = [
        'primary'   => 'bg-blue-600 text-white hover:bg-blue-500 shadow-sm focus-visible:ring-blue-600',
        'secondary' => 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus-visible:ring-slate-400',
        'ghost'     => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-slate-400',
        'danger'    => 'bg-rose-600 text-white hover:bg-rose-500 shadow-sm focus-visible:ring-rose-600',
        'success'   => 'bg-emerald-600 text-white hover:bg-emerald-500 shadow-sm focus-visible:ring-emerald-600',
        'dark'      => 'bg-slate-900 text-white hover:bg-slate-800 shadow-sm focus-visible:ring-slate-700',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-sm',
    ];

    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']);

    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if($loading !== null) wire:target="{{ $loading }}" @endif
    {{ $attributes->merge(['class' => $classes]) }}>

    @if($loading !== null)
        <svg wire:loading wire:target="{{ $loading }}" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
        <span wire:loading.remove wire:target="{{ $loading }}" class="contents">
    @endif

    @if($icon && !$iconRight)<span class="shrink-0">{{ $icon }}</span>@endif
    {{ $slot }}
    @if($icon && $iconRight)<span class="shrink-0">{{ $icon }}</span>@endif

    @if($loading !== null)</span>@endif
</{{ $tag }}>
