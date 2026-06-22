<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Body Builder Yard' }}</title>

    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=3">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=3">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Tablet-first: bigger touch targets, body padding for the
         fixed bottom nav so content never sits underneath. --}}
    <style>
        [x-cloak] { display: none !important; }
        html, body { overscroll-behavior-y: contain; }
        body { padding-bottom: calc(64px + env(safe-area-inset-bottom)); }
    </style>
</head>
<body class="min-h-full font-sans antialiased bg-slate-100 text-slate-900">

    <div class="min-h-screen flex flex-col">

        {{-- Top bar -- thin, branded, with the BB company name front and centre. --}}
        <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/95 backdrop-blur px-4">
            <a href="{{ route('body-builder.yard.index') }}" class="flex items-center gap-2 min-w-0 flex-1">
                <img src="/logo.png?v=2" alt="" class="h-7 w-7 object-contain">
                <span class="text-sm font-semibold tracking-tight text-slate-900 truncate">
                    @isset($header){{ $header }}@else {{ auth()->user()?->company()?->name ?? 'Yard' }} @endisset
                </span>
            </a>
            <a href="{{ route('body-builder.dashboard') }}"
               class="text-xs font-semibold text-slate-500 hover:text-slate-700">
                Portal →
            </a>
        </header>

        @if(session('impersonating_from'))
            <div class="bg-amber-500 text-white text-xs font-semibold text-center py-1.5">
                Impersonating {{ auth()->user()->name }}
                <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="inline">
                    @csrf
                    <button class="underline ml-2">Return</button>
                </form>
            </div>
        @endif

        <div class="px-4 pt-3 max-w-3xl mx-auto w-full">
            @if (session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                     class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        <main class="flex-1 px-4 pb-6 max-w-3xl mx-auto w-full">
            {{ $slot }}
        </main>

        {{-- Bottom tab bar -- yard, check-in, orders, dashboard.  Big
             tap targets, ~64px tall, reused driver-portal pattern. --}}
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-4 max-w-3xl mx-auto">
                <a href="{{ route('body-builder.yard.index') }}"
                   class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold
                          {{ request()->routeIs('body-builder.yard.index') ? 'text-blue-600' : 'text-slate-500' }}">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                    Yard
                </a>
                <a href="{{ route('body-builder.yard.checkin') }}"
                   class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold
                          {{ request()->routeIs('body-builder.yard.checkin') ? 'text-blue-600' : 'text-slate-500' }}">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Check-in
                </a>
                <a href="{{ route('body-builder.orders.index') }}"
                   class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold
                          {{ request()->routeIs('body-builder.orders.*') ? 'text-blue-600' : 'text-slate-500' }}">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="13" x="4" y="6" rx="2"/><path d="M16 3v6M8 3v6M4 11h16"/></svg>
                    Orders
                </a>
                <a href="{{ route('body-builder.dashboard') }}"
                   class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold
                          {{ request()->routeIs('body-builder.dashboard') ? 'text-blue-600' : 'text-slate-500' }}">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    More
                </a>
            </div>
        </nav>

    </div>

    @livewireScripts
</body>
</html>
