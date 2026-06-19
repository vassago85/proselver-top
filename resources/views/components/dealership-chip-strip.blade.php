@props([
    /*
     * Compact chip strip for franchise CEOs whose visibleCompanyIds
     * spans multiple sibling dealerships.  The active chip is the
     * currently-selected value; '' (empty string) means "all".  The
     * caller binds wireModel to the property holding the selected ID.
     *
     * Usage:
     *   <x-dealership-chip-strip
     *       :companies="$visibleCompanies"
     *       wire-model="dealershipFilter"
     *   />
     */
    'companies' => null,
    'wireModel' => 'dealershipFilter',
    'label' => 'Dealership',
])

@if($companies && $companies->count() > 1)
    <div class="mb-4">
        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500 mb-1.5">{{ $label }}</p>
        <div class="flex flex-wrap gap-1.5">
            <button type="button"
                    wire:click="$set('{{ $wireModel }}', '')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-slate-900 border-slate-900 text-white' => $attributes->get('selected') === '' || $attributes->get('selected') === null,
                        'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' => !($attributes->get('selected') === '' || $attributes->get('selected') === null),
                    ])>
                All dealerships
                <span class="rounded-full bg-white/20 px-1.5 text-[10px] font-bold tabular-nums">{{ $companies->count() }}</span>
            </button>
            @foreach($companies as $c)
                @php $isActive = (string) $attributes->get('selected') === (string) $c->id; @endphp
                <button type="button"
                        wire:click="$set('{{ $wireModel }}', '{{ $c->id }}')"
                        @class([
                            'inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 border-blue-600 text-white' => $isActive,
                            'bg-white border-slate-300 text-slate-700 hover:bg-slate-50' => !$isActive,
                        ])>
                    {{ $c->name }}
                </button>
            @endforeach
        </div>
    </div>
@endif
