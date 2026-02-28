<?php
use App\Models\Role;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        if (!in_array($role->tier, ['dealer', 'oem'])) {
            session()->flash('error', 'Cannot delete system roles.');
            return;
        }

        if ($role->users()->exists()) {
            session()->flash('error', "Cannot delete role '{$role->name}' — it is assigned to users. Remove them first.");
            return;
        }

        $role->permissions()->detach();
        $role->delete();
        session()->flash('success', "Role '{$role->name}' deleted.");
    }

    public function with(): array
    {
        $roles = Role::withCount(['users', 'permissions'])->orderBy('tier')->orderBy('name')->get();
        return ['roles' => $roles];
    }
};
?>
<div>
    <x-slot:header>Roles & Permissions</x-slot:header>

    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500">Manage dealer roles and their permissions. Internal roles are system-managed.</p>
        <a href="{{ route('admin.settings.roles.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + New Role
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="space-y-4">
        @php $grouped = $roles->groupBy('tier'); @endphp
        @foreach(['internal' => 'Internal (System)', 'dealer' => 'Dealer (Configurable)', 'oem' => 'OEM (Configurable)'] as $tier => $label)
            @if(isset($grouped[$tier]))
            <div>
                <h3 class="text-xs font-semibold uppercase text-gray-400 mb-3">{{ $label }}</h3>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 divide-y divide-gray-200">
                    @foreach($grouped[$tier] as $role)
                    <div class="flex items-center justify-between px-6 py-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ $role->name }}</p>
                            <p class="text-xs text-gray-500">{{ $role->description ?? 'No description' }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ $role->users_count }} user(s) &middot; {{ $role->permissions_count }} permission(s)</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($tier === 'dealer' || $tier === 'oem')
                                <a href="{{ route('admin.settings.roles.edit', $role) }}" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Edit</a>
                                <button wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete role '{{ $role->name }}'? This cannot be undone."
                                    class="text-sm text-red-600 hover:text-red-800 font-medium">Delete</button>
                            @else
                                <span class="text-xs text-gray-400 italic">System role</span>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        @endforeach

        @if(isset($grouped['driver']))
        <div>
            <h3 class="text-xs font-semibold uppercase text-gray-400 mb-3">Driver</h3>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                @foreach($grouped['driver'] ?? collect() as $role)
                <div class="flex items-center justify-between px-6 py-4">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $role->name }}</p>
                        <p class="text-xs text-gray-500">{{ $role->description }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $role->users_count }} user(s)</p>
                    </div>
                    <span class="text-xs text-gray-400 italic">System role</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
