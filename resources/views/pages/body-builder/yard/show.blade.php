<?php

use App\Models\DealerStock;
use App\Models\DealerStockFitment;
use App\Models\Job;
use App\Models\Location;
use App\Models\MovementRequest;
use App\Services\MovementRequestService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

/*
 * Yard-side per-vehicle detail.  Same job binding the existing
 * /body-builder/jobs/{job} page uses, but rendered for the touch
 * tablet: bigger buttons, less chrome, and surfaces:
 *
 *   - the BB internal job number for the *active fitment leg*
 *     (editable in place by BB staff -- writes to the per-leg row,
 *     not the global stock row, so siblings on a multi-BB chain keep
 *     their own numbers)
 *   - the dealer-shared salesperson / end customer / build notes for
 *     THIS leg (only if the dealer flipped the share toggle on the
 *     leg in question)
 *   - a single Check out action that opens the standard collection
 *     MovementRequest -- reused from the existing portal so we never
 *     re-implement the dealer-approval handshake.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    public Job $job;
    public ?DealerStock $stock = null;
    public ?DealerStockFitment $fitment = null;

    public string $bb_internal_job_number = '';

    // Collection-request panel (sends a movement request back to the
    // dealer's original pickup location).
    public bool $showCheckOut = false;
    public ?int $deliveryLocationId = null;
    public string $requestNotes = '';
    public string $requestDate = '';

    public function mount(Job $job): void
    {
        $user = auth()->user();
        $company = $user?->company();
        abort_unless($company && $company->isBodyBuilder(), 403);

        $myLocationIds   = Location::where('company_id', $company->id)->pluck('id');
        $linkedDealerIds = $company->linkedDealers()->wherePivot('is_active', true)->pluck('companies.id');

        abort_unless(
            $myLocationIds->contains($job->delivery_location_id)
                && $linkedDealerIds->contains($job->company_id),
            404,
        );

        $this->job = $job->load([
            'company', 'pickupLocation', 'deliveryLocation', 'brand',
        ]);

        $this->deliveryLocationId = $job->pickup_location_id;

        if ($job->vin) {
            $this->stock = DealerStock::where('vin', strtoupper(trim($job->vin)))->first();
            if ($this->stock) {
                $this->fitment = $this->resolveFitmentFor($company->id);
                $this->bb_internal_job_number = (string) ($this->fitment->internal_job_number ?? '');
            }
        }
    }

    /**
     * Find (or lazily create) the fitment leg for THIS BB on the
     * current stock row.  Priority: in-progress for me > planned for
     * me > most-recent for me.  If nothing exists at all, mint a new
     * in-progress leg so the BB can immediately write their internal
     * job number without the dealer having to seed the chain first.
     */
    protected function resolveFitmentFor(int $bbCompanyId): DealerStockFitment
    {
        $existing = $this->stock->fitments()
            ->where('body_builder_company_id', $bbCompanyId)
            ->orderByRaw("CASE status
                WHEN 'in_progress' THEN 1
                WHEN 'planned'     THEN 2
                WHEN 'completed'   THEN 3
                ELSE 4 END")
            ->orderByDesc('updated_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $nextSequence = ((int) $this->stock->fitments()->max('sequence')) + 1;

        return $this->stock->fitments()->create([
            'body_builder_company_id' => $bbCompanyId,
            'sequence'                => $nextSequence,
            'status'                  => DealerStockFitment::STATUS_IN_PROGRESS,
            'started_at'              => now(),
        ]);
    }

    public function saveBbJobNumber(): void
    {
        if (!$this->stock) {
            session()->flash('error', 'No dealer stock row found for this VIN -- ask the dealer to add it to their stock list.');
            return;
        }

        $this->validate([
            'bb_internal_job_number' => 'nullable|string|max:80',
        ]);

        if (!$this->fitment) {
            $company = auth()->user()?->company();
            $this->fitment = $this->resolveFitmentFor((int) $company?->id);
        }

        $this->fitment->update([
            'internal_job_number' => trim($this->bb_internal_job_number) ?: null,
        ]);

        session()->flash('success', 'Internal job number saved.');
    }

    public function openCheckOut(): void
    {
        $this->showCheckOut = true;
    }

    public function cancelCheckOut(): void
    {
        $this->showCheckOut = false;
    }

    public function submitCheckOut(MovementRequestService $service): void
    {
        $user = auth()->user();

        if (! Gate::forUser($user)->allows('createFor', [MovementRequest::class, $this->job])) {
            session()->flash('error', 'You don\'t have permission to raise a collection request.');
            return;
        }

        $this->validate([
            'deliveryLocationId' => 'required|exists:locations,id|different:job.delivery_location_id',
            'requestNotes'       => 'nullable|string|max:1000',
            'requestDate'        => 'nullable|date|after_or_equal:today',
        ]);

        $payload = [
            'pickup_location_id'   => $this->job->delivery_location_id,
            'delivery_location_id' => $this->deliveryLocationId,
            'vehicle_class_id'     => $this->job->vehicle_class_id,
            'brand_id'             => $this->job->brand_id,
            'vin'                  => $this->job->vin,
            'registration'         => $this->job->registration,
            'model_name'           => $this->job->model_name,
            'notes'                => $this->requestNotes ?: 'Check-out from yard.',
            'requested_date'       => $this->requestDate ?: null,
        ];

        try {
            $req = $service->createCollectionRequest($this->job, $user, $payload);
            session()->flash('success', 'Check-out request sent to the dealer (#' . substr($req->uuid, 0, 8) . ').');
            $this->showCheckOut = false;
            $this->redirect(route('body-builder.yard.index'), navigate: true);
        } catch (\Throwable $e) {
            session()->flash('error', 'Could not raise request: ' . $e->getMessage());
        }
    }

    public function with(): array
    {
        $dealerLocations = Location::where('company_id', $this->job->company_id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        return ['dealerLocations' => $dealerLocations];
    }
}; ?>

<div class="space-y-3">
    <a href="{{ route('body-builder.yard.index') }}" class="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700">← Back to yard</a>

    @if(session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Vehicle hero --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs font-mono text-slate-500">{{ $job->vin ?: '— no VIN —' }}</div>
        <div class="mt-1 text-xl font-semibold text-slate-900">{{ $job->brand?->name }} {{ $job->model_name }}</div>
        <div class="text-sm text-slate-600">{{ $job->company?->name }}</div>
        <div class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">
            On site since {{ optional($job->delivered_at)->diffForHumans() ?: '—' }}
        </div>
    </div>

    {{-- Dealer-shared metadata for THIS leg.  Only renders when the
         dealer flipped Share with BB on the leg attached to us, so
         siblings on the chain (e.g. a crane supplier) never see
         another BB's customer details. --}}
    @if($fitment && $fitment->share_with_bb)
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 space-y-1.5">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-emerald-900">Shared by dealer</h3>
            @if($fitment->fitment_type)
                <p class="text-sm"><span class="text-slate-500">Fitment:</span> <strong>{{ $fitment->fitment_type }}</strong></p>
            @endif
            @if($fitment->share_salesperson)
                <p class="text-sm"><span class="text-slate-500">Salesperson:</span> <strong>{{ $fitment->share_salesperson }}</strong></p>
            @endif
            @if($fitment->share_end_customer)
                <p class="text-sm"><span class="text-slate-500">End customer:</span> <strong>{{ $fitment->share_end_customer }}</strong></p>
            @endif
            @if($fitment->notes)
                <div class="mt-2 text-sm whitespace-pre-line">{{ $fitment->notes }}</div>
            @endif
        </div>
    @elseif($fitment && $fitment->fitment_type)
        <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
            <span class="text-slate-500">Fitment:</span> <strong>{{ $fitment->fitment_type }}</strong>
        </div>
    @endif

    {{-- BB internal job number editor --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
        <h3 class="text-sm font-semibold text-slate-900">Your internal job number</h3>
        <div class="flex gap-2">
            <input type="text" wire:model="bb_internal_job_number"
                class="flex-1 h-12 rounded-lg border-slate-300 text-base font-mono uppercase"
                placeholder="BB-2026-0042">
            <button wire:click="saveBbJobNumber"
                class="h-12 px-4 rounded-lg bg-slate-900 text-sm font-semibold text-white hover:bg-slate-700">
                Save
            </button>
        </div>
        @if(!$stock)
            <p class="text-[11px] text-amber-700">No dealer stock entry for this VIN yet -- the dealer needs to add it before we can save the BB number.</p>
        @endif
        @error('bb_internal_job_number')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>

    {{-- Job context --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm space-y-1.5">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Transport</h3>
        <div><span class="text-slate-500">From:</span> {{ $job->pickupLocation?->company_name }}</div>
        <div><span class="text-slate-500">To (here):</span> {{ $job->deliveryLocation?->company_name }}</div>
        <div><span class="text-slate-500">Order:</span> <a href="{{ route('body-builder.jobs.show', ['job' => $job->id]) }}" class="font-mono text-blue-600 hover:underline">#{{ substr($job->uuid, 0, 8) }}</a></div>
    </div>

    {{-- Check out --}}
    @if(!$showCheckOut)
        <button wire:click="openCheckOut"
            class="w-full h-14 rounded-xl bg-amber-500 text-base font-semibold text-white hover:bg-amber-600 active:bg-amber-700">
            ↗ Check out -- send back to dealer
        </button>
    @else
        <div class="rounded-xl border-2 border-amber-300 bg-white p-4 space-y-3">
            <div class="flex items-baseline justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Check out</h3>
                <button wire:click="cancelCheckOut" class="text-xs text-slate-500 hover:text-slate-700">Cancel</button>
            </div>
            <p class="text-xs text-slate-600">Raises a collection request to the dealer.  They have to approve before the vehicle is dispatched.</p>

            <div>
                <label class="block text-xs font-medium text-slate-700">Send to (dealer location)</label>
                <select wire:model="deliveryLocationId" class="mt-1 w-full h-12 rounded-lg border-slate-300 text-sm">
                    @foreach($dealerLocations as $loc)
                        <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? ' -- '.$loc->city : '' }}</option>
                    @endforeach
                </select>
                @error('deliveryLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Notes for the dealer (optional)</label>
                <textarea wire:model="requestNotes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                    placeholder="Body fitted, awaiting final QC sign-off."></textarea>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Requested date (optional)</label>
                <input type="date" wire:model="requestDate" class="mt-1 w-full h-12 rounded-lg border-slate-300 text-sm">
            </div>

            <button wire:click="submitCheckOut"
                class="w-full h-14 rounded-xl bg-amber-600 text-base font-semibold text-white hover:bg-amber-700 active:bg-amber-800">
                Send collection request
            </button>
        </div>
    @endif
</div>
