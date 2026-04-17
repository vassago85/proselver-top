<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Trident Driver' }}</title>

    {{-- Driver-scoped PWA manifest (separate from the main app manifest.json) --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1e40af">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Trident Driver">
    <link rel="apple-touch-icon" href="/logo.png">

    @vite(['resources/css/app.css', 'resources/js/driver/driver.js'])
    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        html, body { overscroll-behavior-y: contain; }
        body { padding-bottom: env(safe-area-inset-bottom); }
        .dot-pulse { animation: dot-pulse 2s ease-in-out infinite; }
        @keyframes dot-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
    </style>
</head>
<body class="min-h-full font-sans antialiased bg-slate-50 text-slate-900">

    <div class="min-h-screen flex flex-col pb-20">

        {{-- Top bar --}}
        <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/95 backdrop-blur px-4">
            <a href="{{ route('driver.dashboard') }}" class="flex items-center gap-2 min-w-0 flex-1">
                <img src="/logo.png" alt="" class="h-7 w-7 object-contain">
                <span class="text-sm font-semibold tracking-tight text-slate-900 truncate">
                    @isset($header){{ $header }}@else Trident Driver @endisset
                </span>
            </a>

            {{-- Online / offline indicator --}}
            <div x-data
                 class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                 :class="$store.driverQueue.online
                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                    : 'bg-amber-50 text-amber-700 border border-amber-200'">
                <span class="h-1.5 w-1.5 rounded-full dot-pulse"
                      :class="$store.driverQueue.online ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <span x-text="$store.driverQueue.online ? 'Online' : 'Offline'"></span>
            </div>
        </header>

        {{-- Impersonation banner (developer) --}}
        @if(session('impersonating_from'))
        <div class="bg-amber-500 text-white text-xs font-semibold text-center py-1.5">
            Impersonating {{ auth()->user()->name }}
            <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="inline">
                @csrf
                <button class="underline ml-2">Return</button>
            </form>
        </div>
        @endif

        {{-- Flash messages --}}
        <div class="px-4 pt-3">
            @if (session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                     class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div x-data="{ show: true }" x-show="show"
                     class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        {{-- Main content --}}
        <main class="flex-1 px-4 pb-6">
            {{ $slot }}
        </main>

        {{-- Bottom tab bar --}}
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-3">
                <a href="{{ route('driver.dashboard') }}"
                   class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold
                          {{ request()->routeIs('driver.dashboard') ? 'text-blue-600' : 'text-slate-500' }}">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Jobs
                </a>

                <button x-data type="button" @click="$dispatch('open-queue')"
                        class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold text-slate-500 relative">
                    <div class="relative">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                        <span x-show="$store.driverQueue.pending > 0" x-cloak
                              class="absolute -top-1 -right-2 min-w-4 h-4 px-1 rounded-full bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center"
                              x-text="$store.driverQueue.pending"></span>
                    </div>
                    <span>Queue</span>
                </button>

                <form method="POST" action="{{ route('logout') }}" class="contents">
                    @csrf
                    <button type="submit"
                            class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold text-slate-500">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                        Sign out
                    </button>
                </form>
            </div>
        </nav>
    </div>

    {{-- Queue drawer --}}
    <div x-data="{ open: false }"
         @open-queue.window="open = true"
         @keydown.escape.window="open = false"
         x-cloak>
        <div x-show="open" x-transition.opacity
             class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm"
             @click="open = false"></div>

        <div x-show="open" x-transition
             class="fixed inset-x-0 bottom-0 z-50 max-h-[75vh] overflow-y-auto rounded-t-2xl bg-white shadow-2xl">
            <div class="sticky top-0 bg-white border-b border-slate-200 px-5 py-3 flex items-center gap-3">
                <h2 class="text-base font-semibold text-slate-900">Upload queue</h2>
                <span x-show="$store.driverQueue.pending > 0"
                      class="rounded-full bg-rose-100 text-rose-700 text-[10px] font-bold px-2 py-0.5"
                      x-text="$store.driverQueue.pending + ' pending'"></span>
                <button type="button"
                        class="ml-auto rounded-lg bg-blue-600 text-white text-xs font-semibold px-3 py-1.5 disabled:bg-slate-300"
                        :disabled="$store.driverQueue.flushing"
                        @click="$store.driverQueue.forceSync()">
                    <span x-show="!$store.driverQueue.flushing">Force sync</span>
                    <span x-show="$store.driverQueue.flushing" x-cloak>Syncing...</span>
                </button>
                <button type="button" @click="open = false" class="p-1 text-slate-400 hover:text-slate-700">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="p-4 space-y-2">
                <template x-if="$store.driverQueue.items.length === 0">
                    <p class="text-sm text-slate-500 text-center py-8">No pending uploads. Everything synced.</p>
                </template>

                <template x-for="item in $store.driverQueue.items" :key="item.id">
                    <div class="flex items-center gap-3 rounded-lg border border-slate-200 p-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-900 capitalize" x-text="item.category.replace(/_/g, ' ')"></p>
                            <p class="text-[11px] text-slate-500">
                                Job #<span x-text="item.jobId"></span> ·
                                <span x-text="new Date(item.createdAt).toLocaleString('en-ZA', { hour12: false })"></span>
                                <template x-if="item.attempts > 0">
                                    <span class="text-amber-600"> · <span x-text="item.attempts"></span> attempt(s)</span>
                                </template>
                            </p>
                            <p x-show="item.lastError" x-cloak class="text-[11px] text-rose-600 truncate" x-text="item.lastError"></p>
                        </div>
                        <button type="button"
                                class="text-[11px] text-rose-600 hover:text-rose-800 font-semibold"
                                @click="$store.driverQueue.removeItem(item.id)">
                            Remove
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
