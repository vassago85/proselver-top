{{--
    Live pipeline row: four state tiles + one At Risk hero on the right.
    Ignores the date filter. Polls every 30s.

    Every tile is a link to /admin/orders?<filter>=… so a count is a
    control, never a decoration.
--}}
<div wire:poll.30s
     class="grid gap-3 lg:grid-cols-[repeat(4,minmax(0,1fr))_1.2fr]">
    @php
        $tiles = [
            ['label' => 'Intake',              'sublabel' => 'awaiting review or confirmation', 'count' => $pipeline['intake'],     'href' => route('admin.orders.index', ['statusFilter' => 'received'])],
            ['label' => 'Ready to dispatch',   'sublabel' => 'confirmed · no driver',            'count' => $pipeline['ready'],      'href' => route('admin.orders.index', ['statusFilter' => 'confirmed'])],
            ['label' => 'Dispatched',          'sublabel' => 'assigned · not collected',         'count' => $pipeline['dispatched'], 'href' => route('admin.orders.index', ['statusFilter' => 'driver_assigned'])],
            ['label' => 'On the road',         'sublabel' => 'collected · in transit',           'count' => $pipeline['on_road'],    'href' => route('admin.orders.index', ['statusFilter' => 'in_transit'])],
        ];
    @endphp

    @foreach($tiles as $t)
        <a href="{{ $t['href'] }}"
           class="ow-card block p-4 transition-colors">
            <p class="ow-label">{{ $t['label'] }}</p>
            <p class="ow-hero mt-2">{{ number_format($t['count']) }}</p>
            <p class="mt-1 text-[11px] text-slate-500">{{ $t['sublabel'] }}</p>
        </a>
    @endforeach

    {{-- At risk hero. Rose when critical, amber when warning, neutral at 0. --}}
    @php
        $atRiskBg = match($atRiskSeverity) {
            'critical' => 'border-rose-200 bg-rose-50/60',
            'warning'  => 'border-amber-200 bg-amber-50/60',
            default    => 'border-slate-200 bg-white',
        };
        $atRiskAccent = match($atRiskSeverity) {
            'critical' => 'text-rose-600',
            'warning'  => 'text-amber-700',
            default    => 'text-slate-700',
        };
    @endphp
    <a href="{{ route('admin.orders.index', ['exception' => 'dispatched_not_collected']) }}"
       class="ow-card relative flex flex-col justify-between p-4 {{ $atRiskBg }} {{ $exceptions['at_risk'] > 0 ? 'ow-alert-pulse' : '' }}">
        <div class="flex items-center gap-2">
            <svg class="h-4 w-4 {{ $atRiskAccent }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <p class="ow-label {{ $atRiskAccent }}">At risk</p>
        </div>
        <div>
            <p class="ow-hero {{ $atRiskAccent }}">{{ number_format($exceptions['at_risk']) }}</p>
            <p class="mt-1 text-[11px] text-slate-500">distinct union of the six buckets</p>
        </div>
    </a>
</div>
