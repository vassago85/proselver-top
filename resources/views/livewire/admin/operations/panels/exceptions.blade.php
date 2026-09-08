{{--
    Exception rail. One row per bucket, severity dot, count on the right,
    entire row linked to /admin/orders?exception=<key>.

    Buckets that report zero are dimmed but stay visible so the operator
    can see which controls are firing today and which aren't. Hiding
    them would mask a data-hygiene problem (e.g. status_entered_at
    stopped updating on some code path).
--}}
<div wire:poll.60s
     class="ow-card overflow-hidden">
    <header class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <div>
            <p class="text-[13px] font-semibold text-slate-900">Exceptions</p>
            <p class="mt-0.5 text-[10.5px] text-slate-500">Jobs past their operational threshold · click a row to work the queue.</p>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] text-slate-500">
            <span class="ow-live-dot" aria-hidden="true"></span>
            <span class="font-semibold tabular-nums">Live · updated {{ $updatedAt->format('H:i:s') }}</span>
        </div>
    </header>

    <ul class="divide-y divide-slate-100">
        @foreach($buckets as $b)
            @php
                $dot = match($b['severity']) {
                    'critical' => 'bg-rose-500',
                    'warning'  => 'bg-amber-500',
                    default    => 'bg-slate-300',
                };
                $muted = $b['count'] === 0;
            @endphp
            <li>
                <a href="{{ route('admin.orders.index', ['exception' => $b['key']]) }}"
                   class="grid grid-cols-[8px_1fr_auto] items-center gap-3 px-4 py-2.5 hover:bg-slate-50">
                    <span class="h-2 w-2 rounded-full {{ $dot }} {{ $muted ? 'opacity-40' : '' }}"></span>
                    <div class="min-w-0">
                        <p class="text-[13px] font-semibold {{ $muted ? 'text-slate-400' : 'text-slate-900' }}">{{ $b['label'] }}</p>
                        <p class="text-[10.5px] text-slate-500">{{ $b['sublabel'] }}</p>
                    </div>
                    <span class="tabular-nums text-[18px] font-bold {{ $muted ? 'text-slate-300' : 'text-slate-900' }}">
                        {{ number_format($b['count']) }}
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
