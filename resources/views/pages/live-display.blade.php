<?php
/**
 * "Live Movements" wallboard — single shared component across every
 * portal that wants a TV view of what's moving right now.
 *
 * Audiences:
 *   - Customer-tier tenants (dealer-customers, OEM-customers, body
 *     builders) via /customer/display — scoped by $user->company()->id.
 *   - Internal ops controllers, owners, super admins and developers
 *     via /admin/live-display — runs system-wide with the owning
 *     customer name on each card.
 *
 * Designed for a TV / wall monitor: no mouse, no touch, no chrome.
 *   - Top summary strip with the headline numbers (waiting / in
 *     transit / delivered / unassigned / delayed / last updated)
 *     stays in view regardless of which column is in focus.
 *   - Three lanes (Waiting / In Transit / Delivered Today) take
 *     turns being the "focused" column on a 25s rotation; the
 *     focused column subtly brightens + scales and is the only one
 *     that auto-scrolls when its content overflows the viewport.
 *   - Cards visually distinguish unassigned drivers, stale rows
 *     (no update in >30 min on Waiting / In Transit) and overdue
 *     movements (scheduled date in the past).  Highlights are a
 *     calm glow / pulse — never a flash.
 *   - Newly-arrived and freshly-updated cards animate in on poll
 *     so an ops controller across the room can see when something
 *     changes without having to scan every card.
 *   - `prefers-reduced-motion` disables the rotation, the auto-
 *     scroll and the card entry / pulse animations.
 *
 * Backend data shape is unchanged: same three Eloquent collections,
 * same relations, same lane caps as before.  Everything new lives
 * in computed derivations off those collections and the rendering /
 * Alpine layer below.
 *
 * Permission gate: `view_all_bookings`.  Internal users always pass
 * (tier=internal implies the perm).  Renders with the chromeless
 * `display` layout (no sidebar, no top bar), dark theme, auto-
 * refreshes every 30 seconds.
 */

use App\Models\Job;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.display')] class extends Component {
    public function mount(): void
    {
        $user = auth()->user();
        if (!$user->isInternal() && !$user->hasPermission('view_all_bookings')) {
            abort(403, 'The live display needs the "View all bookings" permission.');
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        // Internal users (ops / owner / super admin / developer) get a
        // system-wide board — they're not pinned to a single tenant, so
        // filtering by $user->company() would just show the internal
        // ProSelver "company" row which is empty.
        //
        // Tenant users (dealer / OEM / customer / body-builder) get the
        // single-company board they've always had.
        $isInternal = $user->isInternal();
        $company = $isInternal ? null : $user->company();

        if (!$isInternal && !$company) {
            return [
                'company'        => null,
                'isInternal'     => false,
                'waiting'        => collect(),
                'inTransit'      => collect(),
                'deliveredToday' => collect(),
                'unassignedCount'=> 0,
                'delayedCount'   => 0,
                'lastUpdatedAt'  => now(),
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
        // Internal users also pull the owning customer onto each card so
        // an ops controller looking at the system-wide board can tell at
        // a glance which dealer/OEM the row belongs to.
        if ($isInternal) {
            $relations[] = 'company:id,name';
        }

        $base = Job::query()
            ->whereNull('archived_at')
            ->with($relations);

        if (!$isInternal) {
            $base->where('company_id', $company->id);
        }

        // Lane caps are bigger for the system-wide ops view because a
        // single dealer board rarely needs more than 30-40 cards but
        // ProSelver-wide traffic easily exceeds that.  Still bounded —
        // the lane is supposed to scroll, not stream forever.
        $lanePerCap = $isInternal ? 80 : 40;

        $waiting = (clone $base)
            ->whereIn('status', $waitingStatuses)
            ->orderByRaw('scheduled_date IS NULL, scheduled_date ASC')
            ->orderBy('scheduled_ready_time')
            ->limit($lanePerCap)
            ->get();

        $inTransit = (clone $base)
            ->whereIn('status', $inTransitStatuses)
            ->orderByDesc('updated_at')
            ->limit($lanePerCap)
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
            ->limit($isInternal ? 60 : 30)
            ->get();

        // -------- derived headline metrics for the summary strip ----
        // Unassigned = waiting rows with no driver.  Delivered rows
        // by definition had a driver (somebody completed them), so
        // we only count Waiting here.  In-Transit rows also need a
        // driver to be in transit so they're always assigned.
        $unassignedCount = $waiting->whereNull('driver_user_id')->count();

        // Delayed = the scheduled ready time is in the past and the
        // movement still hasn't finished moving.  Falls back to
        // start-of-day for the date when scheduled_ready_time is
        // missing.  Only counts Waiting + In Transit; Delivered is
        // by definition done.
        $now = now();
        $isOverdue = function (Job $job) use ($now): bool {
            if (!$job->scheduled_date) {
                return false;
            }
            $cutoff = Carbon::parse($job->scheduled_date)->startOfDay();
            if ($job->scheduled_ready_time) {
                $t = Carbon::parse($job->scheduled_ready_time);
                $cutoff = $cutoff->setTime($t->hour, $t->minute);
            } else {
                // No time specified — treat as overdue once the
                // scheduled date itself has fully passed (so a
                // booking dated "today" without a time isn't flagged
                // delayed until midnight).
                $cutoff = $cutoff->endOfDay();
            }
            return $cutoff->lt($now);
        };
        $delayedCount = $waiting->filter($isOverdue)->count()
            + $inTransit->filter($isOverdue)->count();

        return [
            'company'         => $company,
            'isInternal'      => $isInternal,
            'waiting'         => $waiting,
            'inTransit'       => $inTransit,
            'deliveredToday'  => $deliveredToday,
            'unassignedCount' => $unassignedCount,
            'delayedCount'    => $delayedCount,
            'lastUpdatedAt'   => now(),
        ];
    }
};
?>

@php
    // Map each Phase-1 / legacy status to a high-contrast lane colour.
    // Bright accents work better than the muted in-app badges at TV
    // viewing distance — read across a 5m room.
    $statusStyle = [
        Job::STATUS_PENDING_VERIFICATION           => ['ring-amber-500/40 bg-amber-500/10',   'text-amber-300',  'Pending'],
        Job::STATUS_RECEIVED                       => ['ring-slate-500/40 bg-slate-500/10',   'text-slate-300',  'Received'],
        Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION => ['ring-amber-500/40 bg-amber-500/10',   'text-amber-300',  'Awaiting Confirmation'],
        Job::STATUS_CONFIRMATION_ISSUE             => ['ring-red-500/40 bg-red-500/10',       'text-red-300',    'Confirmation Issue'],
        Job::STATUS_CONFIRMED                      => ['ring-cyan-500/40 bg-cyan-500/10',     'text-cyan-300',   'Confirmed'],
        Job::STATUS_PLANNED                        => ['ring-indigo-500/40 bg-indigo-500/10', 'text-indigo-300', 'Planned'],
        Job::STATUS_DRIVER_ASSIGNED                => ['ring-purple-500/40 bg-purple-500/10', 'text-purple-300', 'Driver Assigned'],
        Job::STATUS_READY_FOR_COLLECTION           => ['ring-cyan-500/40 bg-cyan-500/10',     'text-cyan-300',   'Ready for Collection'],
        Job::STATUS_APPROVED                       => ['ring-slate-500/40 bg-slate-500/10',   'text-slate-300',  'Approved'],
        Job::STATUS_ASSIGNED                       => ['ring-purple-500/40 bg-purple-500/10', 'text-purple-300', 'Assigned'],
        Job::STATUS_COLLECTED                      => ['ring-teal-500/40 bg-teal-500/10',     'text-teal-300',   'Collected'],
        Job::STATUS_IN_TRANSIT                     => ['ring-orange-500/40 bg-orange-500/10', 'text-orange-300', 'In Transit'],
        Job::STATUS_IN_PROGRESS                    => ['ring-orange-500/40 bg-orange-500/10', 'text-orange-300', 'In Progress'],
        Job::STATUS_DELIVERED                      => ['ring-emerald-500/40 bg-emerald-500/10','text-emerald-300','Delivered'],
        Job::STATUS_COMPLETED                      => ['ring-emerald-500/40 bg-emerald-500/10','text-emerald-300','Completed'],
    ];

    // Per-lane accent so the route arrow / focus glow can tint to
    // match the column.  Keep in sync with the column header colours
    // and the per-card ring colours above.
    $laneAccent = [
        'waiting'   => ['amber',   'border-amber-500/20',   'text-amber-300',   'bg-amber-400',   'shadow-amber-500/30'],
        'transit'   => ['orange',  'border-orange-500/20',  'text-orange-300',  'bg-orange-400',  'shadow-orange-500/30'],
        'delivered' => ['emerald', 'border-emerald-500/20', 'text-emerald-300', 'bg-emerald-400', 'shadow-emerald-500/30'],
    ];

    // Card exception flags — derived per-job at render time.  Returns
    // an array of class strings the partial concatenates onto the
    // card root, plus a small badge string for the corner pill.  Kept
    // here (not on the Job model) because it's pure presentation.
    $cardFlags = function (Job $job, string $lane) {
        $now = now();
        $flags = ['classes' => [], 'label' => null];

        // Stale = no update in 30 min, only meaningful for in-flight
        // lanes.  Delivered rows are done; an old updated_at on a
        // delivered card just means the row was closed earlier today.
        $stale = in_array($lane, ['waiting', 'transit'], true)
            && $job->updated_at
            && $job->updated_at->lt($now->copy()->subMinutes(30));

        // Overdue = scheduled to be ready before now and still moving.
        $overdue = false;
        if (in_array($lane, ['waiting', 'transit'], true) && $job->scheduled_date) {
            $cutoff = Carbon::parse($job->scheduled_date)->startOfDay();
            if ($job->scheduled_ready_time) {
                $t = Carbon::parse($job->scheduled_ready_time);
                $cutoff = $cutoff->setTime($t->hour, $t->minute);
            } else {
                $cutoff = $cutoff->endOfDay();
            }
            $overdue = $cutoff->lt($now);
        }

        // Unassigned = waiting lane only.  The Job model's driver
        // FK is `driver_user_id` (it points at users.id, not a
        // separate drivers table) -- using `driver_id` here was the
        // bug that lit every assigned waiting card as "NO DRIVER".
        $unassigned = $lane === 'waiting' && empty($job->driver_user_id);

        // Pick the most severe flag for the badge / glow (overdue
        // outranks unassigned outranks stale).  Multiple flags can
        // still stack on $classes for tooltips / future use.
        if ($overdue) {
            $flags['classes'][] = 'card-alert card-alert-danger';
            $flags['label'] = 'OVERDUE';
        } elseif ($unassigned) {
            $flags['classes'][] = 'card-alert card-alert-warn';
            $flags['label'] = 'NO DRIVER';
        } elseif ($stale) {
            $flags['classes'][] = 'card-alert card-alert-mute';
            $flags['label'] = 'STALE';
        }
        return $flags;
    };
@endphp

<div class="h-screen overflow-hidden flex flex-col"
     wire:poll.30s
     x-data="wallboard()"
     x-init="boot()">

    {{-- ============ HEADER ============ --}}
    <header class="flex items-center gap-4 border-b border-slate-800/80 bg-slate-900/60 px-6 py-3 backdrop-blur">
        <img src="/favicon-192.png?v=3" alt="" class="h-10 w-10 shrink-0">
        <div class="min-w-0">
            <div class="flex items-baseline gap-3">
                <span class="text-2xl font-bold tracking-tight text-white">TRIDENT</span>
                <span class="text-sm font-semibold uppercase tracking-[0.25em] text-cyan-400">Live Movements</span>
                @if($isInternal)
                    <span class="rounded-md border border-cyan-500/40 bg-cyan-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-cyan-300">All customers</span>
                @endif
            </div>
            <div class="truncate text-sm text-slate-400">
                {{ $isInternal ? 'System-wide ops view' : ($company?->name ?? '—') }}
            </div>
        </div>

        <div class="ml-auto flex items-center gap-4">
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
            <a href="{{ resolveUserHomePath(auth()->user()) }}"
               class="rounded-lg border border-slate-700 bg-slate-900/40 px-3 py-2 text-xs font-semibold text-slate-300 hover:text-white hover:border-slate-500">
                Exit board
            </a>
        </div>
    </header>

    {{-- ============ SUMMARY STRIP ============ --}}
    {{-- Always-visible tiles so an ops controller can read the
         current headline numbers without waiting for the focus
         rotation to land on a particular column. --}}
    <div class="grid grid-cols-3 gap-2 border-b border-slate-800/80 bg-slate-950/60 px-4 py-3 sm:grid-cols-6">
        <div class="rounded-lg border border-amber-500/25 bg-amber-500/5 px-3 py-2">
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-amber-300/80">Waiting</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $waiting->count() }}</div>
        </div>
        <div class="rounded-lg border border-orange-500/25 bg-orange-500/5 px-3 py-2">
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-300/80">In Transit</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $inTransit->count() }}</div>
        </div>
        <div class="rounded-lg border border-emerald-500/25 bg-emerald-500/5 px-3 py-2">
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-emerald-300/80">Delivered Today</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $deliveredToday->count() }}</div>
        </div>
        <div @class([
                'rounded-lg border px-3 py-2',
                'border-rose-500/40 bg-rose-500/10' => $unassignedCount > 0,
                'border-slate-700/60 bg-slate-800/40' => $unassignedCount === 0,
        ])>
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] {{ $unassignedCount > 0 ? 'text-rose-300' : 'text-slate-400' }}">Unassigned</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $unassignedCount }}</div>
        </div>
        <div @class([
                'rounded-lg border px-3 py-2',
                'border-red-500/40 bg-red-500/10' => $delayedCount > 0,
                'border-slate-700/60 bg-slate-800/40' => $delayedCount === 0,
        ])>
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] {{ $delayedCount > 0 ? 'text-red-300' : 'text-slate-400' }}">Delayed</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $delayedCount }}</div>
        </div>
        <div class="rounded-lg border border-slate-700/60 bg-slate-800/40 px-3 py-2">
            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Last Updated</div>
            <div class="text-2xl font-bold tabular-nums text-white">{{ $lastUpdatedAt->format('H:i:s') }}</div>
        </div>
    </div>

    {{-- ============ LANES ============ --}}
    <main class="grid flex-1 min-h-0 grid-cols-1 gap-4 p-4 md:grid-cols-3 lg:gap-6 lg:p-6">

        {{-- WAITING --}}
        <section x-ref="lane0"
                 :class="{ 'lane-focused': focusedIdx === 0, 'lane-dim': focusedIdx !== 0 }"
                 class="lane flex min-h-0 flex-col rounded-2xl border border-amber-500/20 bg-slate-900/40 transition-[transform,opacity,box-shadow] duration-700 ease-out shadow-[0_0_0_0_rgba(0,0,0,0)]">
            <div class="flex items-center justify-between border-b border-amber-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-400 transition-transform duration-700" :class="focusedIdx === 0 ? 'scale-125' : 'scale-100'"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Waiting</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $waiting->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto" x-ref="content0">
                <div class="lane-list space-y-2 p-3">
                    @forelse($waiting as $job)
                        @include('pages._live-display-card', [
                            'job'         => $job,
                            'style'       => $statusStyle[$job->status] ?? ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', ucfirst($job->status)],
                            'flags'       => $cardFlags($job, 'waiting'),
                            'isInternal'  => $isInternal,
                            'lane'        => 'waiting',
                            'accentText'  => 'text-amber-400',
                        ])
                    @empty
                        <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                            Nothing waiting. Everything is moving.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- IN TRANSIT --}}
        <section x-ref="lane1"
                 :class="{ 'lane-focused': focusedIdx === 1, 'lane-dim': focusedIdx !== 1 }"
                 class="lane flex min-h-0 flex-col rounded-2xl border border-orange-500/20 bg-slate-900/40 transition-[transform,opacity,box-shadow] duration-700 ease-out shadow-[0_0_0_0_rgba(0,0,0,0)]">
            <div class="flex items-center justify-between border-b border-orange-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-orange-400 live-dot"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-orange-300">In Transit</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $inTransit->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto" x-ref="content1">
                <div class="lane-list space-y-2 p-3">
                    @forelse($inTransit as $job)
                        @include('pages._live-display-card', [
                            'job'         => $job,
                            'style'       => $statusStyle[$job->status] ?? ['ring-slate-500/40 bg-slate-500/10', 'text-slate-300', ucfirst($job->status)],
                            'flags'       => $cardFlags($job, 'transit'),
                            'isInternal'  => $isInternal,
                            'lane'        => 'transit',
                            'accentText'  => 'text-orange-400',
                        ])
                    @empty
                        <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                            No movements on the road right now.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- DELIVERED TODAY --}}
        <section x-ref="lane2"
                 :class="{ 'lane-focused': focusedIdx === 2, 'lane-dim': focusedIdx !== 2 }"
                 class="lane flex min-h-0 flex-col rounded-2xl border border-emerald-500/20 bg-slate-900/40 transition-[transform,opacity,box-shadow] duration-700 ease-out shadow-[0_0_0_0_rgba(0,0,0,0)]">
            <div class="flex items-center justify-between border-b border-emerald-500/20 px-5 py-3">
                <div class="flex items-center gap-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-300">Delivered Today</h2>
                </div>
                <span class="text-2xl font-bold tabular-nums text-white">{{ $deliveredToday->count() }}</span>
            </div>
            <div class="no-scrollbar flex-1 overflow-y-auto" x-ref="content2">
                <div class="lane-list space-y-2 p-3">
                    @forelse($deliveredToday as $job)
                        @include('pages._live-display-card', [
                            'job'         => $job,
                            'style'       => $statusStyle[$job->status] ?? ['ring-emerald-500/40 bg-emerald-500/10', 'text-emerald-300', ucfirst($job->status)],
                            'flags'       => ['classes' => [], 'label' => null],
                            'isInternal'  => $isInternal,
                            'lane'        => 'delivered',
                            'accentText'  => 'text-emerald-400',
                        ])
                    @empty
                        <div class="flex h-full items-center justify-center px-4 py-10 text-center text-sm text-slate-500">
                            No completed deliveries yet today.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

    </main>

    {{-- ============ FOOTER ============ --}}
    <footer class="flex items-center justify-between border-t border-slate-800/80 bg-slate-900/40 px-6 py-2 text-[11px] text-slate-500">
        <span>
            Wallboard mode — focus rotates every 25 s · auto-refresh 30 s
            <span class="ml-2 opacity-50">·</span>
            <span class="ml-2">Focused lane: <span x-text="['Waiting','In Transit','Delivered Today'][focusedIdx]" class="font-semibold text-slate-300">—</span></span>
        </span>
        <span class="tabular-nums">Last loaded {{ $lastUpdatedAt->format('H:i:s') }}</span>
    </footer>
</div>

{{-- =================================================================
     Wallboard Alpine component + styles
     ================================================================= --}}
<style>
    /* Focused / dimmed lane treatment.  Use opacity + a subtle scale +
       a glow ring rather than colour changes so we don't fight the
       per-status ring tints inside the lane. */
    .lane.lane-focused {
        transform: scale(1.015);
        opacity: 1;
        box-shadow: 0 0 0 1px rgba(148, 163, 184, 0.15), 0 18px 48px -24px rgba(15, 23, 42, 0.7);
        z-index: 1;
    }
    .lane.lane-dim {
        transform: scale(0.985);
        opacity: 0.62;
    }

    /* Lane content fade — used by the auto-scroll state machine when
       resetting from the bottom of the list back to the top.  The
       state machine writes inline opacity which trumps these
       defaults; classes only set up the transition. */
    [x-ref^="content"] {
        transition: opacity 600ms ease-out;
    }

    /* Card alert glow.  Three intensities so the most severe alert
       (overdue) reads loudest from across the room while stale rows
       stay subtle.  Pulse is slow (~2.4s) so it never feels like a
       flash. */
    .card-alert {
        position: relative;
    }
    .card-alert-mute  { animation: alertPulseMute 3.2s ease-in-out infinite; }
    .card-alert-warn  { animation: alertPulseWarn 2.6s ease-in-out infinite; }
    .card-alert-danger{ animation: alertPulseDanger 2.0s ease-in-out infinite; }

    @keyframes alertPulseMute {
        0%, 100% { box-shadow: 0 0 0 0 rgba(148, 163, 184, 0.0); }
        50%      { box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.18); }
    }
    @keyframes alertPulseWarn {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.0); }
        50%      { box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.35); }
    }
    @keyframes alertPulseDanger {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.0); }
        50%      { box-shadow: 0 0 0 5px rgba(239, 68, 68, 0.55); }
    }

    /* Entry animation for cards that didn't exist on the previous
       Livewire render — JS adds the class after each morph, removes
       it once the animation finishes.  Subtle slide-down + fade. */
    @keyframes cardEnter {
        0%   { opacity: 0; transform: translateY(-6px) scale(0.985); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
    }
    .card-enter { animation: cardEnter 0.65s cubic-bezier(0.16, 1, 0.3, 1) both; }

    /* "I changed" pulse for cards whose updated_at moved since the
       last render — uses outline rather than box-shadow so it stacks
       cleanly with the alert glow. */
    @keyframes cardPulse {
        0%   { outline: 2px solid rgba(56, 189, 248, 0.0); outline-offset: -1px; }
        25%  { outline: 2px solid rgba(56, 189, 248, 0.9); outline-offset: -1px; }
        100% { outline: 2px solid rgba(56, 189, 248, 0.0); outline-offset: -1px; }
    }
    .card-pulse { animation: cardPulse 1.6s ease-out 1; }

    /* Honour the user's reduced-motion preference.  Disable the
       pulses, the rotation glow transitions and the JS-driven
       auto-scroll (handled in JS).  The board still refreshes every
       30s via wire:poll. */
    @media (prefers-reduced-motion: reduce) {
        .lane,
        .lane.lane-focused,
        .lane.lane-dim         { transform: none !important; transition: opacity 200ms linear !important; }
        .card-alert-mute,
        .card-alert-warn,
        .card-alert-danger,
        .card-enter,
        .card-pulse,
        .live-dot              { animation: none !important; }
        [x-ref^="content"]     { transition: none !important; }
    }
</style>

<script>
    // Single shared Alpine component for the whole wallboard.  Kept
    // inline (rather than registered globally in resources/js/app.js)
    // so reading the file shows the full behaviour in one place.
    document.addEventListener('alpine:init', () => {
        if (window._wallboardRegistered) return;
        window._wallboardRegistered = true;

        Alpine.data('wallboard', () => ({
            // --- focus rotation ----------------------------------------
            focusedIdx: 0,                  // 0=Waiting, 1=In Transit, 2=Delivered
            rotateMs: 25000,                // wallboard focus cycle
            // --- scroll state machine (per lane) ----------------------
            // Phases:
            //   'idle'    -> content fits, nothing to do
            //   'scroll'  -> animating scrollTop downwards
            //   'pause'   -> bottom reached, holding for 4s
            //   'fadeOut' -> fading the content out (600ms)
            //   'reset'   -> snap scrollTop to 0
            //   'fadeIn'  -> fading the content back in (600ms)
            lanePhase: ['idle', 'idle', 'idle'],
            lanePos:   [0, 0, 0],
            phaseStart:[0, 0, 0],
            pxPerSec:  28,                  // calm reading pace
            pauseMs:   4000,
            fadeMs:    600,
            // --- card change tracking ---------------------------------
            // Map<job_id, updated_at> snapshot so we can detect new /
            // recently-changed cards after every Livewire morph and
            // play the card-enter / card-pulse animation only on them.
            snapshot: new Map(),
            // --- header clock -----------------------------------------
            clock: '',
            date: '',
            fs: false,
            reduceMotion: false,
            lastRaf: 0,

            boot() {
                this.reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.tickClock();
                setInterval(() => this.tickClock(), 1000);

                // Initial snapshot of every card on the board so the
                // very first poll doesn't light up every visible card
                // as "newly added".
                this.captureSnapshot();

                // After every Livewire morph, diff the new DOM against
                // the snapshot we captured before the morph and play
                // entry / pulse animations only on the deltas.  Hook
                // is registered once globally even though boot() runs
                // again on every page navigation.
                if (window.Livewire && !window._wallboardMorphHooked) {
                    window._wallboardMorphHooked = true;
                    Livewire.hook('morph.updated', () => {
                        // morph.updated fires after the DOM is patched
                        // but we delay one frame so transitions have a
                        // chance to settle before we attach classes.
                        requestAnimationFrame(() => this.diffSnapshot());
                    });
                }

                if (this.reduceMotion) {
                    // Reduced motion: skip the rotation + scroll loop.
                    // The board still auto-refreshes via wire:poll, so
                    // ops gets fresh data, just no animated motion.
                    return;
                }

                this.rotateInterval = setInterval(() => this.advanceFocus(), this.rotateMs);
                requestAnimationFrame((t) => this.scrollLoop(t));
            },

            tickClock() {
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
            },

            advanceFocus() {
                this.focusedIdx = (this.focusedIdx + 1) % 3;
                // Reset every lane's scroll state on focus change so
                // the next focused column always starts at the top
                // and the previously-focused one isn't left stuck mid-
                // scroll.  Inline opacity from a fade phase has to be
                // cleared too.
                for (let i = 0; i < 3; i++) {
                    const c = this.$refs['content' + i];
                    if (c) {
                        c.scrollTop = 0;
                        c.style.opacity = '';
                    }
                    this.lanePhase[i] = 'idle';
                    this.lanePos[i]   = 0;
                }
            },

            scrollLoop(t) {
                const dt = this.lastRaf ? (t - this.lastRaf) / 1000 : 0;
                this.lastRaf = t;

                const idx = this.focusedIdx;
                const lane = this.$refs['content' + idx];
                if (lane) {
                    const max = lane.scrollHeight - lane.clientHeight;
                    if (max <= 4) {
                        // Content fits the viewport — no scrolling
                        // needed.  Stay idle until focus shifts or
                        // the content grows (next render).
                        this.lanePhase[idx] = 'idle';
                        lane.style.opacity = '';
                    } else {
                        const phase = this.lanePhase[idx];
                        if (phase === 'idle') {
                            this.lanePhase[idx] = 'scroll';
                            this.lanePos[idx] = 0;
                            lane.scrollTop = 0;
                        } else if (phase === 'scroll') {
                            this.lanePos[idx] += this.pxPerSec * dt;
                            if (this.lanePos[idx] >= max) {
                                this.lanePos[idx] = max;
                                this.lanePhase[idx] = 'pause';
                                this.phaseStart[idx] = t;
                            }
                            lane.scrollTop = this.lanePos[idx];
                        } else if (phase === 'pause') {
                            if (t - this.phaseStart[idx] >= this.pauseMs) {
                                this.lanePhase[idx] = 'fadeOut';
                                this.phaseStart[idx] = t;
                            }
                        } else if (phase === 'fadeOut') {
                            const p = Math.min(1, (t - this.phaseStart[idx]) / this.fadeMs);
                            lane.style.opacity = String(1 - p);
                            if (p >= 1) {
                                lane.scrollTop = 0;
                                this.lanePos[idx] = 0;
                                this.lanePhase[idx] = 'fadeIn';
                                this.phaseStart[idx] = t;
                            }
                        } else if (phase === 'fadeIn') {
                            const p = Math.min(1, (t - this.phaseStart[idx]) / this.fadeMs);
                            lane.style.opacity = String(p);
                            if (p >= 1) {
                                lane.style.opacity = '';
                                this.lanePhase[idx] = 'scroll';
                            }
                        }
                    }
                }

                requestAnimationFrame((t2) => this.scrollLoop(t2));
            },

            captureSnapshot() {
                const map = new Map();
                this.$el.querySelectorAll('[data-job-id]').forEach(el => {
                    map.set(el.dataset.jobId, el.dataset.updatedAt || '');
                });
                this.snapshot = map;
            },

            diffSnapshot() {
                const prev = this.snapshot;
                const next = new Map();
                this.$el.querySelectorAll('[data-job-id]').forEach(el => {
                    const id = el.dataset.jobId;
                    const updated = el.dataset.updatedAt || '';
                    next.set(id, updated);
                    if (!prev.has(id)) {
                        // Card didn't exist before this morph — new arrival.
                        el.classList.remove('card-enter');
                        // Re-trigger reflow so the animation restarts even
                        // if the same element gets re-entered later.
                        void el.offsetWidth;
                        el.classList.add('card-enter');
                        setTimeout(() => el.classList.remove('card-enter'), 800);
                    } else if (prev.get(id) !== updated) {
                        // Same card, but the row's updated_at moved —
                        // pulse to draw the eye.
                        el.classList.remove('card-pulse');
                        void el.offsetWidth;
                        el.classList.add('card-pulse');
                        setTimeout(() => el.classList.remove('card-pulse'), 1700);
                    }
                });
                this.snapshot = next;
            }
        }));
    });
</script>
