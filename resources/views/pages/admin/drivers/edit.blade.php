<?php
use App\Models\User;
use App\Models\DriverBaseLocation;
use App\Models\DriverProfile;
use App\Services\DriverOffboardingService;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public User $user;

    // --- Offboarding / reinstate state ---
    public bool $showOffboardModal = false;
    public string $offReason = 'retired';
    public string $offNotes = '';
    public string $plateDisposition = 'release'; // release | transfer
    public ?int $plateTransferTo = null;

    // User fields
    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $phone = '';
    public string $newPassword = '';
    public bool $resetPassword = false;

    // Driver profile fields
    public string $idNumber = '';
    public string $cellphone = '';
    public string $baseLocation = '';
    public string $tradePlate = '';
    public string $tradePlateExpiry = '';
    public string $licenseCode = '';
    public string $licenseNumber = '';
    public string $licenseExpiry = '';
    public string $prdpExpiry = '';
    public string $trackerId = '';
    public string $cameraId = '';
    public string $tollCardNumber = '';
    public string $notes = '';

    /**
     * Rand-per-completed-movement pay rate.  Visible only to
     * accounts / owner / developer — ops shouldn't see or edit
     * driver salary data on the driver form.
     */
    public ?string $ratePerMovement = null;

    // Existing document info
    public ?string $existingLicenseFilename = null;
    public ?string $existingPdpFilename = null;

    // New document uploads
    public $licenseDocument = null;
    public $pdpDocument = null;

    public function mount(User $user): void
    {
        $this->user = $user->load('driverProfile');
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = $user->email ?? '';
        $this->phone = $user->phone ?? '';

        if ($profile = $user->driverProfile) {
            $this->idNumber = $profile->id_number ?? '';
            $this->cellphone = $profile->cellphone ?? '';
            $this->baseLocation = $profile->base_location ?? '';
            $this->tradePlate = $profile->trade_plate ?? '';
            $this->tradePlateExpiry = $profile->trade_plate_expiry?->format('Y-m-d') ?? '';
            $this->licenseCode = $profile->license_code ?? '';
            $this->licenseNumber = $profile->license_number ?? '';
            $this->licenseExpiry = $profile->license_expiry?->format('Y-m-d') ?? '';
            $this->prdpExpiry = $profile->prdp_expiry?->format('Y-m-d') ?? '';
            $this->trackerId = $profile->tracker_id ?? '';
            $this->cameraId = $profile->camera_id ?? '';
            $this->tollCardNumber = $profile->toll_card_number ?? '';
            $this->notes = $profile->notes ?? '';
            $this->ratePerMovement = $profile->rate_per_movement_cents !== null
                ? number_format($profile->rate_per_movement_cents / 100, 2, '.', '')
                : null;
            $this->existingLicenseFilename = $profile->license_document_filename;
            $this->existingPdpFilename = $profile->pdp_document_filename;
        }
    }

    /**
     * Is the current viewer allowed to see and edit the pay rate?
     */
    public function canEditPay(): bool
    {
        $u = auth()->user();
        return $u && ($u->isAccounts() || $u->isOwner() || $u->isDeveloper());
    }

    public function updatedResetPassword(): void
    {
        $this->newPassword = $this->resetPassword ? Str::random(12) : '';
    }

    public function downloadLicenseDocument()
    {
        $profile = $this->user->driverProfile;
        if (!$profile?->license_document_path) return;

        return Storage::disk($profile->license_document_disk)
            ->download($profile->license_document_path, $profile->license_document_filename);
    }

    public function downloadPdpDocument()
    {
        $profile = $this->user->driverProfile;
        if (!$profile?->pdp_document_path) return;

        return Storage::disk($profile->pdp_document_disk)
            ->download($profile->pdp_document_path, $profile->pdp_document_filename);
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'username' => "required|string|max:50|unique:users,username,{$this->user->id}",
            'email' => "nullable|email|unique:users,email,{$this->user->id}",
            'phone' => 'nullable|string|max:20',
            'idNumber' => 'nullable|string|max:20',
            'cellphone' => 'nullable|string|max:20',
            // Base Location comes from the controlled list; the existing
            // value is grandfathered in via `in` because a driver whose
            // depot has since been retired must still save without a
            // forced re-pick (they'll be prompted separately).
            'baseLocation' => [
                'nullable', 'string', 'max:255',
                Rule::in(array_values(array_unique(array_filter(array_merge(
                    DriverBaseLocation::pickerOptions()->all(),
                    [$this->baseLocation],
                ))))),
            ],
            'tradePlate' => 'nullable|string|max:20',
            'tradePlateExpiry' => 'nullable|date',
            'licenseCode' => 'nullable|string|max:20',
            'licenseNumber' => 'nullable|string|max:50',
            'licenseExpiry' => 'nullable|date',
            'prdpExpiry' => 'nullable|date',
            'trackerId' => 'nullable|string|max:100',
            'cameraId' => 'nullable|string|max:100',
            'tollCardNumber' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'ratePerMovement' => 'nullable|numeric|min:0|max:100000',
            'licenseDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'pdpDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $userData = [
            'name' => $this->name,
            'username' => Str::lower($this->username),
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
        ];

        if ($this->resetPassword && $this->newPassword) {
            $userData['password'] = $this->newPassword;
        }

        $this->user->update($userData);

        $profileData = [
            'id_number' => $this->idNumber ?: null,
            'cellphone' => $this->cellphone ?: null,
            'base_location' => $this->baseLocation ?: null,
            'trade_plate' => $this->tradePlate ?: null,
            'trade_plate_expiry' => $this->tradePlateExpiry ?: null,
            'license_code' => $this->licenseCode ?: null,
            'license_number' => $this->licenseNumber ?: null,
            'license_expiry' => $this->licenseExpiry ?: null,
            'prdp_expiry' => $this->prdpExpiry ?: null,
            'tracker_id' => trim($this->trackerId) ?: null,
            'camera_id' => trim($this->cameraId) ?: null,
            'toll_card_number' => trim($this->tollCardNumber) ?: null,
            'notes' => $this->notes ?: null,
        ];

        // Only accounts/owner/developer may set salary data.  Server-side
        // guard: a crafted Livewire payload from ops would otherwise
        // write to a field the UI never showed them.  Blank / null
        // input from an allowed role clears the rate.
        if ($this->canEditPay()) {
            $profileData['rate_per_movement_cents'] = ($this->ratePerMovement === null || $this->ratePerMovement === '')
                ? null
                : (int) round(((float) $this->ratePerMovement) * 100);
        }

        $disk = \App\Support\StorageDisk::forUploads();
        $profile = $this->user->driverProfile;

        if ($this->licenseDocument) {
            if ($profile?->license_document_path) {
                Storage::disk($profile->license_document_disk)->delete($profile->license_document_path);
            }
            $path = $this->licenseDocument->store("drivers/{$this->user->id}/license", $disk);
            $profileData['license_document_disk'] = $disk;
            $profileData['license_document_path'] = $path;
            $profileData['license_document_filename'] = $this->licenseDocument->getClientOriginalName();
        }

        if ($this->pdpDocument) {
            if ($profile?->pdp_document_path) {
                Storage::disk($profile->pdp_document_disk)->delete($profile->pdp_document_path);
            }
            $path = $this->pdpDocument->store("drivers/{$this->user->id}/pdp", $disk);
            $profileData['pdp_document_disk'] = $disk;
            $profileData['pdp_document_path'] = $path;
            $profileData['pdp_document_filename'] = $this->pdpDocument->getClientOriginalName();
        }

        DriverProfile::updateOrCreate(
            ['user_id' => $this->user->id],
            $profileData
        );

        session()->flash('success', "Driver {$this->user->name} updated successfully.");
        $this->redirect(route('admin.drivers.index'));
    }

    // ---------------------------------------------------------------
    // Offboarding: retire / resign / dismiss / deceased / other.
    // Trade plates belong to the business, so we either release them
    // back to the pool or transfer them to another active driver —
    // never let a plate get lost with a departing driver.
    // ---------------------------------------------------------------

    public function openOffboardModal(): void
    {
        abort_unless(Auth::user()?->canManageUsers(), 403);
        $this->resetErrorBag();
        $this->offReason = 'retired';
        $this->offNotes = '';
        $this->plateDisposition = $this->user->driverProfile?->trade_plate ? 'release' : 'release';
        $this->plateTransferTo = null;
        $this->showOffboardModal = true;
    }

    public function closeOffboardModal(): void
    {
        $this->showOffboardModal = false;
    }

    public function offboardDriver(DriverOffboardingService $service): void
    {
        abort_unless(Auth::user()?->canManageUsers(), 403);

        $rules = [
            'offReason' => 'required|string|in:' . implode(',', array_keys(DriverProfile::REASON_LABELS)),
            'offNotes'  => 'nullable|string|max:2000',
            'plateDisposition' => 'required|in:release,transfer',
        ];
        if ($this->plateDisposition === 'transfer') {
            $rules['plateTransferTo'] = 'required|integer|different:user.id';
        }
        $this->validate($rules);

        try {
            $service->offboard(
                $this->user->fresh(),
                $this->offReason,
                $this->offNotes ?: null,
                $this->plateDisposition,
                $this->plateDisposition === 'transfer' ? $this->plateTransferTo : null,
            );
        } catch (\Throwable $e) {
            $this->addError('offboard', $e->getMessage());
            return;
        }

        session()->flash('success', "{$this->user->name} has been taken off the roster.");
        $this->redirect(route('admin.drivers.index'));
    }

    public function reinstateDriver(DriverOffboardingService $service): void
    {
        abort_unless(Auth::user()?->canManageUsers(), 403);

        try {
            $service->reinstate($this->user->fresh());
        } catch (\Throwable $e) {
            $this->addError('offboard', $e->getMessage());
            return;
        }

        session()->flash('success', "{$this->user->name} is back on the active roster.");
        $this->redirect(route('admin.drivers.edit', $this->user));
    }

    public function with(): array
    {
        $service = app(DriverOffboardingService::class);
        $profile = $this->user->driverProfile;

        $transferCandidates = collect();
        if ($profile?->trade_plate) {
            $transferCandidates = User::query()
                ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
                ->where('is_active', true)
                ->where('id', '!=', $this->user->id)
                ->with('driverProfile:user_id,trade_plate')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        // Drivers who already hold a plate were previously rendered as
        // disabled <option>s. The searchable dropdown drops them entirely
        // — there is never a reason to surface a non-selectable row.
        $transferCandidateOptions = $transferCandidates
            ->filter(fn ($u) => empty($u->driverProfile?->trade_plate))
            ->map(fn ($u) => [
                'value' => (string) $u->id,
                'label' => $u->name,
            ])
            ->values()
            ->all();

        // Base Location options come from the controlled reference
        // list. If the driver's stored value has since been removed
        // from the picker (unlikely but possible if an admin deactivates
        // a depot), we prepend it so the select still shows the current
        // value rather than silently dropping to blank.
        $baseLocationOptions = DriverBaseLocation::pickerOptions();
        if ($this->baseLocation !== '' && !$baseLocationOptions->contains($this->baseLocation)) {
            $baseLocationOptions = collect([$this->baseLocation])->concat($baseLocationOptions);
        }

        return [
            'canManage' => (bool) Auth::user()?->canManageUsers(),
            'activeJobCount' => $service->activeJobCount($this->user),
            'reasonLabels' => DriverProfile::REASON_LABELS,
            'transferCandidates' => $transferCandidates,
            'transferCandidateOptions' => $transferCandidateOptions,
            'isOffRoster' => (bool) $profile?->isOffRoster(),
            'offRosterActor' => $profile?->off_roster_by_user_id
                ? User::find($profile->off_roster_by_user_id)?->name
                : null,
            'baseLocationOptions' => $baseLocationOptions,
        ];
    }
};
?>
<div>
    <x-slot:header>Edit Driver: {{ $user->name }}</x-slot:header>

    @if(session('success'))
        <div class="mb-4 max-w-2xl rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <form wire:submit="save" class="max-w-2xl">
        {{-- User Info --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">User Info</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                    <input wire:model="username" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('username')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Optional">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input wire:model.live="resetPassword" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600">
                        <span class="text-sm font-medium text-gray-700">Reset password</span>
                    </label>
                    @if($resetPassword)
                    <div class="mt-2">
                        <input wire:model="newPassword" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-blue-500 focus:ring-blue-500" placeholder="New password">
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Driver Profile --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Driver Profile</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Number</label>
                    <input wire:model="idNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="SA ID number">
                    @error('idNumber')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cellphone (operational)</label>
                    <input wire:model="cellphone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. 0821234567">
                    @error('cellphone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Base Location</label>
                    <select wire:model="baseLocation" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">— Select a base location —</option>
                        @foreach($baseLocationOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Controlled list. Ask an admin to add a new depot if it isn't here.</p>
                    @error('baseLocation')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Trade Plate</label>
                    <input wire:model="tradePlate" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. AGC 166 GP">
                    @error('tradePlate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Trade Plate Expiry</label>
                    <input wire:model="tradePlateExpiry" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('tradePlateExpiry')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Code</label>
                    <select wire:model="licenseCode" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Select license code...</option>
                        <option value="A">A (Motorcycle)</option>
                        <option value="B">B / Code 8 (Light motor vehicle)</option>
                        <option value="C">C / Code 14 (Extra heavy motor vehicle)</option>
                        <option value="C1">C1 / Code 10 (Heavy motor vehicle)</option>
                        <option value="EC">EC / Code 14 (Articulated heavy vehicle)</option>
                        <option value="EC1">EC1 (Combination vehicles)</option>
                    </select>
                    @error('licenseCode')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Number</label>
                    <input wire:model="licenseNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('licenseNumber')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Expiry</label>
                    <input wire:model="licenseExpiry" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('licenseExpiry')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PDP Expiry</label>
                    <input wire:model="prdpExpiry" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('prdpExpiry')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                {{-- Fleet equipment: tracker / dashcam / toll card. Populating --}}
                {{-- any of these turns the matching T / C / $ dot green on the --}}
                {{-- drivers list and makes the tracker number appear next to --}}
                {{-- the driver on tracking/order screens when a vehicle is in --}}
                {{-- transit. All three are free-text; devices have no fixed --}}
                {{-- format across the fleet. --}}
                <div class="sm:col-span-2 mt-2 pt-4 border-t border-gray-100">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">Fleet Equipment</h4>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tracker ID</label>
                    <input wire:model="trackerId" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Cartrack serial">
                    @error('trackerId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Camera / Dashcam ID</label>
                    <input wire:model="cameraId" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Device serial / fleet asset #">
                    @error('cameraId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Toll Card Number</label>
                    <input wire:model="tollCardNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e-tag / fleet card number">
                    @error('tollCardNumber')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                @if($this->canEditPay())
                    <div class="sm:col-span-2 mt-2 pt-4 border-t border-gray-100">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">
                            Pay
                            <span class="ml-1 rounded-full bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-amber-800">Accounts</span>
                        </h4>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate per completed movement (R)</label>
                        <input wire:model="ratePerMovement" type="number" step="0.01" min="0"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. 350.00">
                        <p class="mt-1 text-xs text-gray-500">Used by the month-end driver pay report. Leave blank to clear.</p>
                        @error('ratePerMovement')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea wire:model="notes" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Any additional notes about this driver..."></textarea>
                    @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Documents --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                {{-- License Document --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">License Document</label>
                    @if($existingLicenseFilename)
                        <div class="mb-2 flex items-center gap-2 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">
                            <svg class="h-4 w-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                            <span class="text-sm text-gray-700 truncate flex-1">{{ $existingLicenseFilename }}</span>
                            <button type="button" wire:click="downloadLicenseDocument" class="text-xs font-medium text-blue-600 hover:text-blue-800 shrink-0">Download</button>
                        </div>
                    @endif
                    <input wire:model="licenseDocument" type="file" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-xs text-gray-400">{{ $existingLicenseFilename ? 'Upload to replace current file.' : 'PDF, JPG or PNG — max 5 MB' }}</p>
                    @error('licenseDocument')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <div wire:loading wire:target="licenseDocument" class="mt-1 text-xs text-blue-600">Uploading...</div>
                </div>

                {{-- PDP Document --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">PDP Document</label>
                    @if($existingPdpFilename)
                        <div class="mb-2 flex items-center gap-2 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">
                            <svg class="h-4 w-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                            <span class="text-sm text-gray-700 truncate flex-1">{{ $existingPdpFilename }}</span>
                            <button type="button" wire:click="downloadPdpDocument" class="text-xs font-medium text-blue-600 hover:text-blue-800 shrink-0">Download</button>
                        </div>
                    @endif
                    <input wire:model="pdpDocument" type="file" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-xs text-gray-400">{{ $existingPdpFilename ? 'Upload to replace current file.' : 'PDF, JPG or PNG — max 5 MB' }}</p>
                    @error('pdpDocument')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <div wire:loading wire:target="pdpDocument" class="mt-1 text-xs text-blue-600">Uploading...</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.drivers.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Save Changes</span>
                <span wire:loading wire:target="save">Saving...</span>
            </button>
        </div>
    </form>

    {{-- ---------------------------------------------------------------
         Roster status: retire / dismiss / remove a driver, with explicit
         trade-plate handover. Trade plates belong to the business, so a
         departing driver must either return their plate to the pool or
         hand it to another active driver before they leave the roster.
         --------------------------------------------------------------- --}}
    @if($canManage)
    <div class="max-w-2xl mt-8">
        @if($isOffRoster)
            {{-- Already off-roster: summary + reinstate. --}}
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-semibold text-amber-900">Driver is off the roster</h3>
                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm text-amber-900/90 sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="font-medium">Reason:</dt>
                                <dd>{{ $user->driverProfile->reasonLabel() ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="font-medium">When:</dt>
                                <dd>{{ optional($user->driverProfile->off_roster_at)->format('d M Y, H:i') ?? '—' }}</dd>
                            </div>
                            @if($offRosterActor)
                            <div class="flex gap-2 sm:col-span-2">
                                <dt class="font-medium">By:</dt>
                                <dd>{{ $offRosterActor }}</dd>
                            </div>
                            @endif
                            @if($user->driverProfile->off_roster_notes)
                            <div class="sm:col-span-2 mt-1">
                                <dt class="font-medium">Notes:</dt>
                                <dd class="mt-0.5 whitespace-pre-wrap">{{ $user->driverProfile->off_roster_notes }}</dd>
                            </div>
                            @endif
                        </dl>

                        @if($user->driverProfile->trade_plate_returned_at)
                            <p class="mt-3 text-xs text-amber-800">
                                Trade plate returned to business pool on
                                {{ $user->driverProfile->trade_plate_returned_at->format('d M Y, H:i') }}.
                            </p>
                        @endif

                        @error('offboard')
                            <p class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button"
                                wire:click="reinstateDriver"
                                wire:confirm="Reinstate {{ $user->name }} to the active roster?"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v5h5"/></svg>
                                Reinstate driver
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Active driver: offboarding panel. --}}
            <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5"/><path d="m22 8-5 5"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-semibold text-gray-900">Retire, dismiss or remove driver</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            Takes {{ $user->name }} off the active roster. Their history is preserved on past jobs and invoices, and their trade plate returns to the business.
                        </p>

                        @if($activeJobCount > 0)
                            <div class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                <p class="font-medium">Cannot remove right now.</p>
                                <p class="mt-0.5 text-xs">This driver still has <strong>{{ $activeJobCount }}</strong> active job{{ $activeJobCount === 1 ? '' : 's' }}. Reassign them on the <a href="{{ route('admin.dispatch') }}" class="underline">dispatch board</a> first.</p>
                            </div>
                        @endif

                        @if($user->driverProfile?->trade_plate)
                            <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                <div class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h10"/><path d="M7 14h6"/></svg>
                                    <span>Currently holds trade plate <strong class="font-mono">{{ $user->driverProfile->trade_plate }}</strong>@if($user->driverProfile->trade_plate_expiry) · expires {{ $user->driverProfile->trade_plate_expiry->format('d M Y') }}@endif</span>
                                </div>
                                <p class="mt-1 pl-6 text-[11px] text-slate-500">Trade plates belong to the business. You'll choose whether to release it to the pool or hand it to another driver.</p>
                            </div>
                        @endif

                        @error('offboard')
                            <p class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                        @enderror

                        <div class="mt-4">
                            <button type="button"
                                wire:click="openOffboardModal"
                                @disabled($activeJobCount > 0)
                                class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
                                Remove from roster…
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endif

    {{-- Offboarding confirmation modal --}}
    @if($canManage && $showOffboardModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
         role="dialog" aria-modal="true" aria-labelledby="offboard-title">
        <div class="absolute inset-0 bg-slate-900/50" wire:click="closeOffboardModal"></div>
        <div class="relative z-10 w-full max-w-lg rounded-xl bg-white shadow-xl">
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-4">
                <div>
                    <h3 id="offboard-title" class="text-base font-semibold text-slate-900">Remove {{ $user->name }} from roster</h3>
                    <p class="mt-0.5 text-xs text-slate-500">This action is auditable. It can be reversed from this page later.</p>
                </div>
                <button type="button" wire:click="closeOffboardModal" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <form wire:submit.prevent="offboardDriver" class="space-y-5 px-6 py-5">
                {{-- Reason --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700">Reason</label>
                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach($reasonLabels as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                                <input type="radio" wire:model="offReason" value="{{ $key }}" class="h-4 w-4 border-slate-300 text-red-600 focus:ring-red-500">
                                <span class="text-slate-800">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('offReason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                {{-- Trade plate handling (only if driver holds a plate) --}}
                @if($user->driverProfile?->trade_plate)
                    <div>
                        <label class="block text-sm font-medium text-slate-700">
                            Trade plate
                            <span class="ml-1 font-mono text-xs text-slate-500">{{ $user->driverProfile->trade_plate }}</span>
                        </label>
                        <div class="mt-2 space-y-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                <input type="radio" wire:model.live="plateDisposition" value="release" class="mt-0.5 h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>
                                    <span class="block font-medium text-slate-800">Release to business pool</span>
                                    <span class="block text-xs text-slate-500">The plate becomes available to assign later.</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                <input type="radio" wire:model.live="plateDisposition" value="transfer" class="mt-0.5 h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span class="flex-1">
                                    <span class="block font-medium text-slate-800">Transfer to another driver</span>
                                    <span class="block text-xs text-slate-500">Hand the plate directly to an active driver who doesn't already have one.</span>
                                </span>
                            </label>
                            @if($plateDisposition === 'transfer')
                                <div class="pl-7">
                                    <x-searchable-select
                                        wire:model="plateTransferTo"
                                        :options="$transferCandidateOptions"
                                        placeholder="Select an active driver…"
                                        search-placeholder="Search drivers…"
                                    />
                                    @if(count($transferCandidateOptions) === 0)
                                        <p class="mt-1 text-xs text-slate-500">No active drivers without a plate.</p>
                                    @endif
                                    @error('plateTransferTo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Notes --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400 font-normal">(optional)</span></label>
                    <textarea wire:model="offNotes" rows="3"
                              class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-slate-500"
                              placeholder="Context for the record — handover details, effective date, etc."></textarea>
                    @error('offNotes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                @error('offboard')
                    <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                @enderror

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="closeOffboardModal"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                            wire:loading.attr="disabled" wire:target="offboardDriver">
                        <span wire:loading.remove wire:target="offboardDriver">Confirm removal</span>
                        <span wire:loading wire:target="offboardDriver">Working…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
