<?php
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\JobEvent;
use App\Models\PettyCashEntry;
use App\Services\ImageNormalizer;
use App\Support\StorageDisk;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.driver')] class extends Component {
    use WithFileUploads;

    public Job $job;

    /** @var string Tab id; one of: steps, photos, damage, expenses, timeline */
    public string $tab = 'steps';

    // ---- Petty cash form state ----------------------------------------
    public string $expenseCategory = JobDocument::CATEGORY_FUEL_SLIP;
    public ?string $expenseAmount = null;        // ZAR string (e.g. "152.50")
    public ?string $expenseMerchant = null;
    public ?string $expenseDescription = null;
    public ?string $expenseSpentAt = null;       // YYYY-MM-DD
    public $expensePhoto = null;                  // Livewire upload
    public ?string $expenseOcrText = null;        // best-effort, set client-side
    public ?string $expenseOcrAmount = null;      // client OCR suggestion
    public ?string $expenseOcrConfidence = null;  // 0–100

    // ---- Damage form state --------------------------------------------
    public string $damageSeverity = 'medium';
    public ?string $damageLocation = null;        // e.g. "front bumper, driver side"
    public ?string $damageNotes = null;
    public $damagePhoto = null;

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
            'documents:id,job_id,category,client_uuid,notes,created_at',
        ]);
        $this->expenseSpentAt = now()->toDateString();
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
            'client_uuid' => Str::uuid(),
        ]);

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

    /**
     * Submit a petty cash entry. Photo + structured amount land in one
     * round trip. Each successful submission creates a JobDocument
     * (the slip photo) and a PettyCashEntry (the financial row).
     *
     * The form intentionally does a synchronous upload rather than
     * piggy-backing on the offline IndexedDB queue used for vehicle
     * photos: amount + photo together is what ops need to triage,
     * and a half-arrived entry without a photo is worse than a clean
     * "please retry online".
     */
    public function submitExpense(): void
    {
        $validated = $this->validate([
            'expenseCategory' => 'required|in:' . implode(',', JobDocument::pettyCashCategories()),
            'expenseAmount' => 'required|numeric|min:0.01|max:99999.99',
            'expenseMerchant' => 'nullable|string|max:120',
            'expenseDescription' => 'nullable|string|max:500',
            'expenseSpentAt' => 'nullable|date|before_or_equal:today',
            'expensePhoto' => 'required|image|max:10240',
            'expenseOcrText' => 'nullable|string|max:5000',
            'expenseOcrAmount' => 'nullable|numeric|min:0|max:99999.99',
            'expenseOcrConfidence' => 'nullable|numeric|min:0|max:100',
        ], [
            'expenseAmount.required' => 'Enter the rand amount on the slip.',
            'expensePhoto.required' => 'Photograph the slip.',
        ]);

        $file = $this->expensePhoto;
        // Best-effort EXIF strip + downscale (defensive — service does
        // its own try/catch so a corrupted image still uploads).
        try {
            app(ImageNormalizer::class)->normalise($file);
        } catch (\Throwable $e) { /* noop */ }

        $disk = StorageDisk::forUploads();
        $path = $file->store('jobs/' . $this->job->uuid . '/documents', $disk);

        $realPath = $file->getRealPath();
        $mime = $realPath && is_file($realPath) ? (@mime_content_type($realPath) ?: $file->getMimeType()) : $file->getMimeType();
        $sizeBytes = $realPath && is_file($realPath) ? (@filesize($realPath) ?: $file->getSize()) : $file->getSize();

        $document = JobDocument::create([
            'job_id' => $this->job->id,
            'uploaded_by_user_id' => auth()->id(),
            'category' => $validated['expenseCategory'],
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'client_uuid' => Str::uuid(),
            'captured_at' => now(),
            'notes' => 'petty_cash',
        ]);

        PettyCashEntry::create([
            'job_id' => $this->job->id,
            'driver_user_id' => auth()->id(),
            'document_id' => $document->id,
            'category' => $validated['expenseCategory'],
            'amount_cents' => (int) round(((float) $validated['expenseAmount']) * 100),
            'merchant_name' => $validated['expenseMerchant'] ?? null,
            'description' => $validated['expenseDescription'] ?? null,
            'spent_at' => $validated['expenseSpentAt'] ?? null,
            'ocr_text' => $validated['expenseOcrText'] ?? null,
            'ocr_amount_cents' => isset($validated['expenseOcrAmount'])
                ? (int) round(((float) $validated['expenseOcrAmount']) * 100)
                : null,
            'ocr_confidence' => $validated['expenseOcrConfidence'] ?? null,
        ]);

        $this->reset(['expenseAmount', 'expenseMerchant', 'expenseDescription', 'expensePhoto', 'expenseOcrText', 'expenseOcrAmount', 'expenseOcrConfidence']);
        $this->expenseSpentAt = now()->toDateString();
        $this->expenseCategory = JobDocument::CATEGORY_FUEL_SLIP;

        $this->job->refresh()->load('documents');

        session()->flash('success', 'Expense submitted for review.');
    }

    /**
     * Submit a structured damage report. Stores severity + location +
     * notes JSON-encoded in the JobDocument's notes column so we don't
     * need a separate table for what is, in essence, an annotated photo.
     */
    public function submitDamage(): void
    {
        $validated = $this->validate([
            'damageSeverity' => 'required|in:low,medium,high',
            'damageLocation' => 'required|string|max:120',
            'damageNotes' => 'nullable|string|max:1000',
            'damagePhoto' => 'required|image|max:10240',
        ], [
            'damageLocation.required' => 'Describe where on the vehicle (e.g. "front bumper").',
            'damagePhoto.required' => 'Photograph the damage.',
        ]);

        $file = $this->damagePhoto;
        try { app(ImageNormalizer::class)->normalise($file); } catch (\Throwable $e) { /* noop */ }

        $disk = StorageDisk::forUploads();
        $path = $file->store('jobs/' . $this->job->uuid . '/documents', $disk);

        $realPath = $file->getRealPath();
        $mime = $realPath && is_file($realPath) ? (@mime_content_type($realPath) ?: $file->getMimeType()) : $file->getMimeType();
        $sizeBytes = $realPath && is_file($realPath) ? (@filesize($realPath) ?: $file->getSize()) : $file->getSize();

        // Structured damage payload stuffed into notes. Keeps the
        // database schema unchanged; admin renderers can JSON-decode.
        $payload = json_encode([
            'kind' => 'damage_report',
            'severity' => $validated['damageSeverity'],
            'location' => $validated['damageLocation'],
            'notes' => $validated['damageNotes'] ?? null,
        ], JSON_UNESCAPED_SLASHES);

        JobDocument::create([
            'job_id' => $this->job->id,
            'uploaded_by_user_id' => auth()->id(),
            'category' => JobDocument::CATEGORY_DAMAGE_PHOTO,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'client_uuid' => Str::uuid(),
            'captured_at' => now(),
            'notes' => $payload,
        ]);

        $this->reset(['damageLocation', 'damageNotes', 'damagePhoto']);
        $this->damageSeverity = 'medium';
        $this->job->refresh()->load('documents');

        session()->flash('success', 'Damage report saved.');
    }

    public function with(): array
    {
        $loggedTypes = $this->job->events->pluck('event_type')->toArray();
        $uploaded = $this->job->documents
            ->groupBy('category')
            ->map(fn ($c) => $c->count())
            ->toArray();

        $entriesForJob = PettyCashEntry::with(['document:id,disk,path,mime_type', 'approver:id,name'])
            ->where('job_id', $this->job->id)
            ->where('driver_user_id', auth()->id())
            ->orderByDesc('id')
            ->get();

        $entryTotal = $entriesForJob
            ->whereIn('status', [PettyCashEntry::STATUS_SUBMITTED, PettyCashEntry::STATUS_APPROVED, PettyCashEntry::STATUS_REIMBURSED])
            ->sum('amount_cents');

        return [
            'loggedTypes' => $loggedTypes,
            'uploadedCounts' => $uploaded,
            'pettyCashCategories' => JobDocument::pettyCashCategories(),
            'expenseCategoryLabels' => PettyCashEntry::categories(),
            'entriesForJob' => $entriesForJob,
            'entryTotalCents' => $entryTotal,
        ];
    }
}; ?>

<div x-data="driverJobCapture({{ $job->id }})" x-init="init()">
    <x-slot:header>Job {{ $job->job_number }}</x-slot:header>

    {{-- ============================================================
         SUMMARY (always visible — context for whichever tab the
         driver is on)
         ============================================================ --}}
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

        {{-- Download paperwork — PDF the driver can pull on-device
             before leaving the depot if it wasn't printed for them.
             Available the moment a driver is assigned. Gate matches
             JobPolicy::generateCollectionNote. --}}
        @can('generateCollectionNote', $job)
            <div class="mt-3 border-t border-slate-100 pt-3">
                <a href="{{ route('collection-note.download', $job) }}" target="_blank"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 active:bg-slate-100">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    {{ $job->executor_type === \App\Models\Job::EXECUTOR_PROSELVER ? 'Collection Note PDF' : 'Delivery Note PDF' }}
                </a>
                <p class="mt-1 text-[11px] text-slate-500">5-page paperwork &middot; print before you leave, get it signed at pickup &amp; delivery.</p>
            </div>
        @endcan
    </section>

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

    {{-- ============================================================
         TABS — Steps · Photos · Damage · Expenses · Timeline
         Sticky strip below the summary so the driver can flip
         contexts without scrolling back to the top.
         ============================================================ --}}
    <nav class="mt-4 sticky top-0 z-10 bg-slate-50/95 backdrop-blur supports-[backdrop-filter]:bg-slate-50/70 border-b border-slate-200 -mx-4 px-4">
        <div class="flex gap-1 overflow-x-auto py-1.5 text-[12px] font-semibold uppercase tracking-wide">
            @php
                $tabs = [
                    ['id' => 'steps',    'label' => 'Steps'],
                    ['id' => 'photos',   'label' => 'Photos'],
                    ['id' => 'damage',   'label' => 'Damage'],
                    ['id' => 'expenses', 'label' => 'Expenses'],
                    ['id' => 'timeline', 'label' => 'Timeline'],
                ];
            @endphp
            @foreach($tabs as $t)
                <button type="button"
                        wire:click="$set('tab', '{{ $t['id'] }}')"
                        @class([
                            'shrink-0 rounded-full px-3 py-1.5 transition',
                            'bg-blue-600 text-white shadow-sm' => $tab === $t['id'],
                            'text-slate-600 hover:bg-slate-100' => $tab !== $t['id'],
                        ])>
                    {{ $t['label'] }}
                </button>
            @endforeach
        </div>
    </nav>

    {{-- ============================================================
         STEPS TAB — pickup + delivery capture flow
         ============================================================ --}}
    @if($tab === 'steps')
    @php
        $isPickupPhase = in_array($job->status, [
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_ASSIGNED,
        ]);
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
    @endif

    {{-- ============================================================
         PHOTOS TAB — quick visual roll-up of everything captured
         ============================================================ --}}
    @if($tab === 'photos')
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-3">Captured this job</h3>
        @if($job->documents->isEmpty())
            <p class="text-sm text-slate-500">No photos uploaded yet. Use the <strong>Steps</strong> tab.</p>
        @else
            <div class="grid grid-cols-3 gap-2">
                @foreach($job->documents->sortByDesc('created_at') as $doc)
                    <a href="{{ route('document.view', $doc) }}" target="_blank" class="block aspect-square rounded-lg overflow-hidden bg-slate-100 border border-slate-200 hover:border-blue-300 group">
                        @if(str_starts_with((string) $doc->mime_type, 'image/'))
                            <img src="{{ route('document.view', $doc) }}" alt="" class="h-full w-full object-cover group-hover:opacity-90">
                        @else
                            <div class="h-full w-full flex items-center justify-center text-slate-400 text-[10px] uppercase tracking-wide">{{ $doc->original_filename }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] text-slate-400 text-center">{{ $job->documents->count() }} file(s)</p>
        @endif
    </section>
    @endif

    {{-- ============================================================
         DAMAGE TAB — structured incident report
         ============================================================ --}}
    @if($tab === 'damage')
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-3">Report damage</h3>

        @if (session('success'))
            <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <form wire:submit.prevent="submitDamage" class="space-y-3">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Severity</label>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $lbl)
                        <button type="button"
                                wire:click="$set('damageSeverity', '{{ $val }}')"
                                @class([
                                    'rounded-lg border px-3 py-2 text-sm font-semibold',
                                    'border-rose-500 bg-rose-50 text-rose-700' => $damageSeverity === $val && $val === 'high',
                                    'border-amber-500 bg-amber-50 text-amber-700' => $damageSeverity === $val && $val === 'medium',
                                    'border-blue-500 bg-blue-50 text-blue-700' => $damageSeverity === $val && $val === 'low',
                                    'border-slate-200 bg-white text-slate-600' => $damageSeverity !== $val,
                                ])>
                            {{ $lbl }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Where on the vehicle?</label>
                <input type="text" wire:model="damageLocation" placeholder='e.g. "front bumper, driver side"'
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                @error('damageLocation') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Notes (optional)</label>
                <textarea wire:model="damageNotes" rows="2" placeholder="What happened?"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <label class="block rounded-lg border-2 border-dashed border-rose-300 bg-rose-50 cursor-pointer px-4 py-4 flex items-center gap-3 text-rose-700">
                <input type="file" accept="image/*" capture="environment" wire:model="damagePhoto" class="sr-only">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <span class="text-sm font-semibold">@if($damagePhoto) Photo selected @else Photograph the damage @endif</span>
            </label>
            @error('damagePhoto') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <button type="submit"
                    wire:loading.attr="disabled"
                    class="w-full rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-sm font-semibold py-3 transition disabled:opacity-50">
                <span wire:loading.remove>Submit damage report</span>
                <span wire:loading>Uploading…</span>
            </button>
        </form>

        @php
            $damageDocs = $job->documents->where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)->sortByDesc('created_at');
        @endphp
        @if($damageDocs->isNotEmpty())
            <h4 class="mt-5 mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Reported on this job</h4>
            <ul class="divide-y divide-slate-100">
                @foreach($damageDocs as $d)
                    @php
                        $payload = null;
                        $notes = (string) ($d->notes ?? '');
                        if (str_starts_with($notes, '{')) { $payload = json_decode($notes, true); }
                    @endphp
                    <li class="py-2 flex items-start gap-3">
                        <a href="{{ route('document.view', $d) }}" target="_blank" class="block h-12 w-12 shrink-0 rounded bg-slate-100 overflow-hidden">
                            @if(str_starts_with((string) $d->mime_type, 'image/'))
                                <img src="{{ route('document.view', $d) }}" class="h-full w-full object-cover">
                            @endif
                        </a>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-semibold text-slate-900">
                                @if($payload && isset($payload['location'])) {{ $payload['location'] }}
                                @else Damage @endif
                            </p>
                            @if($payload && isset($payload['severity']))
                                <p class="text-[11px] uppercase tracking-wide
                                    @if($payload['severity'] === 'high') text-rose-600
                                    @elseif($payload['severity'] === 'medium') text-amber-600
                                    @else text-blue-600 @endif">{{ $payload['severity'] }}</p>
                            @endif
                            @if($payload['notes'] ?? null)
                                <p class="text-xs text-slate-600 mt-0.5">{{ $payload['notes'] }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @endif

    {{-- ============================================================
         EXPENSES TAB — petty cash submission + history for this job
         ============================================================ --}}
    @if($tab === 'expenses')
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4"
             x-data="pettyCashOcr()" x-init="bind($wire)">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-3">Add expense</h3>

        @if (session('success'))
            <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <form wire:submit.prevent="submitExpense" class="space-y-3">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Category</label>
                <select wire:model="expenseCategory" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach($pettyCashCategories as $cat)
                        @php
                            $label = $expenseCategoryLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat));
                        @endphp
                        <option value="{{ $cat }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <label class="block rounded-lg border-2 border-dashed border-amber-300 bg-amber-50 cursor-pointer px-4 py-4 flex items-center gap-3 text-amber-800">
                <input type="file" accept="image/*" capture="environment"
                       wire:model="expensePhoto"
                       @change="onPhotoChosen($event)"
                       class="sr-only">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <span class="text-sm font-semibold">
                    @if($expensePhoto) Slip captured @else Photograph the slip @endif
                </span>
                <span x-show="ocrBusy" x-cloak class="ml-auto text-[11px] font-medium">Reading slip…</span>
            </label>
            @error('expensePhoto') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Amount (R)</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">R</span>
                    <input type="number" inputmode="decimal" step="0.01" min="0.01"
                           wire:model="expenseAmount"
                           placeholder="0.00"
                           class="w-full rounded-lg border border-slate-300 pl-7 pr-3 py-2 text-sm" />
                </div>
                @error('expenseAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <template x-if="ocrSuggestion">
                    <p class="mt-1 text-[11px] text-blue-700">
                        Detected on slip:
                        <button type="button" class="underline font-semibold" @click="$wire.set('expenseAmount', ocrSuggestion); ocrSuggestion = null">R <span x-text="ocrSuggestion"></span> — apply</button>
                    </p>
                </template>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Date</label>
                    <input type="date" wire:model="expenseSpentAt" max="{{ now()->toDateString() }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Merchant</label>
                    <input type="text" wire:model="expenseMerchant" placeholder="Engen, Wimpy, …"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Notes (optional)</label>
                <textarea wire:model="expenseDescription" rows="2"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="submitExpense, expensePhoto"
                    class="w-full rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold py-3 transition disabled:opacity-50">
                <span wire:loading.remove wire:target="submitExpense, expensePhoto">Submit expense</span>
                <span wire:loading wire:target="submitExpense, expensePhoto">Uploading…</span>
            </button>
        </form>

        @if($entriesForJob->isNotEmpty())
            <div class="mt-5 pt-4 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">On this job</h4>
                    <span class="text-xs text-slate-700">Total <strong>R {{ number_format($entryTotalCents / 100, 2) }}</strong></span>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach($entriesForJob as $entry)
                        <li class="py-2 flex items-start gap-3">
                            <div class="h-12 w-12 shrink-0 rounded bg-slate-100 overflow-hidden">
                                @if($entry->document && str_starts_with((string) $entry->document->mime_type, 'image/'))
                                    <a href="{{ route('document.view', $entry->document) }}" target="_blank">
                                        <img src="{{ route('document.view', $entry->document) }}" class="h-full w-full object-cover">
                                    </a>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1 text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-semibold text-slate-900">{{ $entry->amountForDisplay() }}</p>
                                    <span class="text-[10px] uppercase tracking-wide font-semibold rounded-full border px-2 py-0.5 {{ $entry->statusBadgeClasses() }}">{{ $entry->statusLabel() }}</span>
                                </div>
                                <p class="text-[11px] text-slate-500">
                                    {{ $entry->categoryLabel() }}
                                    @if($entry->merchant_name) · {{ $entry->merchant_name }} @endif
                                    @if($entry->spent_at) · {{ $entry->spent_at->format('d M') }} @endif
                                </p>
                                @if($entry->status === PettyCashEntry::STATUS_REJECTED && $entry->rejection_reason)
                                    <p class="text-[11px] text-rose-600 mt-0.5">{{ $entry->rejection_reason }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
    @endif

    {{-- ============================================================
         TIMELINE TAB
         ============================================================ --}}
    @if($tab === 'timeline')
    <section class="mt-4 rounded-xl bg-white border border-slate-200 p-4">
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-2">Timeline</h3>
        @if($job->events->isEmpty())
            <p class="text-sm text-slate-500">No events logged yet.</p>
        @else
            <ol class="divide-y divide-slate-100">
                @foreach($job->events->sortByDesc('event_at') as $event)
                <li class="flex justify-between items-center py-2 text-sm">
                    <span class="text-slate-800">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                    <time class="text-xs text-slate-500">{{ $event->event_at->format('D d M H:i') }}</time>
                </li>
                @endforeach
            </ol>
        @endif
    </section>
    @endif
</div>

<script>
    // ----- existing offline photo capture (vehicle / POD / etc.) -----
    function driverJobCapture(jobId) {
        return {
            jobId,
            capturedSlots: {},
            damageCaptured: 0,
            pettyCashCaptured: 0,

            async init() {
                await this.refresh();
                window.addEventListener('driver-capture-enqueued', () => this.refresh());
                window.addEventListener('driver-queue-changed', () => this.refresh());

                let ticks = 0;
                const poll = setInterval(() => {
                    this.refresh();
                    if (++ticks >= 12) clearInterval(poll);
                }, 10000);
            },

            async refresh() {
                let items = [];
                try { items = await window.driverCapture.listByJob(this.jobId); } catch (e) { /* noop */ }

                let serverSlots  = [];
                let serverCounts = {};
                try {
                    const resp = await fetch(`/driver/api/jobs/${this.jobId}/documents/summary`, {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (resp.ok) {
                        const data = await resp.json();
                        serverSlots  = Array.isArray(data.slots)  ? data.slots  : [];
                        serverCounts = data.counts && typeof data.counts === 'object' ? data.counts : {};
                    }
                } catch (e) { /* offline */ }

                const next = {};
                let damage = 0;
                let petty  = 0;

                for (const slot of serverSlots) next[slot] = true;

                if ((serverCounts.photo || 0) >= 4) {
                    ['pickup_front','pickup_rear','pickup_left','pickup_right'].forEach(s => next[s] = true);
                }
                if ((serverCounts.dashboard || 0) >= 1) next['pickup_dashboard']   = next['pickup_dashboard']   || true;
                if ((serverCounts.dashboard || 0) >= 2) next['delivery_dashboard'] = next['delivery_dashboard'] || true;
                if ((serverCounts.data_plate || 0) > 0)       next['pickup_data_plate'] = true;
                if ((serverCounts.collection_note || 0) > 0)  next['collection_note']   = true;
                if ((serverCounts.proof_of_delivery || 0) > 0) next['proof_of_delivery'] = true;

                for (const it of items) {
                    const notes = it.metadata && it.metadata.notes;
                    if (typeof notes === 'string' && notes.startsWith('slot:')) {
                        next[notes.slice(5)] = true;
                    }
                    if (it.category === 'damage_photo') damage++;
                }

                this.capturedSlots    = next;
                this.damageCaptured    = damage;
            },

            hasCategoryFor(slotTag)   { return !!this.capturedSlots[slotTag]; },
            hasAllPickupPhotos() {
                return ['pickup_front','pickup_rear','pickup_left','pickup_right',
                        'pickup_dashboard','pickup_data_plate','collection_note']
                    .every(s => !!this.capturedSlots[s]);
            },
            canArrivePickup() { return true; },
            canDepartPickup() { return this.hasAllPickupPhotos(); },
            canComplete() {
                return !!this.capturedSlots['proof_of_delivery']
                    && !!this.capturedSlots['delivery_front']
                    && !!this.capturedSlots['delivery_other']
                    && !!this.capturedSlots['delivery_dashboard'];
            },

            async capture(event, { category, slotTag = null }) {
                const input = event.target;
                const notes = slotTag ? `slot:${slotTag}` : null;
                await window.driverCapture.fromInput({ input, jobId: this.jobId, category, notes });
                if (slotTag) this.capturedSlots = { ...this.capturedSlots, [slotTag]: true };
                await this.refresh();
            },
        };
    }

    // ----- petty cash OCR (best effort, client side) -----------------
    // We lazy-load Tesseract.js the first time the driver picks a slip
    // photo. It runs the file through OCR, regex-matches the largest
    // rand value, and pre-fills the amount input. The driver still
    // confirms — OCR is a hint, never authoritative. Failures are
    // silent: a flaky network or unreadable photo just means the
    // driver types the amount in manually.
    function pettyCashOcr() {
        return {
            wire: null,
            ocrBusy: false,
            ocrSuggestion: null,

            bind(wire) { this.wire = wire; },

            async ensureTesseract() {
                if (window.Tesseract) return window.Tesseract;
                await new Promise((resolve, reject) => {
                    const s = document.createElement('script');
                    s.src = 'https://unpkg.com/tesseract.js@5/dist/tesseract.min.js';
                    s.async = true;
                    s.onload = resolve;
                    s.onerror = reject;
                    document.head.appendChild(s);
                });
                return window.Tesseract;
            },

            extractAmount(text) {
                if (!text) return null;
                // Find rand-looking amounts: optional R / R prefix, then
                // a decimal number. Pick the largest — receipt totals
                // are almost always the biggest value on the slip.
                const re = /(?:R\s*)?(\d{1,5}(?:[ ,.]?\d{3})*(?:[.,]\d{2}))/gi;
                let m, best = 0;
                while ((m = re.exec(text)) !== null) {
                    const cleaned = m[1].replace(/\s/g, '').replace(/,(\d{2})$/, '.$1').replace(/[, ]/g, '');
                    const v = parseFloat(cleaned);
                    if (!isNaN(v) && v > best && v < 100000) best = v;
                }
                return best > 0 ? best.toFixed(2) : null;
            },

            async onPhotoChosen(event) {
                const file = event.target.files && event.target.files[0];
                if (!file || !this.wire) return;
                this.ocrSuggestion = null;
                this.ocrBusy = true;
                try {
                    const Tesseract = await this.ensureTesseract();
                    const result = await Tesseract.recognize(file, 'eng', { logger: () => {} });
                    const text = (result && result.data && result.data.text) || '';
                    const conf = (result && result.data && typeof result.data.confidence === 'number') ? result.data.confidence : null;
                    const amt = this.extractAmount(text);
                    if (text)  this.wire.set('expenseOcrText', text.slice(0, 5000), false);
                    if (amt)   this.wire.set('expenseOcrAmount', amt, false);
                    if (conf !== null) this.wire.set('expenseOcrConfidence', conf.toFixed(2), false);
                    if (amt)   this.ocrSuggestion = amt;
                } catch (e) {
                    /* OCR failed — silently fall back to manual entry */
                } finally {
                    this.ocrBusy = false;
                }
            },
        };
    }
</script>
