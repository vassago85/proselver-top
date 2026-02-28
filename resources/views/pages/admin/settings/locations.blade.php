<?php
use App\Models\Location;
use App\Models\Company;
use App\Models\Zone;
use App\Services\GeocodingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterCompany = '';

    public bool $showAddForm = false;
    public ?int $editingId = null;

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

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterCompany(): void
    {
        $this->resetPage();
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
        if (!$this->addAddress) return;
        $result = GeocodingService::geocodeDetailed($this->addAddress);
        if ($result) {
            $this->addCity = $result['city'] ?? $this->addCity;
            $this->addProvince = $result['province'] ?? $this->addProvince;
            $this->addLat = (string) ($result['lat'] ?? '');
            $this->addLng = (string) ($result['lng'] ?? '');
        }
    }

    public function lookupEditAddress(): void
    {
        if (!$this->editAddress) return;
        $result = GeocodingService::geocodeDetailed($this->editAddress);
        if ($result) {
            $this->editCity = $result['city'] ?? $this->editCity;
            $this->editProvince = $result['province'] ?? $this->editProvince;
            $this->editLat = (string) ($result['lat'] ?? '');
            $this->editLng = (string) ($result['lng'] ?? '');
        }
    }

    public function startEdit(int $id): void
    {
        $loc = Location::findOrFail($id);
        $this->editingId        = $id;
        $this->editCompanyId    = (string) ($loc->company_id ?? '');
        $this->editCompanyName  = $loc->company_name;
        $this->editAddress      = $loc->address;
        $this->editCity         = $loc->city;
        $this->editProvince     = $loc->province;
        $this->editLat          = (string) ($loc->latitude ?? '');
        $this->editLng          = (string) ($loc->longitude ?? '');
        $this->editZoneId       = (string) ($loc->zone_id ?? '');
        $this->editCustomerName    = $loc->customer_name ?? '';
        $this->editCustomerPhone   = $loc->customer_phone ?? '';
        $this->editCustomerEmail   = $loc->customer_email ?? '';
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

        Location::findOrFail($this->editingId)->update([
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
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
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
    }

    public function with(): array
    {
        $query = Location::with(['company', 'zone'])
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->where('company_name', 'like', "%{$this->search}%")
                      ->orWhere('address', 'like', "%{$this->search}%");
                });
            })
            ->when($this->filterCompany, fn ($q) => $q->where('company_id', $this->filterCompany))
            ->orderBy('company_name');

        return [
            'locations' => $query->paginate(25),
            'companies' => Company::orderBy('name')->get(),
            'zones' => Zone::active()->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Locations</x-slot:header>

    <div class="space-y-6">
        {{-- Toolbar --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex flex-col sm:flex-row gap-3 flex-1">
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name or address…"
                       class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                <select wire:model.live="filterCompany"
                        class="w-full sm:w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="$toggle('showAddForm')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Add Location
            </button>
        </div>

        {{-- Add Form --}}
        @if($showAddForm)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">New Location</h3>
            <form wire:submit="add" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Owner Company</label>
                        <select wire:model="addCompanyId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">— None —</option>
                            @foreach($companies as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
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
                        <button type="button" wire:click="lookupAddAddress" class="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                            <span wire:loading.remove wire:target="lookupAddAddress">Lookup Address</span>
                            <span wire:loading wire:target="lookupAddAddress">Looking up...</span>
                        </button>
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
                        <select wire:model="addZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select zone...</option>
                            @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                        </select>
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

        {{-- Table --}}
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
                            @if($editingId === $loc->id)
                                <tr class="bg-blue-50/50">
                                    <td colspan="8" class="px-4 py-4">
                                        <form wire:submit="update" class="space-y-4">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-700 mb-1">Owner Company</label>
                                                    <select wire:model="editCompanyId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                                        <option value="">— None —</option>
                                                        @foreach($companies as $c)
                                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                                        @endforeach
                                                    </select>
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
                                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                                        <span wire:loading.remove wire:target="lookupEditAddress">Lookup Address</span>
                                                        <span wire:loading wire:target="lookupEditAddress">Looking up...</span>
                                                    </button>
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
                                                    <select wire:model="editZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                                        <option value="">Select zone...</option>
                                                        @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                                                    </select>
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
                                            <div class="flex items-center gap-3">
                                                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition">Save</button>
                                                <button type="button" wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @else
                                <tr class="hover:bg-gray-50">
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
                            @endif
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
    </div>
</div>
