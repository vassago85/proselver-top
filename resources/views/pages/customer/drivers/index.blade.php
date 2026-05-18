<?php

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Dealer-side driver pool. Each row is a User with the 'driver' role
 * attached to the dealer's company via company_users. DriverProfile
 * carries the licence + ID metadata used downstream by the collection
 * note. This page is independent from /customer/team because the
 * member-management surface only deals with the customer_* role family
 * — drivers are a separate (and operationally distinct) population.
 *
 * Permission: customer_owner + customer_admin only. Ops can perform
 * the same attach via admin/users on the dealer's behalf (the "both"
 * answer from the planning conversation).
 */
new #[Layout('components.layouts.app')]
class extends Component
{
    public ?Company $company = null;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $driverName = '';
    public string $driverEmail = '';
    public string $driverPhone = '';
    public string $driverPassword = '';
    public ?int $driverLocationId = null;

    public string $licenseCode = '';
    public string $licenseNumber = '';
    public string $licenseExpiry = '';
    public string $prdpExpiry = '';
    public string $idNumber = '';
    public string $idType = DriverProfile::ID_TYPE_SA_ID;
    public string $cellphone = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->company = auth()->user()->companies()->first();
        abort_unless($this->company, 403);
        abort_unless(
            auth()->user()->canManageCompanyData(),
            403,
            'Only the account owner or admin can manage drivers.'
        );
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()
            ->driversForCompany($this->company->id)
            ->with('driverProfile')
            ->whereKey($id)
            ->firstOrFail();

        $this->editingId = $user->id;
        $this->driverName = $user->name;
        $this->driverEmail = (string) ($user->email ?? '');
        $this->driverPhone = (string) ($user->phone ?? '');
        $this->driverPassword = '';

        $pivot = $user->companies()->where('companies.id', $this->company->id)->first();
        $this->driverLocationId = $pivot?->pivot?->location_id;

        $p = $user->driverProfile;
        $this->licenseCode    = (string) ($p->license_code ?? '');
        $this->licenseNumber  = (string) ($p->license_number ?? '');
        $this->licenseExpiry  = $p?->license_expiry?->toDateString() ?? '';
        $this->prdpExpiry     = $p?->prdp_expiry?->toDateString() ?? '';
        $this->idNumber       = (string) ($p->id_number ?? '');
        $this->idType         = (string) ($p->id_type ?? DriverProfile::ID_TYPE_SA_ID);
        $this->cellphone      = (string) ($p->cellphone ?? '');
        $this->notes          = (string) ($p->notes ?? '');

        $this->showForm = true;
    }

    public function save(): void
    {
        $rules = [
            'driverName'   => 'required|string|max:255',
            'driverEmail'  => 'nullable|email|max:255',
            'driverPhone'  => 'nullable|string|max:50',
            'driverLocationId' => 'nullable|exists:locations,id',
            'licenseCode'   => 'nullable|string|max:20',
            'licenseNumber' => 'nullable|string|max:50',
            'licenseExpiry' => 'nullable|date',
            'prdpExpiry'    => 'nullable|date',
            'idNumber'      => 'nullable|string|max:50',
            'idType'        => 'required|in:' . implode(',', [DriverProfile::ID_TYPE_SA_ID, DriverProfile::ID_TYPE_PASSPORT, DriverProfile::ID_TYPE_OTHER]),
            'cellphone'     => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:2000',
        ];

        if (!$this->editingId) {
            $rules['driverPassword'] = 'required|string|min:6';
            if ($this->driverEmail !== '') {
                $rules['driverEmail'] .= '|unique:users,email';
            }
        } else {
            $rules['driverPassword'] = 'nullable|string|min:6';
            if ($this->driverEmail !== '') {
                $rules['driverEmail'] .= '|unique:users,email,' . $this->editingId;
            }
        }

        $this->validate($rules);

        if ($this->editingId) {
            $user = User::query()
                ->driversForCompany($this->company->id)
                ->whereKey($this->editingId)
                ->firstOrFail();

            $user->update([
                'name'  => $this->driverName,
                'email' => $this->driverEmail ?: null,
                'phone' => $this->driverPhone ?: null,
            ]);

            if ($this->driverPassword) {
                $user->update(['password' => Hash::make($this->driverPassword)]);
            }

            $this->company->users()->updateExistingPivot($user->id, [
                'location_id' => $this->driverLocationId,
            ]);

            $this->upsertProfile($user);

            session()->flash('success', 'Driver updated.');
        } else {
            // Generate a username from the name + a random suffix —
            // mirrors the team page's heuristic so the dealer doesn't
            // have to think about a separate field.
            $username = strtolower(str_replace(' ', '', $this->driverName)) . rand(10, 99);

            $user = User::create([
                'name'      => $this->driverName,
                'email'     => $this->driverEmail ?: null,
                'phone'     => $this->driverPhone ?: null,
                'username'  => $username,
                'password'  => Hash::make($this->driverPassword),
                'is_active' => true,
            ]);

            $user->assignRole('driver');
            $this->company->users()->attach($user->id, [
                'location_id' => $this->driverLocationId,
            ]);

            $this->upsertProfile($user);

            session()->flash('success', 'Driver added to your pool.');
        }

        $this->resetForm();
    }

    protected function upsertProfile(User $user): void
    {
        $payload = [
            'id_number'      => $this->idNumber ?: null,
            'id_type'        => $this->idType,
            'cellphone'      => $this->cellphone ?: ($this->driverPhone ?: null),
            'license_code'   => $this->licenseCode ?: null,
            'license_number' => $this->licenseNumber ?: null,
            'license_expiry' => $this->licenseExpiry ?: null,
            'prdp_expiry'    => $this->prdpExpiry ?: null,
            'notes'          => $this->notes ?: null,
        ];

        if ($user->driverProfile) {
            $user->driverProfile->update($payload);
        } else {
            $payload['user_id'] = $user->id;
            DriverProfile::create($payload);
        }
    }

    public function toggleActive(int $id): void
    {
        $user = User::query()
            ->driversForCompany($this->company->id)
            ->whereKey($id)
            ->firstOrFail();

        $user->update(['is_active' => !$user->is_active]);
        session()->flash('success', $user->is_active ? 'Driver activated.' : 'Driver deactivated.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->showForm = false;
        $this->reset([
            'driverName', 'driverEmail', 'driverPhone', 'driverPassword',
            'driverLocationId',
            'licenseCode', 'licenseNumber', 'licenseExpiry', 'prdpExpiry',
            'idNumber', 'cellphone', 'notes',
        ]);
        $this->idType = DriverProfile::ID_TYPE_SA_ID;
    }

    public function with(): array
    {
        $drivers = User::query()
            ->where(function ($q) {
                // Show active and inactive — deactivated drivers are
                // still in the pool, just greyed out. The driversForCompany
                // scope filters by active, so we re-build without it.
                $q->whereHas('roles', fn ($r) => $r->where('slug', 'driver'))
                  ->whereHas('companies', fn ($c) => $c->where('companies.id', $this->company->id));
            })
            ->with(['driverProfile', 'companies' => fn ($q) => $q->where('companies.id', $this->company->id)])
            ->withCount(['assignedJobs as active_assignments_count' => function ($q) {
                $q->whereIn('status', [
                    \App\Models\Job::STATUS_DRIVER_ASSIGNED,
                    \App\Models\Job::STATUS_READY_FOR_COLLECTION,
                    \App\Models\Job::STATUS_COLLECTED,
                    \App\Models\Job::STATUS_IN_TRANSIT,
                ]);
            }])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $locations = Location::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'city']);

        $locationOptions = $locations->map(fn ($loc) => [
            'value' => (string) $loc->id,
            'label' => $loc->company_name . ($loc->city ? " — {$loc->city}" : ''),
        ])->values()->all();

        $idTypeOptions = [
            ['value' => DriverProfile::ID_TYPE_SA_ID,    'label' => 'SA ID'],
            ['value' => DriverProfile::ID_TYPE_PASSPORT, 'label' => 'Passport'],
            ['value' => DriverProfile::ID_TYPE_OTHER,    'label' => 'Other'],
        ];

        return compact('drivers', 'locations', 'locationOptions', 'idTypeOptions');
    }
};

?>

<div>
    <x-slot:header>Drivers</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-xl bg-white shadow-sm border border-gray-200">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">{{ $company->name }} - Internal Drivers</h3>
                <p class="text-sm text-gray-500 mt-0.5">Drivers in your pool can be assigned to "Internal" movements you book.</p>
            </div>
            <button wire:click="create" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Driver
            </button>
        </div>

        @if($showForm)
        <div class="border-b border-gray-200 bg-gray-50 p-6">
            <h4 class="text-sm font-semibold text-gray-800 mb-4">{{ $editingId ? 'Edit Driver' : 'Add New Driver' }}</h4>
            <form wire:submit="save" class="space-y-4">
                <div>
                    <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Account</h5>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                            <input wire:model="driverName" type="text" required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('driverName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input wire:model="driverEmail" type="email"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Optional — for login">
                            @error('driverEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input wire:model="driverPhone" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('driverPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Password {{ $editingId ? '(leave blank to keep)' : '' }} <span class="{{ $editingId ? '' : 'text-red-500' }}">{{ $editingId ? '' : '*' }}</span></label>
                            <input wire:model="driverPassword" type="password" {{ $editingId ? '' : 'required' }}
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('driverPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Home depot (optional)</label>
                            <x-searchable-select
                                wire:model="driverLocationId"
                                :options="$locationOptions"
                                placeholder="No restriction"
                                search-placeholder="Search locations…"
                            />
                            @error('driverLocationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div>
                    <h5 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Driver Documents</h5>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID Type</label>
                            <x-searchable-select
                                wire:model="idType"
                                :options="$idTypeOptions"
                                placeholder="Select"
                                :allow-clear="false"
                            />
                            @error('idType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID / Passport Number</label>
                            <input wire:model="idNumber" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('idNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cellphone</label>
                            <input wire:model="cellphone" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Defaults to phone above">
                            @error('cellphone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Licence Code</label>
                            <input wire:model="licenseCode" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                placeholder="e.g. EC1">
                            @error('licenseCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Licence Number</label>
                            <input wire:model="licenseNumber" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('licenseNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Licence Expiry</label>
                            <input wire:model="licenseExpiry" type="date"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('licenseExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">PrDP Expiry</label>
                            <input wire:model="prdpExpiry" type="date"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('prdpExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea wire:model="notes" rows="2"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                        {{ $editingId ? 'Update Driver' : 'Add Driver' }}
                    </button>
                    <button type="button" wire:click="resetForm" class="rounded-lg bg-white px-5 py-2 text-sm font-semibold text-gray-700 border border-gray-300 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Licence</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Depot</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Active Jobs</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($drivers as $driver)
                        @php
                            $loc = $driver->companies->first()?->pivot?->location_id;
                            $locationRow = $loc ? $locations->firstWhere('id', $loc) : null;
                            $profile = $driver->driverProfile;
                        @endphp
                        <tr class="{{ !$driver->is_active ? 'opacity-50' : '' }}">
                            <td class="whitespace-nowrap px-6 py-3 text-sm font-medium text-gray-900">{{ $driver->name }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                                <div>{{ $driver->phone ?: ($profile?->cellphone ?: '—') }}</div>
                                @if($driver->email)
                                    <div class="text-xs text-gray-400">{{ $driver->email }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                                @if($profile?->license_code || $profile?->license_number)
                                    <div>{{ $profile->license_code }} · {{ $profile->license_number ?: '—' }}</div>
                                    @if($profile?->license_expiry)
                                        <div class="text-xs text-gray-400">Exp {{ $profile->license_expiry->format('M Y') }}</div>
                                    @endif
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-500">
                                {{ $locationRow?->company_name ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-sm text-gray-700">
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">
                                    {{ $driver->active_assignments_count }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $driver->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $driver->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-sm">
                                <div class="flex items-center gap-2">
                                    <button wire:click="edit({{ $driver->id }})" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                    <button wire:click="toggleActive({{ $driver->id }})"
                                        wire:confirm="{{ $driver->is_active ? 'Deactivate' : 'Activate' }} this driver?"
                                        class="font-medium {{ $driver->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }}">
                                        {{ $driver->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No drivers yet — add your first one above to start booking internal movements.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
