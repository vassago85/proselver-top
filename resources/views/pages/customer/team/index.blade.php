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
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403);

        // Body-builder tenants share this team page but pick from
        // body_builder_* roles instead of customer_*.  Default the
        // new-row role accordingly so the form's first impression is
        // valid; the role list itself flips in with()/template via
        // $tenantRoles / $tenantRoleSlugs below.
        if ($this->company->type === Company::TYPE_BODY_BUILDER) {
            $this->userRole = 'body_builder_user';
        }
    }

    /**
     * Allowed role slugs for the user's tenant type.  Used by edit()
     * to filter what role(s) we recognise on an existing user, by
     * save() to validate, and by with() to populate the role picker.
     */
    protected function tenantRoleSlugs(): array
    {
        return $this->company?->type === Company::TYPE_BODY_BUILDER
            ? ['body_builder_owner', 'body_builder_user']
            : ['customer_owner', 'customer_admin', 'customer_user', 'customer_dispatcher'];
    }

    /**
     * Who can add/edit/deactivate members from this page.
     *
     * canManageCompanyData() is the canonical "this user runs their
     * tenant's customer-portal data" check used by the sidebar, drivers
     * page, and petty-cash page -- it covers customer_owner /
     * customer_admin AND the legacy dealer-tier roles (dealer_principal,
     * stock_controller, sales_manager_new/used, oem_admin).  We tack
     * body_builder_owner on top because that slug isn't included in
     * canManageCompanyData() but legitimately needs to manage its own
     * body-builder team here.
     *
     * Used as both the Blade visibility flag (via $canManage in with())
     * and as a hard guard at the top of every mutating action -- UI
     * gating alone is bypassable through the Livewire wire payload,
     * which the 2026-04-22 security audit flagged on this page.
     */
    protected function canManage(): bool
    {
        $user = auth()->user();
        return $user && ($user->canManageCompanyData() || $user->hasRole('body_builder_owner'));
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage(), 403);

        // Account-owners cannot edit their own row from this screen — name,
        // password and role changes for the current user must go through
        // /profile so a single rogue session can't lock its own role to
        // something it shouldn't have. The Edit button is hidden in the
        // template too; this guard exists because Livewire wire calls are
        // tamperable.
        if ($id === auth()->id()) {
            session()->flash('error', 'Use the Profile page to edit your own account.');
            return;
        }

        $user = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
            ->findOrFail($id);

        $this->editingId = $user->id;
        $this->userName = $user->name;
        $this->userEmail = $user->email ?? '';
        $this->userPhone = $user->phone ?? '';
        $this->userPassword = '';
        $tenantSlugs = $this->tenantRoleSlugs();
        $defaultRole = $this->company?->type === Company::TYPE_BODY_BUILDER ? 'body_builder_user' : 'customer_user';
        $this->userRole = $user->roles->whereIn('slug', $tenantSlugs)->first()?->slug ?? $defaultRole;

        $pivot = $user->companies()->where('companies.id', $this->company->id)->first();
        $this->userLocationId = $pivot?->pivot?->location_id;

        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->canManage(), 403);

        $rules = [
            'userName' => 'required|string|max:255',
            'userEmail' => 'required|email|max:255',
            'userPhone' => 'nullable|string|max:50',
            'userRole' => 'required|in:' . implode(',', $this->tenantRoleSlugs()),
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
            // Refuse self-edits (mirrors the guard in edit()). Without this
            // a tampered editingId pointing at the current user would let
            // a session bypass the in-template "Edit" hide and rotate its
            // own role to customer_owner from anywhere.
            if ($this->editingId === auth()->id()) {
                session()->flash('error', 'Use the Profile page to edit your own account.');
                $this->resetForm();
                return;
            }

            // Re-scope to the current customer's company so a tampered
            // editingId cannot be used to edit a user at another customer.
            // edit() already does this lookup safely, but save() is reachable
            // independently via the Livewire wire payload.
            $user = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
                ->findOrFail($this->editingId);
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
        abort_unless($this->canManage(), 403);

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
        $canManage = $this->canManage();

        $members = User::whereHas('companies', fn($q) => $q->where('companies.id', $this->company->id))
            ->with(['roles', 'companies' => fn($q) => $q->where('companies.id', $this->company->id)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $locations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        // Eager-load permissions so the form can render a "what can
        // each role do?" reference panel without N+1 queries.  Manual
        // power ranking sorts owner → admin → dispatcher → user
        // (descending privilege) rather than alphabetical, so the
        // dropdown + reference card both read in a natural hierarchy.
        $customerRoles = Role::whereIn('slug', $this->tenantRoleSlugs())
            ->with(['permissions' => fn ($q) => $q->orderBy('group')->orderBy('name')])
            ->get(['id', 'slug', 'name', 'description']);

        $rolePower = [
            'customer_owner' => 1, 'customer_admin' => 2,
            'customer_dispatcher' => 3, 'customer_user' => 4,
            'body_builder_owner' => 1, 'body_builder_user' => 2,
        ];
        $customerRoles = $customerRoles
            ->sortBy(fn ($r) => $rolePower[$r->slug] ?? 99)
            ->values();

        // Customer / dealer / OEM all share this team page (the underlying
        // role slugs are the customer_* family for tenanted customers
        // regardless of what they manufacture). Remap the user-facing
        // label by tenant type via the shared helper, so dealers see
        // "Dealer Owner" and OEMs see "OEM Owner" -- matches the header
        // and profile page, all driven from one place.
        $companyType = $this->company->type;
        $roleLabel = fn (string $name): string => tenantRoleDisplayName($name, $companyType);

        $roleOptions = $customerRoles->map(fn ($r) => [
            'value' => $r->slug,
            'label' => $roleLabel($r->name),
        ])->values()->all();

        // Reference data for the in-form "what can each role do?" card.
        // Permissions are pre-grouped by their .group column (Bookings,
        // Documents, etc.) so the view can render compact bullet lists
        // without re-shaping data inline.  Driven straight from the
        // PermissionSeeder so any future perm change is reflected in the
        // UI automatically -- no editorialised duplicate descriptions.
        $roleReference = $customerRoles->map(fn ($r) => [
            'slug' => $r->slug,
            'label' => $roleLabel($r->name),
            'description' => (string) ($r->description ?? ''),
            'groups' => $r->permissions
                ->groupBy('group')
                ->map(fn ($perms) => $perms->pluck('name')->all())
                ->toArray(),
        ])->values()->all();

        $locationOptions = $locations->map(fn ($loc) => [
            'value' => (string) $loc->id,
            'label' => $loc->company_name . ($loc->city ? " — {$loc->city}" : ''),
        ])->values()->all();

        $tenantRoleSlugs = $this->tenantRoleSlugs();

        return compact(
            'members', 'canManage', 'locations', 'customerRoles',
            'roleLabel', 'roleOptions', 'roleReference', 'locationOptions',
            'tenantRoleSlugs'
        );
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
                        <x-searchable-select
                            wire:model.live="userRole"
                            :options="$roleOptions"
                            placeholder="Select role"
                            search-placeholder="Search roles…"
                            :allow-clear="false"
                        />
                        @error('userRole') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Location</label>
                        <x-searchable-select
                            wire:model="userLocationId"
                            :options="$locationOptions"
                            placeholder="All locations (no restriction)"
                            search-placeholder="Search locations…"
                        />
                        @error('userLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- What can each role do?  Built straight from
                     PermissionSeeder via Role::with('permissions'), so
                     the listing stays accurate if perms are added or
                     removed in future seeds.  Closed by default to keep
                     the form compact; the currently-selected role is
                     highlighted when ops opens it. --}}
                <details class="rounded-lg border border-blue-200 bg-blue-50/40">
                    <summary class="cursor-pointer select-none px-4 py-2 text-xs font-semibold text-blue-900 flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        What can each role do?
                    </summary>
                    <div class="border-t border-blue-200 px-4 py-3 space-y-3 text-xs">
                        @foreach($roleReference as $ref)
                            @php $isSelected = $userRole === $ref['slug']; @endphp
                            <div class="rounded-md p-2 {{ $isSelected ? 'ring-1 ring-blue-400 bg-blue-100/60' : '' }}">
                                <p class="font-semibold text-gray-900 flex items-center gap-1.5">
                                    {{ $ref['label'] }}
                                    @if($isSelected)
                                        <span class="inline-flex items-center rounded-full bg-blue-600 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">selected</span>
                                    @endif
                                </p>
                                @if(!empty($ref['groups']))
                                    <ul class="mt-1 ml-4 list-disc space-y-0.5 text-gray-700">
                                        @foreach($ref['groups'] as $group => $perms)
                                            <li><span class="font-medium text-gray-900">{{ $group }}:</span> {{ implode(', ', $perms) }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-1 ml-4 text-gray-500 italic">No specific permissions configured.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
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
                            @foreach($member->roles->whereIn('slug', $tenantRoleSlugs) as $role)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 mr-1">{{ $roleLabel($role->name) }}</span>
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
                                @if($member->id === auth()->id())
                                    <a href="{{ route('profile.index') }}" class="text-gray-500 hover:text-gray-700 font-medium" title="Edit your own account in Profile">Profile</a>
                                @else
                                    <button wire:click="edit({{ $member->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
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
