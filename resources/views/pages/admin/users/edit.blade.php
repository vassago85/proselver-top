<?php
use App\Models\User;
use App\Models\Role;
use App\Models\Company;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public User $user;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $username = '';
    public string $newPassword = '';
    public bool $resetPassword = false;
    public array $selectedRoles = [];
    public ?int $companyId = null;

    public function mount(User $user): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not edit users.');

        // You cannot edit a user whose highest role outranks yours (or equals
        // yours, unless you're a developer). This stops a super_admin being
        // demoted or poached by an ops_manager via the edit form.
        if (!$actor->isDeveloper() && $user->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403, 'You may not edit a user at or above your own role level.');
        }

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email ?? '';
        $this->phone = $user->phone ?? '';
        $this->username = $user->username;
        $this->selectedRoles = $user->roles->pluck('id')->map(fn($id) => (string) $id)->toArray();
        $this->companyId = $user->companies()->first()?->id;
    }

    public function updatedResetPassword(): void
    {
        $this->newPassword = $this->resetPassword ? Str::random(12) : '';
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403);

        // Re-check rank: same guard as mount() to prevent a tampered wire id
        // from editing someone senior.
        if (!$actor->isDeveloper() && $this->user->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:users,email,{$this->user->id}",
            'phone' => 'nullable|string|max:20',
            'username' => "required|string|max:50|unique:users,username,{$this->user->id}",
            'selectedRoles' => 'required|array|min:1',
        ];

        if ($this->resetPassword) {
            $rules['newPassword'] = 'required|string|min:8';
        }

        $hasDealerRole = Role::whereIn('id', $this->selectedRoles)->where('tier', 'dealer')->exists();
        $hasOemRole = Role::whereIn('id', $this->selectedRoles)->where('tier', 'oem')->exists();
        // Driver attached on a dealer's behalf — ops needs to pin the
        // driver to the dealer's company so executor_type=internal jobs
        // can find them.
        $hasDriverRole = Role::whereIn('id', $this->selectedRoles)->where('slug', 'driver')->exists();

        if ($hasDealerRole || $hasOemRole || $hasDriverRole) {
            $rules['companyId'] = 'required|exists:companies,id';
        } else {
            $rules['companyId'] = 'nullable|integer|exists:companies,id';
        }

        $this->validate($rules);

        // Server-side role allowlist (see create.blade.php for rationale).
        // An additional constraint here: if the user already had a role the
        // actor cannot grant (e.g. a super_admin editing another super_admin
        // with dev-tier access is blocked above), those existing role ids
        // are allowed to remain; we only block NEW elevated grants.
        $existingIds = $this->user->roles->pluck('id')->map(fn($id) => (string)$id)->all();
        $newRoleIds = array_diff($this->selectedRoles, $existingIds);
        if (!empty($newRoleIds)) {
            foreach (Role::whereIn('id', $newRoleIds)->get() as $role) {
                if (!$actor->canAssignRole($role->slug)) {
                    abort(403, "You may not assign the {$role->name} role.");
                }
            }
        }

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'username' => Str::lower($this->username),
        ];

        if ($this->resetPassword && $this->newPassword) {
            $data['password'] = $this->newPassword;
            // Admin-initiated password reset → force the target to rotate
            // before doing anything else. Prevents a rogue admin from
            // silently taking over an account by "resetting" its password.
            $data['must_change_password'] = true;
            $data['password_changed_at'] = null;
        }

        $this->user->update($data);
        $this->user->roles()->sync($this->selectedRoles);

        // Company assignment now follows whatever the admin picked, full stop.
        // Earlier this branch silently detached the user from every company
        // whenever no Dealer/OEM role was in the selection — which is how
        // FAW Owner ended up unlinked from FAW after a routine role-edit.
        // The picker is pre-filled with the existing assignment in mount(),
        // so a save with no edits keeps the current link.
        if ($this->companyId) {
            $this->user->companies()->sync([$this->companyId]);
        } else {
            $this->user->companies()->detach();
        }

        session()->flash('success', "User {$this->user->name} updated successfully.");
        $this->redirect(route('admin.users.index'));
    }

    public function with(): array
    {
        $actor = auth()->user();
        // 'driver' is included here so ops can attach (or detach) a
        // driver to a dealer company on the dealer's behalf — the
        // dealer's own /customer/drivers page does the same thing for
        // dealer admins.
        $allRoles = Role::orderBy('tier')->orderBy('name')->get();

        // Show only roles the actor can assign, PLUS any role the edited user
        // already holds (read-only for the actor) so high-rank roles aren't
        // silently stripped when a lower-rank manager saves the form. Those
        // existing roles are excluded from the new-grant check in save().
        $existingIds = $this->user->roles->pluck('id')->all();
        $assignable = $allRoles->filter(
            fn ($r) => $actor->canAssignRole($r->slug) || in_array($r->id, $existingIds, true)
        )->values();

        $companies = Company::where('is_active', true)->orderBy('name')->get();
        $companyOptions = $companies->map(fn ($c) => [
            'value' => (string) $c->id,
            'label' => $c->name,
        ])->values()->all();

        return [
            'roles' => $assignable,
            'companies' => $companies,
            'companyOptions' => $companyOptions,
        ];
    }
};
?>
<div>
    <x-slot:header>Edit User: {{ $user->name }}</x-slot:header>

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">User Details</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                    <input wire:model="email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                    <input wire:model="username" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('username')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input wire:model.live="resetPassword" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        <span class="text-sm font-medium text-gray-700">Reset password</span>
                    </label>
                    @if($resetPassword)
                    <div class="mt-2">
                        <input wire:model="newPassword" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" placeholder="New password">
                        @error('newPassword')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Roles *</h3>
            @error('selectedRoles')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror

            <div class="space-y-4">
                @php $grouped = $roles->groupBy('tier'); @endphp
                @foreach($grouped as $tier => $tierRoles)
                    <div>
                        <p class="text-xs font-semibold uppercase text-gray-400 mb-2">{{ ucfirst($tier) }}</p>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach($tierRoles as $role)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                    <input wire:model.live="selectedRoles" type="checkbox" value="{{ $role->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                                    <span class="text-sm">{{ $role->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @php
            $hasDealerRole = \App\Models\Role::whereIn('id', $selectedRoles)->where('tier', 'dealer')->exists();
            $hasOemRole = \App\Models\Role::whereIn('id', $selectedRoles)->where('tier', 'oem')->exists();
            $hasDriverRole = \App\Models\Role::whereIn('id', $selectedRoles)->where('slug', 'driver')->exists();
            $companyRequired = $hasDealerRole || $hasOemRole || $hasDriverRole;
            $currentCompany = $user->companies()->first();
        @endphp

        @if($hasDriverRole)
            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <p class="font-semibold mb-1">Attaching as a driver to a dealer</p>
                <p class="text-xs">
                    Drivers attached to a dealer company appear in that dealer's
                    /customer/drivers pool and can be assigned to <em>Internal</em>
                    executor movements. Pin the user to the platform-owner
                    company instead if this is a ProSelver driver.
                </p>
            </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">
                Organisation
                @if($companyRequired) <span class="text-red-500">*</span> @endif
            </h3>
            <p class="text-xs text-gray-500 mb-4">
                Pin this user to a single customer / dealer / OEM. Internal
                staff can be left unassigned. Required for dealer-, OEM- or
                driver-role assignments.
                @if($currentCompany)
                    Currently assigned to <span class="font-medium text-gray-700">{{ $currentCompany->name }}</span>.
                @else
                    Currently unassigned.
                @endif
            </p>
            <x-searchable-select
                wire:model="companyId"
                :options="$companyOptions"
                placeholder="— not assigned —"
                search-placeholder="Search organisations…"
            />
            @error('companyId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Save Changes</span>
                <span wire:loading>Saving...</span>
            </button>
        </div>
    </form>
</div>
