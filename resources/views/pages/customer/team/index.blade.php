<?php

use App\Models\Company;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;

new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Company $company = null;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $userName = '';
    public string $userEmail = '';
    public string $userPhone = '';
    public string $userPassword = '';
    public string $userRole = 'customer_user';
    public ?int $userLocationId = null;

    public function mount(): void
    {
        $this->company = auth()->user()->companies()->first();
        abort_unless($this->company, 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
            ->findOrFail($id);

        $this->editingId = $user->id;
        $this->userName = $user->name;
        $this->userEmail = $user->email ?? '';
        $this->userPhone = $user->phone ?? '';
        $this->userPassword = '';
        $this->userRole = $user->roles->whereIn('slug', ['customer_owner', 'customer_admin', 'customer_user', 'customer_dispatcher'])->first()?->slug ?? 'customer_user';

        $pivot = $user->companies()->where('companies.id', $this->company->id)->first();
        $this->userLocationId = $pivot?->pivot?->location_id;

        $this->showForm = true;
    }

    public function save(): void
    {
        $rules = [
            'userName' => 'required|string|max:255',
            'userEmail' => 'required|email|max:255',
            'userPhone' => 'nullable|string|max:50',
            'userRole' => 'required|in:customer_owner,customer_admin,customer_user,customer_dispatcher',
            'userLocationId' => 'nullable|exists:locations,id',
        ];

        if (!$this->editingId) {
            $rules['userPassword'] = 'required|string|min:6';
            $rules['userEmail'] .= '|unique:users,email';
        } else {
            $rules['userPassword'] = 'nullable|string|min:6';
            $rules['userEmail'] .= '|unique:users,email,' . $this->editingId;
        }

        $this->validate($rules);

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $user->update([
                'name' => $this->userName,
                'email' => $this->userEmail,
                'phone' => $this->userPhone ?: null,
            ]);

            if ($this->userPassword) {
                $user->update(['password' => Hash::make($this->userPassword)]);
            }

            $user->syncRoles([$this->userRole]);

            $this->company->users()->updateExistingPivot($user->id, [
                'location_id' => $this->userLocationId,
            ]);

            session()->flash('success', 'Team member updated.');
        } else {
            $username = strtolower(str_replace(' ', '', $this->userName)) . rand(10, 99);

            $user = User::create([
                'name' => $this->userName,
                'email' => $this->userEmail,
                'phone' => $this->userPhone ?: null,
                'username' => $username,
                'password' => Hash::make($this->userPassword),
                'is_active' => true,
            ]);

            $user->assignRole($this->userRole);
            $this->company->users()->attach($user->id, [
                'location_id' => $this->userLocationId,
            ]);

            session()->flash('success', 'Team member added.');
        }

        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $user = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
            ->findOrFail($id);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'You cannot deactivate yourself.');
            return;
        }

        $user->update(['is_active' => !$user->is_active]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->showForm = false;
        $this->reset(['userName', 'userEmail', 'userPhone', 'userPassword', 'userRole', 'userLocationId']);
    }

    public function with(): array
    {
        $user = auth()->user();
        $canManage = $user->hasAnyRole(['customer_owner', 'customer_admin']);

        $members = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
            ->with(['roles', 'companies' => fn($q) => $q->where('companies.id', $this->company->id)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $locations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $customerRoles = Role::whereIn('slug', ['customer_owner', 'customer_admin', 'customer_user', 'customer_dispatcher'])
            ->orderBy('name')
            ->get(['id', 'slug', 'name']);

        return compact('members', 'canManage', 'locations', 'customerRoles');
    }
};
?>

<div>
    <x-slot:header>Team</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-xl bg-white shadow-sm border border-gray-200">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h3 class="text-lg font-semibold text-gray-900">{{ $company->name }} - Team Members</h3>
            @if($canManage)
                <button wire:click="create" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Add Member
                </button>
            @endif
        </div>

        {{-- Inline Form --}}
        @if($showForm && $canManage)
        <div class="border-b border-gray-200 bg-gray-50 p-6">
            <h4 class="text-sm font-semibold text-gray-800 mb-4">{{ $editingId ? 'Edit Team Member' : 'Add New Team Member' }}</h4>
            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                        <input wire:model="userName" type="text" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('userName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input wire:model="userEmail" type="email" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('userEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input wire:model="userPhone" type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('userPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password {{ $editingId ? '(leave blank to keep)' : '' }} <span class="{{ $editingId ? '' : 'text-red-500' }}">{{ $editingId ? '' : '*' }}</span></label>
                        <input wire:model="userPassword" type="password" {{ $editingId ? '' : 'required' }}
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('userPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
                        <select wire:model="userRole" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach($customerRoles as $role)
                                <option value="{{ $role->slug }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        @error('userRole') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Location</label>
                        <select wire:model="userLocationId"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">All locations (no restriction)</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->company_name }}{{ $loc->city ? " — {$loc->city}" : '' }}</option>
                            @endforeach
                        </select>
                        @error('userLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                        {{ $editingId ? 'Update Member' : 'Add Member' }}
                    </button>
                    <button type="button" wire:click="resetForm" class="rounded-lg bg-white px-5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
        @endif

        {{-- Members Table --}}
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        @if($canManage)
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($members as $member)
                    @php
                        $memberPivot = $member->companies->first()?->pivot;
                        $memberLocation = $memberPivot?->location_id
                            ? $locations->firstWhere('id', $memberPivot->location_id)
                            : null;
                    @endphp
                    <tr class="{{ !$member->is_active ? 'opacity-50' : '' }}">
                        <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $member->name }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $member->email ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $member->phone ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm">
                            @foreach($member->roles->whereIn('slug', ['customer_owner', 'customer_admin', 'customer_user', 'customer_dispatcher']) as $role)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 mr-1">{{ $role->name }}</span>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                            @if($memberLocation)
                                <span class="inline-flex items-center gap-1">
                                    <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                    {{ $memberLocation->company_name }}
                                </span>
                            @else
                                <span class="text-gray-400">All locations</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $member->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $member->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        @if($canManage)
                        <td class="whitespace-nowrap px-6 py-3 text-sm">
                            <div class="flex items-center gap-2">
                                <button wire:click="edit({{ $member->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                @if($member->id !== auth()->id())
                                    <button wire:click="toggleActive({{ $member->id }})"
                                        wire:confirm="Are you sure you want to {{ $member->is_active ? 'deactivate' : 'activate' }} this user?"
                                        class="font-medium {{ $member->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }}">
                                        {{ $member->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                @endif
                            </div>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $canManage ? 7 : 6 }}" class="px-6 py-8 text-center text-sm text-gray-500">No team members found.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
