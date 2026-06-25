<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public Company $company;

    public string $name = '';
    public string $type = '';
    public string $workflowType = 'standard';
    public string $address = '';
    public string $vatNumber = '';
    public string $billingEmail = '';
    public string $phone = '';
    public bool $isActive = true;
    public array $selectedBrandIds = [];
    // Group FK as string for the <select> binding. Empty = no group;
    // "__new__" = create-on-save (matches the index modal's UX).
    public string $groupId = '';
    public string $newGroupName = '';

    public bool $editing = false;

    // ----- Inline "Add user" form state ---------------------------------
    // The Volt component on /admin/users/create handles standalone user
    // creation; we mirror its essential fields here so admin doesn't
    // have to bounce out of the Companies page just to add the org's
    // primary contact (or a second / third teammate later on).
    public bool $showAddUser = false;
    public string $newUserName = '';
    public string $newUserEmail = '';
    public string $newUserPhone = '';
    public string $newUserPassword = '';
    public bool $generateNewUserPassword = true;
    public string $newUserRoleSlug = '';

    // ----- "Attach existing user" inline form ---------------------------
    // The /admin/users/create page is for brand-new accounts. When the
    // person already exists (e.g. a CFAO ops manager who covers another
    // CFAO dealership) admin needs a way to link the existing User row
    // to this company without bouncing through /admin/users/{id}/edit.
    // attachableUsers in with() filters to active accounts that are
    // either un-linked OR linked elsewhere — anyone already on this
    // company is hidden so the dropdown is clean.
    public bool $showAttachUser = false;
    public ?int $attachUserId = null;

    // ----- Inline "Add location" form state -----------------------------
    // Google Places autocomplete populates address/city/province/lat/lng
    // via the placesAutocomplete Alpine helper in app.blade.php.
    public bool $showAddLocation = false;
    public string $newLocName = '';
    public string $newLocAddress = '';
    public string $newLocCity = '';
    public string $newLocProvince = '';
    public string $newLocLatitude = '';
    public string $newLocLongitude = '';
    public string $newLocContactName = '';
    public string $newLocContactPhone = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->fillForm();
        $this->newUserRoleSlug = $this->defaultRoleSlugForType($this->company->type ?? '');
        $this->newUserPassword = Str::random(12);
    }

    protected function fillForm(): void
    {
        $this->name = $this->company->name;
        $this->type = $this->company->type ?? Company::TYPE_DEALER;
        $this->workflowType = $this->company->workflow_type ?? 'standard';
        $this->address = $this->company->address ?? '';
        $this->vatNumber = $this->company->vat_number ?? '';
        $this->billingEmail = $this->company->billing_email ?? '';
        $this->phone = $this->company->phone ?? '';
        $this->isActive = $this->company->is_active;
        $this->selectedBrandIds = $this->company->brands()->pluck('brands.id')->map(fn($id) => (string) $id)->toArray();
        $this->groupId = $this->company->company_group_id ? (string) $this->company->company_group_id : '';
        $this->newGroupName = '';
    }

    protected function defaultRoleSlugForType(string $type): string
    {
        return match ($type) {
            Company::TYPE_BODY_BUILDER => 'body_builder_owner',
            Company::TYPE_DEALER, Company::TYPE_OEM, Company::TYPE_CUSTOMER => 'customer_owner',
            default => '',
        };
    }

    public function toggleEdit(): void
    {
        $this->editing = !$this->editing;
        if (!$this->editing) {
            $this->fillForm();
        }
    }

    public function save(): void
    {
        // Type whitelist is the full Company::TYPES list now (dealer,
        // oem, body_builder, transporter, yard, internal, customer)
        // rather than the old 3-value subset, so admin can promote /
        // change a company without surgery on the DB.
        $rules = [
            'name' => [
                'required', 'string', 'max:255',
                // Catch case-only / accent-only duplicates BEFORE the
                // update SQL fires, otherwise the DB's unique index on
                // normalized_name throws a 500 (the column is always
                // lower-ASCII so "Isuzu Motors SA" and "ISUZU MOTORS
                // SA" collide there even though the literal name is
                // different).  withTrashed so the admin sees "you
                // already deleted that one -- restore from Show
                // deleted" instead of being silently blocked.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $normalized = Str::lower(Str::ascii(trim((string) $value)));
                    $clash = Company::withTrashed()
                        ->where('normalized_name', $normalized)
                        ->where('id', '!=', $this->company->id)
                        ->first();
                    if ($clash) {
                        $hint = $clash->trashed()
                            ? '(it is currently soft-deleted -- restore it from Show deleted on the company list)'
                            : '(open and merge / delete the duplicate first)';
                        $fail("Another company already uses this name {$hint}.");
                    }
                },
            ],
            'type' => 'required|in:' . implode(',', Company::TYPES),
            'workflowType' => 'required|in:standard,faw',
            'address' => 'nullable|string|max:500',
            'vatNumber' => 'nullable|string|max:20',
            'billingEmail' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'selectedBrandIds' => 'array',
            'selectedBrandIds.*' => 'exists:brands,id',
            'groupId' => 'nullable|string',
        ];

        if ($this->groupId === '__new__') {
            $rules['newGroupName'] = 'required|string|max:255|unique:company_groups,name';
        } elseif ($this->groupId !== '') {
            $rules['groupId'] = 'required|exists:company_groups,id';
        }

        $this->validate($rules);

        // Resolve the group FK in the same flow the index modal uses,
        // so admins get a single "save" press whether they're picking
        // an existing group or naming a new one inline.
        $resolvedGroupId = null;
        if ($this->groupId === '__new__') {
            $resolvedGroupId = CompanyGroup::create([
                'name'      => $this->newGroupName,
                'is_active' => true,
            ])->id;
        } elseif ($this->groupId !== '') {
            $resolvedGroupId = (int) $this->groupId;
        }

        $this->company->update([
            'name' => $this->name,
            'type' => $this->type,
            'workflow_type' => $this->workflowType,
            'address' => $this->address,
            'vat_number' => $this->vatNumber,
            'billing_email' => $this->billingEmail,
            'phone' => $this->phone,
            'company_group_id' => $resolvedGroupId,
            'is_active' => $this->isActive,
        ]);

        $this->company->brands()->sync(array_map('intval', $this->selectedBrandIds));

        $this->editing = false;
        $this->fillForm();
        session()->flash('success', 'Company updated.');
    }

    // ----- Inline add-user actions --------------------------------------

    public function toggleAddUser(): void
    {
        $this->showAddUser = !$this->showAddUser;
        if (!$this->showAddUser) {
            $this->resetAddUserForm();
        }
    }

    public function updatedGenerateNewUserPassword(): void
    {
        $this->newUserPassword = $this->generateNewUserPassword
            ? Str::random(12)
            : '';
    }

    protected function resetAddUserForm(): void
    {
        $this->newUserName = '';
        $this->newUserEmail = '';
        $this->newUserPhone = '';
        $this->newUserPassword = Str::random(12);
        $this->generateNewUserPassword = true;
        $this->newUserRoleSlug = $this->defaultRoleSlugForType($this->company->type ?? '');
        $this->resetErrorBag(['newUserName', 'newUserEmail', 'newUserPhone', 'newUserPassword', 'newUserRoleSlug']);
    }

    public function addUser(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not create users.');

        $data = $this->validate([
            'newUserName'     => 'required|string|max:255',
            'newUserEmail'    => 'required|email|max:255|unique:users,email',
            'newUserPhone'    => 'nullable|string|max:30',
            'newUserPassword' => 'required|string|min:8',
            'newUserRoleSlug' => 'required|exists:roles,slug',
        ]);

        abort_unless(
            $actor->canAssignRole($data['newUserRoleSlug']),
            403,
            "You may not grant the {$data['newUserRoleSlug']} role."
        );

        $username = Str::lower(Str::before($data['newUserEmail'], '@'));
        $base = $username;
        $suffix = 0;
        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        $user = User::create([
            'name'                 => $data['newUserName'],
            'email'                => $data['newUserEmail'],
            'phone'                => $data['newUserPhone'] ?: null,
            'username'             => $username,
            'password'             => $data['newUserPassword'],
            'must_change_password' => true,
        ]);

        $role = Role::where('slug', $data['newUserRoleSlug'])->firstOrFail();
        $user->roles()->sync([$role->id]);
        $user->companies()->syncWithoutDetaching([$this->company->id]);

        session()->flash(
            'success',
            "Invited {$user->name} as " . $role->name . " (initial password: {$data['newUserPassword']})."
        );

        $this->showAddUser = false;
        $this->resetAddUserForm();
    }

    // ----- Attach-existing-user actions ---------------------------------

    public function toggleAttachUser(): void
    {
        $this->showAttachUser = !$this->showAttachUser;
        if (!$this->showAttachUser) {
            $this->attachUserId = null;
            $this->resetErrorBag(['attachUserId']);
        }
    }

    public function attachExistingUser(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not attach users.');

        $this->validate([
            'attachUserId' => 'required|integer|exists:users,id',
        ]);

        $target = User::findOrFail($this->attachUserId);

        // Rank guard: same rule as the standalone edit form. A lower-rank
        // manager cannot attach a senior user to anything; otherwise
        // ops_manager could quietly bind a super_admin to a random
        // dealership and use that as a back-door to that org's data.
        if (!$actor->isDeveloper() && $target->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403, 'You may not attach a user at or above your own role level.');
        }

        $target->companies()->syncWithoutDetaching([$this->company->id]);

        session()->flash('success', "Linked {$target->name} to {$this->company->name}.");
        $this->showAttachUser = false;
        $this->attachUserId = null;
    }

    public function detachUser(int $userId): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not unlink users.');

        $target = User::findOrFail($userId);

        if (!$actor->isDeveloper() && $target->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403, 'You may not unlink a user at or above your own role level.');
        }

        $target->companies()->detach($this->company->id);

        session()->flash('success', "Unlinked {$target->name} from {$this->company->name}.");
    }

    // ----- Group-principal actions --------------------------------------
    // A "group principal" (the franchise CEO / holding-company manager)
    // is implemented as a User attached to every Company in a group via
    // company_users -- no new role, no new column.  The action just
    // syncWithoutDetaching the user into every sibling dealership in
    // the same company_group_id as the company being viewed.  Reversed
    // by detaching them from every sibling (excluding this one) on the
    // "Remove from group" action.

    /**
     * Pivot-attach the user to every other Company in this company's
     * dealer group, so they see stock/orders across the whole umbrella.
     */
    public function makeGroupPrincipal(int $userId): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not grant group access.');

        $target = User::findOrFail($userId);

        if (!$actor->isDeveloper() && $target->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403, 'You may not modify a user at or above your own role level.');
        }

        if (!$this->company->company_group_id) {
            session()->flash('error', "{$this->company->name} is not in a dealer group. Assign it to a group first, then try again.");
            return;
        }

        $siblingIds = Company::where('company_group_id', $this->company->company_group_id)
            ->where('id', '!=', $this->company->id)
            ->pluck('id')
            ->all();

        if (empty($siblingIds)) {
            session()->flash('error', 'This group has no other dealerships yet. Add more dealerships to the group first.');
            return;
        }

        $target->companies()->syncWithoutDetaching($siblingIds);

        $groupName = $this->company->group?->name ?? 'group';
        session()->flash(
            'success',
            "{$target->name} now has access to every dealership in {$groupName} (" . (count($siblingIds) + 1) . " companies in total)."
        );
    }

    /**
     * Reverse of makeGroupPrincipal(): detach the user from every
     * sibling company in this group, leaving only their direct link to
     * the current company intact.  Safe even if the user was never
     * promoted -- detach() of a non-pivot is a no-op.
     */
    public function removeGroupPrincipal(int $userId): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not revoke group access.');

        $target = User::findOrFail($userId);

        if (!$actor->isDeveloper() && $target->highestRoleLevel() >= $actor->highestRoleLevel()) {
            abort(403, 'You may not modify a user at or above your own role level.');
        }

        if (!$this->company->company_group_id) {
            return;
        }

        $siblingIds = Company::where('company_group_id', $this->company->company_group_id)
            ->where('id', '!=', $this->company->id)
            ->pluck('id')
            ->all();

        if (!empty($siblingIds)) {
            $target->companies()->detach($siblingIds);
        }

        session()->flash('success', "Removed {$target->name}'s access to sibling dealerships.");
    }

    // ----- Inline add-location actions ----------------------------------

    public function toggleAddLocation(): void
    {
        $this->showAddLocation = !$this->showAddLocation;
        if (!$this->showAddLocation) {
            $this->resetAddLocationForm();
        }
    }

    protected function resetAddLocationForm(): void
    {
        $this->newLocName = '';
        $this->newLocAddress = '';
        $this->newLocCity = '';
        $this->newLocProvince = '';
        $this->newLocLatitude = '';
        $this->newLocLongitude = '';
        $this->newLocContactName = '';
        $this->newLocContactPhone = '';
        $this->resetErrorBag(['newLocName', 'newLocAddress', 'newLocCity', 'newLocProvince', 'newLocLatitude', 'newLocLongitude']);
    }

    public function addLocation(): void
    {
        abort_unless(Gate::allows('update', $this->company), 403);

        $data = $this->validate([
            'newLocName'         => 'required|string|max:255',
            'newLocAddress'      => 'required|string|max:500',
            'newLocCity'         => 'nullable|string|max:255',
            'newLocProvince'     => 'nullable|string|max:255',
            'newLocLatitude'     => 'nullable|numeric',
            'newLocLongitude'    => 'nullable|numeric',
            'newLocContactName'  => 'nullable|string|max:255',
            'newLocContactPhone' => 'nullable|string|max:50',
        ]);

        Location::create([
            'company_id'     => $this->company->id,
            'company_name'   => $data['newLocName'],
            'type'           => $this->locationTypeForCompanyType($this->company->type ?? ''),
            'address'        => $data['newLocAddress'],
            'city'           => $data['newLocCity'] ?: null,
            'province'       => $data['newLocProvince'] ?: null,
            'latitude'       => $data['newLocLatitude'] !== '' ? $data['newLocLatitude'] : null,
            'longitude'      => $data['newLocLongitude'] !== '' ? $data['newLocLongitude'] : null,
            'customer_name'  => $data['newLocContactName'] ?: null,
            'customer_phone' => $data['newLocContactPhone'] ?: null,
            'is_active'      => true,
        ]);

        session()->flash('success', "Added location \"{$data['newLocName']}\".");
        $this->showAddLocation = false;
        $this->resetAddLocationForm();
    }

    protected function locationTypeForCompanyType(string $companyType): string
    {
        return match ($companyType) {
            Company::TYPE_BODY_BUILDER => Location::TYPE_BODY_BUILDER,
            Company::TYPE_YARD         => Location::TYPE_YARD,
            default                    => Location::TYPE_DEALER,
        };
    }

    public function with(): array
    {
        $actor = auth()->user();

        $users = $this->company->users()
            ->with(['roles', 'companies' => fn($q) => $q->where('companies.id', $this->company->id)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $orderStats = [
            'total' => Job::where('company_id', $this->company->id)->count(),
            'active' => Job::where('company_id', $this->company->id)
                ->whereIn('status', [
                    Job::STATUS_RECEIVED, Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                    Job::STATUS_CONFIRMATION_ISSUE,
                    Job::STATUS_CONFIRMED, Job::STATUS_PLANNED, Job::STATUS_DRIVER_ASSIGNED,
                    Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT,
                ])->count(),
            'completed' => Job::where('company_id', $this->company->id)
                ->where('status', Job::STATUS_COMPLETED)->count(),
        ];

        $locations = $this->company->locations()->orderBy('company_name')->get();
        $allBrands = Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $companyBrands = $this->company->brands()->pluck('brands.id')->toArray();

        $typeLabels = [
            Company::TYPE_DEALER       => 'Dealer',
            Company::TYPE_OEM          => 'OEM',
            Company::TYPE_BODY_BUILDER => 'Body Builder',
            Company::TYPE_TRANSPORTER  => 'Transporter',
            Company::TYPE_YARD         => 'Yard / Storage',
            Company::TYPE_INTERNAL     => 'Internal (ProSelver)',
            Company::TYPE_CUSTOMER     => 'Customer',
        ];

        // Roles the actor is permitted to grant for the inline
        // "Add user" form.  Same canAssignRole() gate the
        // standalone /admin/users/create page uses.  Tier ordering
        // is done PHP-side (not SQL ORDER BY FIELD()) because the
        // production DB is PostgreSQL and FIELD() is MySQL-only.
        // Legacy dealer-tier and oem-tier roles are no longer offered for
        // NEW assignments: every modern tenant (dealer / OEM / customer)
        // sits on a customer-tier role and Company::$type drives the
        // dealer/OEM re-skinning. The customer-tier options below are
        // relabelled by this company's type in the blade, so a dealer
        // admin picks "Dealer Owner", an OEM admin picks "OEM Owner", etc.
        $legacyTenantTiers = ['dealer', 'oem'];
        $tierOrder = ['customer' => 0, 'driver' => 3, 'internal' => 4];
        $assignableRoles = Role::orderBy('name')
            ->get()
            ->filter(fn ($r) => $actor?->canAssignRole($r->slug))
            ->reject(fn ($r) => in_array($r->tier, $legacyTenantTiers, true))
            ->sortBy(fn ($r) => ($tierOrder[$r->tier] ?? 99) . '|' . $r->name)
            ->values();

        $groups = CompanyGroup::where('is_active', true)
            ->orWhere('id', $this->company->company_group_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Candidate users for the "Attach existing user" picker. Anyone
        // active and not already on this company is fair game — drivers,
        // dealer staff from a sister branch, internal ops, etc. The
        // server-side rank guard in attachExistingUser() does the final
        // authorisation check; this just keeps the dropdown short.
        $existingMemberIds = $users->pluck('id')->all();
        $attachableUsers = User::where('is_active', true)
            ->whereNotIn('id', $existingMemberIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        // Group principal lookup -- a user is treated as the franchise
        // CEO for this group iff they are linked to every other
        // dealership in the same company_group_id.  Returns user id =>
        // bool; consumed by the "Make/Remove group principal" buttons
        // on each row.  Cheap because we already have the users list
        // and their companies in memory via the eager load.
        $isGroupPrincipal = [];
        $siblingIdsForCheck = [];
        $siblingCountForCheck = 0;
        if ($this->company->company_group_id) {
            $siblingIdsForCheck = Company::where('company_group_id', $this->company->company_group_id)
                ->where('id', '!=', $this->company->id)
                ->pluck('id')
                ->all();
            $siblingCountForCheck = count($siblingIdsForCheck);

            if ($siblingCountForCheck > 0) {
                $linkCounts = DB::table('company_users')
                    ->whereIn('user_id', $existingMemberIds)
                    ->whereIn('company_id', $siblingIdsForCheck)
                    ->selectRaw('user_id, COUNT(*) AS linked_count')
                    ->groupBy('user_id')
                    ->pluck('linked_count', 'user_id');

                foreach ($existingMemberIds as $uid) {
                    $isGroupPrincipal[$uid] = (int) ($linkCounts[$uid] ?? 0) === $siblingCountForCheck;
                }
            }
        }

        return [
            'users'                => $users,
            'orderStats'           => $orderStats,
            'locations'            => $locations,
            'allBrands'            => $allBrands,
            'companyBrands'        => $companyBrands,
            'typeLabels'           => $typeLabels,
            'assignableRoles'      => $assignableRoles,
            'groups'               => $groups,
            'attachableUsers'      => $attachableUsers,
            'canCreateUsers'       => (bool) $actor?->canManageInternalUsers(),
            'canManageCompany'     => Gate::allows('update', $this->company),
            'isGroupPrincipal'     => $isGroupPrincipal,
            'groupSiblingCount'    => $siblingCountForCheck,
        ];
    }
};
?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.companies.index') }}" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            {{ $company->name }}
        </div>
    </x-slot:header>

    <div class="space-y-6">

        @if(session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ session('error') }}</div>
        @endif

        {{-- Order Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Total Orders</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $orderStats['total'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Active Orders</p>
                <p class="mt-1 text-2xl font-bold text-blue-600">{{ $orderStats['active'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">Completed</p>
                <p class="mt-1 text-2xl font-bold text-green-600">{{ $orderStats['completed'] }}</p>
            </div>
        </div>

        {{-- Company Details --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Company Details</h3>
                <button wire:click="toggleEdit" class="text-sm font-medium {{ $editing ? 'text-gray-500 hover:text-gray-700' : 'text-blue-600 hover:text-blue-800' }}">
                    {{ $editing ? 'Cancel' : 'Edit' }}
                </button>
            </div>
            <div class="p-6">
                @if($editing)
                <form wire:submit="save" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select wire:model="type" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach($typeLabels as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Workflow Type</label>
                            <select wire:model="workflowType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="standard">Standard</option>
                                <option value="faw">FAW (requires customer confirmation)</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Group</label>
                            <select wire:model.live="groupId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">— No group —</option>
                                @foreach($groups as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                                @endforeach
                                <option value="__new__">+ Create new group…</option>
                            </select>
                            @if($groupId === '__new__')
                                <input wire:model="newGroupName" type="text" placeholder="New group name (e.g. MCCARTHY)" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                @error('newGroupName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            @endif
                            @error('groupId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-500">
                                Optional. Group dealerships under their holding company (e.g. MCCARTHY, CFAO) so siblings share an overview.
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Billing Email</label>
                            <input wire:model="billingEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('billingEmail') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">VAT Number</label>
                            <input wire:model="vatNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        {{-- Address with Google Places autocomplete.  We
                             only populate $address here (city / province /
                             coords for individual workshops live on
                             $locations rows; the Company.address column is
                             only the primary contact / billing address).
                             Use the placesAutocomplete Alpine helper from
                             components/layouts/app.blade.php. --}}
                        <div class="sm:col-span-2" x-data="placesAutocomplete({ addressModel: 'address' })">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Primary address</label>
                            <input x-ref="addressInput" wire:model="address" type="text" autocomplete="off" placeholder="Start typing to search Google Maps…" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @if($company->type === \App\Models\Company::TYPE_BODY_BUILDER)
                                <p class="mt-1 text-xs text-amber-700">
                                    This is just the primary / billing address. Add each manufacturing plant and satellite repair station separately under <strong>Locations</strong> below.
                                </p>
                            @endif
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input wire:model="isActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Active</span>
                            </label>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Assigned Brands</label>
                            <p class="text-xs text-gray-500 mb-2">Select which brands this company can use when creating orders. Leave empty to allow all brands.</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach($allBrands as $brand)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50">
                                    <input type="checkbox" wire:model="selectedBrandIds" value="{{ $brand->id }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">{{ $brand->name }}</span>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                            Save Changes
                        </button>
                    </div>
                </form>
                @else
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Name</dt>
                        <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $company->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Type</dt>
                        <dd class="mt-0.5">
                            <span @class([
                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-purple-100 text-purple-700'   => $company->type === Company::TYPE_OEM,
                                'bg-blue-100 text-blue-700'       => $company->type === Company::TYPE_DEALER,
                                'bg-amber-100 text-amber-800'     => $company->type === Company::TYPE_BODY_BUILDER,
                                'bg-slate-100 text-slate-700'     => $company->type === Company::TYPE_INTERNAL,
                                'bg-cyan-100 text-cyan-700'       => $company->type === Company::TYPE_TRANSPORTER,
                                'bg-orange-100 text-orange-700'   => $company->type === Company::TYPE_YARD,
                                'bg-green-100 text-green-700'     => $company->type === Company::TYPE_CUSTOMER,
                                'bg-gray-100 text-gray-600'       => ! in_array($company->type, Company::TYPES, true),
                            ])>{{ $typeLabels[$company->type] ?? ucfirst($company->type ?? 'unknown') }}</span>
                            @php
                                // The type — not the role slug — drives what tenants
                                // of this company see. Surface it here so a dealer
                                // accidentally typed as "Customer" is obvious at a
                                // glance (the exact mix-up that makes a dealer login
                                // read "Customer Portal").
                                $portalForType = match($company->type) {
                                    Company::TYPE_DEALER       => 'Dealer Portal',
                                    Company::TYPE_OEM          => 'OEM Portal',
                                    Company::TYPE_BODY_BUILDER => 'Body Builder portal',
                                    Company::TYPE_CUSTOMER     => 'Customer Portal',
                                    default                    => null,
                                };
                            @endphp
                            @if($portalForType)
                                <p class="mt-1 text-xs text-gray-500">Tenants see: <span class="font-medium text-gray-700">{{ $portalForType }}</span> &amp; "{{ str_replace('Customer ', $company->type === Company::TYPE_DEALER ? 'Dealer ' : ($company->type === Company::TYPE_OEM ? 'OEM ' : 'Customer '), 'Customer Owner') }}" style labels.</p>
                            @elseif(! in_array($company->type, Company::TYPES, true) || ! $company->type)
                                <p class="mt-1 text-xs text-amber-700">Type not set — tenants will fall back to a generic "Customer Portal". Set the correct type so dealer/OEM branding shows.</p>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Workflow</dt>
                        <dd class="mt-0.5">
                            @if($company->workflow_type === 'faw')
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">FAW (confirmation required)</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Standard</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Group</dt>
                        <dd class="mt-0.5">
                            @if($company->group)
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">{{ $company->group->name }}</span>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Phone</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Billing Email</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->billing_email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">VAT Number</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->vat_number ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-gray-500">Address</dt>
                        <dd class="mt-0.5 text-sm text-gray-900">{{ $company->address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Status</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $company->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $company->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </dd>
                    </div>
                    @if(count($companyBrands) > 0)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-gray-500">Assigned Brands</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @foreach($allBrands->whereIn('id', $companyBrands) as $brand)
                                <span class="inline-flex items-center rounded-full bg-purple-50 px-2.5 py-0.5 text-xs font-medium text-purple-700">{{ $brand->name }}</span>
                            @endforeach
                        </dd>
                    </div>
                    @endif
                </dl>
                @endif
            </div>
        </div>

        {{-- Users --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Users ({{ $users->count() }})</h3>
                @if($canCreateUsers)
                    <div class="flex items-center gap-2">
                        @if($attachableUsers->isNotEmpty())
                            <button wire:click="toggleAttachUser" class="inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-6 0v4"/><rect width="20" height="14" x="2" y="9" rx="2"/></svg>
                                {{ $showAttachUser ? 'Cancel' : 'Attach existing' }}
                            </button>
                        @endif
                        @if($assignableRoles->isNotEmpty())
                            <button wire:click="toggleAddUser" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                {{ $showAddUser ? 'Cancel' : 'Add new user' }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            @if($showAttachUser && $canCreateUsers)
                <div class="border-b border-gray-200 bg-blue-50/40 px-6 py-5"
                     x-data="{ search: '' }">
                    <p class="text-xs text-gray-600 mb-3">
                        Pick an existing user to add to this dealership. Useful for group-level staff who already exist on a sister branch and just need a second link.
                    </p>
                    <input type="text" x-model="search" placeholder="Search by name or email…"
                           class="mb-3 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <form wire:submit.prevent="attachExistingUser" class="flex flex-col gap-3">
                        <div class="max-h-60 overflow-y-auto pr-1 space-y-1">
                            @foreach($attachableUsers as $candidate)
                                <label class="flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 cursor-pointer hover:bg-gray-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"
                                       x-show="search === '' || '{{ Str::lower($candidate->name) }} {{ Str::lower($candidate->email ?? '') }}'.includes(search.toLowerCase())">
                                    <input wire:model.live="attachUserId" type="radio" value="{{ $candidate->id }}" class="h-4 w-4 border-gray-300 text-blue-600">
                                    <span class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium text-gray-900 truncate">{{ $candidate->name }}</span>
                                        <span class="block text-xs text-gray-500 truncate">{{ $candidate->email ?? '—' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('attachUserId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="toggleAttachUser" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                            <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                                Attach to {{ $company->name }}
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            @if($showAddUser && $canCreateUsers)
                <div class="border-b border-gray-200 bg-blue-50/40 px-6 py-5">
                    <form wire:submit.prevent="addUser" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Full name *</label>
                            <input wire:model="newUserName" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newUserName') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Email *</label>
                            <input wire:model="newUserEmail" type="email" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newUserEmail') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Phone</label>
                            <input wire:model="newUserPhone" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Role *</label>
                            <select wire:model="newUserRoleSlug" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">— select —</option>
                                @foreach($assignableRoles as $role)
                                    <option value="{{ $role->slug }}">{{ tenantRoleDisplayName($role->name, $company->type) }}</option>
                                @endforeach
                            </select>
                            @error('newUserRoleSlug') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Initial password *</label>
                            <input wire:model="newUserPassword" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" {{ $generateNewUserPassword ? 'readonly' : '' }}>
                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                                <input wire:model.live="generateNewUserPassword" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                                Auto-generate password
                            </label>
                            <p class="mt-1 text-xs text-gray-500">
                                The user will be forced to change this on first sign-in. Share it over a secure channel.
                            </p>
                            @error('newUserPassword') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2 flex justify-end gap-2">
                            <button type="button" wire:click="toggleAddUser" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                            <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">Invite user</button>
                        </div>
                    </form>
                </div>
            @endif

            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Username</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Role(s)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($users as $user)
                    <tr class="{{ !$user->is_active ? 'opacity-50' : '' }}">
                        <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $user->name }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->username }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->email ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $user->phone ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                            @foreach($user->roles as $role)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 mr-1">{{ $role->name }}</span>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                            @php
                                $userPivot = $user->companies->first()?->pivot;
                                $userLoc = $userPivot?->location_id ? $locations->firstWhere('id', $userPivot->location_id) : null;
                            @endphp
                            {{ $userLoc?->company_name ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm">
                            <div class="flex items-center gap-3">
                                @if(auth()->user()->isDeveloper())
                                    <form method="POST" action="{{ route('admin.impersonate', $user) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-amber-600 hover:text-amber-800 font-medium">Impersonate</button>
                                    </form>
                                @endif
                                @if($canCreateUsers && $company->company_group_id && $groupSiblingCount > 0)
                                    @if(($isGroupPrincipal[$user->id] ?? false))
                                        <button type="button"
                                                wire:click="removeGroupPrincipal({{ $user->id }})"
                                                wire:confirm="Remove {{ $user->name }}'s access to the other {{ $groupSiblingCount }} dealership(s) in {{ $company->group?->name ?? 'this group' }}? Their link to {{ $company->name }} stays intact."
                                                class="text-amber-700 hover:text-amber-900 font-medium"
                                                title="Currently a group principal — sees stock across the whole group">
                                            Remove group access
                                        </button>
                                    @else
                                        <button type="button"
                                                wire:click="makeGroupPrincipal({{ $user->id }})"
                                                wire:confirm="Grant {{ $user->name }} access to every other dealership in {{ $company->group?->name ?? 'this group' }} ({{ $groupSiblingCount }} sibling(s))? They will see stock and orders across the whole umbrella."
                                                class="text-emerald-700 hover:text-emerald-900 font-medium"
                                                title="Promote to franchise CEO / group principal — adds them to every sibling dealership">
                                            Make group principal
                                        </button>
                                    @endif
                                @endif
                                @if($canCreateUsers)
                                    <button type="button"
                                            wire:click="detachUser({{ $user->id }})"
                                            wire:confirm="Unlink {{ $user->name }} from {{ $company->name }}? Their other company links stay intact."
                                            class="text-red-600 hover:text-red-800 font-medium">
                                        Unlink
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500">
                            No users linked yet.
                            @if($canCreateUsers && $assignableRoles->isNotEmpty() && !$showAddUser)
                                <button wire:click="toggleAddUser" class="text-blue-600 hover:text-blue-800 font-medium">Invite the first user →</button>
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Locations — always visible so the "Add location" CTA is
             discoverable.  Body builders especially need this: a single
             tenant often has a head-office plant plus satellite repair
             stations plus secondary plants in other cities. --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Locations ({{ $locations->count() }})</h3>
                    @if($company->type === \App\Models\Company::TYPE_BODY_BUILDER)
                        <p class="mt-0.5 text-xs text-gray-500">
                            Add every site — main manufacturing plants, satellite repair stations, and plants in other cities. Each address becomes a pickable destination on incoming movement requests.
                        </p>
                    @endif
                </div>
                @if($canManageCompany)
                    <button wire:click="toggleAddLocation" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 transition-colors">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        {{ $showAddLocation ? 'Cancel' : 'Add location' }}
                    </button>
                @endif
            </div>

            @if($showAddLocation && $canManageCompany)
                <div class="border-b border-gray-200 bg-blue-50/40 px-6 py-5">
                    <form wire:submit.prevent="addLocation" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Location name *</label>
                            <input wire:model="newLocName" type="text" placeholder="e.g. Pretoria Plant, Cape Town Satellite, Durban Repair Station" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newLocName') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- Google Places autocomplete: place_changed fills
                             address + city + province + lat + lng on the
                             Livewire component in one shot. --}}
                        <div class="sm:col-span-2" x-data="placesAutocomplete({ addressModel: 'newLocAddress', cityModel: 'newLocCity', provinceModel: 'newLocProvince', latModel: 'newLocLatitude', lngModel: 'newLocLongitude' })">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Address *</label>
                            <input x-ref="addressInput" wire:model="newLocAddress" type="text" autocomplete="off" placeholder="Start typing to search Google Maps…" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('newLocAddress') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">City</label>
                            <input wire:model="newLocCity" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Province</label>
                            <input wire:model="newLocProvince" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Site contact name</label>
                            <input wire:model="newLocContactName" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Site contact phone</label>
                            <input wire:model="newLocContactPhone" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        </div>

                        <input type="hidden" wire:model="newLocLatitude">
                        <input type="hidden" wire:model="newLocLongitude">

                        <div class="sm:col-span-2 flex justify-end gap-2">
                            <button type="button" wire:click="toggleAddLocation" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                            <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">Save location</button>
                        </div>
                    </form>
                </div>
            @endif

            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Address</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">City</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($locations as $location)
                    <tr>
                        <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $location->company_name }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $location->address }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $location->city ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">{{ $location->customer_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $location->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $location->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                            No locations yet.
                            @if($canManageCompany && !$showAddLocation)
                                <button wire:click="toggleAddLocation" class="text-blue-600 hover:text-blue-800 font-medium">Add the first one →</button>
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>
</div>
