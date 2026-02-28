<?php
use App\Models\Zone;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $addName = '';
    public string $addDescription = '';
    public ?int $editingId = null;
    public string $editName = '';
    public string $editDescription = '';

    public function add(): void
    {
        $this->validate([
            'addName' => 'required|string|max:255|unique:zones,name',
            'addDescription' => 'nullable|string|max:500',
        ]);

        Zone::create([
            'name' => $this->addName,
            'description' => $this->addDescription ?: null,
        ]);

        $this->addName = '';
        $this->addDescription = '';
    }

    public function startEdit(int $id): void
    {
        $zone = Zone::findOrFail($id);
        $this->editingId = $id;
        $this->editName = $zone->name;
        $this->editDescription = $zone->description ?? '';
    }

    public function update(): void
    {
        $this->validate([
            'editName' => "required|string|max:255|unique:zones,name,{$this->editingId}",
            'editDescription' => 'nullable|string|max:500',
        ]);

        Zone::findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'description' => $this->editDescription ?: null,
        ]);

        $this->editingId = null;
    }

    public function toggle(int $id): void
    {
        $zone = Zone::findOrFail($id);
        $zone->update(['is_active' => !$zone->is_active]);
    }

    public function cancelEdit(): void { $this->editingId = null; }

    public function with(): array
    {
        return [
            'zones' => Zone::withCount('locations')->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Zones</x-slot:header>

    <div class="max-w-3xl space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Zone</h3>
            <form wire:submit="add" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <input wire:model="addName" type="text" placeholder="Zone name (e.g. Pretoria North)" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('addName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex-1">
                    <input wire:model="addDescription" type="text" placeholder="Description (optional)" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 shrink-0">Add Zone</button>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Zone</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Locations</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($zones as $zone)
                        @if($editingId === $zone->id)
                        <tr class="bg-blue-50/50">
                            <td colspan="5" class="px-4 py-4">
                                <form wire:submit="update" class="flex flex-col sm:flex-row gap-3">
                                    <div class="flex-1">
                                        <input wire:model="editName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="flex-1">
                                        <input wire:model="editDescription" type="text" placeholder="Description" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">Save</button>
                                        <button type="button" wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        @else
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium {{ $zone->is_active ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $zone->name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $zone->description ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-center text-gray-600">{{ $zone->locations_count }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $zone->is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">{{ $zone->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="startEdit({{ $zone->id }})" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                    <button wire:click="toggle({{ $zone->id }})" class="text-sm {{ $zone->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">{{ $zone->is_active ? 'Disable' : 'Enable' }}</button>
                                </div>
                            </td>
                        </tr>
                        @endif
                    @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500">No zones created yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex justify-between items-center">
            <a href="{{ route('admin.settings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Back to Settings</a>
            <a href="{{ route('admin.settings.zone-rates') }}" class="rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-500">Manage Zone Rates &rarr;</a>
        </div>
    </div>
</div>
