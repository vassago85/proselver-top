@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,       // small uppercase line above title
    'backHref' => null,
    'backLabel' => 'Back',
])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if($backHref)
            <a href="{{ $backHref }}" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-800 mb-2 transition-colors">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                {{ $backLabel }}
            </a>
        @endif
        @if($eyebrow)
            <p class="text-[10px] font-semibold uppercase tracking-[0.25em] text-blue-600 mb-1.5">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-2xl sm:text-[28px] font-semibold tracking-tight text-slate-900 leading-tight">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-1.5 text-sm text-slate-600 max-w-2xl">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
