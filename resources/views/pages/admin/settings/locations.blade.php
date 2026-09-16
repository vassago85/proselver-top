<?php
use App\Models\Location;
use App\Models\Company;
use App\Models\Zone;
use App\Services\GeocodingService;
use App\Services\LocationMergeService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterCompany = '';

    public bool $showAddForm = false;
    public ?int $editingId = null;

    /** Server-side suggestion results for the currently open form (add or edit). */
    public array $addAddressSuggestions = [];
    public array $editAddressSuggestions = [];

    /** Which tab of the "Clean up" flow is open (null = closed). */
    public ?string $cleanupTab = null;

    /**
     * "Needs address" cleanup state, keyed by location id.  Populated on
     * openCleanup('incomplete') so the operator can pick straight away
     * instead of triggering a per-row API call.
     */
    public array $cleanupIncomplete = [];

    // Deep-link: /admin/settings/locations?focus=123 auto-opens that
    // location's edit form on page load.  Order detail "Edit address"
    // buttons use this so ops doesn't have to hunt for the entry in
    // the list.
    #[Url(as: 'focus')] public ?int $focusLocationId = null;

    /**
     * Deep-link return: /admin/settings/locations?focus=123&return=/admin/orders/456
     * lets ops jump into the edit form from an order page and get back to
     * that exact URL after saving (or hitting Cancel / Back).  Validated
     * in mount() to same-origin only so this can't be abused as an open
     * redirect.  When set the page shows a "Back" banner up top and the
     * Save button becomes "Save & return".
     */
    #[Url(as: 'return')] public ?string $returnUrl = null;

    // Add form
    public string $addCompanyId = '';
    public string $addCompanyName = '';
    public string $addAddress = '';
    public string $addCity = '';
    public string $addProvince = '';
    public string $addLat = '';
    public string $addLng = '';
    public string $addZoneId = '';
    public string $addCustomerName = '';
    public string $addCustomerPhone = '';
    public string $addCustomerEmail = '';

    // Edit form
    public string $editCompanyId = '';
    public string $editCompanyName = '';
    public string $editAddress = '';
    public string $editCity = '';
    public string $editProvince = '';
    public string $editLat = '';
    public string $editLng = '';
    public string $editZoneId = '';
    public string $editCustomerName = '';
    public string $editCustomerPhone = '';
    public string $editCustomerEmail = '';

    public function mount(): void
    {
        // Auto-open the edit form when ?focus=id is in the URL --
        // entry point from the order detail "Edit address" links so
        // ops doesn't have to find the row manually.
        if ($this->focusLocationId) {
            $loc = Location::find($this->focusLocationId);
            if ($loc) {
                $this->startEdit($loc->id);
            }
        }

        // Sanitise the return URL: same-host only.  A path-only value
        // ("/admin/orders/123") is also fine.  Anything else (external
        // host, javascript: scheme, etc.) is discarded so an operator
        // can't be phished into leaving the app via a crafted link.
        $this->returnUrl = $this->sanitiseReturnUrl($this->returnUrl);
    }

    /**
     * Same-origin gate for the ?return= deep-link.  Accepts either an
     * absolute URL that matches the current request host or a rooted
     * path ("/foo/bar").  Everything else -> null.
     */
    private function sanitiseReturnUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        // Reject anything that looks like a scheme other than http(s).
        if (preg_match('#^(?!https?://)[a-z][a-z0-9+.-]*:#i', $url)) {
            return null;
        }
        // Rooted path — always in-app.
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        if (strcasecmp($parts['host'], request()->getHost()) !== 0) {
            return null;
        }
        return $url;
    }

    /**
     * Redirect back to the referring page with an optional flash message.
     * Used by both save-and-return and cancel-and-return so the "Back"
     * banner behaviour is one code path.
     */
    private function goBackWithFlash(?string $message = null): void
    {
        $target = $this->returnUrl;
        $this->returnUrl = null;
        $this->focusLocationId = null;
        if ($message) {
            session()->flash('success', $message);
        }
        $this->redirect($target, navigate: true);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        // Drop the deep-link once the operator starts searching — otherwise
        // ?focus=208 sticks in the URL and looks like a broken filter.
        $this->focusLocationId = null;
    }

    public function updatingFilterCompany(): void
    {
        $this->resetPage();
        $this->focusLocationId = null;
    }

    public function add(): void
    {
        $this->validate([
            'addCompanyId'    => 'nullable|exists:companies,id',
            'addCompanyName'  => 'required|string|max:255',
            'addAddress'      => 'required|string|max:255',
            'addCity'         => 'required|string|max:100',
            'addProvince'     => 'required|string|max:100',
            'addCustomerName' => 'nullable|string|max:255',
            'addCustomerPhone'   => 'nullable|string|max:50',
            'addCustomerEmail'   => 'nullable|email|max:255',
        ]);

        Location::create([
            'company_id'       => $this->addCompanyId ?: null,
            'zone_id'          => $this->addZoneId ?: null,
            'company_name'     => $this->addCompanyName,
            'address'          => $this->addAddress,
            'city'             => $this->addCity,
            'province'         => $this->addProvince,
            'latitude'         => $this->addLat ?: null,
            'longitude'        => $this->addLng ?: null,
            'customer_name'    => $this->addCustomerName ?: null,
            'customer_phone'   => $this->addCustomerPhone ?: null,
            'customer_email'   => $this->addCustomerEmail ?: null,
            'is_active'        => true,
        ]);

        $this->resetAddForm();
        $this->showAddForm = false;
    }

    public function lookupAddAddress(): void
    {
        $this->addAddressSuggestions = [];
        if (!$this->addAddress) return;
        $this->addAddressSuggestions = GeocodingService::suggest($this->addAddress, 4);

        // Backwards-compat: also apply the top hit to the form so the
        // single-click "Lookup Address" workflow still works when the
        // operator doesn't want to pick from a list.
        $top = $this->addAddressSuggestions[0] ?? null;
        if ($top) {
            $this->addCity = $top['city'] ?? $this->addCity;
            $this->addProvince = $top['province'] ?? $this->addProvince;
            $this->addLat = (string) ($top['lat'] ?? '');
            $this->addLng = (string) ($top['lng'] ?? '');
        }
    }

    public function lookupEditAddress(): void
    {
        $this->editAddressSuggestions = [];
        if (!$this->editAddress) return;
        $this->editAddressSuggestions = GeocodingService::suggest($this->editAddress, 4);

        $top = $this->editAddressSuggestions[0] ?? null;
        if ($top) {
            $this->editCity = $top['city'] ?? $this->editCity;
            $this->editProvince = $top['province'] ?? $this->editProvince;
            $this->editLat = (string) ($top['lat'] ?? '');
            $this->editLng = (string) ($top['lng'] ?? '');
        }
    }

    public function pickAddAddressSuggestion(int $index): void
    {
        $pick = $this->addAddressSuggestions[$index] ?? null;
        if (!$pick) return;
        $this->addAddress = (string) ($pick['formatted_address'] ?? $this->addAddress);
        $this->addCity = (string) ($pick['city'] ?? $this->addCity);
        $this->addProvince = (string) ($pick['province'] ?? $this->addProvince);
        $this->addLat = isset($pick['lat']) ? (string) $pick['lat'] : '';
        $this->addLng = isset($pick['lng']) ? (string) $pick['lng'] : '';
    }

    public function pickEditAddressSuggestion(int $index): void
    {
        $pick = $this->editAddressSuggestions[$index] ?? null;
        if (!$pick) return;
        $this->editAddress = (string) ($pick['formatted_address'] ?? $this->editAddress);
        $this->editCity = (string) ($pick['city'] ?? $this->editCity);
        $this->editProvince = (string) ($pick['province'] ?? $this->editProvince);
        $this->editLat = isset($pick['lat']) ? (string) $pick['lat'] : '';
        $this->editLng = isset($pick['lng']) ? (string) $pick['lng'] : '';
    }

    /**
     * Open the "Clean up" flow, honouring the current company filter so
     * ops can clean one customer's book at a time.  Loads suggestions
     * eagerly for the incomplete tab.
     */
    public function openCleanup(string $tab): void
    {
        if (!in_array($tab, ['incomplete', 'duplicates'], true)) {
            return;
        }
        $this->cleanupTab = $tab;

        if ($tab === 'incomplete') {
            $companyId = $this->filterCompany !== '' ? (int) $this->filterCompany : null;
            $service = app(LocationMergeService::class);
            $rows = $service->findIncompleteLocations($companyId);
            $state = [];
            foreach ($rows as $loc) {
                $query = trim((string) $loc->address);
                if ($query === '') {
                    $query = trim((string) $loc->company_name);
                }
                $state[$loc->id] = [
                    'suggestions' => $query !== '' ? GeocodingService::suggest($query, 4) : [],
                    'chosen' => null,
                ];
            }
            $this->cleanupIncomplete = $state;
        }
    }

    public function closeCleanup(): void
    {
        $this->cleanupTab = null;
        $this->cleanupIncomplete = [];
    }

    public function applyIncompleteSuggestion(int $locationId, int $index): void
    {
        $entry = $this->cleanupIncomplete[$locationId] ?? null;
        if (!$entry) return;
        $pick = $entry['suggestions'][$index] ?? null;
        if (!$pick) return;
        $location = Location::find($locationId);
        if (!$location) return;
        $location->update([
            'address' => (string) ($pick['formatted_address'] ?? $location->address),
            'city' => $pick['city'] ?? $location->city,
            'province' => $pick['province'] ?? $location->province,
            'latitude' => $pick['lat'] ?? null,
            'longitude' => $pick['lng'] ?? null,
        ]);
        $this->cleanupIncomplete[$locationId]['chosen'] = $index;
    }

    public function mergeDuplicateCluster(int $keeperId, array $absorbedIds): void
    {
        $merged = app(LocationMergeService::class)->mergeCluster($keeperId, $absorbedIds);
        session()->flash('cleanup_merged', $merged);
    }

    public function startEdit(int $id): void
    {
        $loc = Location::findOrFail($id);
        // All edit-form properties are typed `string`, but legacy rows
        // can carry NULLs in company_name / address / city / province
        // (the columns are nullable in the schema).  Coerce every read
        // to a string so opening the edit form via ?focus=ID never
        // explodes on a half-populated row -- the update() validator
        // still requires these fields, so ops can't save blanks.
        $this->editingId        = $id;
        $this->editCompanyId    = (string) ($loc->company_id ?? '');
        $this->editCompanyName  = (string) ($loc->company_name ?? '');
        $this->editAddress      = (string) ($loc->address ?? '');
        $this->editCity         = (string) ($loc->city ?? '');
        $this->editProvince     = (string) ($loc->province ?? '');
        $this->editLat          = (string) ($loc->latitude ?? '');
        $this->editLng          = (string) ($loc->longitude ?? '');
        $this->editZoneId       = (string) ($loc->zone_id ?? '');
        $this->editCustomerName    = (string) ($loc->customer_name ?? '');
        $this->editCustomerPhone   = (string) ($loc->customer_phone ?? '');
        $this->editCustomerEmail   = (string) ($loc->customer_email ?? '');
        $this->showAddForm = false;
        // Deep-link / edit must not depend on the row being on the
        // current paginated page — the form now lives above the table.
        $this->resetPage();
    }

    public function update(): void
    {
        $this->validate([
            'editCompanyId'    => 'nullable|exists:companies,id',
            'editCompanyName'  => 'required|string|max:255',
            'editAddress'      => 'required|string|max:255',
            'editCity'         => 'required|string|max:100',
            'editProvince'     => 'required|string|max:100',
            'editCustomerName' => 'nullable|string|max:255',
            'editCustomerPhone'   => 'nullable|string|max:50',
            'editCustomerEmail'   => 'nullable|email|max:255',
        ]);

        $loc = Location::findOrFail($this->editingId);
        $loc->update([
            'company_id'       => $this->editCompanyId ?: null,
            'zone_id'          => $this->editZoneId ?: null,
            'company_name'     => $this->editCompanyName,
            'address'          => $this->editAddress,
            'city'             => $this->editCity,
            'province'         => $this->editProvince,
            'latitude'         => $this->editLat ?: null,
            'longitude'        => $this->editLng ?: null,
            'customer_name'    => $this->editCustomerName ?: null,
            'customer_phone'   => $this->editCustomerPhone ?: null,
            'customer_email'   => $this->editCustomerEmail ?: null,
        ]);

        $this->editingId = null;
        $this->focusLocationId = null;
        $this->editAddressSuggestions = [];

        // Deep-link flow: jump straight back to the order (or wherever
        // the ?return= URL points) with a success flash so ops sees the
        // save landed and doesn't have to click Back manually.
        if ($this->returnUrl) {
            $this->goBackWithFlash("Address updated for {$loc->company_name}.");
            return;
        }

        session()->flash('success', "Address updated for {$loc->company_name}.");
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->focusLocationId = null;
        $this->editAddressSuggestions = [];
        // Same rule as save: if we came in from a ?return= deep-link,
        // Cancel should also drop the operator back where they were.
        if ($this->returnUrl) {
            $this->goBackWithFlash();
        }
    }

    public function toggle(int $id): void
    {
        $loc = Location::findOrFail($id);
        $loc->update(['is_active' => !$loc->is_active]);
    }

    private function resetAddForm(): void
    {
        $this->addCompanyId = '';
        $this->addCompanyName = '';
        $this->addAddress = '';
        $this->addCity = '';
        $this->addProvince = '';
        $this->addLat = '';
        $this->addLng = '';
        $this->addZoneId = '';
        $this->addCustomerName = '';
        $this->addCustomerPhone = '';
        $this->addCustomerEmail = '';
        $this->addAddressSuggestions = [];
    }

    public function with(): array
    {
        $companyId = $this->filterCompany !== '' ? (int) $this->filterCompany : null;
        $merger = app(LocationMergeService::class);
        $incompleteCount = $merger->findIncompleteLocations($companyId)->count();

        $duplicateClusters = collect();
        if ($this->cleanupTab === 'duplicates') {
            $duplicateClusters = $merger->findDuplicateClusters($companyId);
        }

        $query = Location::with(['company', 'zone'])
            ->when($this->search, function ($q) {
                // Postgres: LIKE is case-sensitive. OEM stubs are often
                // ALL CAPS ("MOTOR BODY…") so a lowercase search returned
                // zero rows and looked like search was broken.
                $term = '%' . $this->search . '%';
                $q->where(function ($q) use ($term) {
                    $q->where('company_name', 'ilike', $term)
                      ->orWhere('address', 'ilike', $term)
                      ->orWhere('city', 'ilike', $term);
                });
            })
            ->when($this->filterCompany, fn ($q) => $q->where('company_id', $this->filterCompany))
            ->orderBy('company_name');

        $companies = Company::orderBy('name')->get();
        $zones = Zone::active()->orderBy('name')->get();

        $companyOptions = $companies->map(fn ($c) => [
            'value' => (string) $c->id,
            'label' => $c->name,
        ])->values()->all();

        $zoneOptions = $zones->map(fn ($z) => [
            'value' => (string) $z->id,
            'label' => $z->name,
        ])->values()->all();

        return [
            'locations' => $query->paginate(25),
            'companies' => $companies,
            'zones' => $zones,
            'companyOptions' => $companyOptions,
            'zoneOptions' => $zoneOptions,
            'incompleteCount' => $incompleteCount,
            'duplicateClusters' => $duplicateClusters,
        ];
    }
};
?>
<div>
    <x-slot:header>Locations</x-slot:header>

    @php
        // "Deep-link edit" = arrived from an order page via ?focus=&return=.
        // While the operator is inside that edit form we hide the toolbar
        // /Add form / Cleanup panel so the page reads as a single focused
        // task instead of a busy dashboard with a form buried in it.
        $isDeepLinkEdit = $editingId !== null && $returnUrl !== null;
    @endphp

    <div class="space-y-6">
        {{-- Deep-link Back banner: only shown when we arrived from another
             page via ?return=.  Sits at the very top so ops can bail out
             at any moment without hunting for a link. --}}
        @if($returnUrl)
            <div class="flex items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5">
                <div class="flex items-center gap-2 text-sm text-blue-900">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    <span>You opened this from another page — Save or Cancel will take you back.</span>
                </div>
                <a href="{{ $returnUrl }}" wire:navigate
                   class="inline-flex items-center gap-1 rounded-md border border-blue-300 bg-white px-3 py-1.5 text-xs font-semibold text-blue-800 hover:bg-blue-100">
                    ← Back
                </a>
            </div>
        @endif

        @if(session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        {{-- Toolbar --}}
        @unless($isDeepLinkEdit)
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex flex-col sm:flex-row gap-3 flex-1">
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name or address…"
                       class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                <div class="w-full sm:w-56">
                    <x-searchable-select
                        wire:model.live="filterCompany"
                        :options="$companyOptions"
                        placeholder="All Companies"
                        search-placeholder="Search companies…"
                    />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button wire:click="openCleanup('incomplete')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    Clean up
                    @if($incompleteCount > 0)
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">{{ $incompleteCount }}</span>
                    @endif
                </button>
                <button wire:click="$toggle('showAddForm')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Add Location
                </button>
            </div>
        </div>
        @endunless

        {{-- Cleanup panel --}}
        @if($cleanupTab && !$isDeepLinkEdit)
            <div class="bg-white rounded-xl shadow-sm border border-blue-200 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            Clean up
                            @if($filterCompany)
                                <span class="text-sm font-normal text-slate-500">(scoped to selected company)</span>
                            @else
                                <span class="text-sm font-normal text-slate-500">(all companies)</span>
                            @endif
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">
                            Fix incomplete address-book entries and merge duplicates. Use the "All companies" filter above to widen or narrow scope.
                        </p>
                    </div>
                    <button wire:click="closeCleanup" class="text-sm font-medium text-slate-500 hover:text-slate-800">Close</button>
                </div>

                @if(session('cleanup_merged'))
                    <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                        Merged {{ session('cleanup_merged') }} duplicate row(s).
                    </div>
                @endif

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
                        <p class="text-sm text-slate-500 py-4">No incomplete locations found in this scope — every address has a street and coordinates.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($cleanupIncomplete as $locationId => $entry)
                                @php $loc = \App\Models\Location::find($locationId); @endphp
                                @if($loc)
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ $loc->company_name }}
                                                    @if($loc->company)<span class="text-xs font-normal text-slate-500">· {{ $loc->company->name }}</span>@endif
                                                </p>
                                                <p class="text-xs text-slate-500">Current address: {{ $loc->address ?: '(blank)' }}</p>
                                            </div>
                                            @if($entry['chosen'] !== null)
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-800">Updated</span>
                                            @endif
                                        </div>
                                        @if(!empty($entry['suggestions']))
                                            <div class="mt-2 space-y-1">
                                                @foreach($entry['suggestions'] as $sIdx => $sugg)
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
                        <p class="text-sm text-slate-500 py-4">No duplicate clusters found in this scope.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($duplicateClusters as $cluster)
                                @php $absorbedIds = collect($cluster['absorbed'])->pluck('id')->all(); @endphp
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $cluster['display'] }}</p>
                                            <p class="text-xs text-slate-500">{{ $cluster['company_name'] }}</p>
                                        </div>
                                        <button type="button"
                                                wire:click="mergeDuplicateCluster({{ $cluster['keeper']['id'] }}, {{ json_encode($absorbedIds) }})"
                                                wire:confirm="Merge {{ count($absorbedIds) }} duplicate(s) into the keeper? This action can't be undone via the UI."
                                                class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                                            Merge {{ count($absorbedIds) }} into keeper
                                        </button>
                                    </div>
                                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                        <div class="rounded-md border border-emerald-200 bg-emerald-50 p-2">
                                            <p class="font-semibold text-emerald-800">Keeper · #{{ $cluster['keeper']['id'] }} · {{ $cluster['keeper']['refs'] }} refs</p>
                                            <p class="text-emerald-700 mt-0.5">{{ $cluster['keeper']['display'] }}</p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-white p-2">
                                            <p class="font-semibold text-slate-700">Absorbed</p>
                                            <ul class="list-disc list-inside text-slate-600 mt-0.5">
                                                @foreach($cluster['absorbed'] as $abs)
                                                    <li>#{{ $abs['id'] }} — {{ $abs['display'] }} <span class="text-slate-400">({{ $abs['refs'] }} refs)</span></li>
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

        {{-- Add Form --}}
        @if($showAddForm && !$isDeepLinkEdit)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">New Location</h3>
            <form wire:submit="add" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Owner Company</label>
                        <x-searchable-select
                            wire:model="addCompanyId"
                            :options="$companyOptions"
                            placeholder="— None —"
                            search-placeholder="Search companies…"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Location Name *</label>
                        <input wire:model="addCompanyName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('addCompanyName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="placesAutocomplete({ addressModel: 'addAddress', cityModel: 'addCity', provinceModel: 'addProvince', latModel: 'addLat', lngModel: 'addLng' })">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Address *</label>
                        <input x-ref="addressInput" wire:model="addAddress" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Start typing to search...">
                        @error('addAddress')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        <div class="mt-1 flex flex-wrap items-center gap-3 text-xs">
                            <button type="button" wire:click="lookupAddAddress" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium">
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                <span wire:loading.remove wire:target="lookupAddAddress">Look up suggestions</span>
                                <span wire:loading wire:target="lookupAddAddress">Looking up...</span>
                            </button>
                            @if($addLat && $addLng)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-emerald-700 text-[11px] font-medium">
                                    {{ number_format((float) $addLat, 4) }}, {{ number_format((float) $addLng, 4) }}
                                </span>
                            @endif
                        </div>
                        @if(!empty($addAddressSuggestions))
                            <div class="mt-2 space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Google suggestions</p>
                                @foreach($addAddressSuggestions as $idx => $sugg)
                                    <button type="button" wire:click="pickAddAddressSuggestion({{ $idx }})"
                                            class="block w-full text-left rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-700 hover:bg-emerald-50 hover:border-emerald-200">
                                        {{ $sugg['formatted_address'] ?? '—' }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">City *</label>
                        <input wire:model="addCity" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('addCity')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Province *</label>
                        <input wire:model="addProvince" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('addProvince')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Zone</label>
                        <x-searchable-select
                            wire:model="addZoneId"
                            :options="$zoneOptions"
                            placeholder="Select zone..."
                            search-placeholder="Search zones…"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Name</label>
                        <input wire:model="addCustomerName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Phone</label>
                        <input wire:model="addCustomerPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Email</label>
                        <input wire:model="addCustomerEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('addCustomerEmail')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition">Save Location</button>
                    <button type="button" wire:click="$set('showAddForm', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Edit Form — always above the table so ?focus=ID works even when
             the row is on another paginated page (inline-in-row previously
             hid the form whenever the location was not on page 1). --}}
        @if($editingId)
        <div class="bg-white rounded-xl shadow-sm border border-blue-200 p-6">
            <div class="flex items-start justify-between gap-3 mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Edit Location #{{ $editingId }}</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Fix the street address (use autocomplete), then Save — coords refresh for tolls / advances.</p>
                </div>
                @if($editLat && $editLng)
                    <span class="shrink-0 inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                        {{ $editLat }}, {{ $editLng }}
                    </span>
                @else
                    <span class="shrink-0 inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800 border border-amber-200">
                        No coordinates
                    </span>
                @endif
            </div>
            <form wire:submit="update" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Owner Company</label>
                        <x-searchable-select
                            wire:model="editCompanyId"
                            :options="$companyOptions"
                            placeholder="— None —"
                            search-placeholder="Search companies…"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Location Name *</label>
                        <input wire:model="editCompanyName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editCompanyName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="placesAutocomplete({ addressModel: 'editAddress', cityModel: 'editCity', provinceModel: 'editProvince', latModel: 'editLat', lngModel: 'editLng' })">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Address *</label>
                        <input x-ref="addressInput" wire:model="editAddress" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Start typing to search...">
                        @error('editAddress')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        <button type="button" wire:click="lookupEditAddress" class="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                            <span wire:loading.remove wire:target="lookupEditAddress">Look up suggestions</span>
                            <span wire:loading wire:target="lookupEditAddress">Looking up...</span>
                        </button>
                        @if(!empty($editAddressSuggestions))
                            <div class="mt-2 space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Google suggestions</p>
                                @foreach($editAddressSuggestions as $idx => $sugg)
                                    <button type="button" wire:click="pickEditAddressSuggestion({{ $idx }})"
                                            class="block w-full text-left rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs text-slate-700 hover:bg-emerald-50 hover:border-emerald-200">
                                        {{ $sugg['formatted_address'] ?? '—' }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">City *</label>
                        <input wire:model="editCity" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editCity')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Province *</label>
                        <input wire:model="editProvince" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editProvince')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Zone</label>
                        <x-searchable-select
                            wire:model="editZoneId"
                            :options="$zoneOptions"
                            placeholder="Select zone..."
                            search-placeholder="Search zones…"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Name</label>
                        <input wire:model="editCustomerName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Phone</label>
                        <input wire:model="editCustomerPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Customer Email</label>
                        <input wire:model="editCustomerEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editCustomerEmail')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition">
                        {{ $returnUrl ? 'Save & return' : 'Save' }}
                    </button>
                    <button type="button" wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Table --}}
        @unless($isDeepLinkEdit)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">City</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Province</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zone</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Active</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($locations as $loc)
                            <tr class="hover:bg-gray-50 {{ $editingId === $loc->id ? 'bg-blue-50/70 ring-1 ring-inset ring-blue-200' : '' }}">
                                <td class="px-4 py-3 text-sm font-medium {{ $loc->is_active ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $loc->company_name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $loc->address }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $loc->city }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $loc->province }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $loc->zone?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $loc->company?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($loc->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="startEdit({{ $loc->id }})" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                        <button wire:click="toggle({{ $loc->id }})" class="text-sm {{ $loc->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                            {{ $loc->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-gray-500">No locations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($locations->hasPages())
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $locations->links() }}
            </div>
            @endif
        </div>
        @endunless
    </div>
</div>
