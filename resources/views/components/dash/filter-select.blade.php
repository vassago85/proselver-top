{{--
    Standard dashboard filter select. Pass wire:model via attributes.

        <x-dash.filter-select label="Transporter" wire:model.live="transporterId">
            <option value="">All</option>
            @foreach($transporterOptions as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </x-dash.filter-select>
--}}
@props(['label' => null, 'minWidth' => '180px'])

<div class="flex-1" style="min-width: {{ $minWidth }};">
    @if($label)
        <label class="block text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 mb-1.5">{{ $label }}</label>
    @endif
    <select {{ $attributes->class('w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500') }}>
        {{ $slot }}
    </select>
</div>
