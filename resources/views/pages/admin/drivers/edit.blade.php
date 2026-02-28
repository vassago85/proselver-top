<?php
use App\Models\User;
use App\Models\DriverProfile;
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
    public string $licenseCode = '';
    public string $licenseNumber = '';
    public string $licenseExpiry = '';
    public string $prdpExpiry = '';
    public string $notes = '';

    public function mount(User $user): void
    {
        $this->user = $user->load('driverProfile');
        $this->name = $user->name;
        $this->email = $user->email ?? '';
        $this->phone = $user->phone ?? '';
        $this->username = $user->username;

        if ($profile = $user->driverProfile) {
            $this->licenseCode = $profile->license_code ?? '';
            $this->licenseNumber = $profile->license_number ?? '';
            $this->licenseExpiry = $profile->license_expiry?->format('Y-m-d') ?? '';
            $this->prdpExpiry = $profile->prdp_expiry?->format('Y-m-d') ?? '';
            $this->notes = $profile->notes ?? '';
        }
    }

    public function updatedResetPassword(): void
    {
        $this->newPassword = $this->resetPassword ? Str::random(12) : '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => "nullable|email|unique:users,email,{$this->user->id}",
            'phone' => 'required|string|max:20',
            'username' => "required|string|max:50|unique:users,username,{$this->user->id}",
            'licenseCode' => 'required|string|max:20',
            'licenseNumber' => 'nullable|string|max:50',
            'licenseExpiry' => 'nullable|date',
            'prdpExpiry' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $data = [
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone,
            'username' => Str::lower($this->username),
        ];

        if ($this->resetPassword && $this->newPassword) {
            $data['password'] = $this->newPassword;
        }

        $this->user->update($data);

        DriverProfile::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'license_code' => $this->licenseCode,
                'license_number' => $this->licenseNumber ?: null,
                'license_expiry' => $this->licenseExpiry ?: null,
                'prdp_expiry' => $this->prdpExpiry ?: null,
                'notes' => $this->notes ?: null,
            ]
        );

        session()->flash('success', "Driver {$this->user->name} updated successfully.");
        $this->redirect(route('admin.drivers.index'));
    }
};
?>
<div>
    <x-slot:header>Edit Driver: {{ $user->name }}</x-slot:header>

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
                    <input wire:model="phone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input wire:model="email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Optional">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
                    </div>
                    @endif
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
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PrDP Expiry</label>
                    <input wire:model="prdpExpiry" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea wire:model="notes" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.drivers.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Save Changes</span>
                <span wire:loading>Saving...</span>
            </button>
        </div>
    </form>
</div>
