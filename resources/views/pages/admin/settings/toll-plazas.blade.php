<?php
use App\Models\TollPlaza;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination, WithFileUploads;

    public string $search = '';
    public string $filterRoad = '';

    public ?int $editingId = null;
    public string $editPlazaName = '';
    public string $editRoadName = '';
    public string $editPlazaType = '';
    public string $editClass1Fee = '';
    public string $editClass2Fee = '';
    public string $editClass3Fee = '';
    public string $editClass4Fee = '';
    public string $editEffectiveFrom = '';

    public $csvFile = null;

    public function with(): array
    {
        $query = TollPlaza::query()->orderBy('road_name')->orderBy('plaza_name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('plaza_name', 'like', "%{$this->search}%")
                  ->orWhere('road_name', 'like', "%{$this->search}%");
            });
        }

        if ($this->filterRoad) {
            $query->where('road_name', $this->filterRoad);
        }

        return [
            'plazas' => $query->paginate(50),
            'roads' => TollPlaza::distinct()->orderBy('road_name')->pluck('road_name'),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterRoad(): void
    {
        $this->resetPage();
    }

    public function startEdit(int $id): void
    {
        $plaza = TollPlaza::findOrFail($id);
        $this->editingId = $plaza->id;
        $this->editPlazaName = $plaza->plaza_name;
        $this->editRoadName = $plaza->road_name;
        $this->editPlazaType = $plaza->plaza_type ?? '';
        $this->editClass1Fee = (string) $plaza->class_1_fee;
        $this->editClass2Fee = (string) $plaza->class_2_fee;
        $this->editClass3Fee = (string) $plaza->class_3_fee;
        $this->editClass4Fee = (string) $plaza->class_4_fee;
        $this->editEffectiveFrom = $plaza->effective_from?->format('Y-m-d') ?? '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function update(): void
    {
        $this->validate([
            'editPlazaName' => 'required|string|max:255',
            'editRoadName' => 'required|string|max:255',
            'editPlazaType' => 'nullable|string|max:50',
            'editClass1Fee' => 'required|numeric|min:0',
            'editClass2Fee' => 'required|numeric|min:0',
            'editClass3Fee' => 'required|numeric|min:0',
            'editClass4Fee' => 'required|numeric|min:0',
            'editEffectiveFrom' => 'nullable|date',
        ]);

        $plaza = TollPlaza::findOrFail($this->editingId);
        $plaza->update([
            'plaza_name' => $this->editPlazaName,
            'road_name' => $this->editRoadName,
            'plaza_type' => $this->editPlazaType,
            'class_1_fee' => $this->editClass1Fee,
            'class_2_fee' => $this->editClass2Fee,
            'class_3_fee' => $this->editClass3Fee,
            'class_4_fee' => $this->editClass4Fee,
            'effective_from' => $this->editEffectiveFrom ?: null,
        ]);

        $this->editingId = null;
        session()->flash('success', 'Toll plaza updated.');
    }

    public function toggle(int $id): void
    {
        $plaza = TollPlaza::findOrFail($id);
        $plaza->update(['is_active' => !$plaza->is_active]);
    }

    public function importCsv(): void
    {
        $this->validate([
            'csvFile' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $path = $this->csvFile->getRealPath();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (!$header) {
            session()->flash('error', 'CSV file is empty or invalid.');
            fclose($handle);
            return;
        }

        $header = array_map('strtolower', array_map('trim', $header));
        $updated = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if (!$data) continue;

            $plazaName = trim($data['plaza_name'] ?? '');
            $plazaType = trim($data['plaza_type'] ?? '');

            if (!$plazaName) continue;

            $query = TollPlaza::where('plaza_name', $plazaName);
            if ($plazaType) {
                $query->where('plaza_type', $plazaType);
            }

            $plaza = $query->first();
            if (!$plaza) continue;

            $plaza->update(array_filter([
                'class_1_fee' => isset($data['class_1_fee']) ? (float) $data['class_1_fee'] : null,
                'class_2_fee' => isset($data['class_2_fee']) ? (float) $data['class_2_fee'] : null,
                'class_3_fee' => isset($data['class_3_fee']) ? (float) $data['class_3_fee'] : null,
                'class_4_fee' => isset($data['class_4_fee']) ? (float) $data['class_4_fee'] : null,
                'effective_from' => !empty($data['effective_from']) ? $data['effective_from'] : null,
            ], fn ($v) => $v !== null));

            $updated++;
        }

        fclose($handle);
        $this->csvFile = null;

        session()->flash('success', "CSV imported — {$updated} plaza(s) updated.");
    }
};
?>
<div>
    <x-slot:header>Toll Plazas</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search plazas or roads..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="filterRoad" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Roads</option>
            @foreach($roads as $road)
                <option value="{{ $road }}">{{ $road }}</option>
            @endforeach
        </select>
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" type="button"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 inline-flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                CSV Import
            </button>
            <div x-show="open" @click.away="open = false" x-cloak
                class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-lg border border-gray-200 p-4 z-50">
                <p class="text-xs text-gray-500 mb-3">
                    CSV columns: <span class="font-mono">plaza_name, plaza_type, class_1_fee, class_2_fee, class_3_fee, class_4_fee, effective_from</span>
                </p>
                <form wire:submit="importCsv">
                    <input wire:model="csvFile" type="file" accept=".csv,.txt"
                        class="block w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    @error('csvFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="submit" class="mt-3 w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                        Upload & Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Road</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plaza</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Class 1</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Class 2</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Class 3</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Class 4</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($plazas as $plaza)
                    @if($editingId === $plaza->id)
                        <tr class="bg-blue-50/50">
                            <td class="px-4 py-2">
                                <input wire:model="editRoadName" type="text" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editPlazaName" type="text" class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editPlazaType" type="text" class="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editClass1Fee" type="number" step="0.01" min="0" class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editClass2Fee" type="number" step="0.01" min="0" class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editClass3Fee" type="number" step="0.01" min="0" class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editClass4Fee" type="number" step="0.01" min="0" class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-2">
                                <input wire:model="editEffectiveFrom" type="date" class="w-36 rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            </td>
                            <td></td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button wire:click="update" class="text-sm font-medium text-blue-600 hover:text-blue-800">Save</button>
                                <button wire:click="cancelEdit" class="ml-2 text-sm font-medium text-gray-500 hover:text-gray-700">Cancel</button>
                            </td>
                        </tr>
                    @else
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $plaza->road_name }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $plaza->plaza_name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $plaza->plaza_type ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right tabular-nums">R{{ number_format($plaza->class_1_fee, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right tabular-nums">R{{ number_format($plaza->class_2_fee, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right tabular-nums">R{{ number_format($plaza->class_3_fee, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 text-right tabular-nums">R{{ number_format($plaza->class_4_fee, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $plaza->effective_from?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($plaza->is_active)
                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button wire:click="startEdit({{ $plaza->id }})" class="text-sm font-medium text-blue-600 hover:text-blue-800">Edit</button>
                                <button wire:click="toggle({{ $plaza->id }})" class="ml-2 text-sm font-medium {{ $plaza->is_active ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                    {{ $plaza->is_active ? 'Disable' : 'Enable' }}
                                </button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center text-sm text-gray-500">No toll plazas found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $plazas->links() }}
    </div>
</div>
