<?php
use App\Models\BodyType;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public string $newName = '';
    public string $newDescription = '';
    public ?int $editingId = null;
    public string $editName = '';
    public string $editDescription = '';

    public function add(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255',
            'newDescription' => 'nullable|string|max:500',
        ]);

        BodyType::create([
            'name' => $this->newName,
            'slug' => Str::slug($this->newName),
            'description' => $this->newDescription ?: null,
            'is_active' => true,
        ]);

        $this->newName = '';
        $this->newDescription = '';
    }

    public function startEdit(int $id): void
    {
        $bt = BodyType::findOrFail($id);
        $this->editingId = $id;
        $this->editName = $bt->name;
        $this->editDescription = $bt->description ?? '';
    }

    public function update(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editDescription' => 'nullable|string|max:500',
        ]);

        BodyType::findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'slug' => Str::slug($this->editName),
            'description' => $this->editDescription ?: null,
        ]);

        $this->editingId = null;
    }

    public function toggle(int $id): void
    {
        $bt = BodyType::findOrFail($id);
        $bt->update(['is_active' => !$bt->is_active]);
    }

    public function with(): array
    {
        return [
            'bodyTypes' => BodyType::orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Body Types</x-slot:header>

    <div class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Body Type</h3>
            <form wire:submit="add" class="space-y-3">
                <div class="flex gap-3">
                    <input wire:model="newName" type="text" placeholder="Body type name" class="flex-1 rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500">Add</button>
                </div>
                <input wire:model="newDescription" type="text" placeholder="Optional description" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                @error('newName')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 divide-y divide-gray-200">
            @forelse($bodyTypes as $bt)
            <div class="flex items-center justify-between px-6 py-4">
                @if($editingId === $bt->id)
                    <form wire:submit="update" class="flex-1 flex items-center gap-2">
                        <div class="flex-1 space-y-1">
                            <input wire:model="editName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                            <input wire:model="editDescription" type="text" placeholder="Description" class="w-full rounded-lg border border-gray-200 px-3 py-1 text-xs">
                        </div>
                        <button type="submit" class="text-sm text-blue-600 font-medium">Save</button>
                        <button type="button" wire:click="$set('editingId', null)" class="text-sm text-gray-500">Cancel</button>
                    </form>
                @else
                    <div>
                        <p class="text-sm font-medium {{ $bt->is_active ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $bt->name }}</p>
                        @if($bt->description)
                            <p class="text-xs text-gray-500">{{ $bt->description }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="startEdit({{ $bt->id }})" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                        <button wire:click="toggle({{ $bt->id }})" class="text-sm {{ $bt->is_active ? 'text-red-600' : 'text-green-600' }} font-medium">
                            {{ $bt->is_active ? 'Disable' : 'Enable' }}
                        </button>
                    </div>
                @endif
            </div>
            @empty
            <div class="px-6 py-12 text-center text-sm text-gray-500">No body types yet. Add one above.</div>
            @endforelse
        </div>
    </div>
</div>
