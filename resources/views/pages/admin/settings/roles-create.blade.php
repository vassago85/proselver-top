<?php
use App\Models\Role;
use App\Models\Permission;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $tier = 'dealer';
    public string $description = '';
    public array $selectedPermissions = [];

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'tier' => 'required|in:dealer,oem',
            'description' => 'nullable|string|max:500',
            'selectedPermissions' => 'required|array|min:1',
        ]);

        $slug = Str::slug($this->name, '_');
        $base = $slug;
        $suffix = 0;
        while (Role::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '_' . $suffix;
        }

        $role = Role::create([
            'name' => $this->name,
            'slug' => $slug,
            'tier' => $this->tier,
            'description' => $this->description ?: null,
        ]);

        $role->permissions()->sync($this->selectedPermissions);

        session()->flash('success', "Role '{$role->name}' created with " . count($this->selectedPermissions) . " permissions.");
        $this->redirect(route('admin.settings.roles'));
    }

    public function with(): array
    {
        return [
            'permissions' => Permission::orderBy('group')->orderBy('name')->get()->groupBy('group'),
        ];
    }
};
?>
<div>
    <x-slot:header>Create Role</x-slot:header>

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Role Details</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role Name *</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Fleet Manager">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tier *</label>
                    <select wire:model="tier" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="dealer">Dealer</option>
                        <option value="oem">OEM</option>
                    </select>
                    @error('tier')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input wire:model="description" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Brief description of this role">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Permissions *</h3>
            @error('selectedPermissions')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror

            <div class="space-y-6">
                @foreach($permissions as $group => $groupPerms)
                    <div>
                        <p class="text-xs font-semibold uppercase text-gray-400 mb-2">{{ $group }}</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach($groupPerms as $perm)
                                <label class="flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                    <input wire:model="selectedPermissions" type="checkbox" value="{{ $perm->id }}" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600">
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">{{ $perm->name }}</span>
                                        @if($perm->description)
                                            <p class="text-xs text-gray-400">{{ $perm->description }}</p>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.settings.roles') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">Create Role</button>
        </div>
    </form>
</div>
