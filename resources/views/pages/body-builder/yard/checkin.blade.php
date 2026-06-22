<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Services\DealerStockAssignmentService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

/*
 * Check-in by VIN.
 *
 * Foreman types the last few digits of the VIN; we search inbound
 * jobs (status != delivered, destination = one of our locations) AND
 * dealer-stock rows pointing at our locations.  Once they pick a hit,
 * we open the confirm panel where they can:
 *
 *   1. Confirm receipt (idempotent -- uses Job::confirmReceiptAtBodyBuilder)
 *   2. Set the BB internal job number (writes to DealerStock by VIN)
 *
 * Both are independent: a job without a matching DealerStock row still
 * confirms; a DealerStock row without an inbound job still saves the
 * BB number.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    public string $q = '';
    public ?int $selectedJobId = null;
    public string $bb_internal_job_number = '';

    // OEM-direct arrival form (shown when nothing inbound matches the
    // VIN the BB just typed -- a chassis the OEM dropped off cold).
    public bool $showArrivalForm = false;
    public ?int $arrivalLocationId = null;
    public ?int $arrivalOemId = null;
    public ?int $arrivalBrandId = null;
    public string $arrivalModelName = '';
    public string $arrivalColour = '';
    public string $arrivalNotes = '';

    public function mount(): void
    {
        $user = auth()->user();
        $company = $user?->company();
        abort_unless($company && $company->isBodyBuilder(), 403);
    }

    public function search(): array
    {
        $needle = trim($this->q);
        if (mb_strlen($needle) < 3) {
            return [];
        }

        $user = auth()->user();
        $company = $user->company();
        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');
        $linkedDealerIds = $company->linkedDealers()->wherePivot('is_active', true)->pluck('companies.id');

        $needleUpper = strtoupper($needle);

        // Inbound and on-site jobs at our locations.
        $jobs = Job::query()
            ->whereIn('delivery_location_id', $myLocationIds)
            ->whereIn('company_id', $linkedDealerIds)
            ->whereIn('status', [Job::STATUS_PLANNED, Job::STATUS_ASSIGNED, Job::STATUS_IN_TRANSIT, Job::STATUS_DELIVERED])
            ->where(function ($q) use ($needleUpper) {
                $q->where('vin', 'like', "%{$needleUpper}%")
                  ->orWhere('registration', 'like', "%{$needleUpper}%");
            })
            ->with(['company:id,name', 'brand:id,name', 'deliveryLocation:id,company_name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $jobs->map(fn (Job $j) => [
            'id'        => $j->id,
            'vin'       => $j->vin,
            'reg'       => $j->registration,
            'brand'     => $j->brand?->name,
            'model'     => $j->model_name,
            'dealer'    => $j->company?->name,
            'status'    => $j->status,
            'delivered' => $j->status === Job::STATUS_DELIVERED,
        ])->all();
    }

    public function pick(int $jobId): void
    {
        $this->selectedJobId = $jobId;

        // Pre-fill the BB job number from the matching stock row so the
        // foreman sees whatever was already captured.
        $job = Job::find($jobId);
        if ($job && $job->vin) {
            $stock = DealerStock::where('vin', strtoupper(trim($job->vin)))->first();
            if ($stock) {
                $this->bb_internal_job_number = (string) ($stock->bb_internal_job_number ?? '');
            }
        }
    }

    /**
     * Confirm receipt + set the BB internal job number in one go.
     * Either side is safe to run on its own -- the foreman might
     * confirm-only, or set-number-only, depending on which step they're
     * doing.
     */
    public function confirm(): void
    {
        $user = auth()->user();
        $job  = Job::findOrFail($this->selectedJobId);

        $company = $user->company();
        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');
        abort_unless($myLocationIds->contains($job->delivery_location_id), 404);

        // Receipt (idempotent -- safe to call even if already confirmed).
        if (!in_array($job->status, [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED], true)) {
            if (! Gate::forUser($user)->allows('confirmReceiptAtBodyBuilder', $job)) {
                session()->flash('error', 'You don\'t have permission to confirm receipt.');
                return;
            }
            $job->confirmReceiptAtBodyBuilder($user);
        }

        // BB internal job number on the stock row (one per VIN).
        if ($job->vin && trim($this->bb_internal_job_number) !== '') {
            $stock = DealerStock::where('vin', strtoupper(trim($job->vin)))->first();
            if ($stock) {
                $stock->update(['bb_internal_job_number' => trim($this->bb_internal_job_number)]);
            }
        }

        session()->flash('success', "Checked in {$job->vin}.");
        $this->redirect(route('body-builder.yard.show', ['job' => $job->id]), navigate: true);
    }

    /**
     * Open the OEM-direct arrival form, pre-filling the VIN from the
     * current search box and defaulting the workshop location to the
     * BB's only / first location.
     */
    public function openArrivalForm(): void
    {
        $company = auth()->user()->company();
        $locs = Location::where('company_id', $company->id)->where('is_active', true)->get(['id']);
        if ($locs->count() === 1) {
            $this->arrivalLocationId = $locs->first()->id;
        }
        $this->showArrivalForm = true;
    }

    /**
     * Record the OEM-direct chassis arrival.  Creates an unassigned
     * dealer_stock row keyed by VIN at the chosen workshop location.
     * The BB then opens the yard show page to assign a dealer when
     * they know who it's for.
     */
    public function recordArrival(DealerStockAssignmentService $service): void
    {
        $this->validate([
            'q'                 => 'required|string|min:5|max:50',
            'arrivalLocationId' => 'required|exists:locations,id',
            'arrivalOemId'      => 'nullable|exists:companies,id',
            'arrivalBrandId'    => 'nullable|exists:brands,id',
            'arrivalModelName'  => 'nullable|string|max:120',
            'arrivalColour'     => 'nullable|string|max:60',
            'arrivalNotes'      => 'nullable|string|max:2000',
        ]);

        $user    = auth()->user();
        $company = $user->company();

        $location = Location::find($this->arrivalLocationId);
        abort_unless($location && $location->company_id === $company->id, 403);

        try {
            $stock = $service->recordOemArrival($company, $location, $user, [
                'vin'            => $this->q,
                'oem_company_id' => $this->arrivalOemId,
                'brand_id'       => $this->arrivalBrandId,
                'model_name'     => $this->arrivalModelName ?: null,
                'colour'         => $this->arrivalColour ?: null,
                'notes'          => $this->arrivalNotes ?: null,
            ]);

            // Route to the yard show page through the stock UUID if
            // we don't have a job; that page handles the "assign to
            // dealer" flow when current_job_id is null.
            session()->flash('success', "Arrival recorded for {$stock->vin}. Open the vehicle to assign a dealer.");
            $this->redirect(route('body-builder.yard.stock', ['stock' => $stock->id]), navigate: true);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(): array
    {
        $user    = auth()->user();
        $company = $user->company();

        return [
            'results' => $this->search(),
            'oemOptions' => Company::query()
                ->where('type', Company::TYPE_OEM)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
                ->all(),
            'brandOptions' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])
                ->all(),
            'locationOptions' => Location::where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('company_name')
                ->get(['id', 'company_name'])
                ->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->company_name])
                ->all(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-baseline justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Check in a vehicle</h1>
    </div>

    @if(session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <label class="block text-xs font-medium text-slate-700">VIN or registration</label>
        <input type="search" wire:model.live.debounce.300ms="q"
            inputmode="latin"
            autocomplete="off"
            autocapitalize="characters"
            class="w-full h-12 rounded-lg border-slate-300 text-base font-mono uppercase"
            placeholder="Type 3+ chars of the VIN...">

        @if(mb_strlen(trim($q)) >= 3 && empty($results))
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-2">
                <p class="text-xs text-amber-900">
                    No transport job inbound for that VIN.  If the chassis was sent straight here by the OEM, record it as an arrival.
                </p>
                @if(!$showArrivalForm)
                    <button type="button" wire:click="openArrivalForm"
                        class="w-full h-11 rounded-md bg-amber-600 text-sm font-semibold text-white hover:bg-amber-700">
                        + Record OEM-direct arrival
                    </button>
                @endif
            </div>
        @endif

        @if(!empty($results))
            <ul class="space-y-1.5">
                @foreach($results as $r)
                    <li>
                        <button type="button" wire:click="pick({{ $r['id'] }})"
                            class="w-full text-left rounded-lg border {{ $selectedJobId === $r['id'] ? 'border-blue-500 ring-2 ring-blue-200' : 'border-slate-200' }} px-3 py-2.5 hover:border-blue-300">
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-xs font-mono text-slate-600">{{ $r['vin'] }}</div>
                                    <div class="mt-0.5 text-sm font-semibold text-slate-900 truncate">{{ $r['brand'] }} {{ $r['model'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $r['dealer'] }}</div>
                                </div>
                                <div class="text-right">
                                    @if($r['delivered'])
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">On site</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Inbound</span>
                                    @endif
                                </div>
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- OEM-direct arrival form -- only shown after the BB explicitly
         clicks "Record OEM-direct arrival" above. --}}
    @if($showArrivalForm)
        <div class="rounded-xl border-2 border-amber-300 bg-white p-4 space-y-3">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900">OEM-direct arrival</h2>
                <button type="button" wire:click="$set('showArrivalForm', false)" class="text-xs text-slate-500 hover:text-slate-700">Cancel</button>
            </div>
            <p class="text-xs text-slate-600">
                Record the chassis as on-yard now; you can assign it to a dealer the moment you know which one it's for.
            </p>

            <div>
                <label class="block text-xs font-medium text-slate-700">Workshop location <span class="text-rose-500">*</span></label>
                @if(count($locationOptions) === 1)
                    <div class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">{{ $locationOptions[0]['label'] }}</div>
                @else
                    <select wire:model="arrivalLocationId" class="mt-1 w-full h-12 rounded-lg border-slate-300 text-sm">
                        <option value="">-- select workshop --</option>
                        @foreach($locationOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                @error('arrivalLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">OEM (who sent it)</label>
                <x-searchable-select
                    wire:model="arrivalOemId"
                    :options="$oemOptions"
                    placeholder="-- pick OEM --"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-700">Brand</label>
                    <x-searchable-select
                        wire:model="arrivalBrandId"
                        :options="$brandOptions"
                        placeholder="-- pick brand --"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">Model</label>
                    <input type="text" wire:model="arrivalModelName" class="mt-1 w-full h-11 rounded-lg border-slate-300 text-sm" placeholder="e.g. 8.140FL">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Colour</label>
                <input type="text" wire:model="arrivalColour" class="mt-1 w-full h-11 rounded-lg border-slate-300 text-sm" placeholder="White">
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700">Notes (optional)</label>
                <textarea wire:model="arrivalNotes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                    placeholder="Delivered by Truck Transport; paperwork in courier folder."></textarea>
            </div>

            <button wire:click="recordArrival"
                class="w-full h-14 rounded-xl bg-amber-600 text-base font-semibold text-white hover:bg-amber-700 active:bg-amber-800">
                Record arrival
            </button>
        </div>
    @endif

    @if($selectedJobId)
        <div class="rounded-xl border-2 border-blue-200 bg-white p-4 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900">Confirm receipt</h2>
            <p class="text-xs text-slate-600">Confirms the vehicle is on your premises and notifies the dealer.</p>

            <div>
                <label class="block text-xs font-medium text-slate-700">Your internal job number (optional)</label>
                <input type="text" wire:model="bb_internal_job_number"
                    class="mt-1 w-full h-12 rounded-lg border-slate-300 text-base font-mono uppercase"
                    placeholder="e.g. BB-2026-0042">
                <p class="mt-1 text-[11px] text-slate-500">Visible to the dealer once saved.</p>
            </div>

            <button wire:click="confirm"
                class="w-full h-14 rounded-xl bg-emerald-600 text-base font-semibold text-white hover:bg-emerald-500 active:bg-emerald-700">
                ✓ Confirm + check in
            </button>
        </div>
    @endif
</div>
