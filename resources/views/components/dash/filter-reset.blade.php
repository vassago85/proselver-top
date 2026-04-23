{{--
    Consistent reset button for filter bars.

        <x-dash.filter-reset wire:click="resetFilters" />
--}}
@props(['label' => 'Reset'])

<button type="button" {{ $attributes->class('inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors') }}>
    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/>
        <path d="M3 3v5h5"/>
    </svg>
    {{ $label }}
</button>
