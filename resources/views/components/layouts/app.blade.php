<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1e40af">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased">
    @if(session('impersonating_from'))
    <div class="fixed top-0 left-0 right-0 z-[100] bg-amber-500 text-white text-center py-1.5 text-sm font-medium shadow-md">
        Impersonating <strong>{{ auth()->user()->name }}</strong>
        ({{ auth()->user()->roles->pluck('name')->join(', ') }})
        <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="inline ml-4">
            @csrf
            <button type="submit" class="underline font-semibold hover:text-amber-100">Return to your account</button>
        </form>
    </div>
    @endif

    <div class="min-h-full {{ session('impersonating_from') ? 'pt-9' : '' }}" x-data="{ sidebarOpen: false }">
        {{-- Mobile sidebar overlay --}}
        <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-40 bg-gray-600/75 lg:hidden" @click="sidebarOpen = false"></div>

        {{-- Mobile sidebar --}}
        <div x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-300 transform" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in-out duration-300 transform" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="fixed inset-y-0 left-0 z-50 w-72 lg:hidden">
            <x-sidebar />
        </div>

        {{-- Desktop sidebar --}}
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col">
            <x-sidebar />
        </div>

        {{-- Main content --}}
        <div class="lg:pl-64">
            {{-- Top bar --}}
            <div class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8">
                <button type="button" class="-m-2.5 p-2.5 text-gray-700 lg:hidden" @click="sidebarOpen = true">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>
                    </svg>
                </button>

                <div class="h-6 w-px bg-gray-200 lg:hidden"></div>

                <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
                    <div class="flex flex-1 items-center">
                        @isset($header)
                            <h1 class="text-lg font-semibold text-gray-900">{{ $header }}</h1>
                        @endisset
                    </div>
                    <div class="flex items-center gap-x-4 lg:gap-x-6">
                        <span class="text-sm text-gray-500">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-700">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>

            <main class="py-6">
                <div class="px-4 sm:px-6 lg:px-8">
                    @if (session('success'))
                        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('success') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ session('error') }}</div>
                    @endif
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>
    @livewireScripts

    @if(auth()->user()?->roles->contains('slug', 'developer') && !session('impersonating_from'))
    <div class="fixed bottom-0 left-0 right-0 z-[90] bg-gray-900 text-white text-sm px-4 py-2 flex items-center gap-4 shadow-lg" x-data>
        <span class="font-semibold text-gray-400">DEV</span>
        <form method="POST" action="{{ route('admin.dev.role-switch') }}" class="flex items-center gap-2">
            @csrf
            <label class="text-gray-400">View as:</label>
            <select name="role_slug" onchange="this.form.submit()" class="bg-gray-800 border-gray-700 text-white text-xs rounded px-2 py-1">
                <option value="reset" {{ !session('dev_role_override') ? 'selected' : '' }}>Developer (default)</option>
                @foreach(\App\Models\Role::orderBy('tier')->orderBy('name')->get() as $r)
                    <option value="{{ $r->slug }}" {{ session('dev_role_override') === $r->slug ? 'selected' : '' }}>
                        {{ $r->name }} ({{ $r->tier }})
                    </option>
                @endforeach
            </select>
        </form>
        @if(session('dev_role_override'))
            <span class="text-amber-400 font-semibold">Active: {{ session('dev_role_override') }}</span>
        @endif
    </div>
    @endif

    @php
        $__gmapsKey = \App\Models\SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
    @endphp
    @if($__gmapsKey)
    <script>
        function initGooglePlaces() {
            window._googlePlacesReady = true;
            if (window._placesQueue) {
                window._placesQueue.forEach(fn => fn());
                window._placesQueue = [];
            }
        }

        document.addEventListener('alpine:init', () => {
            Alpine.data('placesAutocomplete', (config) => ({
                init() {
                    if (window._googlePlacesReady) {
                        this.$nextTick(() => this.setup());
                    } else {
                        window._placesQueue = window._placesQueue || [];
                        window._placesQueue.push(() => this.setup());
                    }
                },
                setup() {
                    const input = this.$refs.addressInput;
                    if (!input || input._autocompleteAttached) return;
                    input._autocompleteAttached = true;

                    const ac = new google.maps.places.Autocomplete(input, {
                        componentRestrictions: { country: 'za' },
                        fields: ['address_components', 'formatted_address', 'geometry'],
                    });

                    ac.addListener('place_changed', () => {
                        const place = ac.getPlace();
                        if (!place.address_components) return;

                        if (config.addressModel) {
                            this.$wire.set(config.addressModel, place.formatted_address);
                        }

                        let city = '', province = '';
                        for (const c of place.address_components) {
                            if (!city && (c.types.includes('locality') || c.types.includes('sublocality_level_1'))) {
                                city = c.long_name;
                            }
                            if (c.types.includes('administrative_area_level_1')) {
                                province = c.long_name;
                            }
                        }

                        if (config.cityModel && city) this.$wire.set(config.cityModel, city);
                        if (config.provinceModel && province) this.$wire.set(config.provinceModel, province);

                        if (place.geometry?.location) {
                            if (config.latModel) this.$wire.set(config.latModel, String(place.geometry.location.lat()));
                            if (config.lngModel) this.$wire.set(config.lngModel, String(place.geometry.location.lng()));
                        }
                    });
                }
            }));
        });
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key={{ $__gmapsKey }}&libraries=places&callback=initGooglePlaces" async defer></script>
    @endif
</body>
</html>
