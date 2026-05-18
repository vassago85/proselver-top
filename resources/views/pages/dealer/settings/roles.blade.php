<?php

use App\Models\Permission;
use App\Models\Role;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public bool $showForm = false;
    public ?int $editingRoleId = null;
    public string $roleName = '';
    public string $roleDescription = '';
    public array $selectedPermissions = [];

    public function mount(): void
    {
        if (!auth()->user()->hasPermission('manage_dealer_users')) {
            abort(403);
        }
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $roleId): void
    {
        $company = auth()->user()->company();
        $role = Role::forCompany($company?->id)->findOrFail($roleId);

        $this->editingRoleId = $role->id;
        $this->roleName = $role->name;
        $this->roleDescription = $role->description ?? '';
        $this->selectedPermissions = $role->permissions->pluck('id')->map(fn($id) => (string) $id)->toArray();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'roleName' => 'required|string|max:100',
            'roleDescription' => 'nullable|string|max:500',
            'selectedPermissions' => 'required|array|min:1',
        ]);

        $company = auth()->user()->company();
        if (!$company) {
            session()->flash('error', 'No company found.');
            return;
        }

        $tier = auth()->user()->isOem() ? 'oem' : 'dealer';

        if ($this->editingRoleId) {
            $role = Role::forCompany($company->id)->findOrFail($this->editingRoleId);
            $role->update([
                'name' => $this->roleName,
                'description' => $this->roleDescription ?: null,
            ]);
            $role->permissions()->sync($this->selectedPermissions);
            session()->flash('success', "Role \"{$role->name}\" updated.");
        } else {
            $slug = Str::slug($this->roleName, '_') . '_company_' . $company->id;
            $counter = 0;
            $baseSlug = $slug;
            while (Role::where('slug', $slug)->exists()) {
                $counter++;
                $slug = $baseSlug . '_' . $counter;
            }

            $role = Role::create([
                'company_id' => $company->id,
                'name' => $this->roleName,
                'slug' => $slug,
                'tier' => $tier,
                'description' => $this->roleDescription ?: null,
            ]);
            $role->permissions()->sync($this->selectedPermissions);
            session()->flash('success', "Role \"{$role->name}\" created.");
        }

        $this->resetForm();
    }

    public function deleteRole(int $roleId): void
    {
        $company = auth()->user()->company();
        $role = Role::forCompany($company?->id)->findOrFail($roleId);

        if ($role->users()->count() > 0) {
            session()->flash('error', "Cannot delete \"{$role->name}\" — it still has users assigned.");
            return;
        }

        $name = $role->name;
        $role->permissions()->detach();
        $role->delete();
        session()->flash('success', "Role \"{$name}\" deleted.");
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->roleDescription = '';
        $this->selectedPermissions = [];
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function with(): array
    {
        $company = auth()->user()->company();
        $roles = $company
            ? Role::forCompany($company->id)->withCount('users')->with('permissions')->orderBy('name')->get()
            : collect();

        // Permission slugs that are intentionally hidden from the customer-side
        // roles editor. Single source of truth for both the create / edit
        // checkbox grid AND the per-role chip list below so we never drift.
        //
        // Always hidden:
        //   view_bookings  -- legacy, replaced by view_all/own_bookings
        //   upload_po      -- ProSelver-side ingest, never a dealer surface
        //
        // Dealers only (true dealer tier, not OEM):
        //   view_po / generate_po -- POs are raised in the dealer's own
        //     accounting system; surfacing them here implies a workflow we
        //     don't actually run.
        //   confirm_customer_order -- the "FAW-type" readiness gate is an
        //     OEM concept (FAW themselves are an OEM); dealers running
        //     their own local movements have no such handshake.
        $hiddenPermissionSlugs = ['view_bookings', 'upload_po'];
        if (auth()->user()->isDealer() && !auth()->user()->isOem()) {
            $hiddenPermissionSlugs = array_merge($hiddenPermissionSlugs, [
                'view_po', 'generate_po', 'confirm_customer_order',
            ]);
        }

        // Filter then re-group so an entirely-hidden group (e.g. Purchase
        // Orders for a dealer) doesn't render an empty card with just a
        // section heading.
        $permissions = Permission::orderBy('group')->orderBy('name')->get()
            ->reject(fn ($p) => in_array($p->slug, $hiddenPermissionSlugs, true))
            ->groupBy('group');

        return [
            'roles' => $roles,
            'permissionGroups' => $permissions,
            'hiddenPermissionSlugs' => $hiddenPermissionSlugs,
        ];
    }
};

?>

<div>
    <x-slot:header>Roles & Permissions</x-slot:header>

    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500">Create and manage roles for your team. Assign permissions to control what each role can do.</p>
        <button wire:click="openCreate" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + New Role
        </button>
    </div>

    {{-- Role Form (Create / Edit) --}}
    @if($showForm)
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $editingRoleId ? 'Edit Role' : 'Create Role' }}</h3>
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role Name</label>
                    <input wire:model="roleName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Junior Sales">
                    @error('roleName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input wire:model="roleDescription" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Optional description">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-3">Permissions</label>
                @error('selectedPermissions') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($permissionGroups as $group => $perms)
                    <div class="rounded-lg border border-gray-200 p-4">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">{{ $group }}</h4>
                        <div class="space-y-2">
                            @foreach($perms as $perm)
                                @if(!in_array($perm->slug, $hiddenPermissionSlugs, true))
                                <label class="flex items-start gap-2 text-sm">
                                    <input wire:model="selectedPermissions" type="checkbox" value="{{ $perm->id }}" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <div>
                                        <span class="font-medium text-gray-900">{{ $perm->name }}</span>
                                        @if($perm->description)
                                            <p class="text-xs text-gray-500">{{ $perm->description }}</p>
                                        @endif
                                    </div>
                                </label>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    {{ $editingRoleId ? 'Update Role' : 'Create Role' }}
                </button>
                <button type="button" wire:click="cancelForm" class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
    @endif

    {{-- Roles List --}}
    <div class="space-y-4">
        @forelse($roles as $role)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $role->name }}</h3>
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</span>
                    </div>
                    @if($role->description)
                        <p class="mt-1 text-sm text-gray-500">{{ $role->description }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach($role->permissions->sortBy('group') as $perm)
                            @if(!in_array($perm->slug, $hiddenPermissionSlugs, true))
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700">{{ $perm->name }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2 ml-4 shrink-0">
                    <button wire:click="openEdit({{ $role->id }})" class="text-sm font-medium text-blue-600 hover:text-blue-800">Edit</button>
                    @if($role->users_count === 0)
                        <button wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete this role? This cannot be undone." class="text-sm font-medium text-red-600 hover:text-red-800">Delete</button>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
            <p class="text-sm text-gray-500">No roles configured yet. Click "New Role" to create one.</p>
        </div>
        @endforelse
    </div>
</div>
