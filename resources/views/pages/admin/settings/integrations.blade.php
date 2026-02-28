<?php
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Http;

new #[Layout('components.layouts.app')] class extends Component {
    public string $googleMapsApiKey = '';
    public bool $hasExistingKey = false;

    public function mount(): void
    {
        $existing = (string) SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key', ''));
        $this->hasExistingKey = !empty($existing);
    }

    public function save(): void
    {
        if ($this->googleMapsApiKey) {
            $this->validate([
                'googleMapsApiKey' => 'required|string|min:10|max:255',
            ]);

            SystemSetting::set('google_maps_api_key', $this->googleMapsApiKey, 'string', 'Google Maps Platform API key');
            $this->hasExistingKey = true;
            $this->googleMapsApiKey = '';
            session()->flash('success', 'Google Maps API key saved.');
        } else {
            session()->flash('info', 'No changes made — leave blank to keep the current key.');
        }
    }

    public function removeKey(): void
    {
        SystemSetting::set('google_maps_api_key', '', 'string', 'Google Maps Platform API key');
        $this->hasExistingKey = false;
        session()->flash('success', 'Google Maps API key removed.');
    }

    public function testKey(): void
    {
        $apiKey = $this->googleMapsApiKey ?: (string) SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key', ''));

        if (!$apiKey) {
            session()->flash('error', 'No API key configured. Enter a key and save first.');
            return;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => 'Johannesburg, South Africa',
                'key' => $apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (($data['status'] ?? '') === 'OK' && !empty($data['results'])) {
                    session()->flash('success', 'API key is valid — Geocoding API responded successfully.');
                } elseif (($data['status'] ?? '') === 'REQUEST_DENIED') {
                    session()->flash('error', 'API key denied: ' . ($data['error_message'] ?? 'Unknown error. Check key restrictions in Google Cloud Console.'));
                } else {
                    session()->flash('error', 'Unexpected response: ' . ($data['status'] ?? 'unknown') . ' — ' . ($data['error_message'] ?? ''));
                }
            } else {
                session()->flash('error', 'HTTP error: ' . $response->status());
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Connection failed: ' . $e->getMessage());
        }
    }
};
?>
<div>
    <x-slot:header>Integrations</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif
    @if(session('info'))
        <div class="mb-4 rounded-lg bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">{{ session('info') }}</div>
    @endif

    <div class="max-w-2xl">
        <form wire:submit="save" class="mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Google Maps</h3>
                        <p class="text-xs text-gray-500">Geocoding, route calculation, and toll detection</p>
                    </div>
                </div>

                @if($hasExistingKey)
                    <div class="mb-4 flex items-center gap-2 rounded-lg bg-green-50 border border-green-200 px-4 py-2.5 text-sm text-green-700">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        API key is configured
                    </div>
                @else
                    <div class="mb-4 flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-4 py-2.5 text-sm text-amber-700">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        No API key configured — geocoding and route features are disabled
                    </div>
                @endif

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                    <div class="relative">
                        <input
                            wire:model="googleMapsApiKey"
                            :type="show ? 'text' : 'password'"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="{{ $hasExistingKey ? 'Leave blank to keep current key' : 'Enter your Google Maps API key' }}"
                            autocomplete="off"
                        >
                        <button
                            type="button"
                            @click="show = !show"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600"
                        >
                            <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" x-cloak><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                        </button>
                    </div>
                    @error('googleMapsApiKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1.5 text-xs text-gray-400">Requires Geocoding API and Directions API enabled in your Google Cloud Console project.</p>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Back</a>
                    @if($hasExistingKey)
                        <button type="button" wire:click="removeKey" wire:confirm="Are you sure you want to remove the Google Maps API key?" class="rounded-lg border border-red-300 bg-white px-4 py-3 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Remove Key
                        </button>
                    @endif
                </div>
                <button type="submit" class="rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-500">
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </button>
            </div>
        </form>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Test Connection</h3>
            <p class="text-sm text-gray-500 mb-4">Sends a geocoding request for "Johannesburg, South Africa" to verify the API key works.</p>
            <button wire:click="testKey" class="rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-500" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="testKey">Test API Key</span>
                <span wire:loading wire:target="testKey">Testing...</span>
            </button>
        </div>
    </div>
</div>
