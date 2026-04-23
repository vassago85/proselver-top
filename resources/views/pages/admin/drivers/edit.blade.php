<?php
use App\Models\User;
use App\Models\DriverProfile;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public User $user;

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
            $this->existingLicenseFilename = $profile->license_document_filename;
            $this->existingPdpFilename = $profile->pdp_document_filename;
        }
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
            'baseLocation' => 'nullable|string|max:255',
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
                    <input wire:model="baseLocation" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Johannesburg">
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
</div>
