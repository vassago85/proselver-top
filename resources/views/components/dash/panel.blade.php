{{--
    Canonical section card for every dashboard. Replaces ad-hoc
    `rounded-xl border border-slate-200 bg-white ...` wrappers so
    header height, padding, borders and shadow stay identical across
    pages.

        <x-dash.panel
            title="Priority Movements"
            subtitle="Next 7 days — driver assigned, not yet moving."
        >
            <x-slot:actions>
                <x-dash.pill variant="blue">{{ $count }} jobs</x-dash.pill>
                <a href="..." class="text-xs font-semibold text-blue-600 hover:text-blue-700">View all →</a>
            </x-slot:actions>

            ...body content...

            <x-slot:footer>...optional...</x-slot:footer>
        </x-dash.panel>

    Use :tight="true" when the body is a full-width table and you
    don't want the default padding.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'tight' => false,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-col']) }}>
    @if($title || $subtitle || isset($actions))
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div class="min-w-0">
                @if($title)
                    <h2 class="text-[15px] font-semibold tracking-tight text-slate-900 truncate">{{ $title }}</h2>
                @endif
                @if($subtitle)
                    <p class="mt-0.5 text-xs text-slate-500 truncate">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="flex-1 min-w-0 {{ $tight ? '' : 'p-5' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-slate-100 bg-slate-50/60 px-5 py-3 text-xs text-slate-500">
            {{ $footer }}
        </div>
    @endisset
</div>
