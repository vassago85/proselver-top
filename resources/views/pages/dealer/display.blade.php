<?php
/**
 * Dealer "Live Movements" board.
 *
 * Designed for a TV / large monitor in the dealer's dispatch office.
 * Renders with the chromeless `display` layout (no sidebar, no top
 * bar), dark theme, auto-refreshes every 30 seconds.
 *
 * Three lanes, ordered left-to-right the same way a movement actually
 * progresses through the day:
 *
 *   1. WAITING — booked / confirmed / planned but not yet collected
 *   2. IN TRANSIT — driver has collected, vehicle in motion
 *   3. DELIVERED TODAY — finished today only; older deliveries fall off
 *      automatically so the lane doesn't grow forever
 *
 * Cancelled, rejected and archived rows are excluded (the wall display
 * is for live operations, not the audit trail).
 *
 * Permission gate: `view_all_bookings`. A CSO who only sees their own
 * bookings would get a misleading board; restrict to dealer admin /
 * dispatcher / principal. Sidebar link is gated the same way.
 */

use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.display')] class extends Component {
    public function mount(): void
    {
        if (!auth()->user()->hasPermission('view_all_bookings')) {
            abort(403, 'The live display is for dealer admins and dispatchers.');
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $company = $user->company();
        if (!$company) {
            return [
                'company' => null,
                'waiting' => collect(),
                'inTransit' => collect(),
                'deliveredToday' => collect(),
            ];
        }

        // Lane definitions — kept in one place so the column header
        // counts, the SQL filters and the empty-state copy all stay
        // consistent. If you add a new Job::STATUS_*, drop it into the
        // appropriate bucket here.
        $waitingStatuses = [
            Job::STATUS_PENDING_VERIFICATION,
            Job::STATUS_RECEIVED,
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            Job::STATUS_CONFIRMATION_ISSUE,
            Job::STATUS_CONFIRMED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            // Legacy bridge: very old bookings still on the pre-Phase-1
            // workflow sit at APPROVED / ASSIGNED until ops touches them.
            Job::STATUS_APPROVED,
            Job::STATUS_ASSIGNED,
        ];
        $inTransitStatuses = [
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_IN_PROGRESS,
        ];
        $deliveredStatuses = [
            Job::STATUS_DELIVERED,
            Job::STATUS_COMPLETED,
        ];

        $relations = [
            'pickupLocation:id,company_name,city',
            'deliveryLocation:id,company_name,city',
            'driver:id,name',
            'brand:id,name',
        ];

        $base = Job::where('company_id', $company->id)
            ->whereNull('archived_at')
            ->with($relations);

        $waiting = (clone $base)
            ->whereIn('status', $waitingStatuses)
            ->orderByRaw('scheduled_date IS NULL, scheduled_date ASC')
            ->orderBy('scheduled_ready_time')
            ->limit(40)
            ->get();

        $inTransit = (clone $base)
            ->whereIn('status', $inTransitStatuses)
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        // Delivered TODAY only — use the most recent of delivered_at /
        // completed_at / updated_at to catch jobs that closed today
        // regardless of which timestamp the workflow set. Falling back
        // to updated_at keeps legacy rows visible if delivered_at isn't
        // populated yet.
        $today = today();
        $deliveredToday = (clone $base)
            ->whereIn('status', $deliveredStatuses)
            ->where(function ($q) use ($today) {
                $q->whereDate('updated_at', $today)
                  ->orWhereDate('scheduled_date', $today);
            })
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return [
            'company' => $company,
            'waiting' => $waiting,
            'inTransit' => $inTransit,
            'deliveredToday' => $deliveredToday,
        ];
    }
};
?>

<div class="min-h-screen flex flex-col"
     wire:poll.30s
     x-data="{
        clock: '',
        date: '',
        fs: false,
        tick() {
            const now = new Date();
            this.clock = now.toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
            this.date  = now.toLocaleDateString('en-ZA', { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });
        },
        toggleFs() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen?.();
                this.fs = true;
            } else {
                document.exitFullscreen?.();
                this.fs = false;
            }
        }
     }"
     x-init="tick(); setInterval(() => tick(), 1000);">

    {{-- ============ HEADER ============ --}}
    <header class="flex items-center gap-4 border-b border-slate-800/80 bg-slate-900/60 px-6 py-4 backdrop-blur">
        <img src="/favicon-192.png?v=3" alt="" class="h-10 w-10 shrink-0">
        <div class="min-w-0">
            <div class="flex items-baseline gap-3">
                <span class="text-2xl font-bold tracking-tight text-white">TRIDENT</span>
                <span class="text-sm font-semibold uppercase tracking-[0.25em] text-cyan-400">Live Movements</span>
            </div>
            <div class="truncate text-sm text-slate-400">{{ $company?->name ?? '—' }}</div>
        </div>

        <div class="ml-auto flex items-center gap-6">
            <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400">
                <span class="live-dot inline-block h-2 w-2 rounded-full bg-emerald-400"></span>
                <span>Auto-refresh · 30 s</span>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold tabular-nums text-white" x-text="clock">—</div>
                <div class="text-xs uppercase tracking-wider text-slate-400" x-text="date">—</div>
            </div>
            <button type="button"
                    @click="toggleFs()"
                    class="rounded-lg border border-slate-700 bg-slate-900/40 p-2 text-slate-400 hover:text-white hover:border-slate-500"
                    title="Toggle fullscreen">
                <svg x-show="!fs" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7V3h4"/><path d="M21 7V3h-4"/><path d="M3 17v4h4"/><path d="M21 17v4h-4"/>
                </svg>
                <svg x-show="fs" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 3v4H4"/><path d="M16 3v4h4"/><path d="M8 21v-4H4"/><path d="M16 21v-4h4"/>
                </svg>
            </button>
            <a href="{{ route('dealer.dashboard') }}"
               class="rounded-lg border border-slate-700 bg-slate-900/40 px-3 py-2 text-xs font-semibold text-slate-300 hover:text-white hover:border-slate-500">
                Exit board
            </a>
        </div>
    </header>

    {{-- ============ LANES ============ --}}
    @php
        // Map each Phase-1 / legacy status to a high-contrast lane colour.
        // Bright accents work better than the muted in-app badges at TV
        // viewing distance — read across a 5m room.
        $statusStyle = [
            Job::STATUS_PENDING_VERIFICATION => ['ring-amber-500/40 bg-amber-500/10', 'text-amber-300', 'Pending'],
            Job::STATUS_RECEIVED             => ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', 'Received'],
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION => ['ring-amber-500/40 bg-amber-500/10', 'text-amber-300', 'Awaiting Confirmation'],
            Job::STATUS_CONFIRMATION_ISSUE   => ['ring-red-500/40 bg-red-500/10',       'text-red-300',   'Confirmation Issue'],
            Job::STATUS_CONFIRMED            => ['ring-cyan-500/40 bg-cyan-500/10',     'text-cyan-300',  'Confirmed'],
            Job::STATUS_PLANNED              => ['ring-indigo-500/40 bg-indigo-500/10', 'text-indigo-300','Planned'],
            Job::STATUS_DRIVER_ASSIGNED      => ['ring-purple-500/40 bg-purple-500/10', 'text-purple-300','Driver Assigned'],
            Job::STATUS_READY_FOR_COLLECTION => ['ring-cyan-500/40 bg-cyan-500/10',     'text-cyan-300',  'Ready for Collection'],
            Job::STATUS_APPROVED             => ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', 'Approved'],
            Job::STATUS_ASSIGNED             => ['ring-purple-500/40 bg-purple-500/10', 'text-purple-300','Assigned'],
            Job::STATUS_COLLECTED            => ['ring-teal-500/40 bg-teal-500/10',     'text-teal-300',  'Collected'],
            Job::STATUS_IN_TRANSIT           => ['ring-orange-500/40 bg-orange-500/10', 'text-orange-300','In Transit'],
            Job::STATUS_IN_PROGRESS          => ['ring-orange-500/40 bg-orange-500/10', 'text-orange-300','In Progress'],
            Job::STATUS_DELIVERED            => ['ring-emerald-500/40 bg-emerald-500/10','text-emerald-300','Delivered'],
            Job::STATUS_COMPLETED            => ['ring-emerald-500/40 bg-emerald-500/10','text-emerald-300','Completed'],
        ];
    @endphp

    <main class="grid flex-1 grid-cols-1 gap-4 p-4 md:grid-cols-3 lg:gap-6 lg:p-6">

        {{-- WAITING --}}
        <section class="flex min-h-0 flex-col rounded-2xl border border-amber-500/20 bg-slate-900/40">
            <div class="flex items-center justify-between border-b border-amber-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Waiting</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $waiting->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto p-3 space-y-2">
                @forelse($waiting as $job)
                    @php $style = $statusStyle[$job->status] ?? ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', ucfirst($job->status)]; @endphp
                    <div class="rounded-xl ring-1 {{ $style[0] }} p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-base font-bold text-white truncate">{{ $job->job_number ?? '—' }}</div>
                                <div class="text-xs text-slate-300 truncate">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: 'Vehicle TBD' }}</div>
                            </div>
                            <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $style[1] }}">{{ $style[2] }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-400">
                            <div class="truncate"><span class="text-slate-500">From</span> {{ $job->pickupLocation?->company_name ?? '—' }}</div>
                            <div class="truncate"><span class="text-slate-500">To</span> {{ $job->deliveryLocation?->company_name ?? '—' }}</div>
                            <div class="truncate"><span class="text-slate-500">Driver</span> {{ $job->driver?->name ?? '— unassigned' }}</div>
                            <div class="truncate text-right tabular-nums">
                                @if($job->scheduled_date)
                                    {{ $job->scheduled_date->format('d M') }}@if($job->scheduled_ready_time) · {{ $job->scheduled_ready_time->format('H:i') }}@endif
                                @else
                                    <span class="text-slate-600">no date</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                        Nothing waiting. Everything is moving.
                    </div>
                @endforelse
            </div>
        </section>

        {{-- IN TRANSIT --}}
        <section class="flex min-h-0 flex-col rounded-2xl border border-orange-500/20 bg-slate-900/40">
            <div class="flex items-center justify-between border-b border-orange-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-orange-400 live-dot"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-orange-300">In Transit</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $inTransit->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto p-3 space-y-2">
                @forelse($inTransit as $job)
                    @php $style = $statusStyle[$job->status] ?? ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', ucfirst($job->status)]; @endphp
                    <div class="rounded-xl ring-1 {{ $style[0] }} p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-base font-bold text-white truncate">{{ $job->job_number ?? '—' }}</div>
                                <div class="text-xs text-slate-300 truncate">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: 'Vehicle TBD' }}</div>
                            </div>
                            <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $style[1] }}">{{ $style[2] }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-400">
                            <div class="col-span-2 truncate">
                                <span class="text-slate-500">{{ $job->pickupLocation?->company_name ?? '—' }}</span>
                                <span class="text-orange-400 mx-1">→</span>
                                <span class="text-white">{{ $job->deliveryLocation?->company_name ?? '—' }}</span>
                            </div>
                            <div class="truncate"><span class="text-slate-500">Driver</span> {{ $job->driver?->name ?? '—' }}</div>
                            <div class="truncate text-right tabular-nums">
                                <span class="text-slate-500">Updated</span> {{ $job->updated_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) }} ago
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                        No movements on the road right now.
                    </div>
                @endforelse
            </div>
        </section>

        {{-- DELIVERED TODAY --}}
        <section class="flex min-h-0 flex-col rounded-2xl border border-emerald-500/20 bg-slate-900/40">
            <div class="flex items-center justify-between border-b border-emerald-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Delivered Today</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $deliveredToday->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto p-3 space-y-2">
                @forelse($deliveredToday as $job)
                    @php $style = $statusStyle[$job->status] ?? ['ring-emerald-500/40 bg-emerald-500/10', 'text-emerald-300', ucfirst($job->status)]; @endphp
                    <div class="rounded-xl ring-1 {{ $style[0] }} p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-base font-bold text-white truncate">{{ $job->job_number ?? '—' }}</div>
                                <div class="text-xs text-slate-300 truncate">{{ trim(($job->brand?->name ?? '') . ' ' . ($job->model_name ?? '')) ?: 'Vehicle TBD' }}</div>
                            </div>
                            <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $style[1] }}">{{ $style[2] }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-400">
                            <div class="truncate"><span class="text-slate-500">To</span> {{ $job->deliveryLocation?->company_name ?? '—' }}</div>
                            <div class="truncate text-right"><span class="text-slate-500">By</span> {{ $job->driver?->name ?? '—' }}</div>
                            <div class="col-span-2 text-right tabular-nums text-emerald-300/80">
                                {{ $job->updated_at?->format('H:i') }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                        No completed deliveries yet today.
                    </div>
                @endforelse
            </div>
        </section>

    </main>

    {{-- ============ FOOTER ============ --}}
    <footer class="flex items-center justify-between border-t border-slate-800/80 bg-slate-900/40 px-6 py-2 text-[11px] text-slate-500">
        <span>Page refreshes every 30 seconds · Tap fullscreen for kiosk mode</span>
        <span class="tabular-nums">Last loaded {{ now()->format('H:i:s') }}</span>
    </footer>
</div>
