{{--
    Label wrapper for arbitrary inputs inside a filter bar. Use when the
    stock <x-dash.filter-select> / <x-dash.filter-date> don't fit (e.g.
    custom multi-select, search box, toggle group).

        <x-dash.filter-field label="Search">
            <input type="text" class="..." />
        </x-dash.filter-field>
--}}
@props(['label' => null, 'minWidth' => '160px'])

<div class="flex-1" style="min-width: {{ $minWidth }};">
    @if($label)
        <label class="block text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 mb-1.5">{{ $label }}</label>
    @endif
    {{ $slot }}
</div>
