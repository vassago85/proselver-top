<?php

use App\Models\Company;
use App\Models\Location;
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
        $this->reset(['company_name', 'address', 'city', 'province', 'latitude', 'longitude', 'customer_name', 'customer_phone', 'customer_email']);
    }

    public function with(): array
    {
        $query = Location::where('company_id', $this->company->id)
            ->orderBy('company_name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('company_name', 'like', "%{$this->search}%")
                    ->orWhere('city', 'like', "%{$this->search}%")
                    ->orWhere('customer_name', 'like', "%{$this->search}%")
                    ->orWhere('address', 'like', "%{$this->search}%");
            });
        }

        return [
            'locations' => $query->paginate(15),
            'canManage' => auth()->user()->hasAnyRole(['customer_owner', 'customer_admin']),
        ];
    }
};

?>

<div>
    <x-slot:header>Address Book</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Search & Add --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search locations..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <button wire:click="$toggle('showForm')" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            {{ $showForm ? 'Cancel' : 'Add Location' }}
        </button>
    </div>

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
