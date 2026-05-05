<?php
use App\Models\SystemSetting;
use App\Services\TrackSolid\Client as TrackSolidClient;
use App\Services\TrackSolid\TrackSolidClientInterface;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Http;

new #[Layout('components.layouts.app')] class extends Component {
    public string $googleMapsApiKey = '';
    public bool $hasExistingKey = false;

    // TrackSolid GPS tracker integration. We never echo the saved app key
    // / secret back into the form (so a screenshot of this page can't
    // leak credentials); a "leave blank to keep current" sentinel keeps
    // partial saves working.
    public bool $tsEnabled = false;
    public string $tsBaseUrl = '';
    public string $tsAccount = '';
    public string $tsAppKey = '';
    public string $tsAppSecret = '';
    public int $tsPollIntervalSeconds = 30;
    public bool $hasExistingTsKey = false;
    public bool $hasExistingTsSecret = false;

    public function mount(): void
    {
        $existing = (string) SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key', ''));
        $this->hasExistingKey = !empty($existing);

        $this->tsEnabled = (bool) SystemSetting::get(TrackSolidClient::SETTING_ENABLED, false);
        $this->tsBaseUrl = (string) SystemSetting::get(TrackSolidClient::SETTING_BASE_URL, '');
        $this->tsAccount = (string) SystemSetting::get(TrackSolidClient::SETTING_ACCOUNT, '');
        $this->tsPollIntervalSeconds = (int) SystemSetting::get(TrackSolidClient::SETTING_POLL_INTERVAL, 30);
        $this->hasExistingTsKey = !empty(SystemSetting::get(TrackSolidClient::SETTING_APP_KEY));
        $this->hasExistingTsSecret = !empty(SystemSetting::get(TrackSolidClient::SETTING_APP_SECRET));
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

    /**
     * Save TrackSolid credentials. Blank app-key / app-secret fields are
     * treated as "keep current value" so the operator can flip the
     * enabled toggle or change the polling interval without retyping
     * the secret every time.
     */
    public function saveTrackSolid(): void
    {
        $this->validate([
            'tsBaseUrl' => 'nullable|string|max:255',
            'tsAccount' => 'nullable|string|max:255',
            'tsAppKey' => 'nullable|string|max:255',
            'tsAppSecret' => 'nullable|string|max:255',
            'tsPollIntervalSeconds' => 'integer|min:10|max:600',
        ]);

        SystemSetting::set(TrackSolidClient::SETTING_ENABLED, $this->tsEnabled ? '1' : '0', 'boolean', 'TrackSolid GPS integration enabled');
        SystemSetting::set(TrackSolidClient::SETTING_BASE_URL, trim($this->tsBaseUrl), 'string', 'TrackSolid API base URL');
        SystemSetting::set(TrackSolidClient::SETTING_ACCOUNT, trim($this->tsAccount), 'string', 'TrackSolid account / username');
        SystemSetting::set(TrackSolidClient::SETTING_POLL_INTERVAL, (string) $this->tsPollIntervalSeconds, 'integer', 'Seconds between TrackSolid position polls');

        if ($this->tsAppKey !== '') {
            SystemSetting::set(TrackSolidClient::SETTING_APP_KEY, trim($this->tsAppKey), 'string', 'TrackSolid app key');
            $this->hasExistingTsKey = true;
        }
        if ($this->tsAppSecret !== '') {
            SystemSetting::set(TrackSolidClient::SETTING_APP_SECRET, trim($this->tsAppSecret), 'string', 'TrackSolid app secret');
            $this->hasExistingTsSecret = true;
        }

        $this->tsAppKey = '';
        $this->tsAppSecret = '';
        session()->flash('success', 'TrackSolid settings saved.');
    }

    public function clearTrackSolid(): void
    {
        foreach ([
            TrackSolidClient::SETTING_ENABLED,
            TrackSolidClient::SETTING_BASE_URL,
            TrackSolidClient::SETTING_APP_KEY,
            TrackSolidClient::SETTING_APP_SECRET,
            TrackSolidClient::SETTING_ACCOUNT,
        ] as $key) {
            SystemSetting::set($key, '', 'string', null);
        }
        $this->tsEnabled = false;
        $this->tsBaseUrl = '';
        $this->tsAccount = '';
        $this->hasExistingTsKey = false;
        $this->hasExistingTsSecret = false;
        \Illuminate\Support\Facades\Cache::forget('tracksolid.access_token');
        session()->flash('success', 'TrackSolid credentials cleared.');
    }

    /**
     * Resolve the bound client from the container and ask it to
     * authenticate + list devices. Surfaces a count of devices on
     * success so the operator can sanity-check that the right account
     * was bound.
     */
    public function testTrackSolid(TrackSolidClientInterface $client): void
    {
        if (!$client->isConfigured()) {
            session()->flash('error', 'TrackSolid not configured — fill in the base URL, account, app key and app secret first, save, then test.');
            return;
        }

        try {
            $client->authenticate();
            $devices = $client->listDevices();
            $count = count($devices);
            if ($count === 0) {
                session()->flash('info', 'TrackSolid responded but the account has no devices — bind at least one tracker on the TrackSolid portal, then re-test.');
            } else {
                session()->flash('success', "TrackSolid OK — authenticated and found {$count} device(s) on the account.");
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'TrackSolid test failed: ' . $e->getMessage());
        }
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
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Google Maps</h3>
                        <p class="text-xs text-gray-500">Geocoding, route calculation, and toll detection</p>
                    </div>
                </div>

                @if($hasExistingKey)
                    <div class="mb-4 flex items-center gap-2 rounded-lg bg-green-50 border border-green-200 px-4 py-2.5 text-sm text-green-700">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                        API key is configured
                    </div>
                @else
                    <div class="mb-4 flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-4 py-2.5 text-sm text-amber-700">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
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
                            <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" x-cloak><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
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

        {{-- ----------------------------------------------------------------
             TrackSolid GPS tracker integration
             Powers the live driver pins on the Ops wallboard. App key /
             secret are write-only — never echoed back, only "configured"
             flags signal that something is on file.
        ----------------------------------------------------------------- --}}
        <form wire:submit="saveTrackSolid" class="my-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 4 10 15 15 0 0 1-4 10 15 15 0 0 1-4-10 15 15 0 0 1 4-10z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">TrackSolid GPS</h3>
                        <p class="text-xs text-gray-500">Live driver positions on the ops wallboard. Drivers are matched on <code>driver_profiles.tracker_id</code>.</p>
                    </div>
                </div>

                <div class="mb-5 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 select-none">
                        <input type="checkbox" wire:model.live="tsEnabled" class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        Enable TrackSolid integration
                    </label>
                    <span class="ml-auto text-xs text-slate-500">
                        @if($tsEnabled && $hasExistingTsKey && $hasExistingTsSecret)
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700">configured</span>
                        @elseif($hasExistingTsKey || $hasExistingTsSecret)
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700">partial</span>
                        @else
                            <span class="rounded-full bg-slate-200 px-2 py-0.5 font-medium text-slate-700">not configured</span>
                        @endif
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Base URL</label>
                        <input wire:model="tsBaseUrl" type="url" placeholder="https://open.tracksolidpro.com" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        @error('tsBaseUrl')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account / username</label>
                        <input wire:model="tsAccount" type="text" placeholder="ops@yourcompany.com" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        @error('tsAccount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-gray-700 mb-1">App key</label>
                        <div class="relative">
                            <input wire:model="tsAppKey" :type="show ? 'text' : 'password'" autocomplete="off"
                                placeholder="{{ $hasExistingTsKey ? 'Leave blank to keep current key' : 'Paste app key' }}"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                                <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg x-show="show" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                            </button>
                        </div>
                        @error('tsAppKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-gray-700 mb-1">App secret</label>
                        <div class="relative">
                            <input wire:model="tsAppSecret" :type="show ? 'text' : 'password'" autocomplete="off"
                                placeholder="{{ $hasExistingTsSecret ? 'Leave blank to keep current secret' : 'Paste app secret' }}"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 pr-10 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                                <svg x-show="!show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg x-show="show" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                            </button>
                        </div>
                        @error('tsAppSecret')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Poll interval (seconds)</label>
                        <input wire:model="tsPollIntervalSeconds" type="number" min="10" max="600" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">How often the scheduler asks TrackSolid for fresh positions. 30 is a good default.</p>
                        @error('tsPollIntervalSeconds')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex gap-3">
                    @if($hasExistingTsKey || $hasExistingTsSecret)
                        <button type="button" wire:click="clearTrackSolid" wire:confirm="Clear all TrackSolid credentials? Driver pins on the wallboard will go offline until re-configured." class="rounded-lg border border-red-300 bg-white px-4 py-3 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Clear credentials
                        </button>
                    @endif
                </div>
                <button type="submit" class="rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-500">
                    <span wire:loading.remove wire:target="saveTrackSolid">Save TrackSolid</span>
                    <span wire:loading wire:target="saveTrackSolid">Saving...</span>
                </button>
            </div>
        </form>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Test TrackSolid connection</h3>
            <p class="text-sm text-gray-500 mb-4">Authenticates against the saved credentials and lists the devices bound to the account. Use after saving.</p>
            <button wire:click="testTrackSolid" class="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="testTrackSolid">Test TrackSolid</span>
                <span wire:loading wire:target="testTrackSolid">Testing...</span>
            </button>
        </div>
    </div>
</div>
