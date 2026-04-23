{{--
    Standard dashboard date filter.

        <x-dash.filter-date label="From" wire:model.live="dateFrom" />
--}}
@props(['label' => null, 'minWidth' => '160px'])

<div class="flex-1" style="min-width: {{ $minWidth }};">
    @if($label)
        <label class="block text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 mb-1.5">{{ $label }}</label>
    @endif
    <input type="date" {{ $attributes->class('w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500') }}>
</div>
