<?php
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $cutoffMode = 'hours_before';
    public string $cutoffHours = '24';
    public string $cutoffDays = '1';
    public string $cutoffTime = '15:00';

    public function mount(): void
    {
        $this->cutoffMode = SystemSetting::get('collection_cutoff_mode', 'hours_before');
        $this->cutoffHours = SystemSetting::get('collection_cutoff_hours', '24');
        $this->cutoffDays = SystemSetting::get('collection_cutoff_days', '1');
        $this->cutoffTime = SystemSetting::get('collection_cutoff_time', '15:00');
    }

    public function save(): void
    {
        $this->validate([
            'cutoffMode' => 'required|in:hours_before,day_before_at_time',
            'cutoffHours' => 'required_if:cutoffMode,hours_before|integer|min:1|max:168',
            'cutoffDays' => 'required_if:cutoffMode,day_before_at_time|integer|min:1|max:14',
            'cutoffTime' => 'required_if:cutoffMode,day_before_at_time|date_format:H:i',
        ]);

        SystemSetting::set('collection_cutoff_mode', $this->cutoffMode);
        SystemSetting::set('collection_cutoff_hours', $this->cutoffHours, 'integer');
        SystemSetting::set('collection_cutoff_days', $this->cutoffDays, 'integer');
        SystemSetting::set('collection_cutoff_time', $this->cutoffTime);

        session()->flash('success', 'Booking rules saved.');
    }
};
?>
<div>
    <x-slot:header>Booking Rules</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <form wire:submit="save" class="max-w-2xl">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Collection Cutoff</h3>
            <p class="text-sm text-gray-500 mb-6">Define how far in advance a booking must be placed before the scheduled collection time.</p>

            <div class="space-y-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model.live="cutoffMode" type="radio" name="cutoffMode" value="hours_before"
                        class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm font-medium text-gray-700">Hours before collection</span>
                </label>

                <div x-data x-show="$wire.cutoffMode === 'hours_before'" x-cloak class="ml-7 mt-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Minimum hours before collection *</label>
                    <input wire:model="cutoffHours" type="number" min="1" max="168" step="1"
                        class="w-40 rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="mt-1 text-xs text-gray-500">e.g. 24 = booking must be placed at least 24 hours before collection</p>
                    @error('cutoffHours')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model.live="cutoffMode" type="radio" name="cutoffMode" value="day_before_at_time"
                        class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm font-medium text-gray-700">Day before at specific time</span>
                </label>

                <div x-data x-show="$wire.cutoffMode === 'day_before_at_time'" x-cloak class="ml-7 mt-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Days before collection *</label>
                            <input wire:model="cutoffDays" type="number" min="1" max="14" step="1"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('cutoffDays')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cutoff time *</label>
                            <input wire:model="cutoffTime" type="time"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-500">e.g. 15:00 = must book by 3 PM the day before</p>
                            @error('cutoffTime')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.settings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Back</a>
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">Save Settings</button>
        </div>
    </form>
</div>
