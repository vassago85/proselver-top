<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Location;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Manual "add a vehicle to stock" page.  The bulk importer covers the
 * normal case (DMS export of dozens of VINs); this single-vehicle
 * form covers the edge cases where a dealer needs to record one
 * vehicle that didn't come in via the usual flow -- most often a
 * unit shipped factory-direct to a linked body builder.
 *
 * The form lets the dealer pick the STARTING LOCATION (premises /
 * a linked BB yard / one of their own non-primary yards) so the
 * row lands in the right bucket immediately instead of being
 * created at premises and then "moved" via an artificial job.
 *
 * Status is hard-coded to AVAILABLE -- reserve and sell live on
 * the vehicle card after the row exists.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public ?Company $company = null;

    // Vehicle identity
    public string $vin = '';
    public string $suffix = '';
    public string $variant = '';
    public string $description = '';
    public string $engine_number = '';
    public string $colour = '';
    public string $registration = '';
    public ?int   $brand_id = null;
    public string $model_name = '';
    public ?int   $model_year = null;

    // Optional commercial pre-fill -- a dealer might be adding a
    // vehicle that's already had a salesperson assigned off-system.
    public ?int $salesperson_user_id = null;

    // Starting location: which bucket should the row land in?
    public string $location_type = DealerStock::LOCATION_PREMISES;
    public ?int   $body_builder_company_id  = null;
    public ?int   $body_builder_location_id = null;
    public ?int   $storage_location_id      = null;

    public function mount(): void
    {
        // Dealer-tenant only; same gating as the rest of the stock
        // module so OEM/BB users can't reach this URL.
        abort_unless(auth()->user()?->company()?->isDealer(), 404);
        abort_unless(auth()->user()?->hasPermission('manage_dealer_stock'), 403);
        $this->company = auth()->user()->company();
        $this->model_year = (int) date('Y');
    }

    /**
     * Resetting the BB location picker when the BB itself changes
     * stops a stale Location ID (belonging to the previously-picked
     * BB) sneaking through validation.
     */
    public function updatedBodyBuilderCompanyId(): void
    {
        $this->body_builder_location_id = null;
    }

    public function save(): void
    {
        // Normalise the VIN early -- duplicate detection and the
        // unique constraint are both VIN-cased.
        $this->vin = strtoupper(trim($this->vin));

        $rules = [
            'vin'           => 'required|string|max:32|min:6',
            'colour'        => 'nullable|string|max:60',
            'registration'  => 'nullable|string|max:20',
            'engine_number' => 'nullable|string|max:60',
            'suffix'        => 'nullable|string|max:60',
            'variant'       => 'nullable|string|max:80',
            'description'   => 'nullable|string|max:255',
            'model_name'    => 'nullable|string|max:80',
            'model_year'    => 'nullable|integer|min:1980|max:' . ((int) date('Y') + 2),
            'brand_id'      => 'nullable|integer|exists:brands,id',
            'salesperson_user_id' => 'nullable|integer|exists:users,id',
            'location_type' => 'required|in:' . implode(',', [
                DealerStock::LOCATION_PREMISES,
                DealerStock::LOCATION_BODY_BUILDER,
                DealerStock::LOCATION_STORAGE,
            ]),
        ];
        // Only require the linked Location when the dealer picks a
        // non-premises starting bucket -- premises = no location row
        // needed (current_location_id stays null).
        if ($this->location_type === DealerStock::LOCATION_BODY_BUILDER) {
            $rules['body_builder_company_id']  = 'required|integer|exists:companies,id';
            $rules['body_builder_location_id'] = 'required|integer|exists:locations,id';
        } elseif ($this->location_type === DealerStock::LOCATION_STORAGE) {
            $rules['storage_location_id'] = 'required|integer|exists:locations,id';
        }
        $this->validate($rules);

        // Idempotency-ish: don't let the same dealer enter the same VIN
        // twice.  Send them to the existing row instead.
        $existing = DealerStock::where('dealer_company_id', $this->company->id)
            ->where('vin', $this->vin)
            ->first();
        if ($existing) {
            session()->flash('info', "{$this->vin} is already on your books -- opened the existing record.");
            $this->redirect(route('customer.stock.show', $existing), navigate: true);
            return;
        }

        $currentLocationId = match ($this->location_type) {
            DealerStock::LOCATION_BODY_BUILDER => $this->body_builder_location_id,
            DealerStock::LOCATION_STORAGE      => $this->storage_location_id,
            default => null,
        };

        $stock = DealerStock::create([
            'dealer_company_id'     => $this->company->id,
            'vin'                   => $this->vin,
            'suffix'                => $this->suffix ?: null,
            'variant'               => $this->variant ?: null,
            'description'           => $this->description ?: null,
            'engine_number'         => $this->engine_number ?: null,
            'colour'                => $this->colour ?: null,
            'registration'          => $this->registration ?: null,
            'brand_id'              => $this->brand_id ?: null,
            'model_name'            => $this->model_name ?: null,
            'model_year'            => $this->model_year ?: null,
            'salesperson_user_id'   => $this->salesperson_user_id ?: null,
            'current_location_type' => $this->location_type,
            'current_location_id'   => $currentLocationId,
            'status'                => DealerStock::STATUS_AVAILABLE,
        ]);

        session()->flash('success', "Added {$stock->vin} to stock.");
        $this->redirect(route('customer.stock.show', $stock), navigate: true);
    }

    public function with(): array
    {
        // Body builders linked to this dealer -- only these are
        // eligible as a starting location; an unlinked BB shouldn't
        // suddenly appear in the picker.
        $bbCompanies = Company::query()
            ->where('type', Company::TYPE_BODY_BUILDER)
            ->whereHas('linkedDealers', fn ($l) => $l->where('companies.id', $this->company->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Yards owned by the currently-picked BB.  Pulled lazily so
        // the form doesn't query 10 BBs worth of locations on page
        // load when the user is going to pick just one.
        $bbLocations = $this->body_builder_company_id
            ? Location::where('company_id', $this->body_builder_company_id)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city'])
            : collect();

        // Storage = any Location the dealer owns (their own branches /
        // yards).  Third-party storage facilities would need to be
        // added via the locations module first, same as for orders.
        $storageLocations = Location::query()
            ->where('company_id', $this->company->id)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $brands = Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Sales-role users on this dealer's team.  Mirrors the
        // pattern in stock/show so the dropdown is consistent.
        $salesPeople = User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($c) => $c->where('companies.id', $this->company->id))
            ->whereHas('roles', fn ($r) => $r->where(function ($q) {
                $q->where('slug', 'like', 'sales\\_%')
                    ->orWhere('slug', 'customer_admin')
                    ->orWhere('slug', 'customer_owner')
                    ->orWhere('slug', 'dealer_principal');
            }))
            ->orderBy('name')
            ->get(['id', 'name']);

        return compact('bbCompanies', 'bbLocations', 'storageLocations', 'brands', 'salesPeople');
    }
};
?>

<div>
    <x-slot:header>Add vehicle to stock</x-slot:header>

    <div class="max-w-3xl">
        @if(session('success'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
            Use this when you need to record a single vehicle that didn't come in via your DMS import &mdash;
            most often a unit shipped factory-direct to a body builder.
            Got a whole list to load? Use <a href="{{ route('customer.stock.import') }}" class="font-semibold underline">Import stock</a> instead.
        </div>

        <form wire:submit="save" class="space-y-6">

            {{-- Identity ----------------------------------------------------- --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="text-sm font-semibold text-slate-900">Vehicle identity</h3>
                <p class="mt-0.5 text-xs text-slate-500">VIN is the only required field; you can fill the rest in later from the vehicle card.</p>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-700">VIN <span class="text-rose-600">*</span></label>
                        <input wire:model="vin" type="text" maxlength="32" required
                               class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm font-mono tracking-wider uppercase">
                        @error('vin') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Brand / Make</label>
                        <select wire:model="brand_id" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <option value="">&mdash; pick &mdash;</option>
                            @foreach($brands as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Model</label>
                        <input wire:model="model_name" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Suffix</label>
                        <input wire:model="suffix" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Variant</label>
                        <input wire:model="variant" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-700">Description</label>
                        <input wire:model="description" type="text" maxlength="255" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700">Engine number</label>
                        <input wire:model="engine_number" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Registration (blank for new)</label>
                        <input wire:model="registration" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm uppercase">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Colour</label>
                        <input wire:model="colour" type="text" class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700">Model year</label>
                        <input wire:model="model_year" type="number" min="1980" max="{{ (int) date('Y') + 2 }}"
                               class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-sm tabular-nums">
                        @error('model_year') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Starting location ------------------------------------------- --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="text-sm font-semibold text-slate-900">Where is the vehicle right now?</h3>
                <p class="mt-0.5 text-xs text-slate-500">Pick the bucket the row should land in. You can change this later from the vehicle card.</p>

                <div class="mt-4 space-y-3">
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                        <input wire:model.live="location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_PREMISES }}" class="mt-0.5">
                        <span class="text-sm">
                            <span class="font-medium text-slate-900">At my premises</span>
                            <span class="block text-xs text-slate-500">On the dealership floor; the normal starting state for new stock.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                        <input wire:model.live="location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_BODY_BUILDER }}" class="mt-0.5">
                        <span class="text-sm flex-1">
                            <span class="font-medium text-slate-900">At a body builder</span>
                            <span class="block text-xs text-slate-500">Shipped factory-direct, or already with the fitter when you took the unit on.</span>

                            @if($location_type === \App\Models\DealerStock::LOCATION_BODY_BUILDER)
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-600">Body builder</label>
                                        <select wire:model.live="body_builder_company_id"
                                                class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs">
                                            <option value="">&mdash; pick &mdash;</option>
                                            @foreach($bbCompanies as $bb)
                                                <option value="{{ $bb->id }}">{{ $bb->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($bbCompanies->isEmpty())
                                            <p class="mt-1 text-[11px] text-amber-700">No body builders linked yet &mdash; link one under <em>Body Builders</em> first.</p>
                                        @endif
                                        @error('body_builder_company_id') <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-slate-600">BB yard / location</label>
                                        <select wire:model="body_builder_location_id"
                                                @if(!$body_builder_company_id) disabled @endif
                                                class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs disabled:bg-slate-50 disabled:text-slate-400">
                                            <option value="">&mdash; pick &mdash;</option>
                                            @foreach($bbLocations as $loc)
                                                <option value="{{ $loc->id }}">{{ trim(($loc->company_name ?? '') . ($loc->city ? ' — ' . $loc->city : '')) }}</option>
                                            @endforeach
                                        </select>
                                        @error('body_builder_location_id') <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                        <input wire:model.live="location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_STORAGE }}" class="mt-0.5">
                        <span class="text-sm flex-1">
                            <span class="font-medium text-slate-900">At another storage / yard</span>
                            <span class="block text-xs text-slate-500">One of your own branches or yards (not your main premises).</span>

                            @if($location_type === \App\Models\DealerStock::LOCATION_STORAGE)
                                <div class="mt-3">
                                    <label class="block text-[11px] font-medium text-slate-600">Storage location</label>
                                    <select wire:model="storage_location_id"
                                            class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs">
                                        <option value="">&mdash; pick &mdash;</option>
                                        @foreach($storageLocations as $loc)
                                            <option value="{{ $loc->id }}">{{ trim(($loc->company_name ?? '') . ($loc->city ? ' — ' . $loc->city : '')) }}</option>
                                        @endforeach
                                    </select>
                                    @if($storageLocations->isEmpty())
                                        <p class="mt-1 text-[11px] text-amber-700">No storage locations on file. Add one under <em>Resources &rarr; Address Book</em> first.</p>
                                    @endif
                                    @error('storage_location_id') <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </span>
                    </label>
                </div>
            </section>

            {{-- Optional commercial pre-fill -------------------------------- --}}
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="text-sm font-semibold text-slate-900">Salesperson (optional)</h3>
                <p class="mt-0.5 text-xs text-slate-500">Who owns this unit on your sales floor? Leave blank and assign later from the vehicle card.</p>

                <div class="mt-4">
                    <select wire:model="salesperson_user_id" class="block w-full sm:w-72 rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">&mdash; none yet &mdash;</option>
                        @foreach($salesPeople as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            </section>

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('customer.stock.index') }}" class="text-sm text-slate-500 hover:text-slate-700">&larr; Back to stock</a>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    Add to stock
                </button>
            </div>
        </form>
    </div>
</div>
