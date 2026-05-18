{{--
    Inline waypoint insertion form. Rendered either at the end of the
    stop list (insertAfterSequence === null) or directly below a given
    stop. The owning Volt component holds the state on
    $waypointType / $waypointLocationId / $waypointNotes.
--}}
<div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-600">
        Add {{ $waypointTypes[$waypointType]['label'] ?? 'stop' }}
        @if($insertAfterSequence)
            after stop #{{ $insertAfterSequence }}
        @else
            at the end of the trip
        @endif
    </p>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Location (optional)</label>
            <div class="mt-1">
                <x-searchable-select
                    wire:model="waypointLocationId"
                    :options="$locationOptions"
                    placeholder="— pick a location —"
                    search-placeholder="Search…"
                />
            </div>
        </div>
        <div>
            <label class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Notes (optional)</label>
            <input type="text" wire:model="waypointNotes"
                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                placeholder="e.g. weighbridge booking 09:30"/>
        </div>
    </div>

    <div class="mt-3 flex items-center gap-2">
        <button wire:click="saveWaypoint"
            class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
            Add stop
        </button>
        <button wire:click="cancelWaypoint" type="button"
            class="text-xs font-medium text-slate-500 hover:text-slate-800">Cancel</button>
    </div>
</div>
