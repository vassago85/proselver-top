@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
    'hover' => false,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white shadow-sm ' . ($hover ? 'hover:shadow-md hover:border-slate-300 transition-all' : '')]) }}>
    @if($title || $subtitle || isset($header))
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-6 py-4">
            <div class="min-w-0">
                @if($title)<h2 class="text-base font-semibold text-slate-900 tracking-tight">{{ $title }}</h2>@endif
                @if($subtitle)<p class="mt-0.5 text-xs text-slate-500">{{ $subtitle }}</p>@endif
                @isset($header){{ $header }}@endisset
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding ? 'p-6' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-slate-100 px-6 py-3 bg-slate-50/50 rounded-b-xl">
            {{ $footer }}
        </div>
    @endisset
</div>
