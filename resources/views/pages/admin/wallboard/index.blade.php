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
            ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->with(['driverProfile'])
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
            ->with(['pickupLocation:id,company_name,city,latitude,longitude', 'deliveryLocation:id,company_name,city,latitude,longitude'])
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
                'lat' => $r->lat,
                'lng' => $r->lng,
                'heading' => $r->heading_deg,
                'bucket' => $r->bucket,
                'speed_kmh' => $r->speed_kmh,
                'job' => $r->job ? ($r->job->job_number . ' · ' . $r->job->status_label) : null,
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
<script id="wallboard-data" type="application/json">{!! json_encode($wallboardPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<div
    wire:poll.5s
    x-data="wallboardMap()"
    x-init="bootMap()"
    class="-mx-4 -my-6 sm:-mx-6 lg:-mx-8 lg:-my-8"
>
    <x-slot:header>Operations Wallboard</x-slot:header>

    {{-- Top stats strip --}}
    <div class="px-4 sm:px-6 lg:px-8 pt-2 pb-3 border-b border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 text-xs">
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

    {{-- Three-panel grid --}}
    <div class="grid grid-cols-12 gap-0 h-[calc(100vh-9rem)]">

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

        document.addEventListener('alpine:init', () => {
            Alpine.data('wallboardMap', () => ({
                map: null,
                markers: [],
                jobMarkers: [],
                apiKey: '',
                _pins: [],
                _jobPins: [],

                bootMap() {
                    const payload = _wallboardReadPayload();
                    this.markers = payload.markers || [];
                    this.jobMarkers = payload.jobMarkers || [];
                    this.apiKey = payload.apiKey || '';
                    if (!this.apiKey) return;

                    const start = () => this.initMap();

                    if (window.google && window.google.maps) {
                        start();
                        return;
                    }

                    if (window.__wallboardMapsLoading) {
                        const t = setInterval(() => {
                            if (window.google && window.google.maps) {
                                clearInterval(t);
                                start();
                            }
                        }, 100);
                        return;
                    }

                    window.__wallboardMapsLoading = true;
                    const s = document.createElement('script');
                    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(this.apiKey);
                    s.async = true;
                    s.defer = true;
                    s.onload = () => start();
                    document.head.appendChild(s);
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
                    });
                    this.replaceMarkers();
                    this.fitToMarkers();
                },

                replaceMarkers() {
                    if (!this.map || !window.google) return;

                    this._pins.forEach(p => p.setMap(null));
                    this._pins = [];
                    this._jobPins.forEach(p => p.setMap(null));
                    this._jobPins = [];

                    this.markers.forEach((m) => {
                        if (m.lat == null || m.lng == null) return;
                        const colour = ({
                            on_job:  '#10b981',
                            idle:    '#3b82f6',
                            stale:   '#f59e0b',
                            offline: '#94a3b8',
                        })[m.bucket] || '#3b82f6';

                        const pin = new google.maps.Marker({
                            map: this.map,
                            position: { lat: Number(m.lat), lng: Number(m.lng) },
                            title: m.name + (m.job ? ' — ' + m.job : ''),
                            icon: {
                                path: google.maps.SymbolPath.CIRCLE,
                                scale: 8,
                                fillColor: colour,
                                fillOpacity: 1,
                                strokeColor: '#0f172a',
                                strokeWeight: 1.5,
                            },
                        });
                        this._pins.push(pin);
                    });

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
                        });
                        this._jobPins.push(pin);
                    });
                },

                fitToMarkers() {
                    if (!this.map || !window.google) return;
                    const bounds = new google.maps.LatLngBounds();
                    let any = false;
                    this._pins.forEach(p => { bounds.extend(p.getPosition()); any = true; });
                    if (any) this.map.fitBounds(bounds, 60);
                },

                panTo(lat, lng) {
                    if (!this.map || !window.google) return;
                    this.map.panTo({ lat: Number(lat), lng: Number(lng) });
                    if (this.map.getZoom() < 12) this.map.setZoom(12);
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

            // Re-read the freshly-emitted payload script and push it back
            // onto the live Alpine component, then replay the markers.
            const payload = _wallboardReadPayload();
            ctx.markers = payload.markers || [];
            ctx.jobMarkers = payload.jobMarkers || [];
            if (typeof ctx.replaceMarkers === 'function') {
                ctx.replaceMarkers();
            }
        });
    </script>
</div>
