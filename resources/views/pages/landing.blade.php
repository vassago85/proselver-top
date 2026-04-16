<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Proselver Trident — the operational command center for vehicle movement. Control, dispatch and deliver with precision.">
    <title>Proselver Trident — Control • Dispatch • Deliver</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --brand: #2563eb; --brand-dark: #1e3a8a; --ink: #0b1220; }
        html, body { font-feature-settings: "ss01", "cv11"; }
        .grid-bg {
            background-image:
                linear-gradient(to right, rgba(15, 23, 42, 0.06) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(15, 23, 42, 0.06) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, #000 40%, transparent 85%);
            -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, #000 40%, transparent 85%);
        }
        .hero-glow {
            background: radial-gradient(60% 50% at 50% 0%, rgba(37, 99, 235, 0.18) 0%, rgba(37, 99, 235, 0) 70%);
        }
        .trident-tick {
            stroke-dasharray: 4 6;
            animation: tick-move 6s linear infinite;
        }
        @keyframes tick-move {
            to { stroke-dashoffset: -100; }
        }
        .node-pulse {
            animation: node-pulse 2.6s ease-in-out infinite;
        }
        @keyframes node-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.55; transform: scale(1.08); }
        }
        .reveal-up { opacity: 0; transform: translateY(12px); transition: opacity .7s ease, transform .7s ease; }
        .reveal-up.in { opacity: 1; transform: none; }
    </style>
</head>
<body class="bg-white text-slate-900 antialiased selection:bg-blue-600 selection:text-white">

    {{-- ============================================================== --}}
    {{-- NAV                                                             --}}
    {{-- ============================================================== --}}
    <header class="fixed inset-x-0 top-0 z-40 backdrop-blur-md bg-white/75 border-b border-slate-200/70">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="#top" class="flex items-center gap-2.5 group">
                <img src="/logo.png" alt="Proselver Trident" class="h-10 w-auto object-contain" />
                <span class="sr-only">Proselver Trident</span>
            </a>
            <nav class="hidden md:flex items-center gap-8 text-sm text-slate-600">
                <a href="#pillars" class="hover:text-slate-900 transition-colors">Platform</a>
                <a href="#features" class="hover:text-slate-900 transition-colors">Features</a>
                <a href="#how" class="hover:text-slate-900 transition-colors">How it works</a>
                <a href="#workflow" class="hover:text-slate-900 transition-colors">OEM workflow</a>
                <a href="#proof" class="hover:text-slate-900 transition-colors">Proof</a>
            </nav>
            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="text-sm font-medium text-slate-700 hover:text-slate-900 transition-colors">Sign in</a>
                <a href="#contact" class="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-slate-900 hover:bg-slate-800 px-3.5 py-2 rounded-lg transition-colors">
                    Book a walkthrough
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </header>

    <main id="top" class="pt-16">

    {{-- ============================================================== --}}
    {{-- HERO                                                            --}}
    {{-- ============================================================== --}}
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 grid-bg pointer-events-none"></div>
        <div class="absolute inset-x-0 top-0 h-[520px] hero-glow pointer-events-none"></div>

        <div class="relative mx-auto max-w-7xl px-6 lg:px-8 pt-20 lg:pt-28 pb-20 lg:pb-28">
            <div class="mx-auto max-w-3xl text-center">
                <div class="flex justify-center">
                    <img src="/logo.png" alt="Proselver Trident — Control • Dispatch • Deliver" class="h-40 sm:h-48 lg:h-56 w-auto object-contain drop-shadow-sm" />
                </div>
                <div class="mt-8 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/60 backdrop-blur px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Built for OEM-linked movement operations
                </div>
                <h1 class="mt-6 text-3xl sm:text-4xl lg:text-5xl font-semibold tracking-tight text-slate-900 leading-[1.1]">
                    The command center for
                    <span class="relative inline-block">
                        <span class="relative z-10 bg-gradient-to-r from-slate-900 via-blue-700 to-slate-900 bg-clip-text text-transparent">vehicle movement.</span>
                    </span>
                </h1>
                <p class="mt-6 text-lg text-slate-600 leading-relaxed">
                    Proselver Trident is the operations platform for moving vehicles between plants, depots and dealers — with verified dispatch, live execution, and document trails your customers can trust.
                </p>
                <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="#contact" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors">
                        Request a live demo
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                        Sign in to Trident
                    </a>
                </div>
                <p class="mt-6 text-xs text-slate-500">No credit card. Built for dispatch teams, operations controllers and customer coordinators.</p>
            </div>

            {{-- HERO VISUAL: stylised operations graph --}}
            <div class="relative mx-auto mt-16 max-w-5xl">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 overflow-hidden">
                    <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50/50 px-4 py-2.5">
                        <span class="h-2.5 w-2.5 rounded-full bg-rose-300"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-300"></span>
                        <span class="ml-3 text-xs font-medium tracking-wide text-slate-500">trident / operations / live</span>
                        <span class="ml-auto text-[10px] font-medium text-slate-400">UTC+02:00</span>
                    </div>
                    <div class="grid grid-cols-12 gap-0">
                        {{-- Left rail: status counts --}}
                        <div class="col-span-12 md:col-span-3 border-b md:border-b-0 md:border-r border-slate-100 p-5 space-y-3">
                            @php
                                $tiles = [
                                    ['label' => 'Awaiting confirmation', 'val' => '08', 'dot' => 'bg-amber-500'],
                                    ['label' => 'Planned', 'val' => '23', 'dot' => 'bg-blue-500'],
                                    ['label' => 'In transit', 'val' => '14', 'dot' => 'bg-indigo-500'],
                                    ['label' => 'Delivered today', 'val' => '31', 'dot' => 'bg-emerald-500'],
                                ];
                            @endphp
                            @foreach($tiles as $t)
                                <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-2 w-2 rounded-full {{ $t['dot'] }} node-pulse"></span>
                                        <span class="text-[11px] font-medium tracking-wide text-slate-600 uppercase">{{ $t['label'] }}</span>
                                    </div>
                                    <span class="text-sm font-semibold tabular-nums text-slate-900">{{ $t['val'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        {{-- Center: movement graph --}}
                        <div class="col-span-12 md:col-span-6 p-5 relative min-h-[260px] bg-gradient-to-br from-white to-slate-50/50">
                            <div class="flex items-center justify-between mb-4">
                                <span class="text-xs font-semibold text-slate-700">Active movements</span>
                                <span class="text-[10px] font-medium tracking-widest uppercase text-slate-400">live</span>
                            </div>
                            <svg viewBox="0 0 400 180" class="w-full h-full">
                                <defs>
                                    <linearGradient id="flowLine" x1="0" x2="1" y1="0" y2="0">
                                        <stop offset="0%" stop-color="#94a3b8" stop-opacity="0.3"/>
                                        <stop offset="50%" stop-color="#2563eb" stop-opacity="0.9"/>
                                        <stop offset="100%" stop-color="#10b981" stop-opacity="0.9"/>
                                    </linearGradient>
                                </defs>
                                {{-- Nodes: Received → Confirmed → Dispatched → Delivered --}}
                                <g font-family="ui-sans-serif, system-ui" font-size="9" fill="#475569">
                                    <text x="40" y="28" text-anchor="middle">Plant</text>
                                    <text x="160" y="28" text-anchor="middle">Dispatch</text>
                                    <text x="280" y="28" text-anchor="middle">Driver</text>
                                    <text x="370" y="28" text-anchor="middle">Dealer</text>
                                </g>
                                {{-- Line --}}
                                <path d="M 40 90 C 100 90, 100 90, 160 90 S 220 90, 280 90 S 340 90, 370 90"
                                      fill="none" stroke="url(#flowLine)" stroke-width="2" stroke-linecap="round"/>
                                <path d="M 40 90 C 100 90, 100 90, 160 90 S 220 90, 280 90 S 340 90, 370 90"
                                      fill="none" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round" class="trident-tick" opacity="0.8"/>
                                {{-- Nodes --}}
                                <g>
                                    <circle cx="40" cy="90" r="6" fill="#0f172a"/>
                                    <circle cx="160" cy="90" r="7" fill="#2563eb" class="node-pulse"/>
                                    <circle cx="280" cy="90" r="6" fill="#6366f1"/>
                                    <circle cx="370" cy="90" r="6" fill="#10b981"/>
                                </g>
                                {{-- Labels below --}}
                                <g font-family="ui-sans-serif, system-ui" font-size="8" fill="#64748b">
                                    <text x="40" y="115" text-anchor="middle">Received</text>
                                    <text x="160" y="115" text-anchor="middle">Confirmed</text>
                                    <text x="280" y="115" text-anchor="middle">Collected</text>
                                    <text x="370" y="115" text-anchor="middle">Delivered</text>
                                </g>
                                {{-- Secondary branch (FAW confirmation loop) --}}
                                <path d="M 100 90 Q 130 140 160 90" fill="none" stroke="#f59e0b" stroke-width="1.25" stroke-dasharray="3 3" opacity="0.8"/>
                                <text x="130" y="158" text-anchor="middle" font-family="ui-sans-serif, system-ui" font-size="7.5" fill="#b45309">Customer confirmation</text>
                            </svg>
                        </div>

                        {{-- Right: latest movement card --}}
                        <div class="col-span-12 md:col-span-3 border-t md:border-t-0 md:border-l border-slate-100 p-5">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">Latest movement</p>
                            <p class="mt-2 text-sm font-semibold text-slate-900">TRD-48921</p>
                            <p class="text-xs text-slate-500">FAW Coega → JHB Dealer</p>
                            <div class="mt-3 rounded-lg bg-slate-50 border border-slate-100 p-3 space-y-2">
                                <div class="flex justify-between text-[11px]"><span class="text-slate-500">Driver</span><span class="font-medium text-slate-800">T. Molefe</span></div>
                                <div class="flex justify-between text-[11px]"><span class="text-slate-500">VIN</span><span class="font-mono text-slate-800">LFW••••7291</span></div>
                                <div class="flex justify-between text-[11px]"><span class="text-slate-500">Status</span><span class="font-medium text-emerald-600">In transit</span></div>
                            </div>
                            <button class="mt-3 w-full text-center text-xs font-semibold text-blue-600 hover:text-blue-700">View collection note →</button>
                        </div>
                    </div>
                </div>
                <div class="absolute -inset-x-10 -bottom-10 -z-10 h-40 bg-gradient-to-t from-white via-white to-transparent"></div>
            </div>

            {{-- Customer row --}}
            <div class="mt-14 flex flex-wrap items-center justify-center gap-x-10 gap-y-4 text-slate-400">
                <span class="text-[10px] font-semibold tracking-[0.2em] uppercase">Trusted workflows for</span>
                <span class="text-sm font-semibold text-slate-500 tracking-tight">FAW</span>
                <span class="text-sm font-semibold text-slate-500 tracking-tight">Isuzu</span>
                <span class="text-sm font-semibold text-slate-500 tracking-tight">Powerstar</span>
                <span class="text-sm font-semibold text-slate-500 tracking-tight">Multi-brand dealer groups</span>
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- PILLARS                                                         --}}
    {{-- ============================================================== --}}
    <section id="pillars" class="relative border-t border-slate-100 bg-slate-50/50">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-700">The three prongs</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">One platform. Three operational pillars.</h2>
                <p class="mt-4 text-base text-slate-600">Every vehicle movement inside Trident flows through the same operational engine — so nothing falls between spreadsheets, WhatsApp threads, or a missed phone call.</p>
            </div>

            <div class="mt-14 grid gap-6 lg:grid-cols-3">
                @php
                    $pillars = [
                        [
                            'num' => '01',
                            'title' => 'Booking',
                            'desc' => 'Capture movement orders from OEMs, dealers and internal controllers. Customer-specific fields, brand limits and workflow flags are built in — without a workflow engine to maintain.',
                            'icon' => '<path d="M9 2h6v4H9z"/><rect x="4" y="6" width="16" height="16" rx="2"/><path d="M8 12h8"/><path d="M8 16h5"/>',
                        ],
                        [
                            'num' => '02',
                            'title' => 'Dispatch',
                            'desc' => 'Plan, assign and release in one pass. Allocate drivers, check license and PDP validity, and generate a verified collection note the moment the truck is ready.',
                            'icon' => '<path d="M3 12h13l3-4h2v8h-3a3 3 0 1 1-6 0H9a3 3 0 1 1-6 0Z"/><circle cx="7" cy="18" r="1.5"/><circle cx="16" cy="18" r="1.5"/>',
                        ],
                        [
                            'num' => '03',
                            'title' => 'Deliver',
                            'desc' => 'Track execution from collection to delivered. POD uploads, QR-verified collection notes and status timelines give customers the visibility they used to chase by email.',
                            'icon' => '<path d="M20 6 9 17l-5-5"/>',
                        ],
                    ];
                @endphp
                @foreach($pillars as $p)
                    <div class="group relative rounded-2xl bg-white border border-slate-200 p-7 hover:border-slate-300 hover:shadow-xl hover:shadow-slate-900/5 transition-all">
                        <div class="flex items-start justify-between">
                            <span class="h-11 w-11 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    {!! $p['icon'] !!}
                                </svg>
                            </span>
                            <span class="text-xs font-mono font-semibold text-slate-300 tabular-nums">{{ $p['num'] }}</span>
                        </div>
                        <h3 class="mt-6 text-xl font-semibold text-slate-900">{{ $p['title'] }}</h3>
                        <p class="mt-2.5 text-sm text-slate-600 leading-relaxed">{{ $p['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- WHY IT MATTERS                                                  --}}
    {{-- ============================================================== --}}
    <section class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-16">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-700">Why it matters</p>
                    <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">Fewer missed calls. Cleaner handovers. Verified delivery.</h2>
                    <p class="mt-5 text-base text-slate-600 leading-relaxed">
                        Transport operations live and die on information: who is confirming the load, which driver has been assigned, which document is missing, which vehicle left the yard. Trident makes that information the single source of truth — not an email thread.
                    </p>
                    <a href="#contact" class="mt-8 inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
                        See it in your environment
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>
                <div class="lg:col-span-7 grid sm:grid-cols-2 gap-4">
                    @php
                        $values = [
                            ['t' => 'Real operational control', 'd' => 'Every order, confirmation, assignment and document is auditable against a movement — not a person.'],
                            ['t' => 'Faster dispatch decisions', 'd' => 'See who is confirmed, who is not, which drivers are clear to collect, and which orders still need intervention.'],
                            ['t' => 'Cleaner document handling', 'd' => 'POs, collection notes and PODs live on the movement record. Customers pull their own. You stop being the inbox.'],
                            ['t' => 'Customer-specific workflows', 'd' => 'Some OEMs insist on confirming each load before dispatch. Standard accounts skip it. Both supported by default.'],
                        ];
                    @endphp
                    @foreach($values as $v)
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <div class="h-9 w-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center mb-4">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <h3 class="text-sm font-semibold text-slate-900">{{ $v['t'] }}</h3>
                            <p class="mt-1.5 text-sm text-slate-600 leading-relaxed">{{ $v['d'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- FEATURES                                                        --}}
    {{-- ============================================================== --}}
    <section id="features" class="relative border-t border-slate-100 bg-slate-900 text-slate-100 overflow-hidden">
        <div class="absolute inset-0 opacity-20 pointer-events-none"
             style="background-image: radial-gradient(1px 1px at 20px 20px, rgba(255,255,255,.35) 1px, transparent 0); background-size: 40px 40px;"></div>
        <div class="relative mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-300">Built for operations</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-white">Everything the control room actually uses.</h2>
                <p class="mt-4 text-slate-300">No bloat. No deprecated modules you have to pretend aren't there. Just the seven capabilities dispatch teams rely on every shift.</p>
            </div>

            <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @php
                    $features = [
                        ['t' => 'Order intake', 'd' => 'Receive, capture and validate movement requests from OEMs, dealers and internal controllers.', 'i' => '<path d="M4 4h16v6H4z"/><path d="M4 14h10v6H4z"/><path d="M20 14v6"/><path d="M17 17h6"/>'],
                        ['t' => 'Dispatch planning', 'd' => 'Planning queue with customer, route and readiness visibility. Nothing ships that isn\'t ready.', 'i' => '<path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/>'],
                        ['t' => 'Driver allocation', 'd' => 'Assign by location, license class, PDP validity and current availability — not by memory.', 'i' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>'],
                        ['t' => 'Collection notes', 'd' => 'Generated on demand. Driver ID, cellphone, chassis, location, reference — signed by a verifiable QR.', 'i' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>'],
                        ['t' => 'POD & documents', 'd' => 'Customers access their own documents. You stop emailing attachments. Everything is on the record.', 'i' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'],
                        ['t' => 'Customer workflows', 'd' => 'Per-account configuration. FAW-style confirmation loops and standard workflows, side by side.', 'i' => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>'],
                        ['t' => 'QR verification', 'd' => 'Every collection note carries a signed QR. Scan it, see the movement. No more forged paperwork.', 'i' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3z"/><path d="M17 17h4v4h-4z"/>'],
                        ['t' => 'Driver expiry tracking', 'd' => 'License and PDP expiries surface at the right time — before the driver is put on a load they can\'t legally carry.', 'i' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
                        ['t' => 'Developer impersonation', 'd' => 'Test every role end-to-end with a safe impersonation banner. No shadow accounts. No risky logins.', 'i' => '<path d="M16 18l6-6-6-6"/><path d="M8 6 2 12l6 6"/><path d="M14 4l-4 16"/>'],
                    ];
                @endphp
                @foreach($features as $f)
                    <div class="group rounded-xl border border-white/10 bg-white/[0.04] hover:bg-white/[0.07] p-6 transition-colors">
                        <div class="h-10 w-10 rounded-lg bg-blue-500/15 text-blue-300 flex items-center justify-center mb-5 ring-1 ring-blue-400/20">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                {!! $f['i'] !!}
                            </svg>
                        </div>
                        <h3 class="text-base font-semibold text-white">{{ $f['t'] }}</h3>
                        <p class="mt-2 text-sm text-slate-400 leading-relaxed">{{ $f['d'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- HOW IT WORKS                                                    --}}
    {{-- ============================================================== --}}
    <section id="how" class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-700">How it works</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">Four steps. No heroics.</h2>
                <p class="mt-4 text-slate-600">A controller can move an order from intake to collection in under two minutes. Customers can see exactly where it is without calling you.</p>
            </div>

            <ol class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                @php
                    $steps = [
                        ['n' => '01', 't' => 'Receive the movement', 'd' => 'An OEM, dealer or internal controller captures the order. Brand, route and reference are validated against the account.'],
                        ['n' => '02', 't' => 'Confirm & plan', 'd' => 'For accounts that need it, the customer dispatcher confirms readiness. Operations plans the movement and assigns the controller.'],
                        ['n' => '03', 't' => 'Allocate & release', 'd' => 'A driver with valid license and PDP is allocated. A collection note with QR verification is generated.'],
                        ['n' => '04', 't' => 'Execute & deliver', 'd' => 'Statuses update through collection, in transit and delivered. POD is uploaded. The customer sees it live.'],
                    ];
                @endphp
                @foreach($steps as $i => $s)
                    <li class="relative rounded-2xl border border-slate-200 bg-white p-6">
                        <span class="text-xs font-mono font-semibold text-blue-600 tabular-nums">{{ $s['n'] }}</span>
                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $s['t'] }}</h3>
                        <p class="mt-2 text-sm text-slate-600 leading-relaxed">{{ $s['d'] }}</p>
                        @if(!$loop->last)
                            <span class="hidden lg:block absolute top-10 -right-[13px] text-slate-300">
                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- OEM / CUSTOMER WORKFLOW FLEXIBILITY                             --}}
    {{-- ============================================================== --}}
    <section id="workflow" class="relative border-t border-slate-100 bg-gradient-to-b from-white to-slate-50">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-700">Customer workflows</p>
                    <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">Different OEMs. Different rules. Same platform.</h2>
                    <p class="mt-5 text-slate-600 leading-relaxed">
                        Some customers need to confirm a vehicle is physically ready before anyone dispatches. Others don't. Trident supports both as first-class workflows — configured on the account, not hardcoded in logic.
                    </p>
                    <ul class="mt-6 space-y-3 text-sm text-slate-700">
                        <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 rounded-full bg-blue-600 shrink-0"></span>Per-account workflow type — e.g. <span class="font-mono text-xs bg-slate-100 px-1.5 py-0.5 rounded">standard</span> or <span class="font-mono text-xs bg-slate-100 px-1.5 py-0.5 rounded">faw</span>.</li>
                        <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 rounded-full bg-blue-600 shrink-0"></span>Customer dispatchers scoped to a specific location (Coega, Johannesburg, etc).</li>
                        <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 rounded-full bg-blue-600 shrink-0"></span>Brand-level access limits per customer — no irrelevant options in the order form.</li>
                        <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 rounded-full bg-blue-600 shrink-0"></span>Confirmation exceptions handled gracefully: truck missing, damaged, not ready.</li>
                    </ul>
                </div>

                <div class="lg:col-span-7">
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                        {{-- Standard flow --}}
                        <div class="p-6 border-b border-slate-100">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Standard account</p>
                                    <p class="text-sm font-semibold text-slate-900">Isuzu · Powerstar · dealer-originated orders</p>
                                </div>
                                <span class="text-[10px] font-semibold tracking-wider uppercase px-2 py-1 rounded-full bg-emerald-50 text-emerald-700">default</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs font-medium text-slate-700 overflow-x-auto">
                                <span class="px-3 py-1.5 rounded-lg bg-slate-100 whitespace-nowrap">Received</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 whitespace-nowrap">Confirmed</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 whitespace-nowrap">Planned</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-slate-100 whitespace-nowrap">Driver assigned</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 whitespace-nowrap">Delivered</span>
                            </div>
                        </div>
                        {{-- FAW flow --}}
                        <div class="p-6 bg-amber-50/40">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-700">FAW account</p>
                                    <p class="text-sm font-semibold text-slate-900">Requires customer confirmation before dispatch</p>
                                </div>
                                <span class="text-[10px] font-semibold tracking-wider uppercase px-2 py-1 rounded-full bg-amber-100 text-amber-800">workflow: faw</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs font-medium text-slate-700 overflow-x-auto">
                                <span class="px-3 py-1.5 rounded-lg bg-slate-100 whitespace-nowrap">Received</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-amber-100 text-amber-800 whitespace-nowrap">Awaiting customer confirmation</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 whitespace-nowrap">Confirmed</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 whitespace-nowrap">Planned</span>
                                <span class="text-slate-300">→</span>
                                <span class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 whitespace-nowrap">Delivered</span>
                            </div>
                            <p class="mt-4 text-xs text-amber-900/70">Customer dispatchers (e.g. FAW Coega) confirm the vehicle is physically ready — or flag it as missing, damaged, or not ready — before dispatch proceeds.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- PROOF / DOCUMENTS                                               --}}
    {{-- ============================================================== --}}
    <section id="proof" class="border-t border-slate-100">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-20 lg:py-28">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
                <div class="lg:col-span-5 lg:order-2">
                    <p class="text-xs font-semibold tracking-[0.2em] uppercase text-blue-700">Proof, not promises</p>
                    <h2 class="mt-3 text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">Every movement leaves a verifiable trail.</h2>
                    <p class="mt-5 text-slate-600 leading-relaxed">
                        A collection note is not a piece of paper. In Trident, it's a signed record tied to a driver, a vehicle, a location, and a scannable QR code — so the person receiving the load can confirm it's real in a second.
                    </p>
                    <dl class="mt-8 space-y-5">
                        <div class="flex gap-4">
                            <dt class="shrink-0 h-10 w-10 rounded-lg bg-slate-900 text-white flex items-center justify-center">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3z"/><path d="M17 17h4v4h-4z"/></svg>
                            </dt>
                            <dd>
                                <p class="font-semibold text-slate-900">QR verification</p>
                                <p class="text-sm text-slate-600 mt-1">Scan the note — a public verification page confirms the movement, driver and destination match.</p>
                            </dd>
                        </div>
                        <div class="flex gap-4">
                            <dt class="shrink-0 h-10 w-10 rounded-lg bg-slate-900 text-white flex items-center justify-center">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            </dt>
                            <dd>
                                <p class="font-semibold text-slate-900">Document record</p>
                                <p class="text-sm text-slate-600 mt-1">POs, collection notes and PODs attach to the movement — not to a person's inbox.</p>
                            </dd>
                        </div>
                        <div class="flex gap-4">
                            <dt class="shrink-0 h-10 w-10 rounded-lg bg-slate-900 text-white flex items-center justify-center">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </dt>
                            <dd>
                                <p class="font-semibold text-slate-900">Audit trail</p>
                                <p class="text-sm text-slate-600 mt-1">Every status change, assignment and confirmation is timestamped against the user who did it.</p>
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- Sample collection note mock --}}
                <div class="lg:col-span-7 lg:order-1">
                    <div class="relative">
                        <div class="absolute -inset-4 bg-gradient-to-br from-blue-100/60 to-slate-100 rounded-3xl -z-10"></div>
                        <div class="rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5 overflow-hidden">
                            <div class="flex items-center justify-between bg-slate-900 text-white px-6 py-4">
                                <div>
                                    <p class="text-[10px] font-semibold tracking-[0.2em] uppercase text-blue-300">Collection Note</p>
                                    <p class="text-sm font-semibold mt-0.5">TRD-48921</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-semibold tracking-[0.2em] uppercase text-slate-400">Issued</p>
                                    <p class="text-xs font-medium mt-0.5">16 Apr 2026 · 09:42</p>
                                </div>
                            </div>
                            <div class="p-6 grid grid-cols-5 gap-6">
                                <div class="col-span-3 space-y-4 text-sm">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">Driver</p>
                                        <p class="text-slate-900 font-medium mt-1">Thabo Molefe</p>
                                        <p class="text-xs text-slate-500">ID 8501015123081 · 082 123 4567</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">Vehicle</p>
                                        <p class="text-slate-900 font-medium mt-1">FAW J6P · 6x4 Truck Tractor</p>
                                        <p class="text-xs text-slate-500 font-mono">VIN LFWSRXSE7MG007291</p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">From</p>
                                            <p class="text-slate-900 font-medium mt-1 text-sm">FAW Coega Plant</p>
                                            <p class="text-xs text-slate-500">Gqeberha</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">To</p>
                                            <p class="text-slate-900 font-medium mt-1 text-sm">FAW Sandton</p>
                                            <p class="text-xs text-slate-500">Johannesburg</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-2 flex flex-col items-end">
                                    {{-- QR mock --}}
                                    <div class="h-28 w-28 bg-white border border-slate-200 rounded-lg p-2">
                                        <svg viewBox="0 0 40 40" class="h-full w-full" fill="#0f172a">
                                            <rect x="0" y="0" width="12" height="12"/><rect x="28" y="0" width="12" height="12"/><rect x="0" y="28" width="12" height="12"/>
                                            <rect x="3" y="3" width="6" height="6" fill="white"/><rect x="31" y="3" width="6" height="6" fill="white"/><rect x="3" y="31" width="6" height="6" fill="white"/>
                                            <rect x="5" y="5" width="2" height="2"/><rect x="33" y="5" width="2" height="2"/><rect x="5" y="33" width="2" height="2"/>
                                            <rect x="16" y="4" width="2" height="2"/><rect x="20" y="4" width="4" height="2"/><rect x="14" y="8" width="2" height="4"/><rect x="18" y="8" width="2" height="2"/><rect x="22" y="10" width="4" height="2"/>
                                            <rect x="4" y="16" width="2" height="2"/><rect x="8" y="14" width="4" height="4"/><rect x="14" y="18" width="2" height="2"/><rect x="18" y="14" width="2" height="6"/><rect x="22" y="16" width="6" height="2"/><rect x="30" y="18" width="4" height="4"/>
                                            <rect x="16" y="22" width="2" height="4"/><rect x="20" y="24" width="4" height="2"/><rect x="26" y="22" width="2" height="6"/><rect x="32" y="24" width="2" height="2"/>
                                            <rect x="14" y="30" width="2" height="4"/><rect x="18" y="30" width="4" height="2"/><rect x="24" y="32" width="2" height="4"/><rect x="28" y="30" width="4" height="2"/><rect x="34" y="34" width="2" height="4"/>
                                        </svg>
                                    </div>
                                    <p class="mt-3 text-[10px] text-slate-500 text-right leading-tight">Scan to verify<br><span class="font-mono text-slate-400">proselver.co.za/verify</span></p>
                                </div>
                            </div>
                            <div class="border-t border-slate-100 px-6 py-3 bg-slate-50/70 flex items-center justify-between">
                                <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-emerald-700">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    Signed · valid
                                </span>
                                <span class="text-[10px] text-slate-400">Proselver Trident · auto-generated</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================== --}}
    {{-- FINAL CTA                                                       --}}
    {{-- ============================================================== --}}
    <section id="contact" class="border-t border-slate-100 bg-slate-900 text-white">
        <div class="mx-auto max-w-5xl px-6 lg:px-8 py-20 lg:py-24 text-center">
            <p class="text-xs font-semibold tracking-[0.25em] uppercase text-blue-300">Ready when you are</p>
            <h2 class="mt-4 text-3xl sm:text-5xl font-semibold tracking-tight">Put your dispatch under one roof.</h2>
            <p class="mt-5 text-slate-300 max-w-2xl mx-auto text-lg leading-relaxed">
                We'll stand up a private instance of Proselver Trident, configure your accounts and workflows, and run your first movements alongside your team.
            </p>
            <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="mailto:hello@proselver.co.za?subject=Proselver%20Trident%20demo" class="inline-flex items-center gap-2 rounded-lg bg-blue-500 hover:bg-blue-400 px-6 py-3.5 text-sm font-semibold text-white transition-colors">
                    Book a walkthrough
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg border border-white/20 bg-white/[0.04] hover:bg-white/[0.08] px-6 py-3.5 text-sm font-semibold text-white transition-colors">
                    Sign in to Trident
                </a>
            </div>
            <p class="mt-10 text-xs font-semibold tracking-[0.25em] uppercase text-blue-300">Control • Dispatch • Deliver</p>
        </div>
    </section>

    </main>

    {{-- ============================================================== --}}
    {{-- FOOTER                                                          --}}
    {{-- ============================================================== --}}
    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 py-10 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <img src="/logo.png" alt="Proselver Trident" class="h-9 w-auto object-contain" />
                <span class="hidden sm:inline text-xs text-slate-400">· ProSelver — Prospect • Select • Verify</span>
            </div>
            <p class="text-xs text-slate-500">© {{ date('Y') }} Proselver. Built for operations that don't miss loads.</p>
        </div>
    </footer>

    <script>
        // Subtle reveal-on-scroll for any future-tagged section
        const io = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { threshold: 0.08 });
        document.querySelectorAll('.reveal-up').forEach(el => io.observe(el));
    </script>
</body>
</html>
