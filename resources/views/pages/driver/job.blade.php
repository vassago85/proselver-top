<?php
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\JobEvent;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.driver')] class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        if ($job->driver_user_id !== auth()->id()) {
            abort(403);
        }
        $this->job = $job->load([
            'company:id,name',
            'pickupLocation:id,company_name,address',
            'deliveryLocation:id,company_name,address',
            'events',
            'documents:id,job_id,category,client_uuid,created_at',
        ]);
    }

    public function logEvent(string $eventType): void
    {
        if (!in_array($eventType, JobEvent::TYPES)) return;

        JobEvent::create([
            'job_id' => $this->job->id,
            'user_id' => auth()->id(),
            'event_type' => $eventType,
            'event_at' => now(),
            'synced_at' => now(),
            'client_uuid' => \Illuminate\Support\Str::uuid(),
        ]);

        // Legacy transitions
        if ($eventType === JobEvent::TYPE_ARRIVED_PICKUP && $this->job->status === Job::STATUS_ASSIGNED) {
            $this->job->transitionTo(Job::STATUS_IN_PROGRESS);
        }
        if ($eventType === JobEvent::TYPE_VEHICLE_READY) {
            $this->job->actual_ready_time = now();
            $this->job->save();
        }
        if ($eventType === JobEvent::TYPE_JOB_COMPLETED && $this->job->status === Job::STATUS_IN_PROGRESS) {
            $this->job->transitionTo(Job::STATUS_COMPLETED);
        }

        // Phase 1 transitions. "Arrived at pickup" flips the job to COLLECTED
        // straight from DRIVER_ASSIGNED (or the legacy READY_FOR_COLLECTION
        // state for orders booked under the previous workflow).
        if ($eventType === JobEvent::TYPE_ARRIVED_PICKUP
            && in_array($this->job->status, [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION], true)
        ) {
            $this->job->transitionTo(Job::STATUS_COLLECTED);
        }
        if ($eventType === JobEvent::TYPE_DEPARTED_PICKUP && $this->job->status === Job::STATUS_COLLECTED) {
            $this->job->transitionTo(Job::STATUS_IN_TRANSIT);
        }
        if ($eventType === JobEvent::TYPE_ARRIVED_DELIVERY && $this->job->status === Job::STATUS_IN_TRANSIT) {
            $this->job->transitionTo(Job::STATUS_DELIVERED);
        }
        if ($eventType === JobEvent::TYPE_JOB_COMPLETED && $this->job->status === Job::STATUS_DELIVERED) {
            $this->job->transitionTo(Job::STATUS_COMPLETED);
        }

        $this->job->refresh()->load('events', 'documents');
        session()->flash('success', ucfirst(str_replace('_', ' ', $eventType)) . ' logged.');
    }

    public function with(): array
    {
        $loggedTypes = $this->job->events->pluck('event_type')->toArray();

        // Pre-count server-side documents by category so the capture tiles
        // know whether the photo already made it up and can stop nagging.
        $uploaded = $this->job->documents
            ->groupBy('category')
            ->map(fn ($c) => $c->count())
            ->toArray();

        return [
            'loggedTypes' => $loggedTypes,
            'uploadedCounts' => $uploaded,
            'pettyCashCategories' => JobDocument::pettyCashCategories(),
        ];
    }
}; ?>

<div x-data="driverJobCapture({{ $job->id }})" x-init="init()">
    <x-slot:header>Job {{ $job->job_number }}</x-slot:header>

    {{-- Summary card --}}
    <section class="mt-3 rounded-xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-slate-900 truncate">{{ $job->company?->name }}</h3>
            <x-status-badge :status="$job->status" />
        </div>

        @if($job->isTransport())
        <div class="mt-3 text-sm text-slate-700">
            <p class="font-semibold text-slate-900">{{ $job->pickupLocation?->company_name ?? '—' }}</p>
            <p class="text-xs text-slate-500">{{ $job->pickupLocation?->address }}</p>
            <div class="my-2 text-slate-300">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
            </div>
            <p class="font-semibold text-slate-900">{{ $job->deliveryLocation?->company_name ?? '—' }}</p>
            <p class="text-xs text-slate-500">{{ $job->deliveryLocation?->address }}</p>
        </div>
        @endif

        <div class="mt-3 text-xs text-slate-500 flex items-center justify-between">
            <span>{{ $job->scheduled_date?->format('D, d M Y') }}</span>
            @if($job->vin)
                <span class="font-mono">VIN {{ substr($job->vin, -8) }}</span>
            @endif
        </div>
    </section>

    {{-- Special Instructions — surfaced BEFORE collection so the driver cannot
         miss things like "deliver with a full tank" or "collect spare keys".   --}}
    @if(trim($job->customer_notes ?? '') !== '')
    <section class="mt-4 rounded-xl border-2 border-amber-400 bg-amber-50 p-4">
        <div class="flex items-start gap-3">
            <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-bold text-amber-900 uppercase tracking-wide">Special Instructions</h3>
                <p class="mt-1 text-sm text-amber-900 leading-snug whitespace-pre-line">{{ $job->customer_notes }}</p>
            </div>
        </div>
    </section>
    @endif

    {{-- =============================================================== --}}
    {{-- PICKUP CAPTURE                                                   --}}
    {{-- =============================================================== --}}
    @php
        $isPickupPhase = in_array($job->status, [
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_ASSIGNED,
        ]);
        // 4 body angles + dashboard (fuel/odo) + data plate (VIN).
        // Dashboard and data plate flagged with category so the admin UI
        // can surface them distinctly even without the slot tag.
        $pickupTiles = [
            ['slot' => 'pickup_front',      'label' => 'Front',      'category' => 'photo'],
            ['slot' => 'pickup_rear',       'label' => 'Rear',       'category' => 'photo'],
            ['slot' => 'pickup_left',       'label' => 'Left side',  'category' => 'photo'],
            ['slot' => 'pickup_right',      'label' => 'Right side', 'category' => 'photo'],
            ['slot' => 'pickup_dashboard',  'label' => 'Dashboard',  'category' => 'dashboard', 'hint' => 'Fuel + odo'],
            ['slot' => 'pickup_data_plate', 'label' => 'Data plate', 'category' => 'data_plate', 'hint' => 'VIN plate'],
        ];
    @endphp

    @if($isPickupPhase || in_array(JobEvent::TYPE_ARRIVED_PICKUP, $loggedTypes))
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">Collection</h3>
            <span class="text-[11px] text-slate-500">4 angles + dashboard + data plate + collection note</span>
        </div>

        <div class="grid grid-cols-2 gap-2">
            @foreach($pickupTiles as $tile)
                <template x-if="true">
                    <div>
                        <label class="block rounded-lg border-2 border-dashed cursor-pointer transition aspect-square flex flex-col items-center justify-center gap-1"
                               :class="hasCategoryFor('{{ $tile['slot'] }}') ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-500'">
                            <input type="file" accept="image/*" capture="environment"
                                   class="sr-only"
                                   @change="capture($event, { category: '{{ $tile['category'] ?? 'photo' }}', slotTag: '{{ $tile['slot'] }}' })">
                            <svg x-show="!hasCategoryFor('{{ $tile['slot'] }}')" viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <svg x-show="hasCategoryFor('{{ $tile['slot'] }}')" x-cloak viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            <span class="text-xs font-semibold">{{ $tile['label'] }}</span>
                            @if(!empty($tile['hint']))
                                <span class="text-[10px] text-slate-400 uppercase tracking-wide">{{ $tile['hint'] }}</span>
                            @endif
                        </label>
                    </div>
                </template>
            @endforeach
        </div>

        <label class="mt-3 block rounded-lg border-2 border-dashed cursor-pointer px-4 py-3 flex items-center gap-3"
               :class="hasCategoryFor('collection_note') ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-600'">
            <input type="file" accept="image/*,application/pdf" capture="environment"
                   class="sr-only"
                   @change="capture($event, { category: 'collection_note', slotTag: 'collection_note' })">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="text-sm font-semibold">Collection note (signed)</span>
            <span x-show="hasCategoryFor('collection_note')" x-cloak class="ml-auto text-xs">Captured</span>
        </label>

        <div class="mt-4 grid grid-cols-2 gap-2">
            @if(!in_array(JobEvent::TYPE_ARRIVED_PICKUP, $loggedTypes))
                <button type="button"
                        wire:click="logEvent('arrived_pickup')"
                        wire:confirm="Mark as Driver Arrived at Pickup Location?"
                        :disabled="!canArrivePickup()"
                        :class="canArrivePickup() ? 'bg-blue-600 hover:bg-blue-500' : 'bg-slate-300'"
                        class="col-span-2 rounded-lg text-white text-sm font-semibold py-3 transition">
                    Arrived at pickup location
                </button>
            @elseif(!in_array(JobEvent::TYPE_DEPARTED_PICKUP, $loggedTypes))
                <button type="button"
                        wire:click="logEvent('departed_pickup')"
                        wire:confirm="Mark as Departed Pickup?"
                        :disabled="!canDepartPickup()"
                        :class="canDepartPickup() ? 'bg-blue-600 hover:bg-blue-500' : 'bg-slate-300'"
                        class="col-span-2 rounded-lg text-white text-sm font-semibold py-3 transition">
                    Departed pickup
                </button>
            @endif
        </div>
        <p x-show="!canDepartPickup() && !hasAllPickupPhotos()" x-cloak class="mt-2 text-[11px] text-amber-700 text-center">
            Capture all 4 angles + dashboard + data plate + collection note before continuing.
        </p>
    </section>
    @endif

    {{-- =============================================================== --}}
    {{-- DELIVERY CAPTURE                                                 --}}
    {{-- =============================================================== --}}
    @php
        $isDeliveryPhase = in_array($job->status, [
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_DELIVERED,
        ]);
        $deliveryTiles = [
            ['slot' => 'delivery_front',     'label' => 'Vehicle at delivery (1)', 'category' => 'photo'],
            ['slot' => 'delivery_other',     'label' => 'Vehicle at delivery (2)', 'category' => 'photo'],
            ['slot' => 'delivery_dashboard', 'label' => 'Dashboard',                'category' => 'dashboard', 'hint' => 'Fuel + odo'],
        ];
    @endphp

    @if($isDeliveryPhase)
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">Delivery</h3>
            <span class="text-[11px] text-slate-500">POD + 2 photos + dashboard</span>
        </div>

        <label class="block rounded-lg border-2 border-dashed cursor-pointer px-4 py-3 flex items-center gap-3 mb-2"
               :class="hasCategoryFor('proof_of_delivery') ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-600'">
            <input type="file" accept="image/*,application/pdf" capture="environment"
                   class="sr-only"
                   @change="capture($event, { category: 'proof_of_delivery', slotTag: 'proof_of_delivery' })">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span class="text-sm font-semibold">Proof of delivery (signed)</span>
            <span x-show="hasCategoryFor('proof_of_delivery')" x-cloak class="ml-auto text-xs">Captured</span>
        </label>

        <div class="grid grid-cols-2 gap-2">
            @foreach($deliveryTiles as $tile)
                <label class="block rounded-lg border-2 border-dashed cursor-pointer aspect-square flex flex-col items-center justify-center gap-1"
                       :class="hasCategoryFor('{{ $tile['slot'] }}') ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-500'">
                    <input type="file" accept="image/*" capture="environment"
                           class="sr-only"
                           @change="capture($event, { category: '{{ $tile['category'] ?? 'photo' }}', slotTag: '{{ $tile['slot'] }}' })">
                    <svg x-show="!hasCategoryFor('{{ $tile['slot'] }}')" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <svg x-show="hasCategoryFor('{{ $tile['slot'] }}')" x-cloak viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <span class="text-[11px] font-semibold">{{ $tile['label'] }}</span>
                    @if(!empty($tile['hint']))
                        <span class="text-[10px] text-slate-400 uppercase tracking-wide">{{ $tile['hint'] }}</span>
                    @endif
                </label>
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 gap-2">
            @if(!in_array(JobEvent::TYPE_ARRIVED_DELIVERY, $loggedTypes) && $job->status === Job::STATUS_IN_TRANSIT)
                <button type="button"
                        wire:click="logEvent('arrived_delivery')"
                        wire:confirm="Mark as Arrived at Delivery?"
                        class="rounded-lg bg-blue-600 text-white text-sm font-semibold py-3">
                    Arrived at delivery
                </button>
            @elseif($job->status === Job::STATUS_DELIVERED)
                <button type="button"
                        wire:click="logEvent('job_completed')"
                        wire:confirm="Complete this job?"
                        :disabled="!canComplete()"
                        :class="canComplete() ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-300'"
                        class="rounded-lg text-white text-sm font-semibold py-3 transition">
                    Complete job
                </button>
                <p x-show="!canComplete()" x-cloak class="text-[11px] text-amber-700 text-center">
                    Capture POD + 2 delivery photos + dashboard to complete.
                </p>
            @endif
        </div>
    </section>
    @endif

    {{-- =============================================================== --}}
    {{-- DAMAGE CAPTURE (always available)                                --}}
    {{-- =============================================================== --}}
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-3">Damage / Incident</h3>
        <label class="block rounded-lg border-2 border-dashed border-rose-300 bg-rose-50 cursor-pointer px-4 py-4 flex items-center gap-3 text-rose-700">
            <input type="file" accept="image/*" capture="environment"
                   class="sr-only"
                   @change="captureWithNote($event, 'damage_photo')">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            <span class="text-sm font-semibold">Photograph damage</span>
            <span class="ml-auto text-[11px]">Adds note</span>
        </label>
        <p x-show="damageCaptured > 0" x-cloak class="mt-2 text-[11px] text-slate-500" x-text="damageCaptured + ' damage photo(s) captured'"></p>
    </section>

    {{-- =============================================================== --}}
    {{-- PETTY CASH                                                       --}}
    {{-- =============================================================== --}}
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">Petty cash / slips</h3>
            <span class="text-[11px] text-slate-500">Photo + category</span>
        </div>

        <div class="grid grid-cols-3 gap-2" x-data="{ chosen: null }">
            @php
                $pettyMap = [
                    'fuel_slip' => ['label' => 'Fuel', 'icon' => 'M3 22h12V7l-3-4H6L3 7v15z M9 10h3M9 14h3M9 18h3'],
                    'food_slip' => ['label' => 'Food', 'icon' => 'M17 8c0-2-2-4-5-4S7 6 7 8m-2 0h14v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8z'],
                    'toll_slip' => ['label' => 'Toll', 'icon' => 'M2 12h20M6 8h12M6 16h12'],
                    'parking_slip' => ['label' => 'Parking', 'icon' => 'M5 3h9a5 5 0 0 1 0 10h-4v8H5z M10 7v6h4a3 3 0 0 0 0-6z'],
                    'other' => ['label' => 'Other', 'icon' => 'M3 7h18M3 12h18M3 17h18'],
                ];
            @endphp

            @foreach($pettyMap as $category => $meta)
            <label class="block rounded-lg border border-slate-200 cursor-pointer px-2 py-3 flex flex-col items-center gap-1 text-slate-600 hover:bg-slate-50">
                <input type="file" accept="image/*" capture="environment"
                       class="sr-only"
                       @change="capture($event, { category: '{{ $category }}' })">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $meta['icon'] }}"/></svg>
                <span class="text-[11px] font-semibold">{{ $meta['label'] }}</span>
            </label>
            @endforeach
        </div>
        <p x-show="pettyCashCaptured > 0" x-cloak class="mt-2 text-[11px] text-slate-500" x-text="pettyCashCaptured + ' slip(s) captured today'"></p>
    </section>

    {{-- =============================================================== --}}
    {{-- TIMELINE                                                         --}}
    {{-- =============================================================== --}}
    @if($job->events->isNotEmpty())
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-2">Timeline</h3>
        <ol class="divide-y divide-slate-100">
            @foreach($job->events->sortByDesc('event_at') as $event)
            <li class="flex justify-between items-center py-2 text-sm">
                <span class="text-slate-800">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                <time class="text-xs text-slate-500">{{ $event->event_at->format('H:i') }}</time>
            </li>
            @endforeach
        </ol>
    </section>
    @endif
</div>

<script>
    // Tracks capture state for THIS job by tagging queue items with slotTag in notes.
    // "Captured" means: either already uploaded server-side OR sitting in the IndexedDB queue.
    function driverJobCapture(jobId) {
        return {
            jobId,
            capturedSlots: new Set(),
            damageCaptured: 0,
            pettyCashCaptured: 0,
            uploadedServerSide: @json($uploadedCounts ?? []),

            async init() {
                await this.refresh();
                window.addEventListener('driver-capture-enqueued', () => this.refresh());
                window.addEventListener('driver-queue-changed', () => this.refresh());
            },

            async refresh() {
                const items = await window.driverCapture.listByJob(this.jobId);
                this.capturedSlots = new Set();
                this.damageCaptured = 0;
                this.pettyCashCaptured = 0;

                for (const it of items) {
                    const slot = it.metadata?.notes && it.metadata.notes.startsWith('slot:')
                        ? it.metadata.notes.slice(5)
                        : null;
                    if (slot) this.capturedSlots.add(slot);
                    if (it.category === 'damage_photo') this.damageCaptured++;
                    if (['fuel_slip','food_slip','toll_slip','parking_slip','other'].includes(it.category)) {
                        this.pettyCashCaptured++;
                    }
                }

                // Server-side uploaded items count as captured for pickup/delivery gating
                // (server-side ones don't have slot tags, so we fall back to category counts).
                // Note: we only know category totals from the server hydrate, not per-slot,
                // so the mapping below is a best-effort assumption: if 4+ generic photos
                // landed it's fair to assume all 4 angles are done.
                const pickupPhotoCount = this.uploadedServerSide.photo || 0;
                if (pickupPhotoCount >= 4) {
                    ['pickup_front','pickup_rear','pickup_left','pickup_right'].forEach(s => this.capturedSlots.add(s));
                }
                // Dashboard is captured at BOTH pickup and delivery under the same
                // `dashboard` category. If 1 exists it's almost certainly pickup
                // (captured first); if 2+ exist the delivery one is in there too.
                const dashboardCount = this.uploadedServerSide.dashboard || 0;
                if (dashboardCount >= 1) this.capturedSlots.add('pickup_dashboard');
                if (dashboardCount >= 2) this.capturedSlots.add('delivery_dashboard');
                if ((this.uploadedServerSide.data_plate || 0) > 0) {
                    this.capturedSlots.add('pickup_data_plate');
                }
                if ((this.uploadedServerSide.collection_note || 0) > 0) {
                    this.capturedSlots.add('collection_note');
                }
                if ((this.uploadedServerSide.proof_of_delivery || 0) > 0) {
                    this.capturedSlots.add('proof_of_delivery');
                }
            },

            hasCategoryFor(slotTag) {
                return this.capturedSlots.has(slotTag);
            },

            hasAllPickupPhotos() {
                return ['pickup_front','pickup_rear','pickup_left','pickup_right',
                        'pickup_dashboard','pickup_data_plate','collection_note']
                    .every(s => this.capturedSlots.has(s));
            },

            canArrivePickup() {
                return true;
            },
            canDepartPickup() {
                return this.hasAllPickupPhotos();
            },
            canComplete() {
                return this.capturedSlots.has('proof_of_delivery')
                    && this.capturedSlots.has('delivery_front')
                    && this.capturedSlots.has('delivery_other')
                    && this.capturedSlots.has('delivery_dashboard');
            },

            async capture(event, { category, slotTag = null }) {
                const input = event.target;
                const notes = slotTag ? `slot:${slotTag}` : null;
                await window.driverCapture.fromInput({
                    input,
                    jobId: this.jobId,
                    category,
                    notes,
                });
                await this.refresh();
            },

            async captureWithNote(event, category) {
                const note = prompt('Short note about the damage (optional)') || null;
                const input = event.target;
                await window.driverCapture.fromInput({
                    input,
                    jobId: this.jobId,
                    category,
                    notes: note,
                });
                await this.refresh();
            },
        };
    }
</script>
