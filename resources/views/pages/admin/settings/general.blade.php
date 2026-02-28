<?php
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $companyName = '';
    public string $companyPhone = '';
    public string $companyEmail = '';
    public string $timezone = 'Africa/Johannesburg';
    public string $currency = 'ZAR';
    public string $vatRate = '15';

    public function mount(): void
    {
        $this->companyName = SystemSetting::get('company_name', '');
        $this->companyPhone = SystemSetting::get('company_phone', '');
        $this->companyEmail = SystemSetting::get('company_email', '');
        $this->timezone = SystemSetting::get('timezone', 'Africa/Johannesburg');
        $this->currency = SystemSetting::get('currency', 'ZAR');
        $this->vatRate = SystemSetting::get('vat_rate', '15');
    }

    public function save(): void
    {
        $this->validate([
            'companyName' => 'required|string|max:255',
            'companyPhone' => 'nullable|string|max:20',
            'companyEmail' => 'nullable|email',
            'timezone' => 'required|string',
            'currency' => 'required|string|size:3',
            'vatRate' => 'required|numeric|min:0|max:100',
        ]);

        SystemSetting::set('company_name', $this->companyName);
        SystemSetting::set('company_phone', $this->companyPhone);
        SystemSetting::set('company_email', $this->companyEmail);
        SystemSetting::set('timezone', $this->timezone);
        SystemSetting::set('currency', $this->currency);
        SystemSetting::set('vat_rate', $this->vatRate, 'float');

        session()->flash('success', 'General settings saved.');
    }
};
?>
<div>
    <x-slot:header>General Settings</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Company Information</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name *</label>
                    <input wire:model="companyName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Proselver (Pty) Ltd">
                    @error('companyName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Phone</label>
                    <input wire:model="companyPhone" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Email</label>
                    <input wire:model="companyEmail" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('companyEmail')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Regional</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Timezone *</label>
                    <select wire:model="timezone" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="Africa/Johannesburg">Africa/Johannesburg (SAST)</option>
                        <option value="Africa/Lagos">Africa/Lagos (WAT)</option>
                        <option value="Africa/Nairobi">Africa/Nairobi (EAT)</option>
                        <option value="UTC">UTC</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency *</label>
                    <select wire:model="currency" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                        <option value="ZAR">ZAR (South African Rand)</option>
                        <option value="USD">USD (US Dollar)</option>
                        <option value="EUR">EUR (Euro)</option>
                        <option value="GBP">GBP (British Pound)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">VAT Rate (%) *</label>
                    <input wire:model="vatRate" type="number" step="0.01" min="0" max="100" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('vatRate')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.settings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Back</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">Save Settings</button>
        </div>
    </form>
</div>
