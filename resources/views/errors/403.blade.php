<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access denied · TRIDENT</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <div class="min-h-full flex flex-col justify-center px-6 py-12">
        <div class="mx-auto w-full max-w-md">
            <div class="flex justify-center">
                <img src="/logo.png?v=2" alt="TRIDENT" class="h-20 w-auto object-contain" />
            </div>

            <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 pt-6 pb-4 text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-rose-100">
                        <svg viewBox="0 0 24 24" class="h-6 w-6 text-rose-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    </span>
                    <h1 class="mt-4 text-lg font-semibold text-slate-900">Access denied</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $exception?->getMessage() ?: 'You do not have permission to view this page.' }}
                    </p>
                </div>

                @auth
                    <div class="px-6 pb-2">
                        <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-600">
                            Signed in as
                            <span class="font-semibold text-slate-900">{{ auth()->user()->name }}</span>
                            @if(auth()->user()->roles->first())
                                · <span class="uppercase tracking-wide text-[10px] text-slate-500">{{ auth()->user()->roles->first()->name }}</span>
                            @endif
                        </div>
                    </div>
                @endauth

                <div class="px-6 pb-6 pt-4 flex flex-col gap-2">
                    @auth
                        <a href="{{ resolveUserHomePath(auth()->user()) }}"
                           class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition">
                            Back to my dashboard
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                class="w-full inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                                Sign out &amp; use another account
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>

            <p class="mt-6 text-center text-[11px] text-slate-400">TRIDENT · Control &amp; Dispatch Center</p>
        </div>
    </div>
</body>
</html>
