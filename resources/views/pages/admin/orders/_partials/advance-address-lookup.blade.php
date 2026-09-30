{{--
    Inline Google Maps address picker for the Petty Cash / Advance modal.

    Lives inside the amber "missing_coords" block on the admin order
    detail page (resources/views/pages/admin/orders/show.blade.php).
    Renders once per side (pickup / delivery) that is missing lat/lng.

    Required $variables (all passed in from the include call):
      $which          -- 'pickup' | 'delivery'         (semantic tag)
      $label          -- string. Section heading text
      $input          -- string. Livewire property name to model
      $suggestions    -- array of geocoding suggestions
      $lookupAttempts -- int. How many lookups have been fired for this
                        side already (drives the "no matches" hint copy)
      $pickAction     -- string. Livewire method name that commits a pick
                        (pickPickupAddressSuggestion / pickDeliveryAddressSuggestion)

    Wiring notes:
      - The <input> uses wire:model.live.debounce.600ms so ops just
        types and the server-side geocode fires automatically. There is
        NO "Look up" button on purpose -- button-hunt is friction we do
        not want on the "correct data entry" path.
      - When suggestions is empty AND at least one lookup has fired, we
        show a soft "no matches, try adding city / suburb" hint. This
        is the moment that also unlocks the override reveal in the
        parent template.
--}}
<div class="rounded-md border border-amber-200 bg-white/60 px-3 py-2 space-y-2">
    <div class="flex items-center justify-between gap-2">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-900">{{ $label }}</p>
        <span wire:loading wire:target="{{ $input }},lookup{{ ucfirst($which) }}Address"
              class="text-[10px] italic text-amber-700">Searching Google&hellip;</span>
    </div>

    <input type="text"
           wire:model.live.debounce.600ms="{{ $input }}"
           placeholder="Street, suburb, city — e.g. 12 Sample Rd, Randburg"
           class="w-full rounded border border-amber-300 bg-white px-2.5 py-1.5 text-xs focus:border-amber-500 focus:ring-amber-500 focus:ring-1">

    @if(!empty($suggestions))
        <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800/70">Pick the correct match:</p>
        <div class="space-y-1">
            @foreach($suggestions as $idx => $sugg)
                <button type="button"
                        wire:click="{{ $pickAction }}({{ $idx }})"
                        wire:loading.attr="disabled"
                        wire:target="{{ $pickAction }}"
                        class="group flex w-full items-start gap-2 rounded border border-slate-200 bg-white px-2.5 py-1.5 text-left text-xs hover:border-emerald-400 hover:bg-emerald-50/60 disabled:opacity-50">
                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400 group-hover:text-emerald-600"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                         stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-slate-900">{{ $sugg['formatted_address'] ?? 'Unnamed' }}</span>
                        @if(!empty($sugg['city']) || !empty($sugg['province']))
                            <span class="block text-[10px] text-slate-500">
                                {{ trim(($sugg['city'] ?? '') . ($sugg['city'] && $sugg['province'] ? ' · ' : '') . ($sugg['province'] ?? '')) }}
                                @if(isset($sugg['lat'], $sugg['lng']))
                                    · <span class="font-mono">{{ number_format((float) $sugg['lat'], 4) }}, {{ number_format((float) $sugg['lng'], 4) }}</span>
                                @endif
                            </span>
                        @endif
                    </span>
                    <span class="shrink-0 self-center text-[10px] font-semibold uppercase tracking-wide text-emerald-600 opacity-0 group-hover:opacity-100">Use this</span>
                </button>
            @endforeach
        </div>
    @elseif($lookupAttempts > 0)
        {{-- Google returned zero matches -- soft nudge to refine the
             input.  Also the trigger that unlocks the override reveal
             in the parent template ($hasZeroResults). --}}
        <p class="text-[11px] text-amber-800/80">
            No Google matches. Try adding the city or suburb, or check for typos.
        </p>
    @endif
</div>
