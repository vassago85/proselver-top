<?php
use App\Models\User;
use App\Models\Role;
use App\Models\Company;
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
    public array $selectedRoles = [];
    public ?int $companyId = null;

    public function mount(): void
    {
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
        if ($hasDealerRole || $hasOemRole) {
            $rules['companyId'] = 'required|exists:companies,id';
        }

        $this->validate($rules);

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
        ]);

        $user->roles()->sync($this->selectedRoles);

        if (($hasDealerRole || $hasOemRole) && $this->companyId) {
            $user->companies()->sync([$this->companyId]);
        }

        session()->flash('success', "User {$user->name} created successfully.");
        $this->redirect(route('admin.users.index'));
    }

    public function with(): array
    {
        return [
            'roles' => Role::where('slug', '!=', 'driver')->orderBy('tier')->orderBy('name')->get(),
            'companies' => Company::where('is_active', true)->orderBy('name')->get(),
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

        @if(collect($selectedRoles)->isNotEmpty())
            @php
                $hasDealerRole = \App\Models\Role::whereIn('id', $selectedRoles)->where('tier', 'dealer')->exists();
                $hasOemRole = \App\Models\Role::whereIn('id', $selectedRoles)->where('tier', 'oem')->exists();
                $companyType = $hasOemRole ? 'oem' : ($hasDealerRole ? 'dealer' : null);
            @endphp
            @if($companyType)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $hasOemRole ? 'OEM' : 'Dealer' }} Company Assignment *</h3>
                <select wire:model="companyId" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    <option value="">Select company...</option>
                    @foreach($companies->where('type', $companyType) as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('companyId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            @endif
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                <span wire:loading.remove>Create User</span>
                <span wire:loading>Creating...</span>
            </button>
        </div>
    </form>
</div>
