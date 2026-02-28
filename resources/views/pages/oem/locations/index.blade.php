<?php
use App\Models\Location;
use App\Models\Zone;
use App\Services\GeocodingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showAddForm = false;
    public ?int $editingId = null;

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

    public function add(): void
    {
        $this->validate([
            'addCompanyName' => 'required|string|max:255',
            'addAddress' => 'required|string|max:500',
            'addCity' => 'required|string|max:255',
            'addProvince' => 'required|string|max:255',
            'addCustomerName' => 'nullable|string|max:255',
            'addCustomerPhone' => 'nullable|string|max:20',
            'addCustomerEmail' => 'nullable|email|max:255',
        ]);

        Location::create([
            'company_id' => auth()->user()->company()->id,
            'zone_id' => $this->addZoneId ?: null,
            'company_name' => $this->addCompanyName,
            'address' => $this->addAddress,
            'city' => $this->addCity,
            'province' => $this->addProvince,
            'latitude' => $this->addLat ?: null,
            'longitude' => $this->addLng ?: null,
            'customer_name' => $this->addCustomerName ?: null,
            'customer_phone' => $this->addCustomerPhone ?: null,
            'customer_email' => $this->addCustomerEmail ?: null,
        ]);

        $this->resetAddForm();
        session()->flash('success', 'Location added.');
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
            session()->flash('success', 'Address resolved successfully.');
        } else {
            session()->flash('error', 'Could not resolve address. Please fill city and province manually.');
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
            session()->flash('success', 'Address resolved successfully.');
        } else {
            session()->flash('error', 'Could not resolve address. Please fill city and province manually.');
        }
    }

    public function startEdit(int $id): void
    {
        $location = Location::where('company_id', auth()->user()->company()->id)->findOrFail($id);
        $this->editingId = $id;
        $this->editCompanyName = $location->company_name ?? '';
        $this->editAddress = $location->address ?? '';
        $this->editCity = $location->city ?? '';
        $this->editProvince = $location->province ?? '';
        $this->editLat = (string) ($location->latitude ?? '');
        $this->editLng = (string) ($location->longitude ?? '');
        $this->editZoneId = (string) ($location->zone_id ?? '');
        $this->editCustomerName = $location->customer_name ?? '';
        $this->editCustomerPhone = $location->customer_phone ?? '';
        $this->editCustomerEmail = $location->customer_email ?? '';
    }

    public function update(): void
    {
        $this->validate([
            'editCompanyName' => 'required|string|max:255',
            'editAddress' => 'required|string|max:500',
            'editCity' => 'required|string|max:255',
            'editProvince' => 'required|string|max:255',
            'editCustomerName' => 'nullable|string|max:255',
            'editCustomerPhone' => 'nullable|string|max:20',
            'editCustomerEmail' => 'nullable|email|max:255',
        ]);

        $location = Location::where('company_id', auth()->user()->company()->id)->findOrFail($this->editingId);

        $location->update([
            'company_name' => $this->editCompanyName,
            'address' => $this->editAddress,
            'city' => $this->editCity,
            'province' => $this->editProvince,
            'latitude' => $this->editLat ?: null,
            'longitude' => $this->editLng ?: null,
            'zone_id' => $this->editZoneId ?: null,
            'customer_name' => $this->editCustomerName ?: null,
            'customer_phone' => $this->editCustomerPhone ?: null,
            'customer_email' => $this->editCustomerEmail ?: null,
        ]);

        $this->editingId = null;
        session()->flash('success', 'Location updated.');
    }

    public function toggle(int $id): void
    {
        $location = Location::where('company_id', auth()->user()->company()->id)->findOrFail($id);
        $location->update(['is_active' => !$location->is_active]);
    }

    public function cancelEdit(): void { $this->editingId = null; }

    private function resetAddForm(): void
    {
        $this->showAddForm = false;
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

    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        $companyId = auth()->user()->company()->id;
        $query = Location::with('zone')->where('company_id', $companyId)->orderBy('company_name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('company_name', 'like', "%{$this->search}%")
                  ->orWhere('city', 'like', "%{$this->search}%")
                  ->orWhere('customer_name', 'like', "%{$this->search}%");
            });
        }

        return [
            'locations' => $query->paginate(25),
            'zones' => Zone::active()->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Locations</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, city or contact..."
            class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
        <button wire:click="$set('showAddForm', true)" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + Add Location
        </button>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Add Location Form --}}
    @if($showAddForm)
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Location</h3>
        <form wire:submit="add" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company / Location Name *</label>
                    <input wire:model="addCompanyName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addCompanyName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div x-data="placesAutocomplete({ addressModel: 'addAddress', cityModel: 'addCity', provinceModel: 'addProvince', latModel: 'addLat', lngModel: 'addLng' })">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address *</label>
                    <input x-ref="addressInput" wire:model="addAddress" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Start typing to search...">
                    @error('addAddress') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <button type="button" wire:click="lookupAddAddress" class="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <span wire:loading.remove wire:target="lookupAddAddress">Lookup Address</span>
                        <span wire:loading wire:target="lookupAddAddress">Looking up...</span>
                    </button>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City *</label>
                    <input wire:model="addCity" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addCity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Province *</label>
                    <input wire:model="addProvince" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addProvince') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                    <select wire:model="addZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Select zone...</option>
                        @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                    <input wire:model="addCustomerName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addCustomerName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input wire:model="addCustomerPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addCustomerPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="addCustomerEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addCustomerEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">Save</button>
                <button type="button" wire:click="$set('showAddForm', false)" class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
    @endif

    {{-- Locations Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Zone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($locations as $location)
                    @if($editingId === $location->id)
                    <tr class="bg-blue-50">
                        <td colspan="7" class="px-6 py-4">
                            <form wire:submit="update" class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Company / Location Name *</label>
                                        <input wire:model="editCompanyName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editCompanyName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div x-data="placesAutocomplete({ addressModel: 'editAddress', cityModel: 'editCity', provinceModel: 'editProvince', latModel: 'editLat', lngModel: 'editLng' })">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Address *</label>
                                        <input x-ref="addressInput" wire:model="editAddress" type="text" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Start typing to search...">
                                        @error('editAddress') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                        <button type="button" wire:click="lookupEditAddress" class="mt-1 inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                            <span wire:loading.remove wire:target="lookupEditAddress">Lookup Address</span>
                                            <span wire:loading wire:target="lookupEditAddress">Looking up...</span>
                                        </button>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">City *</label>
                                        <input wire:model="editCity" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editCity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Province *</label>
                                        <input wire:model="editProvince" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editProvince') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                                        <select wire:model="editZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                            <option value="">Select zone...</option>
                                            @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                                        <input wire:model="editCustomerName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editCustomerName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                        <input wire:model="editCustomerPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editCustomerPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="lg:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                        <input wire:model="editCustomerEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editCustomerEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @else
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $location->company_name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $location->address ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $location->city ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $location->zone?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $location->customer_name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $location->customer_phone ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $location->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $location->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <button wire:click="startEdit({{ $location->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                <button wire:click="toggle({{ $location->id }})" wire:confirm="Are you sure you want to {{ $location->is_active ? 'deactivate' : 'activate' }} this location?"
                                    class="{{ $location->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                    {{ $location->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endif
                @empty
                <tr><td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">No locations found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($locations instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-4">{{ $locations->links() }}</div>
    @endif
</div>
