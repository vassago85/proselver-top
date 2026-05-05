<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Tab title: pages can set $title for per-page context (e.g. "Orders"),
         otherwise fall back to the product name. Hardcoded so the rebrand
         can't regress if the APP_NAME env var is left on an old value. --}}
    <title>{{ !empty($title) ? $title . ' · TRIDENT' : 'TRIDENT — Control & Dispatch Center' }}</title>

    {{-- PWA + iOS standalone: allows owner/ops to add to Home Screen on iPhone
         and launch into a chromeless app. Without these iOS will refuse to
         remove Safari UI and the in-app nav buttons below make no sense. --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Trident">
    <link rel="apple-touch-icon" href="/logo.png?v=2">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
        .node-pulse { animation: node-pulse 2.4s ease-in-out infinite; }
        @keyframes node-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.15); }
        }
        /* subtle scrollbar for sidebar + main */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; border: 2px solid transparent; background-clip: content-box; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; border: 2px solid transparent; background-clip: content-box; }

        /* iOS PWA standalone adjustments:
           - safe-area padding on the body for the notch / home bar
           - show the standalone-only bottom nav
           - reserve room above the bottom nav so content isn't hidden */
        @media (display-mode: standalone) {
            body { padding-top: env(safe-area-inset-top); }
            .pwa-bottom-nav { display: flex !important; }
            .pwa-standalone-pad { padding-bottom: calc(4rem + env(safe-area-inset-bottom)) !important; }
        }
        /* iOS-specific legacy fallback for `navigator.standalone` */
        html.ios-standalone body { padding-top: env(safe-area-inset-top); }
        html.ios-standalone .pwa-bottom-nav { display: flex !important; }
        html.ios-standalone .pwa-standalone-pad { padding-bottom: calc(4rem + env(safe-area-inset-bottom)) !important; }
    </style>
    <script>
        // Tag the <html> element early so the standalone-only styles apply on
        // iOS (where matchMedia('(display-mode: standalone)') doesn't match
        // until iOS 16.4+, but navigator.standalone has worked since iOS 4).
        if (window.navigator.standalone === true) {
            document.documentElement.classList.add('ios-standalone');
        }
    </script>
</head>
<body class="h-full font-sans antialiased text-slate-900 selection:bg-blue-600 selection:text-white">

    {{-- =================================================================== --}}
    {{-- IMPERSONATION BANNER                                                 --}}
    {{-- =================================================================== --}}
    @if(session('impersonating_from'))
    <div class="fixed inset-x-0 top-0 z-[100] border-b border-amber-300/80 bg-gradient-to-r from-amber-500 via-amber-400 to-amber-500 text-white shadow-sm">
        <div class="mx-auto flex max-w-full items-center gap-3 px-4 py-2 text-sm">
            <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
            <span>
                Impersonating <strong class="font-semibold">{{ auth()->user()->name }}</strong>
                <span class="hidden sm:inline text-amber-900/80">· {{ auth()->user()->roles->pluck('name')->join(', ') }}</span>
            </span>
            <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="ml-auto">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1 rounded-md bg-white/15 hover:bg-white/25 px-2.5 py-1 text-xs font-semibold backdrop-blur-sm transition">
                    Return to your account
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
            </form>
        </div>
    </div>
    @endif

    <div class="min-h-full {{ session('impersonating_from') ? 'pt-10' : '' }}"
         x-data="{ sidebarOpen: false, userMenu: false }"
         @open-mobile-sidebar.window="sidebarOpen = true">

        {{-- Mobile sidebar overlay --}}
        <div x-show="sidebarOpen" x-cloak x-transition:enter="transition-opacity ease-linear duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false"></div>

        {{-- Mobile sidebar --}}
        <div x-show="sidebarOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="fixed inset-y-0 left-0 z-50 w-72 lg:hidden {{ session('impersonating_from') ? 'top-10' : '' }}">
            <x-sidebar />
        </div>

        {{-- Desktop sidebar --}}
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col {{ session('impersonating_from') ? 'lg:top-10' : '' }}">
            <x-sidebar />
        </div>

        {{-- Main column --}}
        <div class="lg:pl-64 flex min-h-screen flex-col">

            {{-- Top bar --}}
            @php $homeUrl = auth()->check() ? resolveUserHomePath(auth()->user()) : route('login'); @endphp
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-1 sm:gap-x-3 border-b border-slate-200 bg-white/80 backdrop-blur px-3 sm:px-6 lg:px-8 {{ session('impersonating_from') ? 'top-10' : '' }}">
                {{-- Mobile: Menu / Back / Home trio. Back is hidden when we're
                     already on the role home route so we don't dead-end the user. --}}
                <button type="button" class="-ml-1 p-2 rounded-md text-slate-500 hover:text-slate-900 hover:bg-slate-100 lg:hidden transition" @click="sidebarOpen = true" aria-label="Open navigation">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                </button>

                <button type="button" class="p-2 rounded-md text-slate-500 hover:text-slate-900 hover:bg-slate-100 lg:hidden transition"
                        x-data="{ canBack: false }"
                        x-init="canBack = (window.history.length > 1) && (document.referrer.startsWith(window.location.origin) || document.referrer === '')"
                        x-show="canBack"
                        x-cloak
                        @click="window.history.back()"
                        aria-label="Back">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>

                <a href="{{ $homeUrl }}" class="p-2 rounded-md text-slate-500 hover:text-slate-900 hover:bg-slate-100 lg:hidden transition"
                   aria-label="Home"
                   @if(request()->url() === $homeUrl) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </a>

                <div class="h-6 w-px bg-slate-200 lg:hidden"></div>

                <div class="flex flex-1 items-center min-w-0">
                    @isset($header)
                        <h1 class="text-[15px] font-semibold tracking-tight text-slate-900 truncate">{{ $header }}</h1>
                    @endisset
                </div>

                <div class="flex items-center gap-1.5 sm:gap-3">
                    {{-- Live clock --}}
                    <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full border border-slate-200 text-slate-500 text-xs font-medium" x-data="{ t: '' }" x-init="t = new Date().toLocaleTimeString('en-ZA', {hour:'2-digit',minute:'2-digit'}); setInterval(() => t = new Date().toLocaleTimeString('en-ZA', {hour:'2-digit',minute:'2-digit'}), 30000)">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 node-pulse"></span>
                        <span class="tabular-nums" x-text="t"></span>
                    </div>

                    {{-- User menu --}}
                    <div class="relative" @click.outside="userMenu = false">
                        <button type="button" @click="userMenu = !userMenu" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100 transition">
                            <span class="h-8 w-8 rounded-lg bg-gradient-to-br from-slate-800 to-slate-900 text-white flex items-center justify-center text-xs font-semibold ring-1 ring-slate-900/10">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}{{ strtoupper(substr(strstr(auth()->user()->name, ' ') ?: '', 1, 1)) }}
                            </span>
                            @php
                                // Remap "Customer X" → "OEM X" / "Dealer X" when the
                                // user's primary company is typed as such — see sidebar
                                // notes; we keep the customer_* role slug for tenanting
                                // but present an OEM/Dealer label to the human.
                                $primaryRoleName = auth()->user()->roles->first()?->name ?? 'Member';
                                $primaryCompanyType = optional(auth()->user()->companies()->first())->type;
                                if ($primaryCompanyType === \App\Models\Company::TYPE_OEM) {
                                    $primaryRoleName = str_replace('Customer ', 'OEM ', $primaryRoleName);
                                } elseif ($primaryCompanyType === \App\Models\Company::TYPE_DEALER) {
                                    $primaryRoleName = str_replace('Customer ', 'Dealer ', $primaryRoleName);
                                }
                            @endphp
                            <span class="hidden md:flex flex-col items-start leading-tight text-left">
                                <span class="text-sm font-semibold text-slate-900 truncate max-w-[140px]">{{ auth()->user()->name }}</span>
                                <span class="text-[10px] font-medium tracking-wider uppercase text-slate-400 truncate max-w-[140px]">{{ $primaryRoleName }}</span>
                            </span>
                            <svg viewBox="0 0 24 24" class="hidden md:block h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>

                        <div x-show="userMenu" x-cloak x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="absolute right-0 mt-2 w-60 rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100">
                                <p class="text-sm font-semibold text-slate-900 truncate">{{ auth()->user()->name }}</p>
                                <p class="text-xs text-slate-500 truncate">{{ auth()->user()->email ?: auth()->user()->username }}</p>
                            </div>
                            <div class="py-1">
                                <a href="{{ route('profile.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
                                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Profile &amp; security
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition">
                                        <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Main content --}}
            <main class="flex-1 pwa-standalone-pad">
                <div class="px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
                    {{-- Session flash messages --}}
                    @if (session('success'))
                        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)" class="mb-5 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <p class="flex-1 leading-relaxed">{{ session('success') }}</p>
                            <button @click="show = false" class="text-emerald-600 hover:text-emerald-800" aria-label="Dismiss">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </button>
                        </div>
                    @endif
                    @if (session('error'))
                        <div x-data="{ show: true }" x-show="show" class="mb-5 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-rose-600" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
                            <p class="flex-1 leading-relaxed">{{ session('error') }}</p>
                            <button @click="show = false" class="text-rose-600 hover:text-rose-800" aria-label="Dismiss">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </button>
                        </div>
                    @endif
                    @if (session('warning'))
                        <div x-data="{ show: true }" x-show="show" class="mb-5 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                            <p class="flex-1 leading-relaxed">{{ session('warning') }}</p>
                            <button @click="show = false" class="text-amber-700 hover:text-amber-900" aria-label="Dismiss">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </button>
                        </div>
                    @endif
                    @if (session('pwa_access_denied'))
                        <div x-data="{ show: true }" x-show="show" class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                            <div class="flex-1 space-y-2">
                                <p class="leading-relaxed">{{ session('pwa_access_denied') }}</p>
                                <div class="flex items-center gap-3">
                                    <form method="POST" action="{{ route('logout') }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500 transition">
                                            Sign out &amp; switch to driver
                                        </button>
                                    </form>
                                    <button @click="show = false" class="text-xs font-medium text-amber-800 hover:text-amber-900 underline-offset-2 hover:underline">Dismiss</button>
                                </div>
                            </div>
                        </div>
                    @endif
                    {{ $slot }}
                </div>
            </main>

            {{-- Footer (light, unobtrusive) --}}
            <footer class="border-t border-slate-200 bg-white/60 px-4 sm:px-6 lg:px-8 py-4">
                <p class="text-[11px] text-slate-400 text-center">TRIDENT · Control &amp; Dispatch Center · © {{ date('Y') }}</p>
            </footer>
        </div>
    </div>

    {{-- =================================================================== --}}
    {{-- STANDALONE-MODE BOTTOM NAV (iPhone / Android PWA installed)         --}}
    {{-- Hidden by default; flipped to `display: flex` by the `.pwa-bottom-nav` --}}
    {{-- CSS rules in <head> when @media (display-mode: standalone) OR when  --}}
    {{-- iOS `navigator.standalone` is true. Home Screen launch = bottom nav.--}}
    {{-- =================================================================== --}}
    <nav class="pwa-bottom-nav fixed inset-x-0 bottom-0 z-[85] hidden items-stretch border-t border-slate-200 bg-white/95 backdrop-blur pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_-8px_rgba(15,23,42,0.15)]"
         aria-label="App navigation">
        <button type="button"
                x-data="{ canBack: false }"
                x-init="canBack = (window.history.length > 1) && (document.referrer.startsWith(window.location.origin) || document.referrer === '')"
                @click="window.history.back()"
                :disabled="!canBack"
                :class="canBack ? 'text-slate-700' : 'text-slate-300'"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-semibold active:bg-slate-100">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            <span>Back</span>
        </button>

        <a href="{{ $homeUrl ?? '/' }}"
           class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-semibold text-slate-700 active:bg-slate-100"
           @if(isset($homeUrl) && request()->url() === $homeUrl) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Home</span>
        </a>

        <button type="button" x-data @click="$dispatch('open-mobile-sidebar')"
                class="flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-semibold text-slate-700 active:bg-slate-100">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
            <span>Menu</span>
        </button>
    </nav>

    @livewireScripts

    {{-- =================================================================== --}}
    {{-- DEVELOPER ROLE-SWITCHER BAR                                          --}}
    {{-- =================================================================== --}}
    @if(auth()->user()?->roles->contains('slug', 'developer') && !session('impersonating_from'))
    <div class="fixed bottom-0 left-0 right-0 z-[90] border-t border-slate-800 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 text-slate-200 px-4 py-2.5 flex items-center gap-4 shadow-[0_-4px_20px_-8px_rgba(15,23,42,0.6)]" x-data>
        <span class="inline-flex items-center gap-2 text-xs font-semibold tracking-[0.2em] uppercase text-blue-400">
            <span class="h-1.5 w-1.5 rounded-full bg-blue-400 node-pulse"></span>
            Developer
        </span>
        <form method="POST" action="{{ route('admin.dev.role-switch') }}" class="flex items-center gap-2">
            @csrf
            <label class="text-xs text-slate-400 hidden sm:inline">View as</label>
            <select name="role_slug" onchange="this.form.submit()" class="bg-slate-800 border border-slate-700 text-slate-100 text-xs rounded-md px-2.5 py-1.5 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                <option value="reset" {{ !session('dev_role_override') ? 'selected' : '' }}>Default (Developer)</option>
                @foreach(\App\Models\Role::orderBy('tier')->orderBy('name')->get() as $r)
                    <option value="{{ $r->slug }}" {{ session('dev_role_override') === $r->slug ? 'selected' : '' }}>
                        {{ $r->name }} · {{ $r->tier }}
                    </option>
                @endforeach
            </select>
        </form>
        @if(session('dev_role_override'))
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-400">
                <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                Acting as {{ session('dev_role_override') }}
            </span>
        @endif
        <span class="ml-auto text-[10px] tracking-[0.25em] uppercase text-slate-500 hidden md:inline">Developer toolbar</span>
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
