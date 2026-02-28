<?php
use App\Models\User;
use App\Models\Role;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $roleFilter = '';
    public string $statusFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedRoleFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);
        if ($user->isSuperAdmin() && $user->id !== auth()->id()) {
            session()->flash('error', 'Cannot deactivate a super admin.');
            return;
        }
        $user->update(['is_active' => !$user->is_active]);
    }

    public function with(): array
    {
        $query = User::with('roles')
            ->whereDoesntHave('roles', fn($q) => $q->where('slug', 'driver'));

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('email', 'ilike', "%{$this->search}%")
                  ->orWhere('username', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->roleFilter) {
            $query->whereHas('roles', fn($q) => $q->where('slug', $this->roleFilter));
        }

        if ($this->statusFilter !== '') {
            $query->where('is_active', $this->statusFilter === 'active');
        }

        return [
            'users' => $query->orderBy('name')->paginate(20),
            'roles' => Role::where('slug', '!=', 'driver')->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Users</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex flex-1 gap-3">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, email or username..."
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            <select wire:model.live="roleFilter" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role->slug }}">{{ $role->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <a href="{{ route('admin.users.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + Add User
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roles</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($users as $u)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $u->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->email ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->phone ?? '—' }}</td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1">
                            @foreach($u->roles as $role)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $role->tier === 'internal' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                    {{ $role->name }}
                                </span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <x-badge :color="$u->is_active ? 'green' : 'red'">{{ $u->is_active ? 'Active' : 'Inactive' }}</x-badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $u->created_at->format('d M Y') }}</td>
                    <td class="px-6 py-4 text-right text-sm">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-blue-600 hover:text-blue-800 font-medium">Edit</a>
                            <button wire:click="toggleActive({{ $u->id }})" wire:confirm="Are you sure you want to {{ $u->is_active ? 'deactivate' : 'activate' }} this user?"
                                class="{{ $u->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</div>
