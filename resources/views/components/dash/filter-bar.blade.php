{{--
    <x-dash.filter-bar>
        ...<x-dash.filter-date>, <x-dash.filter-select>, ...

    The unified header filter strip used on every operations dashboard.
    Defines spacing, border, shadow and responsive wrapping once so each
    page stops inventing its own filter row. Children are free-form so a
    page can drop in a reset button, a pill, an export link — whatever.
--}}
@props([])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm p-4']) }}>
    <div class="flex flex-wrap items-end gap-3">
        {{ $slot }}
    </div>
</div>
