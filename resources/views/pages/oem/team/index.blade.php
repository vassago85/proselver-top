<?php
use App\Models\User;
use App\Models\Role;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showAddForm = false;
    public ?int $editingUserId = null;

    // Add form fields
    public string $newName = '';
    public string $newEmail = '';
    public string $newPhone = '';
    public string $newPassword = '';
    public array $newSelectedRoles = [];

    // Edit form fields
    public string $editName = '';
    public string $editEmail = '';
    public string $editPhone = '';
    public string $editPassword = '';
    public array $editSelectedRoles = [];

    public function mount(): void
    {
        if (!auth()->user()->hasPermission('manage_dealer_users')) {
            abort(403);
        }
        $this->newPassword = Str::random(12);
    }

    public function addMember(): void
    {
        $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|unique:users,email',
            'newPhone' => 'nullable|string|max:20',
            'newPassword' => 'required|string|min:8',
            'newSelectedRoles' => 'required|array|min:1',
        ]);

        $company = auth()->user()->company();
        $username = Str::before($this->newEmail, '@');
        $base = $username;
        $suffix = 0;
        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        $user = User::create([
            'name' => $this->newName,
            'email' => $this->newEmail,
            'phone' => $this->newPhone ?: null,
            'username' => Str::lower($username),
            'password' => $this->newPassword,
        ]);

        $user->roles()->sync($this->newSelectedRoles);
        if ($company) {
            $user->companies()->sync([$company->id]);
        }

        $this->resetAddForm();
        session()->flash('success', "{$user->name} added to team.");
    }

    public function startEdit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingUserId = $userId;
        $this->editName = $user->name;
        $this->editEmail = $user->email ?? '';
        $this->editPhone = $user->phone ?? '';
        $this->editPassword = '';
        $this->editSelectedRoles = $user->roles->pluck('id')->map(fn($id) => (string) $id)->toArray();
    }

    public function saveEdit(): void
    {
        $user = User::findOrFail($this->editingUserId);
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => "required|email|unique:users,email,{$user->id}",
            'editPhone' => 'nullable|string|max:20',
            'editSelectedRoles' => 'required|array|min:1',
        ]);

        $data = [
            'name' => $this->editName,
            'email' => $this->editEmail,
            'phone' => $this->editPhone ?: null,
        ];
        if ($this->editPassword) {
            $data['password'] = $this->editPassword;
        }

        $user->update($data);
        $user->roles()->sync($this->editSelectedRoles);
        $this->editingUserId = null;
        session()->flash('success', "{$user->name} updated.");
    }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);
    }

    public function cancelEdit(): void { $this->editingUserId = null; }

    private function resetAddForm(): void
    {
        $this->showAddForm = false;
        $this->newName = '';
        $this->newEmail = '';
        $this->newPhone = '';
        $this->newPassword = Str::random(12);
        $this->newSelectedRoles = [];
    }

    public function updatedSearch(): void { $this->resetPage(); }

    public function with(): array
    {
        $company = auth()->user()->company();
        $users = collect();
        if ($company) {
            $query = $company->users()->with('roles')->orderBy('name');
            if ($this->search) {
                $query->where(function ($q) {
                    $q->where('name', 'ilike', "%{$this->search}%")
                      ->orWhere('email', 'ilike', "%{$this->search}%");
                });
            }
            $users = $query->paginate(20);
        }

        return [
            'members' => $users,
            'oemRoles' => Role::where('tier', 'oem')->orderBy('name')->get(),
        ];
    }
};
?>
<div>
    <x-slot:header>Team</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name or email..."
            class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
        <button wire:click="$set('showAddForm', true)" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + Add Team Member
        </button>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Add Member Form --}}
    @if($showAddForm)
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Team Member</h3>
        <form wire:submit="addMember" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input wire:model="newName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('newName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="newEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('newEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input wire:model="newPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('newPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input wire:model="newPassword" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                    @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Roles</label>
                <div class="flex flex-wrap gap-3">
                    @foreach($oemRoles as $role)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input wire:model="newSelectedRoles" type="checkbox" value="{{ $role->id }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            {{ $role->name }}
                        </label>
                    @endforeach
                </div>
                @error('newSelectedRoles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">Save</button>
                <button type="button" wire:click="$set('showAddForm', false)" class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">Cancel</button>
            </div>
        </form>
    </div>
    @endif

    {{-- Members Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role(s)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($members as $member)
                    @if($editingUserId === $member->id)
                    <tr class="bg-blue-50">
                        <td colspan="6" class="px-6 py-4">
                            <form wire:submit="saveEdit" class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                                        <input wire:model="editName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                        <input wire:model="editEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                        <input wire:model="editPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        @error('editPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-gray-400 font-normal">(leave blank to keep)</span></label>
                                        <input wire:model="editPassword" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Roles</label>
                                    <div class="flex flex-wrap gap-3">
                                        @foreach($oemRoles as $role)
                                            <label class="inline-flex items-center gap-2 text-sm">
                                                <input wire:model="editSelectedRoles" type="checkbox" value="{{ $role->id }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                {{ $role->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('editSelectedRoles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-center gap-3">
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="inline-flex items-center rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @else
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $member->name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $member->email ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $member->phone ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-1">
                                @foreach($member->roles as $role)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700">{{ $role->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <x-badge :color="$member->is_active ? 'green' : 'red'">{{ $member->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </td>
                        <td class="px-6 py-4 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <button wire:click="startEdit({{ $member->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                <button wire:click="toggleActive({{ $member->id }})" wire:confirm="Are you sure you want to {{ $member->is_active ? 'deactivate' : 'activate' }} this member?"
                                    class="{{ $member->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                    {{ $member->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endif
                @empty
                <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">No team members found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($members instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-4">{{ $members->links() }}</div>
    @endif
</div>
