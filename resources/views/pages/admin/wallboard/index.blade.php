<?php

use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\JobEvent;
use App\Models\SystemSetting;
use App\Models\TrackerPosition;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Ops Wallboard.
 *
 * Designed to live on a wall TV / second screen in dispatch. Three
 * panels:
 *   - Drivers (left)         who's on the road, who's idle, last fix
 *   - Map (centre, biggest)  live driver pins + active job markers
 *   - Events (right)         live feed of recent activity
 *
 * Polling: a single `wire:poll.5s` cycles the whole component, which
 * re-runs `with()` and pushes a fresh markers payload to the map
 * (the Alpine glue lives at the bottom of this file).
 *
 * Auth: this is a /admin route, so internal auth is already enforced by
 * `bootstrap/app.php` middleware. Sidebar visibility further gates it
 * to ops/dispatch/owner/superadmin.
 */
new #[Layout('components.layouts.app')] class extends Component {

    /**
     * Show stale (no fix in over an hour) drivers in the panel? Default
     * yes — we'd rather see "11 drivers online, 3 cold" than hide three
     * trucks the supervisor needs to chase.
     */
    public bool $showStale = true;

    /**
     * Kiosk / wall-TV mode: hides the app sidebar and top bar, takes
     * over the full viewport and lets the operator press F11 / the
     * floating button to drop into native browser fullscreen too.
     * Toggleable from the wallboard top strip; URL is rewritten to
     * `?kiosk=1` so a refresh keeps the mode.
     */
    public bool $kiosk = false;

    public function mount(): void
    {
        $this->kiosk = (bool) request()->boolean('kiosk', false);
    }

    public function with(): array
    {
        // ---------------------------------------------------------------
        // DRIVERS PANEL
        // Pull every roster driver, decorate with their latest tracker
        // sample (if any) and any in-flight job. Status colour buckets
        // are computed here so the template stays dumb.
        // ---------------------------------------------------------------
        $latestPerTracker = TrackerPosition::query()
            ->select(['tracker_id', 'latitude', 'longitude', 'speed_kmh', 'heading_deg', 'reported_at'])
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('tracker_positions')
                    ->where('reported_at', '>=', now()->subHours(24))
                    ->groupBy('tracker_id');
            })
            ->get()
            ->keyBy('tracker_id');

        $drivers = User::query()
            ->select(['id', 'name', 'phone', 'is_active'])
            ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->with(['driverProfile:id,user_id,tracker_id'])
            ->orderBy('name')
            ->get();

        $activeJobsByDriver = Job::query()
            ->whereIn('status', [
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
                Job::STATUS_COLLECTED,
                Job::STATUS_IN_TRANSIT,
                Job::STATUS_ASSIGNED,
                Job::STATUS_IN_PROGRESS,
            ])
            ->with([
                'pickupLocation:id,company_name,city,latitude,longitude',
                'deliveryLocation:id,company_name,city,latitude,longitude',
                'company:id,name',
            ])
            ->get()
            ->keyBy('driver_user_id');

        $rows = collect($drivers->all())->map(function (User $driver) use ($latestPerTracker, $activeJobsByDriver) {
            $trackerId = $driver->driverProfile?->tracker_id;
            $position = $trackerId ? $latestPerTracker->get($trackerId) : null;
            $job = $activeJobsByDriver->get($driver->id);

            $bucket = $this->bucketFor($position, $job);

            return (object) [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'tracker_id' => $trackerId,
                'has_position' => $position !== null,
                'lat' => $position?->latitude !== null ? (float) $position->latitude : null,
                'lng' => $position?->longitude !== null ? (float) $position->longitude : null,
                'speed_kmh' => $position?->speed_kmh !== null ? (float) $position->speed_kmh : null,
                'heading_deg' => $position?->heading_deg !== null ? (float) $position->heading_deg : null,
                'reported_at' => $position?->reported_at,
                'job' => $job ? (object) [
                    'id' => $job->id,
                    'job_number' => $job->job_number,
                    'status' => $job->status,
                    'status_label' => $job->phase1StatusLabel(),
                    'pickup' => $job->pickupLocation?->company_name,
                    'delivery' => $job->deliveryLocation?->company_name,
                    'customer' => $job->company?->name,
                    'detail_url' => route('admin.orders.show', $job->id),
                ] : null,
                'bucket' => $bucket,
            ];
        });

        if (!$this->showStale) {
            $rows = $rows->filter(fn ($r) => $r->bucket !== 'offline')->values();
        }

        // ---------------------------------------------------------------
        // EVENTS PANEL
        // Latest 50 entries: union of JobEvent rows (driver check-ins)
        // with synthetic "new order" rows derived from the very recent
        // creates on transport_jobs. Sorted desc on a single timestamp
        // so the feed reads chronologically without the operator having
        // to mentally interleave two streams.
        // ---------------------------------------------------------------
        // The merge of two Eloquent collections needs the rows to expose
        // ->getKey(), so we deliberately collect() them as base Collections
        // before mapping into stdClass DTOs. The final $events is a plain
        // Collection that the view iterates with foreach.
        $eventModels = JobEvent::query()
            ->whereIn('event_type', [
                JobEvent::TYPE_ARRIVED_PICKUP,
                JobEvent::TYPE_DEPARTED_PICKUP,
                JobEvent::TYPE_ARRIVED_DELIVERY,
                JobEvent::TYPE_VEHICLE_READY,
                JobEvent::TYPE_JOB_COMPLETED,
            ])
            ->where('event_at', '>=', now()->subHours(6))
            ->with(['user:id,name', 'job.pickupLocation:id,company_name', 'job.deliveryLocation:id,company_name', 'job.company:id,name'])
            ->orderByDesc('event_at')
            ->limit(60)
            ->get();

        $eventRows = collect($eventModels->all())->map(function (JobEvent $e) {
            return (object) [
                'kind' => 'event',
                'at' => $e->event_at,
                'event_type' => $e->event_type,
                'driver_name' => $e->user?->name ?? 'Driver',
                'job_number' => $e->job?->job_number,
                'pickup' => $e->job?->pickupLocation?->company_name,
                'delivery' => $e->job?->deliveryLocation?->company_name,
                'customer' => $e->job?->company?->name,
                'message' => $this->formatEventMessage($e),
            ];
        });

        $newOrderModels = Job::query()
            ->where('created_at', '>=', now()->subHours(6))
            ->with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $newOrderRows = collect($newOrderModels->all())->map(function (Job $j) {
            return (object) [
                'kind' => 'new_order',
                'at' => $j->created_at,
                'event_type' => 'new_order',
                'driver_name' => null,
                'job_number' => $j->job_number,
                'pickup' => $j->pickupLocation?->company_name,
                'delivery' => $j->deliveryLocation?->company_name,
                'customer' => $j->company?->name,
                'message' => 'New order from ' . ($j->company?->name ?? 'unknown') .
                    ($j->pickupLocation ? ' — pickup ' . $j->pickupLocation->company_name : ''),
            ];
        });

        $events = $eventRows->concat($newOrderRows)
            ->sortByDesc(fn ($r) => $r->at?->getTimestamp() ?? 0)
            ->values()
            ->take(50);

        // ---------------------------------------------------------------
        // MAP MARKERS PAYLOAD
        // Plain array shape consumed by the Alpine glue at the bottom of
        // this file. Driver pins + pickup/delivery pins for active jobs.
        // ---------------------------------------------------------------
        $driverMarkers = $rows
            ->filter(fn ($r) => $r->lat !== null && $r->lng !== null)
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'phone' => $r->phone,
                'lat' => $r->lat,
                'lng' => $r->lng,
                'heading' => $r->heading_deg,
                'bucket' => $r->bucket,
                'speed_kmh' => $r->speed_kmh !== null ? (int) round($r->speed_kmh) : null,
                'last_fix_human' => $r->reported_at?->diffForHumans(['parts' => 1, 'short' => true]),
                'job' => $r->job ? [
                    'id' => $r->job->id,
                    'job_number' => $r->job->job_number,
                    'status' => $r->job->status,
                    'status_label' => $r->job->status_label,
                    'pickup' => $r->job->pickup,
                    'delivery' => $r->job->delivery,
                    'customer' => $r->job->customer,
                    'detail_url' => $r->job->detail_url,
                ] : null,
            ])
            ->values();

        $jobMarkers = $activeJobsByDriver->values()->flatMap(function (Job $job) {
            $out = [];
            if ($job->pickupLocation && $job->pickupLocation->latitude !== null) {
                $out[] = [
                    'kind' => 'pickup',
                    'lat' => (float) $job->pickupLocation->latitude,
                    'lng' => (float) $job->pickupLocation->longitude,
                    'label' => $job->job_number . ' pickup · ' . $job->pickupLocation->company_name,
                ];
            }
            if ($job->deliveryLocation && $job->deliveryLocation->latitude !== null) {
                $out[] = [
                    'kind' => 'delivery',
                    'lat' => (float) $job->deliveryLocation->latitude,
                    'lng' => (float) $job->deliveryLocation->longitude,
                    'label' => $job->job_number . ' delivery · ' . $job->deliveryLocation->company_name,
                ];
            }
            return $out;
        });

        // Counts shown along the top: tally on the unfiltered driver list
        // so toggling "show stale" doesn't lie about the actual fleet.
        $allRows = collect($drivers->all())->map(fn ($d) => $this->bucketFor(
            $d->driverProfile?->tracker_id ? $latestPerTracker->get($d->driverProfile->tracker_id) : null,
            $activeJobsByDriver->get($d->id)
        ));
        $counts = [
            'total' => $allRows->count(),
            'on_job' => $allRows->filter(fn ($b) => $b === 'on_job')->count(),
            'idle' => $allRows->filter(fn ($b) => $b === 'idle')->count(),
            'stale' => $allRows->filter(fn ($b) => $b === 'stale')->count(),
            'offline' => $allRows->filter(fn ($b) => $b === 'offline')->count(),
        ];

        return [
            'rows' => $rows,
            'events' => $events,
            'counts' => $counts,
            'driverMarkers' => $driverMarkers,
            'jobMarkers' => $jobMarkers,
            'mapsApiKey' => (string) SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key', '')),
            'tracksolidConfigured' => filter_var(
                SystemSetting::get(\App\Services\TrackSolid\Client::SETTING_ENABLED, false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    /**
     * Driver status bucket. Drives both the dot colour in the panel and
     * the marker style on the map.
     *
     * - on_job:  has an active job AND a fresh fix (< 5 min old)
     * - idle:    fresh fix, no active job
     * - stale:   last fix 5-60 min ago (signal patchy / under a roof)
     * - offline: no fix in the last hour, OR no tracker bound at all
     */
    public function bucketFor(?TrackerPosition $position, ?Job $job): string
    {
        if (!$position) {
            return 'offline';
        }
        $minutes = $position->reported_at?->diffInMinutes(now()) ?? PHP_INT_MAX;

        if ($minutes >= 60) {
            return 'offline';
        }
        if ($minutes >= 5) {
            return 'stale';
        }
        return $job ? 'on_job' : 'idle';
    }

    /**
     * Plain-English event line. Kept here (not on the model) so it can
     * incorporate the related job/driver/location context in one place.
     */
    public function formatEventMessage(JobEvent $event): string
    {
        $driver = $event->user?->name ?? 'Driver';
        $jobNum = $event->job?->job_number ?? '';
        $pickup = $event->job?->pickupLocation?->company_name;
        $delivery = $event->job?->deliveryLocation?->company_name;

        return match ($event->event_type) {
            JobEvent::TYPE_ARRIVED_PICKUP => "{$driver} arrived at pickup" . ($pickup ? " {$pickup}" : '') . ($jobNum ? " ({$jobNum})" : ''),
            JobEvent::TYPE_DEPARTED_PICKUP => "{$driver} departed from" . ($pickup ? " {$pickup}" : ' pickup') . ($jobNum ? " ({$jobNum})" : ''),
            JobEvent::TYPE_ARRIVED_DELIVERY => "{$driver} arrived at delivery" . ($delivery ? " {$delivery}" : '') . ($jobNum ? " ({$jobNum})" : ''),
            JobEvent::TYPE_VEHICLE_READY => "Vehicle ready for collection" . ($pickup ? " at {$pickup}" : '') . ($jobNum ? " ({$jobNum})" : ''),
            JobEvent::TYPE_JOB_COMPLETED => "{$driver} completed" . ($jobNum ? " {$jobNum}" : ' a job'),
            default => ucfirst(str_replace('_', ' ', $event->event_type)),
        };
    }
};

?>

{{-- Stash the live payload in an inert script tag so Alpine doesn't
     have to receive it through a multi-line attribute (which trips
     Blade's directive parser on this page). Each wire:poll cycle
     re-emits this tag, refreshing the data the Alpine component reads
     on its next tick. --}}
@php
    $wallboardPayload = [
        'markers' => $driverMarkers,
        'jobMarkers' => $jobMarkers,
        'apiKey' => (string) $mapsApiKey,
    ];
@endphp
<div
    wire:poll.5s
    x-data="wallboardMap({ kiosk: @js($kiosk) })"
    x-init="bootMap()"
    @class([
        '-mx-4 -my-6 sm:-mx-6 lg:-mx-8 lg:-my-8' => !$kiosk,
        'fixed inset-0 z-[60] bg-slate-100' => $kiosk,
    ])
>
    {{-- Live JSON payload re-emitted on every wire:poll cycle. The
         Alpine glue further down reads it via getElementById, so its
         position inside the root element is irrelevant — but it has
         to live INSIDE the root or Livewire flags multiple roots. --}}
    <script id="wallboard-data" type="application/json">{!! json_encode($wallboardPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    @if($kiosk)
    {{-- Kiosk overrides: hide the app shell so the wallboard owns the
         entire viewport on a wall TV. Scoped <style> tag instead of
         touching the layout component, because the layout is used by
         every other page and kiosk mode is wallboard-specific. --}}
    <style>
        body { overflow: hidden !important; }
        body > nav,
        body > header,
        aside.app-sidebar,
        nav[role="navigation"]:first-of-type,
        .app-shell-sidebar,
        .app-shell-topbar,
        div[x-data*="sidebarOpen"],
        .min-h-screen > aside,
        .min-h-screen > header { display: none !important; }
        main, .min-h-screen > main, .py-8, .py-6, .max-w-7xl, .container-fluid {
            margin: 0 !important; padding: 0 !important; max-width: 100vw !important;
        }
    </style>
    @endif

    <style>
        .wb-iw {
            font-family: ui-sans-serif, system-ui, sans-serif;
            min-width: 240px;
            max-width: 300px;
        }
        .wb-iw h3 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
        .wb-iw .wb-row { font-size: 12px; color: #475569; line-height: 1.4; }
        .wb-iw .wb-row strong { color: #0f172a; font-weight: 600; }
        .wb-iw .wb-pill {
            display: inline-block; font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
            padding: 2px 8px; border-radius: 9999px;
            margin-bottom: 6px;
        }
        .wb-iw .wb-actions { margin-top: 8px; display: flex; gap: 8px; }
        .wb-iw .wb-btn {
            font-size: 11px; font-weight: 600; padding: 5px 9px;
            border-radius: 6px; text-decoration: none;
            background: #2563eb; color: white;
        }
        .wb-iw .wb-btn-secondary { background: #f1f5f9; color: #0f172a; }
        .wb-iw .wb-route { font-size: 11px; color: #334155; margin-top: 4px; }
    </style>

    @if(!$kiosk)
        <x-slot:header>Operations Wallboard</x-slot:header>
    @endif

    {{-- Top stats strip --}}
    <div class="px-4 sm:px-6 lg:px-8 pt-2 pb-3 border-b border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if($kiosk)
                <span class="rounded-full border border-slate-300 bg-slate-900 px-3 py-1.5 font-semibold uppercase tracking-[0.18em] text-white">
                    Ops Wallboard
                </span>
            @endif
            <span class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-700">
                <span class="font-semibold tabular-nums">{{ $counts['total'] }}</span> drivers
            </span>
            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-emerald-700">
                <span class="font-semibold tabular-nums">{{ $counts['on_job'] }}</span> on job
            </span>
            <span class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-blue-700">
                <span class="font-semibold tabular-nums">{{ $counts['idle'] }}</span> idle
            </span>
            <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-amber-700">
                <span class="font-semibold tabular-nums">{{ $counts['stale'] }}</span> stale
            </span>
            <span class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 text-rose-700">
                <span class="font-semibold tabular-nums">{{ $counts['offline'] }}</span> offline
            </span>

            <label class="ml-auto inline-flex items-center gap-2 text-slate-600">
                <input type="checkbox" wire:model.live="showStale" class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Show stale / offline drivers
            </label>

            {{-- Kiosk / fullscreen toggle. Two buttons because they do
                 different things: "Kiosk" hides the app chrome via
                 ?kiosk=1 (survives refresh, good for a TV that boots
                 to this URL), and "Fullscreen" calls the browser's
                 own fullscreen API on top of that. --}}
            @if($kiosk)
                <a href="{{ route('admin.wallboard') }}"
                   class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 6a1 1 0 011-1h4a1 1 0 010 2H5v3a1 1 0 11-2 0V6zm14 0a1 1 0 00-1-1h-4a1 1 0 100 2h3v3a1 1 0 102 0V6zM3 14a1 1 0 011-1h4a1 1 0 110 2H5v-3a1 1 0 00-2 0v2zm14 0a1 1 0 01-1 1h-4a1 1 0 110-2h3v-3a1 1 0 112 0v4z"/></svg>
                    Exit kiosk
                </a>
            @else
                <a href="{{ route('admin.wallboard', ['kiosk' => 1]) }}"
                   class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h4a1 1 0 010 2H5v3a1 1 0 11-2 0V4zm14 0a1 1 0 00-1-1h-4a1 1 0 100 2h3v3a1 1 0 102 0V4zM3 16a1 1 0 011 1h4a1 1 0 110-2H5v-3a1 1 0 00-2 0v4zm14 0a1 1 0 01-1 1h-4a1 1 0 110-2h3v-3a1 1 0 112 0v4z"/></svg>
                    Kiosk mode
                </a>
            @endif
            <button type="button"
                    x-on:click="toggleFullscreen()"
                    class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v3a1 1 0 002 0V5h3a1 1 0 100-2H5zm10 0a1 1 0 100 2h3v3a1 1 0 102 0V5a2 2 0 00-2-2h-3zM5 17a2 2 0 01-2-2v-3a1 1 0 112 0v3h3a1 1 0 110 2H5zm10 0a1 1 0 110-2h3v-3a1 1 0 112 0v3a2 2 0 01-2 2h-3z"/></svg>
                <span x-text="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"></span>
            </button>

            @if(!$tracksolidConfigured)
                <a href="{{ route('admin.settings.integrations') }}" class="rounded-full border border-amber-300 bg-amber-50 px-3 py-1.5 font-medium text-amber-700 hover:bg-amber-100">
                    TrackSolid not configured — set up integration
                </a>
            @endif

            @if(!$mapsApiKey)
                <a href="{{ route('admin.settings.integrations') }}" class="rounded-full border border-rose-300 bg-rose-50 px-3 py-1.5 font-medium text-rose-700 hover:bg-rose-100">
                    Google Maps key missing — map disabled
                </a>
            @endif
        </div>
    </div>

    {{-- Three-panel grid. In kiosk mode we eat the whole viewport
         minus the top stats strip; in normal mode we leave room for
         the app chrome above. --}}
    <div @class([
        'grid grid-cols-12 gap-0',
        'h-[calc(100vh-9rem)]' => !$kiosk,
        'h-[calc(100vh-3.25rem)]' => $kiosk,
    ])>

        {{-- LEFT · DRIVERS --}}
        <aside class="col-span-12 md:col-span-3 lg:col-span-3 border-r border-slate-200 bg-white overflow-y-auto">
            <header class="sticky top-0 bg-white border-b border-slate-200 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">
                Drivers
            </header>
            <ul class="divide-y divide-slate-100">
                @foreach($rows as $row)
                    @php
                        $dot = match($row->bucket) {
                            'on_job' => 'bg-emerald-500',
                            'idle' => 'bg-blue-500',
                            'stale' => 'bg-amber-500',
                            default => 'bg-slate-300',
                        };
                        $bucketLabel = match($row->bucket) {
                            'on_job' => 'On job',
                            'idle' => 'Idle',
                            'stale' => 'Stale fix',
                            default => 'Offline',
                        };
                    @endphp
                    <li class="px-4 py-2.5 flex items-start gap-3 hover:bg-slate-50 cursor-pointer"
                        @if($row->lat !== null && $row->lng !== null)
                            x-on:click="panTo({{ $row->lat }}, {{ $row->lng }})"
                        @endif
                    >
                        <span class="mt-1.5 h-2.5 w-2.5 rounded-full shrink-0 {{ $dot }}" title="{{ $bucketLabel }}"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-900 truncate">{{ $row->name }}</p>
                            @if($row->job)
                                <p class="text-xs text-slate-600 truncate">
                                    {{ $row->job->job_number }} · {{ $row->job->status_label }}
                                </p>
                                @if($row->job->pickup || $row->job->delivery)
                                    <p class="text-[11px] text-slate-500 truncate">{{ $row->job->pickup ?? '?' }} → {{ $row->job->delivery ?? '?' }}</p>
                                @endif
                            @else
                                <p class="text-xs text-slate-500">{{ $bucketLabel }}</p>
                            @endif
                            @if($row->reported_at)
                                <p class="text-[11px] text-slate-400">
                                    {{ $row->reported_at->diffForHumans(['parts' => 1, 'short' => true]) }}
                                    @if($row->speed_kmh !== null)
                                        · {{ (int) round($row->speed_kmh) }} km/h
                                    @endif
                                </p>
                            @elseif($row->tracker_id)
                                <p class="text-[11px] text-slate-400">no fix yet</p>
                            @else
                                <p class="text-[11px] text-rose-500">no tracker bound</p>
                            @endif
                        </div>
                    </li>
                @endforeach

                @if(count($rows) === 0)
                    <li class="px-4 py-8 text-center text-sm text-slate-500">
                        @if($showStale)
                            No drivers on roster.
                        @else
                            All drivers are stale or offline. Toggle "Show stale / offline" to see them.
                        @endif
                    </li>
                @endif
            </ul>
        </aside>

        {{-- CENTRE · MAP --}}
        <section class="col-span-12 md:col-span-6 lg:col-span-6 relative bg-slate-100">
            @if($mapsApiKey)
                <div id="wallboard-map" class="absolute inset-0"></div>
            @else
                <div class="absolute inset-0 flex items-center justify-center text-sm text-slate-500 px-6 text-center">
                    Map disabled — set the Google Maps API key in
                    <a class="ml-1 text-blue-600 hover:underline" href="{{ route('admin.settings.integrations') }}">Integrations</a>
                    .
                </div>
            @endif
        </section>

        {{-- RIGHT · EVENTS --}}
        <aside class="col-span-12 md:col-span-3 lg:col-span-3 border-l border-slate-200 bg-white overflow-y-auto">
            <header class="sticky top-0 bg-white border-b border-slate-200 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500 flex items-center justify-between">
                <span>Events</span>
                <span class="font-mono text-[10px] text-slate-400">live · 5s</span>
            </header>
            <ul class="divide-y divide-slate-100">
                @foreach($events as $event)
                    @php
                        $accent = match($event->event_type) {
                            'new_order' => 'border-l-blue-500',
                            JobEvent::TYPE_ARRIVED_PICKUP, JobEvent::TYPE_VEHICLE_READY => 'border-l-emerald-500',
                            JobEvent::TYPE_DEPARTED_PICKUP => 'border-l-amber-500',
                            JobEvent::TYPE_ARRIVED_DELIVERY => 'border-l-emerald-600',
                            JobEvent::TYPE_JOB_COMPLETED => 'border-l-slate-500',
                            default => 'border-l-slate-300',
                        };
                    @endphp
                    <li class="px-4 py-2.5 border-l-4 {{ $accent }}">
                        <p class="text-sm text-slate-800 leading-snug">{{ $event->message }}</p>
                        <p class="mt-0.5 text-[11px] text-slate-400">
                            {{ optional($event->at)->diffForHumans(['parts' => 1, 'short' => true]) }}
                            @if($event->customer)
                                · {{ $event->customer }}
                            @endif
                        </p>
                    </li>
                @endforeach

                @if(count($events) === 0)
                    <li class="px-4 py-8 text-center text-sm text-slate-500">No events in the last 6 hours.</li>
                @endif
            </ul>
        </aside>
    </div>

    {{-- ----------------------------------------------------------------
         Map glue. Loads the Google Maps JS API once (idempotent) and
         exposes panTo() + replaceMarkers() to the Alpine component so
         every wire:poll cycle can refresh marker positions without a
         full page reload. The layout doesn't expose a @stack('scripts')
         hook, so we inline the script — Alpine.data() is idempotent
         (last registration wins) so a re-render of this fragment is
         safe.
    ----------------------------------------------------------------- --}}
    <script>
        function _wallboardReadPayload() {
            const el = document.getElementById('wallboard-data');
            if (!el) return { markers: [], jobMarkers: [], apiKey: '' };
            try {
                return JSON.parse(el.textContent || '{}');
            } catch (e) {
                return { markers: [], jobMarkers: [], apiKey: '' };
            }
        }

        // Bucket → colour palette shared between marker pin, cluster
        // tint and info window status pill so everything reads as a
        // single coherent visual system.
        const WB_COLOURS = {
            on_job:  { fill: '#10b981', dark: '#047857', label: 'On job',   bg: '#d1fae5', text: '#065f46' },
            idle:    { fill: '#3b82f6', dark: '#1d4ed8', label: 'Idle',     bg: '#dbeafe', text: '#1e40af' },
            stale:   { fill: '#f59e0b', dark: '#b45309', label: 'Stale',    bg: '#fef3c7', text: '#92400e' },
            offline: { fill: '#94a3b8', dark: '#475569', label: 'Offline',  bg: '#f1f5f9', text: '#334155' },
        };
        const WB_DEFAULT = WB_COLOURS.idle;

        // SVG pin used per driver. Tinted by status; the inner dot
        // pulses on active drivers. Embedded as a data: URL so we can
        // hand it straight to the Google Maps Marker icon prop.
        // We use \x3c (escaped '<') everywhere a real angle bracket
        // would appear in a JS string. PHP's libxml HTML parser
        // (DOMDocument) — which is what Livewire's multiple-root
        // detector runs on the rendered output — has a long-standing
        // quirk where certain content inside a <body>-level <script>
        // (especially when there's also a <style> in body, processing-
        // instructions, or particular tag patterns) trips it out of
        // "script data" state and then it parses our JS template
        // literals as actual HTML elements, which spawn extra root
        // nodes. The escape sidesteps the whole class of bugs.
        function wbDriverIcon(bucket) {
            const c = WB_COLOURS[bucket] || WB_DEFAULT;
            const svg =
                '\x3csvg xmlns="http://www.w3.org/2000/svg" width="34" height="44" viewBox="0 0 34 44">' +
                  '\x3cdefs>' +
                    '\x3cradialGradient id="g" cx="50%" cy="40%" r="60%">' +
                      '\x3cstop offset="0%" stop-color="' + c.fill + '" stop-opacity="0.95"/>' +
                      '\x3cstop offset="100%" stop-color="' + c.dark + '" stop-opacity="1"/>' +
                    '\x3c/radialGradient>' +
                    '\x3cfilter id="s" x="-50%" y="-50%" width="200%" height="200%">' +
                      '\x3cfeDropShadow dx="0" dy="1" stdDeviation="1.4" flood-opacity="0.35"/>' +
                    '\x3c/filter>' +
                  '\x3c/defs>' +
                  '\x3cpath d="M17 1 C8.2 1 1 8.2 1 17 c0 11.5 16 26 16 26 s16-14.5 16-26 c0-8.8-7.2-16-16-16 z" ' +
                       'fill="url(#g)" stroke="#0f172a" stroke-width="1.4" filter="url(#s)"/>' +
                  '\x3ccircle cx="17" cy="17" r="6.5" fill="#ffffff" opacity="0.95"/>' +
                  '\x3ccircle cx="17" cy="17" r="3.5" fill="' + c.dark + '"/>' +
                '\x3c/svg>';
            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
        }

        function wbClusterIconUrl(tone, size) {
            const c = WB_COLOURS[tone] || WB_DEFAULT;
            const svg =
                '\x3csvg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
                  '\x3cdefs>' +
                    '\x3cradialGradient id="cg" cx="50%" cy="50%" r="50%">' +
                      '\x3cstop offset="0%" stop-color="' + c.fill + '" stop-opacity="0.45"/>' +
                      '\x3cstop offset="60%" stop-color="' + c.fill + '" stop-opacity="0.25"/>' +
                      '\x3cstop offset="100%" stop-color="' + c.fill + '" stop-opacity="0"/>' +
                    '\x3c/radialGradient>' +
                  '\x3c/defs>' +
                  '\x3ccircle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + (size/2) + '" fill="url(#cg)"/>' +
                  '\x3ccircle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + (size*0.32) + '" fill="' + c.fill + '" stroke="#0f172a" stroke-width="1.5" opacity="0.95"/>' +
                '\x3c/svg>';
            return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
        }

        function wbInfoWindowHtml(m) {
            const c = WB_COLOURS[m.bucket] || WB_DEFAULT;
            const speedTxt = (m.speed_kmh != null) ? (' · ' + m.speed_kmh + ' km/h') : '';
            const fixTxt = m.last_fix_human
                ? ('\x3cdiv class="wb-row">\x3cstrong>Last fix:\x3c/strong> ' + m.last_fix_human + speedTxt + '\x3c/div>')
                : '';
            const phoneRow = m.phone
                ? ('\x3cdiv class="wb-row">\x3cstrong>Phone:\x3c/strong> \x3ca href="tel:' + m.phone + '" style="color:#2563eb">' + m.phone + '\x3c/a>\x3c/div>')
                : '';

            let jobBlock = '\x3cdiv class="wb-row" style="margin-top:6px">\x3cem>No active job.\x3c/em>\x3c/div>';
            let actions = '';
            if (m.job) {
                const route = (m.job.pickup || m.job.delivery)
                    ? ('\x3cdiv class="wb-route">' + (m.job.pickup || '?') + ' → ' + (m.job.delivery || '?') + '\x3c/div>') : '';
                const cust = m.job.customer
                    ? ('\x3cdiv class="wb-row">\x3cstrong>Customer:\x3c/strong> ' + m.job.customer + '\x3c/div>')
                    : '';
                jobBlock =
                    '\x3cdiv class="wb-row" style="margin-top:6px">\x3cstrong>' + m.job.job_number + '\x3c/strong> · ' + m.job.status_label + '\x3c/div>' +
                    cust + route;
                actions = '\x3ca class="wb-btn" target="_blank" rel="noopener" href="' + m.job.detail_url + '">Open job\x3c/a>';
            }
            const callBtn = m.phone ? ('\x3ca class="wb-btn wb-btn-secondary" href="tel:' + m.phone + '">Call\x3c/a>') : '';

            return (
                '\x3cdiv class="wb-iw">' +
                  '\x3cspan class="wb-pill" style="background:' + c.bg + ';color:' + c.text + '">' + c.label + '\x3c/span>' +
                  '\x3ch3>' + (m.name || 'Driver') + '\x3c/h3>' +
                  fixTxt + phoneRow + jobBlock +
                  '\x3cdiv class="wb-actions">' + actions + callBtn + '\x3c/div>' +
                '\x3c/div>'
            );
        }

        document.addEventListener('alpine:init', () => {
            Alpine.data('wallboardMap', (opts = {}) => ({
                map: null,
                markers: [],
                jobMarkers: [],
                apiKey: '',
                kiosk: !!opts.kiosk,
                isFullscreen: false,
                _pins: [],
                _jobPins: [],
                _clusterer: null,
                _infoWindow: null,
                _hasFitted: false,

                bootMap() {
                    const payload = _wallboardReadPayload();
                    this.markers = payload.markers || [];
                    this.jobMarkers = payload.jobMarkers || [];
                    this.apiKey = payload.apiKey || '';

                    document.addEventListener('fullscreenchange', () => {
                        this.isFullscreen = !!document.fullscreenElement;
                    });

                    if (!this.apiKey) return;

                    const start = () => this.loadClusterer().then(() => this.initMap());

                    // Case 1: API already fully loaded (e.g. another tab on
                    // this page already pulled it in). Just go.
                    if (window.google && window.google.maps && window.google.maps.Map) {
                        start();
                        return;
                    }

                    // Case 2: SOMETHING (the app layout's Places loader, or
                    // an earlier Alpine instance of this same wallboard) has
                    // already added a <script> for maps.googleapis.com but
                    // it hasn't finished executing yet. Reuse it instead of
                    // injecting a duplicate — Google's loader corrupts and
                    // throws "Cannot read properties of undefined (reading
                    // 'JS')" if you load the API twice with different
                    // params. We just poll until window.google.maps.Map is
                    // there, then start.
                    const existing = document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]');
                    if (existing || window.__wallboardMapsLoading) {
                        const t = setInterval(() => {
                            if (window.google && window.google.maps && window.google.maps.Map) {
                                clearInterval(t);
                                start();
                            }
                        }, 100);
                        // After 30s give up — something is wrong, e.g. key
                        // restrictions. The map div will just stay blank.
                        setTimeout(() => clearInterval(t), 30000);
                        return;
                    }

                    // Case 3: nobody loaded Maps yet. Pull it in WITH the
                    // Places library so any other widget on this page that
                    // needs it (address autocomplete in the app shell)
                    // doesn't trigger a second load later.
                    window.__wallboardMapsLoading = true;
                    const s = document.createElement('script');
                    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(this.apiKey) + '&libraries=places&loading=async';
                    s.async = true;
                    s.defer = true;
                    s.onload = () => start();
                    document.head.appendChild(s);
                },

                // Lazy-load the @googlemaps/markerclusterer UMD bundle.
                // Resolves immediately if it's already on window.
                loadClusterer() {
                    return new Promise((resolve) => {
                        if (window.markerClusterer) return resolve();
                        const s = document.createElement('script');
                        s.src = 'https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js';
                        s.async = true;
                        s.onload = () => resolve();
                        s.onerror = () => resolve(); // fall back to plain markers
                        document.head.appendChild(s);
                    });
                },

                initMap() {
                    const el = document.getElementById('wallboard-map');
                    if (!el) return;
                    this.map = new google.maps.Map(el, {
                        center: { lat: -28.4793, lng: 24.6727 }, // SA centroid
                        zoom: 6,
                        mapTypeControl: false,
                        streetViewControl: false,
                        fullscreenControl: false,
                        clickableIcons: false,
                        gestureHandling: 'greedy',
                    });
                    this._infoWindow = new google.maps.InfoWindow();
                    this.replaceMarkers();
                    this.fitToMarkers();
                },

                replaceMarkers() {
                    if (!this.map || !window.google) return;

                    if (this._clusterer) {
                        this._clusterer.clearMarkers();
                    }
                    this._pins.forEach(p => p.setMap(null));
                    this._pins = [];
                    this._jobPins.forEach(p => p.setMap(null));
                    this._jobPins = [];

                    this.markers.forEach((m) => {
                        if (m.lat == null || m.lng == null) return;

                        const pin = new google.maps.Marker({
                            position: { lat: Number(m.lat), lng: Number(m.lng) },
                            title: m.name + (m.job ? ' — ' + m.job.job_number : ''),
                            icon: {
                                url: wbDriverIcon(m.bucket || 'idle'),
                                scaledSize: new google.maps.Size(34, 44),
                                anchor: new google.maps.Point(17, 42),
                            },
                            optimized: false,
                        });
                        pin.__driver = m;

                        pin.addListener('click', () => {
                            this._infoWindow.setContent(wbInfoWindowHtml(m));
                            this._infoWindow.open({ map: this.map, anchor: pin });
                        });

                        this._pins.push(pin);
                    });

                    // Cluster the driver pins. The clusterer auto-creates
                    // count bubbles when zoomed out, and breaks them up
                    // as you zoom in — same pattern operators use on
                    // every other fleet tool, so it's the right fit.
                    if (window.markerClusterer && this._pins.length) {
                        const renderer = {
                            render: ({ count, position, markers: cMarkers }) => {
                                // Tint the cluster by the most "active" status
                                // it contains: on_job > stale > idle > offline.
                                const order = ['on_job', 'stale', 'idle', 'offline'];
                                let tone = 'idle';
                                for (const t of order) {
                                    if (cMarkers.some(mk => (mk.__driver && mk.__driver.bucket === t))) { tone = t; break; }
                                }
                                const size = Math.min(72, 36 + Math.log2(count) * 7);
                                return new google.maps.Marker({
                                    position,
                                    icon: {
                                        url: wbClusterIconUrl(tone, size),
                                        scaledSize: new google.maps.Size(size, size),
                                        anchor: new google.maps.Point(size / 2, size / 2),
                                    },
                                    label: {
                                        text: String(count),
                                        color: '#ffffff',
                                        fontWeight: '700',
                                        fontSize: '13px',
                                    },
                                    zIndex: 1000 + count,
                                    optimized: false,
                                });
                            },
                        };

                        if (this._clusterer) {
                            this._clusterer.addMarkers(this._pins);
                        } else {
                            this._clusterer = new markerClusterer.MarkerClusterer({
                                map: this.map,
                                markers: this._pins,
                                renderer,
                            });
                        }
                    } else {
                        this._pins.forEach(p => p.setMap(this.map));
                    }

                    this.jobMarkers.forEach((j) => {
                        const pin = new google.maps.Marker({
                            map: this.map,
                            position: { lat: Number(j.lat), lng: Number(j.lng) },
                            title: j.label,
                            icon: {
                                path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW,
                                scale: 4,
                                fillColor: j.kind === 'pickup' ? '#0ea5e9' : '#a855f7',
                                fillOpacity: 0.9,
                                strokeColor: '#0f172a',
                                strokeWeight: 1,
                            },
                            zIndex: 50,
                        });
                        this._jobPins.push(pin);
                    });
                },

                // Only fit on the first paint — auto-refitting on every
                // poll makes the wallboard zoom in and out every 5
                // seconds, which is nausea-inducing on a wall TV.
                fitToMarkers() {
                    if (!this.map || !window.google || this._hasFitted) return;
                    const bounds = new google.maps.LatLngBounds();
                    let any = false;
                    this._pins.forEach(p => { bounds.extend(p.getPosition()); any = true; });
                    if (any) {
                        this.map.fitBounds(bounds, 60);
                        this._hasFitted = true;
                    }
                },

                panTo(lat, lng) {
                    if (!this.map || !window.google) return;
                    this.map.panTo({ lat: Number(lat), lng: Number(lng) });
                    if (this.map.getZoom() < 12) this.map.setZoom(12);
                },

                toggleFullscreen() {
                    const el = document.documentElement;
                    if (!document.fullscreenElement) {
                        if (el.requestFullscreen) el.requestFullscreen().catch(() => {});
                    } else {
                        if (document.exitFullscreen) document.exitFullscreen().catch(() => {});
                    }
                },
            }));
        });

        // Re-render markers each time Livewire morphs the DOM after a
        // wire:poll cycle. We do this by re-reading the JSON payload off
        // the root element's x-data state, which Livewire keeps fresh.
        document.addEventListener('livewire:morph.updated', () => {
            const root = document.querySelector('[x-data^="wallboardMap"]');
            if (!root || !root._x_dataStack) return;
            const ctx = root._x_dataStack[0];
            if (!ctx) return;

            const payload = _wallboardReadPayload();
            ctx.markers = payload.markers || [];
            ctx.jobMarkers = payload.jobMarkers || [];
            if (typeof ctx.replaceMarkers === 'function') {
                ctx.replaceMarkers();
            }
        });
    </script>
</div>
