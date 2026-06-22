<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\DealerStockFitment;
use App\Models\Location;
use App\Services\DealerStockAssignmentService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Stock-keyed yard view for OEM-direct arrivals that don't have a
 * transport_job binding yet.  Same look-and-feel as the job-keyed
 * yard show; the differences are:
 *
 *   - the hero shows the OEM instead of the dealer (until assigned)
 *   - a prominent "Assign to dealer" panel sits at the top, with a
 *     dealer picker
 *   - the check-out (collection request) action is hidden until a
 *     dealer is assigned -- we can't raise a movement request when we
 *     don't know which tenant the request belongs to.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    public DealerStock $stock;
    public ?DealerStockFitment $fitment = null;

    public string $bb_internal_job_number = '';
    public ?int $assignDealerId = null;

    public function mount(DealerStock $stock): void
    {
        $user    = auth()->user();
        $company = $user?->company();
        abort_unless($company && $company->isBodyBuilder(), 403);

        // Tenancy: the stock must be at one of our locations.
        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');
        abort_unless($myLocationIds->contains($stock->current_location_id), 404);

        $this->stock = $stock->load(['dealerCompany', 'oemCompany', 'currentLocation', 'brand']);
        $this->fitment = $this->resolveFitmentFor((int) $company->id);
        $this->bb_internal_job_number = (string) ($this->fitment->internal_job_number ?? '');
    }

    /**
     * The fitment leg for THIS BB on this stock row, lazily created
     * if it doesn't exist yet so OEM-direct arrivals (where the dealer
     * hasn't planned a chain) still let the BB write their internal
     * job number immediately.
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
        $this->validate(['bb_internal_job_number' => 'nullable|string|max:80']);

        if (!$this->fitment) {
            $company = auth()->user()?->company();
            $this->fitment = $this->resolveFitmentFor((int) $company?->id);
        }

        $this->fitment->update([
            'internal_job_number' => trim($this->bb_internal_job_number) ?: null,
        ]);
        session()->flash('success', 'Internal job number saved.');
    }

    public function assign(DealerStockAssignmentService $service): void
    {
        $this->validate([
            'assignDealerId' => 'required|exists:companies,id',
        ]);

        $dealer = Company::find($this->assignDealerId);
        if (!$dealer || !$dealer->isDealer()) {
            session()->flash('error', 'Pick a valid dealer.');
            return;
        }

        try {
            $this->stock = $service->assignToDealer($this->stock, $dealer, auth()->user());
            session()->flash('success', "Assigned to {$dealer->name}.");
            $this->redirect(route('body-builder.yard.stock', ['stock' => $this->stock->id]), navigate: true);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(): array
    {
        // All active dealer companies.  Reasonably small set on this
        // platform (a few hundred at most), no pagination needed.
        $dealerOptions = Company::query()
            ->where('type', Company::TYPE_DEALER)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
            ->all();

        return ['dealerOptions' => $dealerOptions];
    }
}; ?>

<div class="space-y-3">
    <a href="{{ route('body-builder.yard.index') }}" class="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700">← Back to yard</a>

    @if(session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Vehicle hero --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="text-xs font-mono text-slate-500">{{ $stock->vin }}</div>
        <div class="mt-1 text-xl font-semibold text-slate-900">
            {{ $stock->brand?->name }} {{ $stock->model_name }}
        </div>
        @if($stock->isUnassigned())
            <div class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
                ⚠ Unassigned -- no dealer yet
            </div>
        @else
            <div class="text-sm text-slate-600">{{ $stock->dealerCompany?->name }}</div>
        @endif
        @if($stock->oemCompany)
            <p class="mt-1 text-xs text-slate-500">From {{ $stock->oemCompany->name }}</p>
        @endif
        <p class="mt-1 text-xs text-slate-400">Workshop: {{ $stock->currentLocation?->company_name ?: '—' }}</p>
    </div>

    {{-- Assign to dealer (only shown while unassigned). --}}
    @if($stock->isUnassigned())
        <div class="rounded-xl border-2 border-amber-300 bg-white p-4 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900">Assign to dealer</h2>
            <p class="text-xs text-slate-600">
                Pick the dealer this chassis belongs to.  They will immediately see it on their stock list as "at body builder".
            </p>
            <div>
                <label class="block text-xs font-medium text-slate-700">Dealer</label>
                <x-searchable-select
                    wire:model="assignDealerId"
                    :options="$dealerOptions"
                    placeholder="-- pick dealer --"
                />
                @error('assignDealerId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <button wire:click="assign"
                @disabled(!$assignDealerId)
                class="w-full h-14 rounded-xl bg-emerald-600 text-base font-semibold text-white hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-50">
                Assign chassis
            </button>
        </div>
    @endif

    {{-- BB internal job number editor (works whether assigned or not). --}}
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
        @error('bb_internal_job_number')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>

    @if(!$stock->isUnassigned())
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
            Once the dealer triggers a transport movement, the regular yard view will take over from here.
        </div>
    @endif
</div>
