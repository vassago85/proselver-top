<?php
use App\Models\User;
use App\Models\DriverProfile;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $username = '';
    public string $password = '';
    public bool $generatePassword = true;
    public string $licenseCode = '';
    public string $licenseNumber = '';
    public string $licenseExpiry = '';
    public string $prdpExpiry = '';
    public string $notes = '';

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
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'required|string|max:20',
            'username' => 'nullable|string|max:50|unique:users,username',
            'password' => 'required|string|min:8',
            'licenseCode' => 'required|string|max:20',
            'licenseNumber' => 'nullable|string|max:50',
            'licenseExpiry' => 'nullable|date',
            'prdpExpiry' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $username = $this->username ?: Str::lower(Str::slug($this->name, '.'));
        $base = $username;
        $suffix = 0;
        while (User::where('username', $username)->exists()) {
            $suffix++;
            $username = $base . $suffix;
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone,
            'username' => $username,
            'password' => $this->password,
        ]);

        $user->assignRole('driver');

        DriverProfile::create([
            'user_id' => $user->id,
            'license_code' => $this->licenseCode,
            'license_number' => $this->licenseNumber ?: null,
            'license_expiry' => $this->licenseExpiry ?: null,
            'prdp_expiry' => $this->prdpExpiry ?: null,
            'notes' => $this->notes ?: null,
        ]);

        session()->flash('success', "Driver {$user->name} created successfully.");
        $this->redirect(route('admin.drivers.index'));
    }
};
?>
<div>
    <x-slot:header>Add Driver</x-slot:header>

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Personal Details</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input wire:model="name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
                    <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. 0821234567">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Optional">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input wire:model="username" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Auto-generated from name if blank">
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
            <h3 class="text-lg font-semibold text-gray-900 mb-4">License Details</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">License Code *</label>
                    <select wire:model="licenseCode" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="">Select license code...</option>
                        <option value="B/Code 8">B / Code 8 (Light motor vehicle)</option>
                        <option value="C1/Code 10">C1 / Code 10 (Heavy motor vehicle)</option>
                        <option value="C/Code 14">C / Code 14 (Extra heavy motor vehicle)</option>
                        <option value="EC/Code 14">EC / Code 14 (Articulated heavy vehicle)</option>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">PrDP Expiry</label>
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

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.drivers.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Create Driver</span>
                <span wire:loading>Creating...</span>
            </button>
        </div>
    </form>
</div>
