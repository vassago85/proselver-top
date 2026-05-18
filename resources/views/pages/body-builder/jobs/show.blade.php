<?php

use App\Models\Job;
use App\Models\Location;
use App\Models\MovementRequest;
use App\Models\VehicleClass;
use App\Services\MovementRequestService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Gate;

new #[Layout('components.layouts.app')] class extends Component
{
    public Job $job;

    // Action panel state: 'confirm' | 'next_move' | 'collection' | null
    public ?string $activeForm = null;

    public ?int $requestPickupLocationId = null;
    public ?int $requestDeliveryLocationId = null;
    public ?int $requestVehicleClassId = null;
    public ?int $requestBrandId = null;
    public string $requestVin = '';
    public string $requestRegistration = '';
    public string $requestModelName = '';
    public string $requestNotes = '';
    public string $requestDate = '';

    public function mount(Job $job): void
    {
        $user = auth()->user();
        $company = $user?->company();

        abort_unless($company, 403);

        // Tenancy check: BB can only see jobs delivered to one of
        // their workshops AND from a dealer that has actively linked
        // them.  Both are enforced in the dashboard query too, but
        // we re-check here because show() is reachable by direct URL.
        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');
        $linkedDealerIds = $company->linkedDealers()
            ->wherePivot('is_active', true)
            ->pluck('companies.id');

        abort_unless(
            $myLocationIds->contains($job->delivery_location_id)
                && $linkedDealerIds->contains($job->company_id),
            404,
        );

        $this->job = $job->load([
            'company', 'pickupLocation', 'deliveryLocation',
            'brand', 'vehicleClass', 'driver',
        ]);

        // Seed the request form with whatever we already know about
        // the vehicle so the BB user doesn't have to retype anything.
        $this->requestPickupLocationId = $job->delivery_location_id;
        $this->requestVehicleClassId = $job->vehicle_class_id;
        $this->requestBrandId = $job->brand_id;
        $this->requestVin = (string) ($job->vin ?? '');
        $this->requestRegistration = (string) ($job->registration ?? '');
        $this->requestModelName = (string) ($job->model_name ?? '');
    }

    public function openForm(string $form): void
    {
        if (! in_array($form, ['confirm', 'next_move', 'collection'], true)) {
            return;
        }
        $this->activeForm = $form;

        // Sensible delivery default for collection: send it back to
        // the dealer's original pickup.  For next_move we deliberately
        // leave delivery blank so the BB user has to pick the next
        // fitment workshop on purpose.
        if ($form === 'collection') {
            $this->requestDeliveryLocationId = $this->job->pickup_location_id;
        } else {
            $this->requestDeliveryLocationId = null;
        }
    }

    public function cancelForm(): void
    {
        $this->activeForm = null;
    }

    public function confirmReceipt(): void
    {
        $user = auth()->user();
        if (! Gate::forUser($user)->allows('confirmReceiptAtBodyBuilder', $this->job)) {
            session()->flash('error', 'You do not have permission to confirm receipt on this vehicle.');
            return;
        }

        if ($this->job->confirmReceiptAtBodyBuilder($user)) {
            session()->flash('success', 'Vehicle marked as received. The dealer has been notified.');
            $this->activeForm = null;
            $this->job->refresh();
        } else {
            session()->flash('error', 'This vehicle has already been received.');
        }
    }

    public function submitRequest(MovementRequestService $service): void
    {
        $user = auth()->user();
        if (! Gate::forUser($user)->allows('createFor', [\App\Models\MovementRequest::class, $this->job])) {
            session()->flash('error', 'You do not have permission to raise a request against this vehicle.');
            return;
        }

        $type = $this->activeForm === 'collection'
            ? MovementRequest::TYPE_COLLECTION
            : MovementRequest::TYPE_NEXT_MOVE;

        $this->validate([
            'requestPickupLocationId'   => 'required|exists:locations,id',
            'requestDeliveryLocationId' => 'required|exists:locations,id|different:requestPickupLocationId',
            'requestVehicleClassId'     => 'required|exists:vehicle_classes,id',
            'requestBrandId'            => 'nullable|exists:brands,id',
            'requestVin'                => 'nullable|string|max:50',
            'requestRegistration'       => 'nullable|string|max:20',
            'requestModelName'          => 'nullable|string|max:255',
            'requestNotes'              => 'nullable|string|max:1000',
            'requestDate'               => 'nullable|date|after_or_equal:today',
        ]);

        try {
            $payload = [
                'pickup_location_id'   => $this->requestPickupLocationId,
                'delivery_location_id' => $this->requestDeliveryLocationId,
                'vehicle_class_id'     => $this->requestVehicleClassId,
                'brand_id'             => $this->requestBrandId,
                'vin'                  => $this->requestVin ?: null,
                'registration'         => $this->requestRegistration ?: null,
                'model_name'           => $this->requestModelName ?: null,
                'notes'                => $this->requestNotes ?: null,
                'requested_date'       => $this->requestDate ?: null,
            ];

            $req = $type === MovementRequest::TYPE_COLLECTION
                ? $service->createCollectionRequest($this->job, $user, $payload)
                : $service->createNextMoveRequest($this->job, $user, $payload);

            session()->flash('success', 'Request sent to the dealer for approval (#' . substr($req->uuid, 0, 8) . ').');
            $this->activeForm = null;
            $this->reset(['requestDeliveryLocationId', 'requestNotes', 'requestDate']);
        } catch (\Throwable $e) {
            session()->flash('error', 'Could not raise request: ' . $e->getMessage());
        }
    }

    public function with(): array
    {
        $company = auth()->user()?->company();

        $myLocations = Location::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        // Delivery destinations differ by request type:
        //   - collection → back to one of the source dealer's locations
        //   - next_move  → another BB workshop (linked dealer's BBs or
        //     our own sister workshops; for now we keep it simple and
        //     show every active location across our linked dealers'
        //     networks so the BB can pick a crane shop, paint shop,
        //     etc.)
        $deliveryOptionsForCollection = Location::where('company_id', $this->job->company_id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        // For next_move: every BB-typed location across all our linked
        // dealers' networks, excluding our own pickup location.
        $linkedDealerIds = $company->linkedDealers()
            ->wherePivot('is_active', true)
            ->pluck('companies.id');
        $linkedBbCompanyIds = \App\Models\Company::whereIn('id',
                \App\Models\BodyBuilderDealerLink::whereIn('dealer_company_id', $linkedDealerIds)
                    ->where('is_active', true)
                    ->pluck('body_builder_company_id')
            )->pluck('id');

        $deliveryOptionsForNextMove = Location::query()
            ->where(function ($q) use ($linkedBbCompanyIds) {
                $q->whereIn('company_id', $linkedBbCompanyIds);
            })
            ->where('is_active', true)
            ->where('id', '!=', $this->job->delivery_location_id)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $existingRequests = MovementRequest::where('source_job_id', $this->job->id)
            ->with(['createdJob:id,job_number,uuid', 'decidedBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $vehicleClassOptions = VehicleClass::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
            ->values()->all();

        return [
            'myLocations'                  => $myLocations,
            'deliveryOptionsForCollection' => $deliveryOptionsForCollection,
            'deliveryOptionsForNextMove'   => $deliveryOptionsForNextMove,
            'existingRequests'             => $existingRequests,
            'vehicleClassOptions'          => $vehicleClassOptions,
            'canConfirm'                   => Gate::allows('confirmReceiptAtBodyBuilder', $this->job),
            'canRequest'                   => Gate::allows('createFor', [\App\Models\MovementRequest::class, $this->job]),
        ];
    }
};
?>

<div>
    <x-slot:header>Vehicle · {{ $job->job_number ?: 'Job #'.$job->id }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Vehicle</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Brand</dt><dd class="text-slate-900">{{ $job->brand?->name ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Model</dt><dd class="text-slate-900">{{ $job->model_name ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">VIN</dt><dd class="text-slate-900">{{ $job->vin ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Registration</dt><dd class="text-slate-900">{{ $job->registration ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Vehicle class</dt><dd class="text-slate-900">{{ $job->vehicleClass?->name ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Status</dt><dd class="text-slate-900">{{ str_replace('_', ' ', $job->status) }}</dd></div>
                </dl>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Movement</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-slate-500">From dealer</dt>
                        <dd class="text-slate-900">{{ $job->company?->name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Driver</dt>
                        <dd class="text-slate-900">{{ $job->driver?->name ?: '—' }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Pickup</dt>
                        <dd class="text-slate-900">{{ $job->pickupLocation?->company_name ?: '—' }}<span class="text-slate-500"> · {{ $job->pickupLocation?->city }}</span></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Delivery</dt>
                        <dd class="text-slate-900">{{ $job->deliveryLocation?->company_name ?: '—' }}<span class="text-slate-500"> · {{ $job->deliveryLocation?->city }}</span></dd>
                    </div>
                </dl>
            </div>

            @if($existingRequests->isNotEmpty())
                <div class="rounded-xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-5 py-3">
                        <h2 class="text-base font-semibold text-slate-900">Movement requests on this vehicle</h2>
                    </div>
                    <ul role="list" class="divide-y divide-slate-100">
                        @foreach($existingRequests as $req)
                            <li class="px-5 py-3 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-slate-900">
                                        {{ $req->typeLabel() }}
                                        <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $req->status === 'approved' ? 'bg-emerald-50 text-emerald-700'
                                              : ($req->status === 'rejected' ? 'bg-rose-50 text-rose-700'
                                              : ($req->status === 'cancelled' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700')) }}">
                                            {{ $req->statusLabel() }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        Raised {{ optional($req->created_at)->diffForHumans() }}
                                        @if($req->createdJob)
                                            · became <a href="{{ route('body-builder.jobs.show', $req->created_job_id) }}" class="text-blue-600 hover:underline">{{ $req->createdJob->job_number }}</a>
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('body-builder.requests.show', $req->uuid) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">Open →</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Actions</h2>
                <p class="mt-1 text-xs text-slate-500">What can we do with this vehicle?</p>

                <div class="mt-3 grid gap-2">
                    @if($canConfirm)
                        <button type="button" wire:click="openForm('confirm')"
                            class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                            Confirm receipt
                        </button>
                    @endif

                    @if($canRequest)
                        <button type="button" wire:click="openForm('next_move')"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            Request next fitment
                        </button>
                        <button type="button" wire:click="openForm('collection')"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            Request collection
                        </button>
                    @endif

                    @if(! $canConfirm && ! $canRequest)
                        <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            No actions available at this stage.
                        </p>
                    @endif
                </div>
            </div>

            @if($activeForm === 'confirm')
                <div class="rounded-xl border border-emerald-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-slate-900">Confirm receipt</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        This marks the vehicle as delivered to your workshop and notifies the dealer.
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button wire:click="confirmReceipt" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Confirm</button>
                        <button wire:click="cancelForm" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    </div>
                </div>
            @endif

            @if($activeForm === 'next_move' || $activeForm === 'collection')
                @php
                    $deliveryOptions = $activeForm === 'collection'
                        ? $deliveryOptionsForCollection
                        : $deliveryOptionsForNextMove;
                    $isCollection = $activeForm === 'collection';
                @endphp
                <form wire:submit.prevent="submitRequest" class="rounded-xl border border-slate-200 bg-white p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-slate-900">
                        {{ $isCollection ? 'Request collection back to dealer' : 'Request next fitment movement' }}
                    </h3>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Pickup (our workshop)</label>
                        <select wire:model="requestPickupLocationId" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            @foreach($myLocations as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->company_name }} · {{ $loc->city }}</option>
                            @endforeach
                        </select>
                        @error('requestPickupLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">
                            {{ $isCollection ? 'Deliver back to' : 'Deliver to (next workshop)' }}
                        </label>
                        <select wire:model="requestDeliveryLocationId" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">— pick —</option>
                            @foreach($deliveryOptions as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->company_name }} · {{ $loc->city }}</option>
                            @endforeach
                        </select>
                        @error('requestDeliveryLocationId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Vehicle class</label>
                        <x-searchable-select
                            wire:model="requestVehicleClassId"
                            :options="$vehicleClassOptions"
                            placeholder="— pick a class —"
                        />
                        @error('requestVehicleClassId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Requested date (optional)</label>
                        <input type="date" wire:model="requestDate" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                        @error('requestDate')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Notes for the dealer</label>
                        <textarea wire:model="requestNotes" rows="3" class="mt-1 block w-full rounded-md border-slate-300 text-sm" placeholder="e.g. canopy + radio fitment complete, ready for crane install"></textarea>
                        @error('requestNotes')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">Send request</button>
                        <button type="button" wire:click="cancelForm" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
