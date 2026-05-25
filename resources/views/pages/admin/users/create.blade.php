<?php
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $username = '';
    public string $password = '';
    public bool $generatePassword = true;
    public array $selectedRoles = [];
    // Multi-company support: a single user (e.g. a CFAO group ops
    // manager) can belong to many dealerships. Stored as an array of
    // string ids so it round-trips through the <select multiple>
    // / Livewire pivot cleanly. Old single-value $companyId was the
    // reason a save here used to detach siblings — see save() below.
    public array $companyIds = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->canManageInternalUsers(), 403, 'You may not create users.');
        $this->password = Str::random(12);
    }

    public function updatedGeneratePassword(): void
    {
        if ($this->generatePassword) {
            $this->password = Str::random(12);
        } else {
            $this->password = '';
        }
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403);

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:50|unique:users,username',
            'password' => 'required|string|min:8',
            'selectedRoles' => 'required|array|min:1',
        ];

        $hasDealerRole = Role::whereIn('id', $this->selectedRoles)->where('tier', 'dealer')->exists();
        $hasOemRole = Role::whereIn('id', $this->selectedRoles)->where('tier', 'oem')->exists();
        // Driver role attached on a dealer's behalf: ops uses this form
        // to onboard an internal driver for a dealer who's just signing
        // up. The dealer link is the whole point of the operation, so
        // make companyId mandatory in that path too.
        $hasDriverRole = Role::whereIn('id', $this->selectedRoles)->where('slug', 'driver')->exists();

        if ($hasDealerRole || $hasOemRole || $hasDriverRole) {
            $rules['companyIds']   = 'required|array|min:1';
            $rules['companyIds.*'] = 'integer|exists:companies,id';
        } else {
            $rules['companyIds']   = 'array';
            $rules['companyIds.*'] = 'integer|exists:companies,id';
        }

        $this->validate($rules);

        // Server-side role allowlist — the UI already hides roles the actor
        // cannot grant, but Livewire properties can be tampered with so we
        // must re-check every submitted role id here. Without this, a
        // dispatcher (or any user who reaches this form) could promote
        // themselves or someone else to super_admin.
        $submittedRoles = Role::whereIn('id', $this->selectedRoles)->get();
        foreach ($submittedRoles as $role) {
            if (!$actor->canAssignRole($role->slug)) {
                abort(403, "You may not assign the {$role->name} role.");
            }
        }

        $username = $this->username ?: Str::before($this->email, '@');
        $suffix = 0;
        $base = $username;
        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'username' => Str::lower($username),
            'password' => $this->password,
            // Admin-issued passwords are shared over voice/chat — force the
            // new user to rotate on first sign-in so the admin's copy goes
            // stale immediately.
            'must_change_password' => true,
        ]);

        $user->roles()->sync($this->selectedRoles);

        if (!empty($this->companyIds)) {
            $user->companies()->sync(array_map('intval', $this->companyIds));
        }

        session()->flash('success', "User {$user->name} created successfully.");
        $this->redirect(route('admin.users.index'));
    }

    public function with(): array
    {
        $actor = auth()->user();
        // 'driver' is included here intentionally — ops uses this form to
        // attach internal drivers on behalf of a dealer who's just
        // onboarding (the "both" answer to the planning question). The
        // role picker groups by tier so drivers land in their own
        // dedicated row, separate from the customer_* family.
        $allRoles = Role::orderBy('tier')->orderBy('name')->get();

        $companies = Company::where('is_active', true)->orderBy('name')->get();

        $companyOptions = $companies->map(fn ($c) => [
            'value' => (string) $c->id,
            'label' => $c->name,
        ])->values()->all();

        return [
            // Filter to only roles the actor is permitted to grant. Prevents
            // a lower-tier internal user from seeing (let alone assigning)
            // super_admin or developer.
            'roles' => $allRoles->filter(fn ($r) => $actor->canAssignRole($r->slug))->values(),
            'companies' => $companies,
            'companyOptions' => $companyOptions,
        ];
    }
};
?>
<div>
    <x-slot:header>Create User</x-slot:header>

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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input wire:model="username" type="text" placeholder="Auto-generated from email if blank" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('username')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                    <input wire:model="password" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" {{ $generatePassword ? 'readonly' : '' }}>
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-600">
                        <input wire:model.live="generatePassword" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        Auto-generate password
                    </label>
                    @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
        @endphp

        @if($hasDriverRole)
            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <p class="font-semibold mb-1">Attaching as a driver to a dealer</p>
                <p class="text-xs">
                    Drivers attached to a dealer company appear in that dealer's
                    /customer/drivers pool and can be assigned to <em>Internal</em>
                    executor movements. Pick the platform-owner company instead
                    if this is a ProSelver-employed driver.
                </p>
            </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6"
             x-data="{ search: '' }">
            <div class="flex items-start justify-between gap-4 mb-1">
                <h3 class="text-lg font-semibold text-gray-900">
                    Organisations
                    @if($companyRequired) <span class="text-red-500">*</span> @endif
                </h3>
                <span class="text-xs text-gray-500">{{ count($companyIds) }} selected</span>
            </div>
            <p class="text-xs text-gray-500 mb-3">
                Tick every customer / dealer / OEM this user belongs to.
                A group ops manager (e.g. CFAO covering 5 dealerships) ticks all 5.
                Required for dealer-, OEM- or driver-role assignments.
            </p>

            <input type="text" x-model="search" placeholder="Search organisations…"
                   class="mb-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">

            <div class="max-h-72 overflow-y-auto pr-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                @foreach($companies as $c)
                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"
                           x-show="search === '' || '{{ Str::lower($c->name) }}'.includes(search.toLowerCase())">
                        <input wire:model.live="companyIds" type="checkbox" value="{{ $c->id }}" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        <span class="text-sm">{{ $c->name }}</span>
                    </label>
                @endforeach
            </div>

            @error('companyIds')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            @error('companyIds.*')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Create User</span>
                <span wire:loading>Creating...</span>
            </button>
        </div>
    </form>
</div>
