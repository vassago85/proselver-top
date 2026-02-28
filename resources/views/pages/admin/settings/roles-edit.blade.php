<?php
use App\Models\Role;
use App\Models\Permission;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Role $role;
    public string $name = '';
    public string $description = '';
    public array $selectedPermissions = [];

    public function mount(Role $role): void
    {
        if (!in_array($role->tier, ['dealer', 'oem'])) {
            abort(403, 'Cannot edit system roles.');
        }

        $this->role = $role;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->selectedPermissions = $role->permissions->pluck('id')->map(fn($id) => (string) $id)->toArray();
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'selectedPermissions' => 'required|array|min:1',
        ]);

        $this->role->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
        ]);

        $this->role->permissions()->sync($this->selectedPermissions);

        session()->flash('success', "Role '{$this->role->name}' updated.");
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
    <x-slot:header>Edit Role: {{ $role->name }}</x-slot:header>

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Role Details</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role Name *</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input wire:model="description" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <p class="text-xs text-gray-400">Slug: <code class="bg-gray-100 px-1 py-0.5 rounded">{{ $role->slug }}</code></p>
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
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">Save Changes</button>
        </div>
    </form>
</div>
