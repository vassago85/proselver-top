<?php
use App\Models\Zone;
use App\Models\ZoneRate;
use App\Models\VehicleClass;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $filterOrigin = '';
    public string $filterDestination = '';
    public string $filterVehicleClass = '';

    public bool $showAddForm = false;
    public ?int $editingId = null;

    public string $addOriginZoneId = '';
    public string $addDestinationZoneId = '';
    public string $addVehicleClassId = '';
    public string $addDistanceKm = '';
    public string $addPrice = '';

    public string $editDistanceKm = '';
    public string $editPrice = '';

    public function add(): void
    {
        $this->validate([
            'addOriginZoneId' => 'required|exists:zones,id',
            'addDestinationZoneId' => 'required|exists:zones,id',
            'addVehicleClassId' => 'required|exists:vehicle_classes,id',
            'addDistanceKm' => 'required|numeric|min:0.1',
            'addPrice' => 'required|numeric|min:0',
        ]);

        $exists = ZoneRate::where('origin_zone_id', $this->addOriginZoneId)
            ->where('destination_zone_id', $this->addDestinationZoneId)
            ->where('vehicle_class_id', $this->addVehicleClassId)
            ->exists();

        if ($exists) {
            $this->addError('addOriginZoneId', 'A rate for this zone pair and vehicle class already exists.');
            return;
        }

        ZoneRate::create([
            'origin_zone_id' => $this->addOriginZoneId,
            'destination_zone_id' => $this->addDestinationZoneId,
            'vehicle_class_id' => $this->addVehicleClassId,
            'distance_km' => $this->addDistanceKm,
            'price' => $this->addPrice,
        ]);

        $this->resetAddForm();
        session()->flash('success', 'Zone rate added.');
    }

    public function startEdit(int $id): void
    {
        $rate = ZoneRate::findOrFail($id);
        $this->editingId = $id;
        $this->editDistanceKm = (string) $rate->distance_km;
        $this->editPrice = (string) $rate->price;
    }

    public function update(): void
    {
        $this->validate([
            'editDistanceKm' => 'required|numeric|min:0.1',
            'editPrice' => 'required|numeric|min:0',
        ]);

        ZoneRate::findOrFail($this->editingId)->update([
            'distance_km' => $this->editDistanceKm,
            'price' => $this->editPrice,
        ]);

        $this->editingId = null;
        session()->flash('success', 'Zone rate updated.');
    }

    public function toggle(int $id): void
    {
        $rate = ZoneRate::findOrFail($id);
        $rate->update(['is_active' => !$rate->is_active]);
    }

    public function delete(int $id): void
    {
        ZoneRate::findOrFail($id)->delete();
        session()->flash('success', 'Zone rate deleted.');
    }

    public function cancelEdit(): void { $this->editingId = null; }

    private function resetAddForm(): void
    {
        $this->showAddForm = false;
        $this->addOriginZoneId = '';
        $this->addDestinationZoneId = '';
        $this->addVehicleClassId = '';
        $this->addDistanceKm = '';
        $this->addPrice = '';
    }

    public function updatingFilterOrigin(): void { $this->resetPage(); }
    public function updatingFilterDestination(): void { $this->resetPage(); }
    public function updatingFilterVehicleClass(): void { $this->resetPage(); }

    public function with(): array
    {
        $query = ZoneRate::with(['originZone', 'destinationZone', 'vehicleClass'])
            ->when($this->filterOrigin, fn ($q) => $q->where('origin_zone_id', $this->filterOrigin))
            ->when($this->filterDestination, fn ($q) => $q->where('destination_zone_id', $this->filterDestination))
            ->when($this->filterVehicleClass, fn ($q) => $q->where('vehicle_class_id', $this->filterVehicleClass))
            ->orderBy('origin_zone_id')
            ->orderBy('destination_zone_id')
            ->orderBy('vehicle_class_id');

        return [
            'rates' => $query->paginate(30),
            'zones' => Zone::active()->orderBy('name')->get(),
            'vehicleClasses' => VehicleClass::where('is_active', true)->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Zone Rates</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex flex-col sm:flex-row gap-3 flex-1">
                <select wire:model.live="filterOrigin" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">All Origins</option>
                    @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterDestination" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">All Destinations</option>
                    @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterVehicleClass" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">All Vehicle Classes</option>
                    @foreach($vehicleClasses as $vc)<option value="{{ $vc->id }}">{{ $vc->name }}</option>@endforeach
                </select>
            </div>
            <button wire:click="$toggle('showAddForm')" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Add Rate
            </button>
        </div>

        @if($showAddForm)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">New Zone Rate</h3>
            <form wire:submit="add" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Origin Zone *</label>
                        <select wire:model="addOriginZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">Select...</option>
                            @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                        </select>
                        @error('addOriginZoneId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Destination Zone *</label>
                        <select wire:model="addDestinationZoneId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">Select...</option>
                            @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Vehicle Class *</label>
                        <select wire:model="addVehicleClassId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">Select...</option>
                            @foreach($vehicleClasses as $vc)<option value="{{ $vc->id }}">{{ $vc->name }}</option>@endforeach
                        </select>
                        @error('addVehicleClassId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Distance (km) *</label>
                        <input wire:model="addDistanceKm" type="number" step="0.01" min="0.1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @error('addDistanceKm')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Price (ZAR) *</label>
                        <input wire:model="addPrice" type="number" step="0.01" min="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @error('addPrice')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Save Rate</button>
                    <button type="button" wire:click="$set('showAddForm', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                </div>
            </form>
        </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Origin</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">&rarr;</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Destination</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle Class</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Distance</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($rates as $rate)
                            @if($editingId === $rate->id)
                            <tr class="bg-blue-50/50">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $rate->originZone->name }}</td>
                                <td class="px-4 py-3 text-center text-gray-400">&rarr;</td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $rate->destinationZone->name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $rate->vehicleClass->name }}</td>
                                <td class="px-4 py-3">
                                    <input wire:model="editDistanceKm" type="number" step="0.01" min="0.1" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right">
                                    @error('editDistanceKm')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                </td>
                                <td class="px-4 py-3">
                                    <input wire:model="editPrice" type="number" step="0.01" min="0" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm text-right">
                                    @error('editPrice')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                </td>
                                <td></td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="update" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Save</button>
                                        <button wire:click="cancelEdit" class="text-sm text-gray-500 hover:text-gray-700 font-medium">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                            @else
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm font-medium {{ $rate->is_active ? 'text-gray-900' : 'text-gray-400' }}">{{ $rate->originZone->name }}</td>
                                <td class="px-4 py-3 text-center text-gray-400">&rarr;</td>
                                <td class="px-4 py-3 text-sm font-medium {{ $rate->is_active ? 'text-gray-900' : 'text-gray-400' }}">{{ $rate->destinationZone->name }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $rate->vehicleClass->name }}</td>
                                <td class="px-4 py-3 text-sm text-right text-gray-600">{{ number_format($rate->distance_km, 1) }} km</td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">R{{ number_format($rate->price, 2) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $rate->is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">{{ $rate->is_active ? 'Yes' : 'No' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="startEdit({{ $rate->id }})" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                        <button wire:click="toggle({{ $rate->id }})" class="text-sm {{ $rate->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">{{ $rate->is_active ? 'Disable' : 'Enable' }}</button>
                                        <button wire:click="delete({{ $rate->id }})" wire:confirm="Delete this rate?" class="text-sm text-red-600 hover:text-red-800 font-medium">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            @endif
                        @empty
                        <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-gray-500">No zone rates found. Add your first rate above.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($rates->hasPages())
            <div class="border-t border-gray-200 px-4 py-3">{{ $rates->links() }}</div>
            @endif
        </div>

        <a href="{{ route('admin.settings.zones') }}" class="inline-flex rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Back to Zones</a>
    </div>
</div>
