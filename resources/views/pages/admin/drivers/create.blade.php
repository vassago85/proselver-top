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

    // User fields
    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public bool $generatePassword = true;

    // Driver profile fields
    public string $idNumber = '';
    public string $cellphone = '';
    public string $baseLocation = '';
    public string $licenseCode = '';
    public string $licenseNumber = '';
    public string $licenseExpiry = '';
    public string $prdpExpiry = '';
    public string $notes = '';

    // Document uploads
    public $licenseDocument = null;
    public $pdpDocument = null;

    public function mount(): void
    {
        $this->password = Str::random(12);
    }

    public function updatedGeneratePassword(): void
    {
        $this->password = $this->generatePassword ? Str::random(12) : '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'idNumber' => 'nullable|string|max:20',
            'cellphone' => 'nullable|string|max:20',
            'baseLocation' => 'nullable|string|max:255',
            'licenseCode' => 'nullable|string|max:20',
            'licenseNumber' => 'nullable|string|max:50',
            'licenseExpiry' => 'nullable|date',
            'prdpExpiry' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'licenseDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'pdpDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $username = Str::lower($this->username);
        $base = $username;
        $suffix = 0;
        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        $user = User::create([
            'name' => $this->name,
            'username' => $username,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'password' => $this->password,
        ]);

        $user->assignRole('driver');

        $profileData = [
            'user_id' => $user->id,
            'id_number' => $this->idNumber ?: null,
            'cellphone' => $this->cellphone ?: null,
            'base_location' => $this->baseLocation ?: null,
            'license_code' => $this->licenseCode ?: null,
            'license_number' => $this->licenseNumber ?: null,
            'license_expiry' => $this->licenseExpiry ?: null,
            'prdp_expiry' => $this->prdpExpiry ?: null,
            'notes' => $this->notes ?: null,
        ];

        $disk = config('filesystems.default', 'local');

        if ($this->licenseDocument) {
            $path = $this->licenseDocument->store("drivers/{$user->id}/license", $disk);
            $profileData['license_document_disk'] = $disk;
            $profileData['license_document_path'] = $path;
            $profileData['license_document_filename'] = $this->licenseDocument->getClientOriginalName();
        }

        if ($this->pdpDocument) {
            $path = $this->pdpDocument->store("drivers/{$user->id}/pdp", $disk);
            $profileData['pdp_document_disk'] = $disk;
            $profileData['pdp_document_path'] = $path;
            $profileData['pdp_document_filename'] = $this->pdpDocument->getClientOriginalName();
        }

        DriverProfile::create($profileData);

        session()->flash('success', "Driver {$user->name} created successfully.");
        $this->redirect(route('admin.drivers.index'));
    }
};
?>
<div>
    <x-slot:header>Add Driver</x-slot:header>

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
                    <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. 0821234567">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea wire:model="notes" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Any additional notes about this driver..."></textarea>
                    @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Document Uploads --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Document Uploads</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Document</label>
                    <input wire:model="licenseDocument" type="file" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-xs text-gray-400">PDF, JPG or PNG — max 5 MB</p>
                    @error('licenseDocument')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <div wire:loading wire:target="licenseDocument" class="mt-1 text-xs text-blue-600">Uploading...</div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PDP Document</label>
                    <input wire:model="pdpDocument" type="file" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-xs text-gray-400">PDF, JPG or PNG — max 5 MB</p>
                    @error('pdpDocument')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <div wire:loading wire:target="pdpDocument" class="mt-1 text-xs text-blue-600">Uploading...</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.drivers.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Create Driver</span>
                <span wire:loading wire:target="save">Creating...</span>
            </button>
        </div>
    </form>
</div>
