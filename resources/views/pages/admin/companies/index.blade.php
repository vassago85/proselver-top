<?php

use App\Models\Company;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    // Create-form state. Kept on the component (rather than a child
    // Volt page) so the modal can read the same $typeFilter etc. and
    // we don't have to re-resolve the user permission twice.
    public bool $showCreate = false;
    public string $newName = '';
    public string $newType = Company::TYPE_DEALER;
    public string $newWorkflowType = 'standard';
    public string $newPhone = '';
    public string $newBillingEmail = '';
    public string $newVatNumber = '';
    public string $newAddress = '';
    // Google Places autocomplete writes city/province/lat/lng into
    // these so we can seed a first Location row for the company in
    // the same transaction — that way the address appears on
    // /customer/orders/create pickup/delivery pickers immediately
    // (otherwise the admin has to bounce into Locations and re-key).
    public string $newCity = '';
    public string $newProvince = '';
    public string $newLatitude = '';
    public string $newLongitude = '';
    public bool $newIsActive = true;

    // First-user fields — toggling the checkbox reveals them on the
    // modal so the admin can onboard the org's primary contact in
    // the same step as creating the company.
    public bool $createFirstUser = true;
    public string $firstUserName = '';
    public string $firstUserEmail = '';
    public string $firstUserPhone = '';
    public string $firstUserPassword = '';
    public bool $generateFirstUserPassword = true;
    public string $firstUserRoleSlug = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedNewType(): void
    {
        // Default the first-user role to whatever the new company
        // type wants for its primary contact (body-builder owner for
        // BBs, customer_owner for everyone else).  Admin can still
        // change it before submitting.
        $this->firstUserRoleSlug = $this->defaultRoleSlugForType($this->newType);
    }

    public function updatedGenerateFirstUserPassword(): void
    {
        $this->firstUserPassword = $this->generateFirstUserPassword
            ? Str::random(12)
            : '';
    }

    public function openCreate(): void
    {
        // Server-side gate so a tampered Livewire payload can't open
        // the form for a viewer-only user.  The template hides the
        // button on the same condition.
        abort_unless(Gate::allows('create', Company::class), 403);
        $this->resetCreateForm();
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->resetCreateForm();
    }

    protected function resetCreateForm(): void
    {
        $this->newName = '';
        $this->newType = Company::TYPE_DEALER;
        $this->newWorkflowType = 'standard';
        $this->newPhone = '';
        $this->newBillingEmail = '';
        $this->newVatNumber = '';
        $this->newAddress = '';
        $this->newCity = '';
        $this->newProvince = '';
        $this->newLatitude = '';
        $this->newLongitude = '';
        $this->newIsActive = true;
        $this->createFirstUser = true;
        $this->firstUserName = '';
        $this->firstUserEmail = '';
        $this->firstUserPhone = '';
        $this->generateFirstUserPassword = true;
        $this->firstUserPassword = Str::random(12);
        $this->firstUserRoleSlug = $this->defaultRoleSlugForType($this->newType);
        $this->resetErrorBag();
    }

    /**
     * Pick a sensible default role for the org's primary contact
     * based on the company type.  Body builders get their own owner
     * role; dealers, OEMs and generic customers all share the
     * customer-tier owner slug (Company::$type drives the portal
     * branding and the BB / OEM / dealer behavioural splits).
     * Internal / yard / transporter don't auto-pick — the admin has
     * to choose explicitly (rare flow, usually no first user).
     */
    protected function defaultRoleSlugForType(string $type): string
    {
        return match ($type) {
            Company::TYPE_BODY_BUILDER => 'body_builder_owner',
            Company::TYPE_DEALER, Company::TYPE_OEM, Company::TYPE_CUSTOMER => 'customer_owner',
            default => '',
        };
    }

    public function createCompany()
    {
        $actor = auth()->user();
        abort_unless(Gate::allows('create', Company::class), 403);

        $rules = [
            'newName'         => 'required|string|max:255|unique:companies,name',
            'newType'         => 'required|in:' . implode(',', Company::TYPES),
            'newWorkflowType' => 'required|in:standard,faw',
            'newPhone'        => 'nullable|string|max:30',
            'newBillingEmail' => 'nullable|email|max:255',
            'newVatNumber'    => 'nullable|string|max:30',
            'newAddress'      => 'nullable|string|max:500',
            'newCity'         => 'nullable|string|max:255',
            'newProvince'     => 'nullable|string|max:255',
            'newLatitude'     => 'nullable|numeric',
            'newLongitude'    => 'nullable|numeric',
            'newIsActive'     => 'boolean',
        ];

        if ($this->createFirstUser) {
            $rules['firstUserName']     = 'required|string|max:255';
            $rules['firstUserEmail']    = 'required|email|max:255|unique:users,email';
            $rules['firstUserPhone']    = 'nullable|string|max:30';
            $rules['firstUserPassword'] = 'required|string|min:8';
            $rules['firstUserRoleSlug'] = 'required|exists:roles,slug';
        }

        $data = $this->validate($rules);

        if ($this->createFirstUser) {
            // Re-check that the actor is allowed to grant the chosen
            // role — Livewire properties can be tampered with so we
            // never trust the slug we got in $data without asking the
            // policy layer.  Same allowlist the standalone create-
            // user page enforces.
            abort_unless(
                $actor?->canManageInternalUsers() && $actor->canAssignRole($data['firstUserRoleSlug']),
                403,
                "You may not grant the {$data['firstUserRoleSlug']} role."
            );
        }

        $company = \DB::transaction(function () use ($data) {
            $company = Company::create([
                'name'          => $data['newName'],
                'type'          => $data['newType'],
                'workflow_type' => $data['newWorkflowType'],
                'phone'         => $data['newPhone'] ?: null,
                'billing_email' => $data['newBillingEmail'] ?: null,
                'vat_number'    => $data['newVatNumber'] ?: null,
                'address'       => $data['newAddress'] ?: null,
                'is_active'     => (bool) $data['newIsActive'],
            ]);

            // If the admin picked an address off Google Places we
            // also have city + province + coords — seed a Location
            // row so the address is selectable on /customer/orders/
            // create from the get-go.  Body builders especially
            // benefit because they typically need at least one
            // "head office" plant before they can be linked.
            if (!empty($data['newAddress'])) {
                Location::create([
                    'company_id'   => $company->id,
                    'company_name' => $data['newName'],
                    'type'         => $this->locationTypeForCompanyType($data['newType']),
                    'address'      => $data['newAddress'],
                    'city'         => $data['newCity'] ?: null,
                    'province'     => $data['newProvince'] ?: null,
                    'latitude'     => $data['newLatitude'] !== '' ? $data['newLatitude'] : null,
                    'longitude'    => $data['newLongitude'] !== '' ? $data['newLongitude'] : null,
                    'is_active'    => true,
                ]);
            }

            if (!empty($data['firstUserName'] ?? null)) {
                $username = Str::lower(Str::before($data['firstUserEmail'], '@'));
                $base = $username;
                $suffix = 0;
                while (User::where('username', $username)->exists()) {
                    $suffix++;
                    $username = $base . $suffix;
                }

                $user = User::create([
                    'name'                 => $data['firstUserName'],
                    'email'                => $data['firstUserEmail'],
                    'phone'                => $data['firstUserPhone'] ?: null,
                    'username'             => $username,
                    'password'             => $data['firstUserPassword'],
                    // Admin-issued password is shared over chat/voice —
                    // force the user to rotate on first sign-in so the
                    // admin's copy goes stale immediately.  Same flow
                    // the standalone admin/users/create page uses.
                    'must_change_password' => true,
                ]);

                $role = Role::where('slug', $data['firstUserRoleSlug'])->firstOrFail();
                $user->roles()->sync([$role->id]);
                $user->companies()->sync([$company->id]);
            }

            return $company;
        });

        $msg = 'Created ' . $company->name;
        if ($this->createFirstUser) {
            $msg .= ' and invited ' . $data['firstUserName'] . ' (password: ' . $data['firstUserPassword'] . ').';
        } else {
            $msg .= '. Add the first user from the company page when ready.';
        }
        session()->flash('success', $msg);

        // Land the operator straight on the new company's detail page
        // so the next steps (more users / locations / brand
        // assignments) are one click away — closes the loop on "I
        // added a company, now what?".
        return $this->redirectRoute('admin.companies.show', $company, navigate: true);
    }

    /**
     * Map a Company::$type to the closest Location::$type. Falls
     * back to TYPE_DEALER (the generic depot bucket) for company
     * types that don't have a 1:1 location equivalent.
     */
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
        $query = Company::withCount('users')->orderBy('name');

        if ($this->search) {
            $needle = '%' . $this->search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('billing_email', 'like', $needle)
                    ->orWhere('vat_number', 'like', $needle)
                    ->orWhere('phone', 'like', $needle);
            });
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        // Pretty labels for each Company::TYPE_* constant.  Kept here
        // (not on the model) because they're presentation strings —
        // the model carries the slugs as the canonical source.
        $typeLabels = [
            Company::TYPE_DEALER       => 'Dealer',
            Company::TYPE_OEM          => 'OEM',
            Company::TYPE_BODY_BUILDER => 'Body Builder',
            Company::TYPE_TRANSPORTER  => 'Transporter',
            Company::TYPE_YARD         => 'Yard / Storage',
            Company::TYPE_INTERNAL     => 'Internal (ProSelver)',
            Company::TYPE_CUSTOMER     => 'Customer',
        ];

        // Roles the actor is permitted to grant when filling out the
        // "first user" section of the modal.  Same canAssignRole()
        // gate the standalone /admin/users/create page uses, so a
        // dispatcher can't sneak a super_admin assignment in
        // through this side door.  Tier ordering is done PHP-side
        // (not SQL ORDER BY FIELD()) because the production DB is
        // PostgreSQL and FIELD() is MySQL-only.
        $actor = auth()->user();
        $tierOrder = ['customer' => 0, 'dealer' => 1, 'oem' => 2, 'driver' => 3, 'internal' => 4];
        $firstUserRoles = Role::orderBy('name')
            ->get()
            ->filter(fn ($r) => $actor?->canAssignRole($r->slug))
            ->sortBy(fn ($r) => ($tierOrder[$r->tier] ?? 99) . '|' . $r->name)
            ->values();

        return [
            'companies'      => $query->paginate(20),
            'canManage'      => Gate::allows('create', Company::class),
            'canCreateUsers' => (bool) $actor?->canManageInternalUsers(),
            'typeLabels'     => $typeLabels,
            'firstUserRoles' => $firstUserRoles,
        ];
    }
};

?>

<div>
    <x-slot:header>Companies</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, email, VAT or phone…"
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="typeFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Types</option>
            @foreach($typeLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @if($canManage)
            <button type="button" wire:click="openCreate" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Company
            </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Workflow</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Users</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($companies as $company)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium">
                        <a href="{{ route('admin.companies.show', $company) }}" class="text-gray-900 hover:text-blue-600">{{ $company->name }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-purple-100 text-purple-700'   => $company->type === \App\Models\Company::TYPE_OEM,
                            'bg-blue-100 text-blue-700'       => $company->type === \App\Models\Company::TYPE_DEALER,
                            'bg-amber-100 text-amber-800'     => $company->type === \App\Models\Company::TYPE_BODY_BUILDER,
                            'bg-slate-100 text-slate-700'     => $company->type === \App\Models\Company::TYPE_INTERNAL,
                            'bg-cyan-100 text-cyan-700'       => $company->type === \App\Models\Company::TYPE_TRANSPORTER,
                            'bg-orange-100 text-orange-700'   => $company->type === \App\Models\Company::TYPE_YARD,
                            'bg-green-100 text-green-700'     => $company->type === \App\Models\Company::TYPE_CUSTOMER,
                            'bg-gray-100 text-gray-600'       => ! in_array($company->type, \App\Models\Company::TYPES, true),
                        ])>{{ $typeLabels[$company->type] ?? ucfirst($company->type ?? 'unknown') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if($company->workflow_type === 'faw')
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">FAW</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Standard</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $company->phone ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($company->is_active)
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Yes</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">No</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $company->users_count }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.companies.show', $company) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">View / Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No companies found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $companies->links() }}
    </div>

    {{-- Create modal --}}
    @if($showCreate)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 px-4 py-8" wire:click.self="closeCreate">
            <form wire:submit.prevent="createCompany" class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Add Company</h3>
                    <button type="button" wire:click="closeCreate" class="text-slate-400 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-5 py-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Company name *</label>
                        <input wire:model="newName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" autofocus>
                        @error('newName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                        <select wire:model.live="newType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach($typeLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('newType') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">
                            Picks the portal the company's users land on (dealer, OEM, body-builder, etc.) and what they can do.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Workflow *</label>
                        <select wire:model="newWorkflowType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="standard">Standard (auto-confirm)</option>
                            <option value="faw">FAW (requires customer confirmation)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input wire:model="newPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Billing email</label>
                        <input wire:model="newBillingEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('newBillingEmail') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">VAT number</label>
                        <input wire:model="newVatNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    {{-- Address with Google Places autocomplete.  Selecting
                         a suggestion populates city / province / lat / lng
                         via the placesAutocomplete Alpine helper defined
                         in components/layouts/app.blade.php.  These hidden
                         coords let the controller seed a first Location
                         row for the company so the address appears on
                         /customer/orders/create immediately. --}}
                    <div class="sm:col-span-2" x-data="placesAutocomplete({ addressModel: 'newAddress', cityModel: 'newCity', provinceModel: 'newProvince', latModel: 'newLatitude', lngModel: 'newLongitude' })">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Primary address</label>
                        <input x-ref="addressInput" wire:model="newAddress" type="text" autocomplete="off" placeholder="Start typing to search Google Maps…" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @if($newCity || $newProvince)
                            <p class="mt-1 text-xs text-gray-500">{{ trim($newCity . ', ' . $newProvince, ', ') }}</p>
                        @endif
                        @if($newType === \App\Models\Company::TYPE_BODY_BUILDER)
                            <p class="mt-1 text-xs text-amber-700">
                                This becomes the body builder's first workshop. You can add more plants and satellite repair stations from the company page after saving.
                            </p>
                        @endif
                        @error('newAddress') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input wire:model="newIsActive" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">Active</span>
                        </label>
                    </div>
                </div>

                {{-- First-user section ----------------------------------- --}}
                @if($canCreateUsers && $firstUserRoles->isNotEmpty())
                    <div class="border-t border-slate-200 px-5 py-4">
                        <label class="flex items-center gap-2 mb-3">
                            <input wire:model.live="createFirstUser" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-semibold text-gray-900">Invite the first user now</span>
                        </label>

                        @if($createFirstUser)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Full name *</label>
                                    <input wire:model="firstUserName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    @error('firstUserName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                                    <input wire:model="firstUserEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    @error('firstUserEmail') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                    <input wire:model="firstUserPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                                    <select wire:model="firstUserRoleSlug" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">— select —</option>
                                        @foreach($firstUserRoles as $role)
                                            <option value="{{ $role->slug }}">{{ $role->name }} ({{ $role->tier }})</option>
                                        @endforeach
                                    </select>
                                    @error('firstUserRoleSlug') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Initial password *</label>
                                    <input wire:model="firstUserPassword" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" {{ $generateFirstUserPassword ? 'readonly' : '' }}>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                                        <input wire:model.live="generateFirstUserPassword" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                                        Auto-generate password
                                    </label>
                                    <p class="mt-1 text-xs text-gray-500">
                                        The user will be forced to change this password on first sign-in. Share it over a secure channel.
                                    </p>
                                    @error('firstUserPassword') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeCreate" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                        {{ $createFirstUser ? 'Create company & invite user' : 'Create company' }}
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
