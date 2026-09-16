<?php

use App\Models\Company;
use App\Models\Location;
use App\Services\GeocodingService;
use App\Services\LocationMergeService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?Company $company = null;

    #[Url]
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $company_name = '';
    public string $address = '';
    public string $city = '';
    public string $province = '';
    public string $latitude = '';
    public string $longitude = '';
    public string $customer_name = '';
    public string $customer_phone = '';
    public string $customer_email = '';

    /**
     * Server-side "Look up suggestions" results for the current form.
     * Each entry has {formatted_address, city, province, lat, lng}.
     * Emptied on form open / save / cancel so it never persists across
     * unrelated edits.
     */
    public array $addressSuggestions = [];

    /** Which tab of the "Clean up address book" view is active, or null when closed. */
    public ?string $cleanupTab = null;

    /**
     * "Needs address" cleanup state: per-location suggestions plus the
     * operator's chosen index.  Keyed by location id.
     *   [
     *     42 => [
     *       'suggestions' => [...],
     *       'chosen' => 0,
     *     ],
     *   ]
     */
    public array $cleanupIncomplete = [];

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'company_name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
        ]);

        $validated['company_id'] = $this->company->id;

        // Eloquent's decimal cast can't handle '' from the form — normalise.
        $validated['latitude'] = $validated['latitude'] === '' ? null : $validated['latitude'];
        $validated['longitude'] = $validated['longitude'] === '' ? null : $validated['longitude'];

        if ($this->editingId) {
            $location = Location::where('company_id', $this->company->id)->findOrFail($this->editingId);
            $location->update($validated);
            session()->flash('success', 'Location updated successfully.');
        } else {
            Location::create($validated);
            session()->flash('success', 'Location added successfully.');
        }

        $this->resetForm();
    }

    /**
     * Server-side address lookup for the current form.  Used when
     * Places autocomplete isn't enough -- e.g. the operator pasted a
     * dealer name and needs to pick from the geocoder's guesses.
     */
    public function lookupAddress(): void
    {
        $this->addressSuggestions = [];
        $query = trim($this->address);
        if ($query === '') {
            return;
        }
        $this->addressSuggestions = GeocodingService::suggest($query, 4);
    }

    /**
     * Apply a server-side suggestion into the current form -- mirrors
     * the browser Places autocomplete write-through.  The user still
     * has to press Save to commit.
     */
    public function pickAddressSuggestion(int $index): void
    {
        $pick = $this->addressSuggestions[$index] ?? null;
        if (!$pick) {
            return;
        }
        $this->address = (string) ($pick['formatted_address'] ?? '');
        $this->city = (string) ($pick['city'] ?? '');
        $this->province = (string) ($pick['province'] ?? '');
        $this->latitude = isset($pick['lat']) ? (string) $pick['lat'] : '';
        $this->longitude = isset($pick['lng']) ? (string) $pick['lng'] : '';
    }

    /**
     * Open the "Clean up address book" flow on the given tab.  This
     * must stay fast -- we do NOT eager-fetch Google suggestions here
     * because 30 stubs would mean 30 sequential API calls (and a
     * 15+ second wait where the button appears to "do nothing").
     * Suggestions load per-row on demand via lookupIncomplete().
     */
    public function openCleanup(string $tab): void
    {
        if (!in_array($tab, ['incomplete', 'duplicates'], true)) {
            return;
        }
        $this->cleanupTab = $tab;

        if ($tab === 'incomplete') {
            $service = app(LocationMergeService::class);
            $rows = $service->findIncompleteLocations($this->company->id);
            $state = [];
            foreach ($rows as $loc) {
                // 'suggestions' starts as null so the view can tell
                // "not fetched yet" (show a Look up button) apart from
                // "fetched, empty" (show a manual-edit hint).
                $state[$loc->id] = [
                    'suggestions' => null,
                    'chosen' => null,
                ];
            }
            $this->cleanupIncomplete = $state;
        }
    }

    /**
     * Fetch Google suggestions for a single incomplete row on demand.
     * Called from the per-row "Look up" button so a customer only pays
     * the API cost for rows they actually plan to fix.
     */
    public function lookupIncomplete(int $locationId): void
    {
        if (!isset($this->cleanupIncomplete[$locationId])) {
            return;
        }
        $location = Location::where('company_id', $this->company->id)->find($locationId);
        if (!$location) {
            return;
        }
        $query = trim((string) $location->address);
        if ($query === '') {
            $query = trim((string) $location->company_name);
        }
        $this->cleanupIncomplete[$locationId]['suggestions'] =
            $query !== '' ? GeocodingService::suggest($query, 4) : [];
    }

    public function closeCleanup(): void
    {
        $this->cleanupTab = null;
        $this->cleanupIncomplete = [];
    }

    /**
     * Apply a picked suggestion to an incomplete location.  Writes
     * formatted_address / city / province / lat / lng in one shot so
     * the Location::saving hook doesn't have to geocode again.
     */
    public function applyIncompleteSuggestion(int $locationId, int $index): void
    {
        $entry = $this->cleanupIncomplete[$locationId] ?? null;
        if (!$entry) {
            return;
        }
        $pick = $entry['suggestions'][$index] ?? null;
        if (!$pick) {
            return;
        }
        $location = Location::where('company_id', $this->company->id)->find($locationId);
        if (!$location) {
            return;
        }
        $location->update([
            'address' => (string) ($pick['formatted_address'] ?? $location->address),
            'city' => $pick['city'] ?? $location->city,
            'province' => $pick['province'] ?? $location->province,
            'latitude' => $pick['lat'] ?? null,
            'longitude' => $pick['lng'] ?? null,
        ]);
        $this->cleanupIncomplete[$locationId]['chosen'] = $index;
        session()->flash('success', 'Address updated for ' . $location->company_name . '.');
    }

    /**
     * Merge the currently-shown duplicate cluster.  Wraps
     * LocationMergeService::mergeCluster so the FK-repointing rules
     * stay in one place; the customer view scopes strictly to the
     * user's own company so cross-tenant merges are impossible.
     */
    public function mergeDuplicateCluster(int $keeperId, array $absorbedIds): void
    {
        $keeper = Location::where('company_id', $this->company->id)->find($keeperId);
        if (!$keeper) {
            return;
        }
        // Absorbed rows must belong to the same company as the keeper.
        $safe = Location::where('company_id', $this->company->id)
            ->whereIn('id', $absorbedIds)
            ->pluck('id')
            ->all();
        if (empty($safe)) {
            return;
        }
        $merged = app(LocationMergeService::class)->mergeCluster($keeperId, $safe);
        session()->flash('success', "Merged {$merged} duplicate row(s) into “{$keeper->company_name}”.");
    }

    public function edit(int $id): void
    {
        $location = Location::where('company_id', $this->company->id)->findOrFail($id);

        $this->editingId = $location->id;
        $this->company_name = $location->company_name ?? '';
        $this->address = $location->address ?? '';
        $this->city = $location->city ?? '';
        $this->province = $location->province ?? '';
        $this->latitude = $location->latitude ?? '';
        $this->longitude = $location->longitude ?? '';
        $this->customer_name = $location->customer_name ?? '';
        $this->customer_phone = $location->customer_phone ?? '';
        $this->customer_email = $location->customer_email ?? '';
        $this->showForm = true;
    }

    public function toggleActive(int $id): void
    {
        $user = auth()->user();
        abort_unless(
            $user->hasAnyRole(['customer_owner', 'customer_admin']),
            403,
            'Only owners and admins can change location status.'
        );

        $location = Location::where('company_id', $this->company->id)->findOrFail($id);
        $location->update(['is_active' => !$location->is_active]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->showForm = false;
        $this->addressSuggestions = [];
        $this->reset(['company_name', 'address', 'city', 'province', 'latitude', 'longitude', 'customer_name', 'customer_phone', 'customer_email']);
    }

    public function with(): array
    {
        $user = auth()->user();
        $visibleCompanyIds = $user->visibleCompanyIds();

        // Read-side scope spans every dealership this user can see (a
        // franchise CEO browses the combined address book of all their
        // dealerships).  Single-dealership users get the same result
        // as the previous where('company_id', ...) call.
        $query = Location::whereIn('company_id', $visibleCompanyIds)
            ->with('company:id,name')
            ->orderBy('company_name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('company_name', 'like', "%{$this->search}%")
                    ->orWhere('city', 'like', "%{$this->search}%")
                    ->orWhere('customer_name', 'like', "%{$this->search}%")
                    ->orWhere('address', 'like', "%{$this->search}%");
            });
        }

        $visibleCompanies = count($visibleCompanyIds) > 1
            ? Company::whereIn('id', $visibleCompanyIds)->orderBy('name')->get(['id', 'name'])
            : collect();

        $merger = app(LocationMergeService::class);
        $incompleteCount = $merger->findIncompleteLocations($this->company->id)->count();
        $duplicateClusters = collect();
        if ($this->cleanupTab === 'duplicates') {
            $duplicateClusters = $merger->findDuplicateClusters($this->company->id);
        }

        return [
            'locations' => $query->paginate(15),
            'canManage' => $user->hasAnyRole(['customer_owner', 'customer_admin']),
            'visibleCompanies' => $visibleCompanies,
            'isMultiCompany' => $visibleCompanies->isNotEmpty(),
            'incompleteCount' => $incompleteCount,
            'duplicateClusters' => $duplicateClusters,
        ];
    }
};

?>

<div>
    <x-slot:header>Address Book</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Dealership chip strip (group principals only). The save form
         and toggleActive() still write against $this->company (the
         user's primary dealership), so this filter only narrows what
         a CEO sees in the table -- it doesn't change who owns new
         locations. --}}
    @if($isMultiCompany)
        <p class="mb-3 text-xs text-slate-500">Showing locations across {{ $visibleCompanies->count() }} dealerships. New locations are saved against your primary dealership ({{ $company->name }}).</p>
    @endif

    {{-- Search & Add --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search locations..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <div class="flex items-center gap-2">
            @if($canManage)
                <button wire:click="openCleanup('incomplete')"
                        wire:loading.attr="disabled"
                        wire:target="openCleanup"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-60">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    <span wire:loading.remove wire:target="openCleanup">Clean up</span>
                    <span wire:loading wire:target="openCleanup">Opening…</span>
                    @if($incompleteCount > 0)
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">{{ $incompleteCount }}</span>
                    @endif
                </button>
            @endif
            <button wire:click="$toggle('showForm')" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                {{ $showForm ? 'Cancel' : 'Add Location' }}
            </button>
        </div>
    </div>

    {{-- Cleanup panel --}}
    @if($cleanupTab && $canManage)
        <div class="mb-6 bg-white rounded-xl shadow-sm border border-blue-200 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Clean up address book</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        Fix incomplete locations and merge duplicates so tolls, routes and address matching all work correctly.
                    </p>
                </div>
                <button wire:click="closeCleanup" class="text-sm font-medium text-slate-500 hover:text-slate-800">
                    Close
                </button>
            </div>

            <div class="flex gap-2 border-b border-slate-200 mb-4">
                <button wire:click="openCleanup('incomplete')"
                        class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $cleanupTab === 'incomplete' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Needs address ({{ $incompleteCount }})
                </button>
                <button wire:click="openCleanup('duplicates')"
                        class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $cleanupTab === 'duplicates' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
                    Duplicates
                </button>
            </div>

            @if($cleanupTab === 'incomplete')
                @if(empty($cleanupIncomplete))
                    <p class="text-sm text-slate-500 py-4">No incomplete locations found — every address has a street and coordinates.</p>
                @else
                    <div class="space-y-3">
                        @foreach($cleanupIncomplete as $locationId => $entry)
                            @php $loc = \App\Models\Location::find($locationId); @endphp
                            @if($loc && $loc->company_id === $company->id)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $loc->company_name }}</p>
                                            <p class="text-xs text-slate-500">Current address: {{ $loc->address ?: '(blank)' }}</p>
                                        </div>
                                        @if($entry['chosen'] !== null)
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-800">Updated</span>
                                        @endif
                                    </div>
                                    @php $suggs = $entry['suggestions']; @endphp
                                    @if($suggs === null)
                                        {{-- Suggestions load on demand so the panel opens
                                             instantly even when the book has many stubs. --}}
                                        <div class="mt-2">
                                            <button type="button" wire:click="lookupIncomplete({{ $locationId }})"
                                                    class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                                <span wire:loading.remove wire:target="lookupIncomplete({{ $locationId }})">Look up suggestions</span>
                                                <span wire:loading wire:target="lookupIncomplete({{ $locationId }})">Looking up…</span>
                                            </button>
                                        </div>
                                    @elseif(!empty($suggs))
                                        <div class="mt-2 space-y-1">
                                            @foreach($suggs as $sIdx => $sugg)
                                                <button type="button"
                                                        wire:click="applyIncompleteSuggestion({{ $locationId }}, {{ $sIdx }})"
                                                        class="block w-full text-left rounded-md border {{ $entry['chosen'] === $sIdx ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-white hover:bg-emerald-50 hover:border-emerald-200' }} px-2 py-1.5 text-xs text-slate-700">
                                                    {{ $sugg['formatted_address'] ?? '—' }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="mt-2 text-xs text-amber-700">No suggestions — open the row's Edit form and type a street address manually.</p>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @elseif($cleanupTab === 'duplicates')
                @if($duplicateClusters->isEmpty())
                    <p class="text-sm text-slate-500 py-4">No duplicate clusters found — every address name+street combination is unique.</p>
                @else
                    <div class="space-y-3">
                        @foreach($duplicateClusters as $cluster)
                            @php $absorbedIds = collect($cluster['absorbed'])->pluck('id')->all(); @endphp
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-sm font-semibold text-slate-900">{{ $cluster['display'] }}</p>
                                    <button type="button"
                                            wire:click="mergeDuplicateCluster({{ $cluster['keeper']['id'] }}, {{ json_encode($absorbedIds) }})"
                                            wire:confirm="Merge {{ count($absorbedIds) }} duplicate(s) into the keeper? This action can't be undone via the UI."
                                            class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                                        Merge {{ count($absorbedIds) }} into keeper
                                    </button>
                                </div>
                                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-md border border-emerald-200 bg-emerald-50 p-2">
                                        <p class="font-semibold text-emerald-800">Keeper · {{ $cluster['keeper']['refs'] }} refs</p>
                                        <p class="text-emerald-700 mt-0.5">{{ $cluster['keeper']['display'] }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-white p-2">
                                        <p class="font-semibold text-slate-700">Absorbed</p>
                                        <ul class="list-disc list-inside text-slate-600 mt-0.5">
                                            @foreach($cluster['absorbed'] as $abs)
                                                <li>{{ $abs['display'] }} <span class="text-slate-400">({{ $abs['refs'] }} refs)</span></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif

    {{-- Inline Form --}}
    @if($showForm)
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $editingId ? 'Edit Location' : 'Add New Location' }}</h3>
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location Name <span class="text-red-500">*</span></label>
                    <input wire:model="company_name" type="text" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="e.g. Head Office, Warehouse A">
                    @error('company_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2" x-data="placesAutocomplete({ addressModel: 'address', cityModel: 'city', provinceModel: 'province', latModel: 'latitude', lngModel: 'longitude' })">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" x-ref="addressInput" wire:model="address"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Start typing to search...">
                    @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                        <button type="button" wire:click="lookupAddress" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                            <span wire:loading.remove wire:target="lookupAddress">Look up suggestions</span>
                            <span wire:loading wire:target="lookupAddress">Looking up...</span>
                        </button>

                        {{-- Confirmation strip: what will actually be saved once the user hits Save. --}}
                        @if($address || $city || $province)
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if($latitude && $longitude)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-emerald-700 text-[11px] font-medium">
                                        {{ number_format((float) $latitude, 4) }}, {{ number_format((float) $longitude, 4) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-amber-800 text-[11px] font-medium">
                                        No coordinates
                                    </span>
                                @endif
                                @if($city)<span class="text-slate-500">{{ $city }}</span>@endif
                                @if($province)<span class="text-slate-400">· {{ $province }}</span>@endif
                            </div>
                        @endif
                    </div>

                    @if(!empty($addressSuggestions))
                        <div class="mt-2 space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Google suggestions</p>
                            @foreach($addressSuggestions as $idx => $sugg)
                                <button type="button" wire:click="pickAddressSuggestion({{ $idx }})"
                                        class="block w-full text-left rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-700 hover:bg-emerald-50 hover:border-emerald-200">
                                    {{ $sugg['formatted_address'] ?? '—' }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input wire:model="city" type="text"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 bg-gray-50">
                    @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                    <input wire:model="province" type="text"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 bg-gray-50">
                    @error('province') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Name</label>
                    <input wire:model="customer_name" type="text"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('customer_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Phone</label>
                    <input wire:model="customer_phone" type="text"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('customer_phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Email</label>
                    <input wire:model="customer_email" type="email"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('customer_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <input type="hidden" wire:model="latitude">
                <input type="hidden" wire:model="longitude">
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    {{ $editingId ? 'Update Location' : 'Save Location' }}
                </button>
                <button type="button" wire:click="resetForm" class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- Locations Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    @if($isMultiCompany)
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dealership</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($locations as $location)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $location->company_name }}</td>
                    @if($isMultiCompany)
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $location->company?->name ?? '—' }}</td>
                    @endif
                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">{{ $location->address ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $location->city ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $location->customer_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $location->customer_phone ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($location->is_active)
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Inactive</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <button wire:click="edit({{ $location->id }})" class="text-sm font-medium text-blue-600 hover:text-blue-800">Edit</button>
                            @if($canManage)
                                <button wire:click="toggleActive({{ $location->id }})" wire:confirm="Are you sure you want to {{ $location->is_active ? 'deactivate' : 'activate' }} this location?"
                                    class="text-sm font-medium {{ $location->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }}">
                                    {{ $location->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                        No locations found. Click "Add Location" to create your first address.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $locations->links() }}
    </div>
</div>
