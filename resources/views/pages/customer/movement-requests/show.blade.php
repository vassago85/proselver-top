<?php

use App\Models\Location;
use App\Models\MovementRequest;
use App\Models\VehicleClass;
use App\Services\MovementRequestService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

new #[Layout('components.layouts.app')] class extends Component
{
    public MovementRequest $req;

    // Decision form state
    public ?string $action = null; // approve | reject
    public string $decisionNotes = '';

    // Editable movement details (dealer can tweak before approving —
    // e.g. swap delivery location, change requested_date).
    public ?int $editPickupLocationId = null;
    public ?int $editDeliveryLocationId = null;
    public ?int $editVehicleClassId = null;
    public string $editRequestedDate = '';

    public function mount(string $request): void
    {
        $this->req = MovementRequest::where('uuid', $request)
            ->with([
                'requestingCompany:id,name', 'targetCompany:id,name',
                'requestingUser:id,name', 'decidedBy:id,name',
                'sourceJob', 'createdJob:id,job_number,uuid',
                'pickupLocation:id,company_name,city,company_id',
                'deliveryLocation:id,company_name,city,company_id',
                'brand:id,name', 'vehicleClass:id,name',
            ])
            ->firstOrFail();

        abort_unless(Gate::allows('view', $this->req), 403);

        $this->editPickupLocationId = $this->req->pickup_location_id;
        $this->editDeliveryLocationId = $this->req->delivery_location_id;
        $this->editVehicleClassId = $this->req->vehicle_class_id;
        $this->editRequestedDate = optional($this->req->requested_date)->toDateString() ?? '';
    }

    public function setAction(string $action): void
    {
        if (! in_array($action, ['approve', 'reject'], true)) return;
        $this->action = $action;
    }

    public function cancelAction(): void
    {
        $this->action = null;
        $this->decisionNotes = '';
    }

    public function submitDecision(MovementRequestService $service): void
    {
        $user = auth()->user();

        if ($this->action === 'approve') {
            if (! Gate::forUser($user)->allows('approve', $this->req)) {
                session()->flash('error', 'You cannot approve this request.');
                return;
            }

            $this->validate([
                'editPickupLocationId'   => 'required|exists:locations,id',
                'editDeliveryLocationId' => 'required|exists:locations,id|different:editPickupLocationId',
                'editVehicleClassId'     => 'required|exists:vehicle_classes,id',
                'editRequestedDate'      => 'nullable|date',
            ]);

            // Persist any dealer tweaks back onto the request BEFORE
            // approving so the service uses the corrected values when
            // it materialises the new transport_jobs row.
            $this->req->update([
                'pickup_location_id'   => $this->editPickupLocationId,
                'delivery_location_id' => $this->editDeliveryLocationId,
                'vehicle_class_id'     => $this->editVehicleClassId,
                'requested_date'       => $this->editRequestedDate ?: null,
            ]);
            $this->req->refresh();

            try {
                $service->approve($this->req, $user, $this->decisionNotes ?: null);
                session()->flash('success', 'Request approved. A new transport job has been created and is in your dispatch queue.');
                $this->req->refresh();
                $this->action = null;
            } catch (\Throwable $e) {
                session()->flash('error', 'Could not approve: ' . $e->getMessage());
            }
            return;
        }

        if ($this->action === 'reject') {
            if (! Gate::forUser($user)->allows('reject', $this->req)) {
                session()->flash('error', 'You cannot reject this request.');
                return;
            }
            $this->validate([
                'decisionNotes' => 'required|string|min:5|max:500',
            ], [], ['decisionNotes' => 'rejection reason']);

            try {
                $service->reject($this->req, $user, $this->decisionNotes);
                session()->flash('success', 'Request rejected. The body builder has been notified.');
                $this->req->refresh();
                $this->action = null;
                $this->decisionNotes = '';
            } catch (\Throwable $e) {
                session()->flash('error', 'Could not reject: ' . $e->getMessage());
            }
        }
    }

    public function with(): array
    {
        // Pickup options = locations owned by the BB raising the
        // request (i.e. their workshops).  Delivery options vary by
        // type: collection → dealer's own locations; next_move →
        // any active location belonging to the BBs linked to this
        // dealer, excluding the pickup.
        $bbCompanyId = $this->req->requesting_company_id;
        $dealerCompanyId = $this->req->target_company_id;

        $pickupOptions = Location::where('company_id', $bbCompanyId)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city'])
            ->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->company_name . ' · ' . $l->city])
            ->values()->all();

        if ($this->req->isCollection()) {
            $deliveryOptions = Location::where('company_id', $dealerCompanyId)
                ->where('is_active', true)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city']);
        } else {
            $linkedBbIds = \App\Models\BodyBuilderDealerLink::where('dealer_company_id', $dealerCompanyId)
                ->where('is_active', true)
                ->pluck('body_builder_company_id');

            $deliveryOptions = Location::whereIn('company_id', $linkedBbIds)
                ->where('is_active', true)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city']);
        }

        $deliveryOptionsList = $deliveryOptions
            ->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->company_name . ' · ' . $l->city])
            ->values()->all();

        $vehicleClassOptions = VehicleClass::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
            ->values()->all();

        return [
            'pickupOptions'        => $pickupOptions,
            'deliveryOptionsList'  => $deliveryOptionsList,
            'vehicleClassOptions'  => $vehicleClassOptions,
            'canDecide'            => Gate::allows('decide', $this->req),
        ];
    }
};
?>

<div>
    <x-slot:header>Request from {{ $req->requestingCompany?->name }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ $req->typeLabel() }}</h2>
                        <p class="text-xs text-slate-500">Raised by {{ $req->requestingUser?->name ?: '—' }} · {{ optional($req->created_at)->format('D, j M Y \a\t H:i') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                        {{ $req->status === 'approved' ? 'bg-emerald-50 text-emerald-700'
                          : ($req->status === 'rejected' ? 'bg-rose-50 text-rose-700'
                          : ($req->status === 'cancelled' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700')) }}">
                        {{ $req->statusLabel() }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Vehicle</dt><dd class="text-slate-900">{{ $req->brand?->name }} {{ $req->model_name }}</dd></div>
                    <div><dt class="text-slate-500">VIN / Reg</dt><dd class="text-slate-900">{{ $req->vin ?: '—' }} · {{ $req->registration ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Class</dt><dd class="text-slate-900">{{ $req->vehicleClass?->name ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Requested date</dt><dd class="text-slate-900">{{ optional($req->requested_date)->format('D, j M Y') ?: '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Pickup (BB workshop)</dt><dd class="text-slate-900">{{ $req->pickupLocation?->company_name }}<span class="text-slate-500"> · {{ $req->pickupLocation?->city }}</span></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Delivery</dt><dd class="text-slate-900">{{ $req->deliveryLocation?->company_name }}<span class="text-slate-500"> · {{ $req->deliveryLocation?->city }}</span></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">BB notes</dt><dd class="text-slate-900 whitespace-pre-wrap">{{ $req->notes ?: '—' }}</dd></div>
                </dl>
            </div>

            @if($req->status !== 'pending')
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-slate-900">Decision</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">Decided by</dt><dd class="text-slate-900">{{ $req->decidedBy?->name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">When</dt><dd class="text-slate-900">{{ optional($req->decided_at)->format('D, j M Y \a\t H:i') ?: '—' }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">Notes</dt><dd class="text-slate-900 whitespace-pre-wrap">{{ $req->decision_notes ?: '—' }}</dd></div>
                        @if($req->createdJob)
                            <div class="col-span-2">
                                <dt class="text-slate-500">Created job</dt>
                                <dd><a href="{{ route('customer.orders.show', $req->createdJob->uuid ?? $req->createdJob->id) }}" class="text-blue-600 hover:underline">{{ $req->createdJob->job_number }}</a></dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            @if($req->isPending() && $canDecide)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-slate-900">Decision</h3>
                    <p class="mt-1 text-xs text-slate-500">Review the request and choose:</p>

                    <div class="mt-3 grid gap-2">
                        <button type="button" wire:click="setAction('approve')"
                            class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                            Approve
                        </button>
                        <button type="button" wire:click="setAction('reject')"
                            class="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            Reject
                        </button>
                    </div>
                </div>

                @if($action === 'approve')
                    <form wire:submit.prevent="submitDecision" class="rounded-xl border border-emerald-200 bg-white p-5 space-y-3">
                        <h4 class="text-sm font-semibold text-slate-900">Confirm approval</h4>
                        <p class="text-xs text-slate-500">Tweak any details below, then approve to create a new transport job.</p>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Pickup (BB workshop)</label>
                            <x-searchable-select
                                wire:model="editPickupLocationId"
                                :options="$pickupOptions"
                                placeholder="— pick —"
                            />
                            @error('editPickupLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Delivery</label>
                            <x-searchable-select
                                wire:model="editDeliveryLocationId"
                                :options="$deliveryOptionsList"
                                placeholder="— pick —"
                            />
                            @error('editDeliveryLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Vehicle class</label>
                            <x-searchable-select
                                wire:model="editVehicleClassId"
                                :options="$vehicleClassOptions"
                                placeholder="— pick —"
                            />
                            @error('editVehicleClassId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Scheduled date</label>
                            <input type="date" wire:model="editRequestedDate" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                            @error('editRequestedDate')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Internal notes (optional)</label>
                            <textarea wire:model="decisionNotes" rows="2" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="e.g. Use Driver Joe; prioritise Friday"></textarea>
                        </div>

                        <div class="flex gap-2 pt-1">
                            <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve &amp; create job</button>
                            <button type="button" wire:click="cancelAction" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        </div>
                    </form>
                @endif

                @if($action === 'reject')
                    <form wire:submit.prevent="submitDecision" class="rounded-xl border border-rose-200 bg-white p-5 space-y-3">
                        <h4 class="text-sm font-semibold text-slate-900">Confirm rejection</h4>
                        <p class="text-xs text-slate-500">A reason is required — the body builder will see this on their request page.</p>

                        <div>
                            <label class="block text-xs font-medium text-slate-700">Reason</label>
                            <textarea wire:model="decisionNotes" rows="3" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="e.g. Customer collection planned for next week — please hold"></textarea>
                            @error('decisionNotes')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="flex gap-2 pt-1">
                            <button type="submit" class="rounded-md bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700">Send rejection</button>
                            <button type="button" wire:click="cancelAction" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                        </div>
                    </form>
                @endif
            @endif

            @if($req->sourceJob)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-slate-900">Source vehicle</h3>
                    <p class="mt-1 text-sm text-slate-700">{{ $req->sourceJob->job_number ?: '—' }}</p>
                    <a href="{{ route('customer.orders.show', $req->sourceJob->uuid ?? $req->source_job_id) }}" class="mt-2 inline-flex text-sm font-medium text-blue-600 hover:text-blue-800">Open order →</a>
                </div>
            @endif
        </div>
    </div>
</div>
