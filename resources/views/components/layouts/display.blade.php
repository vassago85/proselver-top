<!DOCTYPE html>
{{--
    Chromeless display layout — used by the "Live Movements" board that
    dealers run on a wall-mounted monitor / TV at the dispatch desk.

    Why a separate layout (vs. just hiding the sidebar in the main one):
      - No top bar, no sidebar, no user menu, no PWA standalone offsets.
        On a TV you want every pixel for the board.
      - Dark theme by default for readable contrast across the room.
      - Disables text selection cursors so swiping/touch on a smart TV
        doesn't start highlighting cards.
      - No global `@auth`-driven impersonation banner — auth is still
        enforced by the route middleware, we just don't render the chrome.

    Livewire scripts are still included so child pages can use wire:poll
    for the auto-refresh loop.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ !empty($title) ? $title . ' · TRIDENT Live Board' : 'TRIDENT Live Board' }}</title>

    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=3">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png?v=3">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=3">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        html, body { background: #020617; }
        body {
            -webkit-font-smoothing: antialiased;
            -webkit-user-select: none;
            user-select: none;
            overscroll-behavior: none;
        }
        /* Pulsing "live" indicator in the header — signals that the board
           is auto-refreshing even when nothing on screen has changed yet. */
        .live-dot { animation: live-pulse 1.6s ease-in-out infinite; }
        @keyframes live-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.35; transform: scale(0.85); }
        }
        /* Marquee scroll for long destination strings on small cards. */
        .marquee { display: inline-block; white-space: nowrap; animation: marquee 18s linear infinite; }
        @keyframes marquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        /* Hide scrollbars on the lane columns — kiosk mode looks cleaner
           without them, content sized to fit the viewport. */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { scrollbar-width: none; }
    </style>
</head>
<body class="h-full font-sans text-slate-100 selection:bg-cyan-400 selection:text-slate-950">
    {{ $slot }}

    @livewireScripts
</body>
</html>
