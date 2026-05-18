@php
    $user = auth()->user();
    $isInternal = $user->isInternal();
    $isCustomer = $user->isCustomer();
    $isDealer = $user->isDealer() && !$isCustomer;
    $isOem = $user->isOem() && !$isCustomer;
    $isDriver = $user->isDriver();
    $isDeveloper = $user->isDeveloper();
    $isSuperAdmin = $user->isSuperAdmin();
    $isOpsController = $user->isOperationsController();
    $isDispatcher = $user->hasRole('dispatcher');
    $isOwner = $user->isOwner();

    // OEMs hold customer-tier roles for tenanting, so $isCustomer is true.
    // Treat the company type as the source of truth for the *portal* label
    // we present so an FAW or Isuzu operator sees "OEM" branding even
    // though the underlying role slug is customer_owner.  Same trick for
    // body-builder tenants — their roles are tier=customer so they also
    // pass $isCustomer, but the portal label / sidebar branch they get
    // is BB-specific.
    $userCompanyType = $isCustomer ? optional($user->companies()->first())->type : null;
    $isOemCustomer = $isCustomer && $userCompanyType === \App\Models\Company::TYPE_OEM;
    $isDealerCustomer = $isCustomer && $userCompanyType === \App\Models\Company::TYPE_DEALER;
    $isBodyBuilderTenant = $userCompanyType === \App\Models\Company::TYPE_BODY_BUILDER;

    // Portal subtitle for the sidebar brand area
    $portalLabel = match(true) {
        $isDeveloper => 'Developer',
        $isSuperAdmin => 'Super Admin',
        $isOpsController => 'Operations',
        $isDispatcher => 'Dispatch',
        $isOwner => 'Owner',
        $isBodyBuilderTenant => 'Body Builder',
        $isOemCustomer => 'OEM Portal',
        $isDealerCustomer => 'Dealer Portal',
        $isCustomer => 'Customer Portal',
        $isDealer => 'Dealer Portal',
        $isOem => 'OEM Portal',
        $isDriver => 'Driver',
        default => 'Internal',
    };
@endphp

<div class="flex grow flex-col gap-y-4 overflow-y-auto bg-white border-r border-slate-200 pb-4">
    {{-- Brand --}}
    <div class="flex shrink-0 items-center gap-3 px-5 pt-5">
        <a href="{{ route('home') }}" class="flex items-center gap-3 group">
            <img src="/logo.png?v=2" alt="TRIDENT Control &amp; Dispatch Center" class="h-11 w-auto object-contain" />
        </a>
    </div>

    {{-- Portal badge --}}
    <div class="px-5">
        <div class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50/60 px-2.5 py-1 text-[10px] font-semibold tracking-[0.2em] uppercase text-slate-500">
            <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
            {{ $portalLabel }}
        </div>
    </div>

    <nav class="flex flex-1 flex-col px-3">
        <ul role="list" class="flex flex-1 flex-col gap-y-6">

            {{-- ============================================================ --}}
            {{-- INTERNAL / ADMIN PORTAL                                       --}}
            {{-- ============================================================ --}}
            @if($isInternal)

                {{-- OVERVIEW --}}
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- BOOKING --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Booking</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.orders.index')" :active="request()->routeIs('admin.orders.index') || request()->routeIs('admin.orders.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Orders
                        </x-sidebar-link>

                        {{-- Bulk Upload sits inside Booking next to Orders so ops controllers
                             find it next to where they spend their day. Restricted to roles
                             that already book on customers' behalf — drivers / dispatchers
                             don't onboard OEM movement files. --}}
                        @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                        <x-sidebar-link :href="route('admin.orders.bulk-upload')" :active="request()->routeIs('admin.orders.bulk-upload')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg></x-slot:icon>
                            Bulk Upload
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('admin.documents.index')" :active="request()->routeIs('admin.documents.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
                        </x-sidebar-link>

                        {{-- Petty cash review queue. Driver-submitted slip
                             approvals + reimbursement tracking. Internal
                             staff + platform-owner only — gated by route
                             middleware AND PettyCashEntryPolicy at the
                             page level. --}}
                        <x-sidebar-link :href="route('admin.petty-cash.index')" :active="request()->routeIs('admin.petty-cash.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></x-slot:icon>
                            Petty Cash
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- DISPATCH --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isDispatcher || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Dispatch</p>
                    <ul role="list" class="space-y-0.5">
                        @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                        <x-sidebar-link :href="route('admin.planning')" :active="request()->routeIs('admin.planning')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg></x-slot:icon>
                            Planning Queue
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('admin.dispatch')" :active="request()->routeIs('admin.dispatch')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></x-slot:icon>
                            Dispatch Board
                        </x-sidebar-link>

                        {{-- Trip planner (cross-company). Ops sees every
                             dealer's and ProSelver's own trips here; the
                             planner UI is unscoped so ops can edit on a
                             dealer's behalf. --}}
                        <x-sidebar-link :href="route('admin.trips.index')" :active="request()->routeIs('admin.trips.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg></x-slot:icon>
                            Trips
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.drivers.index')" :active="request()->routeIs('admin.drivers.index') || request()->routeIs('admin.drivers.create') || request()->routeIs('admin.drivers.edit')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>

                        {{-- Driver Ops — fleet-control view (who is moving / idle /
                             late / overloaded). Kept separate from the Drivers
                             roster above so HR/compliance and ops stay distinct. --}}
                        <x-sidebar-link :href="route('admin.drivers.operations')" :active="request()->routeIs('admin.drivers.operations')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
                            Driver Ops
                        </x-sidebar-link>

                        {{-- Wallboard — second-screen ops view designed for a
                             dispatch TV. Three panels (drivers / map / events)
                             on a 5-second poll; intentionally lighter on chrome
                             than Driver Ops so it reads from across the room. --}}
                        <x-sidebar-link :href="route('admin.wallboard')" :active="request()->routeIs('admin.wallboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg></x-slot:icon>
                            Wallboard
                        </x-sidebar-link>

                        {{-- Live Display — chromeless 3-lane board (waiting /
                             in transit / delivered today) the dealer and OEM
                             portals also expose.  For ops / owner / super
                             admin / developer it runs system-wide (every
                             customer's active jobs in one TV view), with the
                             owning customer name on each card.  Opens in a
                             new tab so the dispatcher can drop it on a
                             wall-mounted monitor without losing their main
                             session. --}}
                        <x-sidebar-link :href="route('admin.live-display')" target="_blank" rel="noopener" :active="request()->routeIs('admin.live-display')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></x-slot:icon>
                            Live Display
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- FLEET --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Fleet</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.vehicles.index')" :active="request()->routeIs('admin.vehicles.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg></x-slot:icon>
                            Vehicles
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('admin.deliveries')" :active="request()->routeIs('admin.deliveries')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></x-slot:icon>
                            Deliveries
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('admin.damage')" :active="request()->routeIs('admin.damage')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></x-slot:icon>
                            Damage Reports
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- COMPANIES (dealers, OEMs, body builders, internal tenants, etc.) --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Companies</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.companies.index')" :active="request()->routeIs('admin.companies.*') || request()->routeIs('admin.customers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg></x-slot:icon>
                            Companies
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- CATALOGUE --}}
                {{-- Brands & Models is exposed here (not only buried under Settings)
                     because operational staff and Owners extend the model list
                     regularly — every new FAW / Isuzu variant, every new OEM. --}}
                @if($isDeveloper || $isSuperAdmin || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Catalogue</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.settings.brands')" :active="request()->routeIs('admin.settings.brands')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/></svg></x-slot:icon>
                            Brands &amp; Models
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- ADMIN --}}
                {{-- Owner and ops controllers get the Team (user management) and
                     Audit Log links so the business owner can self-serve onboarding
                     ops/admin/finance/accounting staff without pulling in a
                     super_admin. The Settings area stays dev/super_admin only
                     because it exposes integrations & role definitions. --}}
                @if($isDeveloper || $isSuperAdmin || $isOwner || $isOpsController)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Administration</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>

                        @if($isDeveloper || $isSuperAdmin)
                        <x-sidebar-link :href="route('admin.settings.index')" :active="request()->routeIs('admin.settings.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></x-slot:icon>
                            Settings
                        </x-sidebar-link>
                        @elseif($isOwner)
                        {{-- Owners don't see the full Settings surface (integrations /
                             role definitions stay dev/super_admin only) but they do
                             get a direct link to the one thing they curate — who on
                             the team is trusted to cancel a confirmed order. --}}
                        <x-sidebar-link :href="route('admin.settings.cancellation')" :active="request()->routeIs('admin.settings.cancellation')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></x-slot:icon>
                            Cancellation Permissions
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('admin.audit-log')" :active="request()->routeIs('admin.audit-log')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
                            Audit Log
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- BODY BUILDER PORTAL                                           --}}
            {{-- ============================================================ --}}
            {{-- BB tenants pass $isCustomer (their roles are tier=customer),
                 so this branch MUST come before the customer one and is
                 mutually exclusive — once we render the BB sidebar we skip
                 everything else with the matching @elseif chain below. --}}
            @if($isBodyBuilderTenant)

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Workshop</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('body-builder.dashboard')" :active="request()->routeIs('body-builder.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('body-builder.jobs.index')" :active="request()->routeIs('body-builder.jobs.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg></x-slot:icon>
                            Vehicles
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('body-builder.requests.index')" :active="request()->routeIs('body-builder.requests.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg></x-slot:icon>
                            My Requests
                        </x-sidebar-link>
                    </ul>
                </li>

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Relationships</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('body-builder.dealers.index')" :active="request()->routeIs('body-builder.dealers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg></x-slot:icon>
                            Linked Dealers
                        </x-sidebar-link>
                    </ul>
                </li>

                @if($user->canManageCompanyData())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Workspace</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Reuses the tenant-scoped customer team /
                             locations Volt pages — they auto-scope on
                             the user's primary company, so a BB tenant
                             editing here only sees / mutates their own
                             workshops + their own users.  Saves us
                             duplicating two full CRUD pages. --}}
                        <x-sidebar-link :href="route('customer.locations.index')" :active="request()->routeIs('customer.locations.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Locations
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('customer.team.index')" :active="request()->routeIs('customer.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Help</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('body-builder.help')" :active="request()->routeIs('body-builder.help')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg></x-slot:icon>
                            Help
                        </x-sidebar-link>
                    </ul>
                </li>

            @endif

            {{-- ============================================================ --}}
            {{-- CUSTOMER PORTAL                                                --}}
            {{-- ============================================================ --}}
            {{-- BB tenants are excluded explicitly because their roles also
                 satisfy $isCustomer; the BB branch above is the right one
                 for them. --}}
            @if($isCustomer && !$isBodyBuilderTenant)

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Orders</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.dashboard')" :active="request()->routeIs('customer.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>

                        @if($user->hasPermission('submit_booking'))
                        <x-sidebar-link :href="route('customer.orders.create')" :active="request()->routeIs('customer.orders.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Order
                        </x-sidebar-link>
                        @endif

                        {{-- Bulk Upload — gated to account-wide roles. Depot
                             dispatchers can't upload because the file may
                             span multiple branches and dispatchers are
                             pinned to a single branch. Same gate enforced
                             server-side in the Volt component. --}}
                        @if($user->canManageCompanyData())
                        <x-sidebar-link :href="route('customer.orders.bulk-upload')" :active="request()->routeIs('customer.orders.bulk-upload')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg></x-slot:icon>
                            Bulk Upload
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('customer.orders.index')" :active="request()->routeIs('customer.orders.index') || request()->routeIs('customer.orders.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            My Orders
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('customer.stock.at-body-builder')" :active="request()->routeIs('customer.stock.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" x2="12" y1="22.08" y2="12"/></svg></x-slot:icon>
                            Stock In Transit
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- Trips planner — gated to dispatch-capable roles. Drivers
                     also get a "My Day" entry below; depot dispatchers and
                     admins get the full planner. --}}
                @if($user->canPlanMovements() || $user->isDriver())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Trips</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Trip Planner is for dealers running their own driver pool.
                             OEM tenants book ProSelver only — they don't dispatch
                             their own drivers, so the planner has nothing to plan. --}}
                        @if($user->canPlanMovements() && !$isOemCustomer)
                            <x-sidebar-link :href="route('customer.trips.index')" :active="request()->routeIs('customer.trips.index') || request()->routeIs('customer.trips.show') || request()->routeIs('customer.trips.create')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg></x-slot:icon>
                                Trip Planner
                            </x-sidebar-link>
                        @endif
                        @if($user->hasRole('driver'))
                            <x-sidebar-link :href="route('customer.trips.my-day')" :active="request()->routeIs('customer.trips.my-day')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg></x-slot:icon>
                                My Day
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Reports</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.reports.deliveries')" :active="request()->routeIs('customer.reports.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></x-slot:icon>
                            Deliveries
                        </x-sidebar-link>

                        @if($user->hasPermission('view_all_bookings'))
                            {{-- Opens in a new tab so a dispatcher can drop it on
                                 the wall-mounted monitor without losing their main
                                 working session.  Scoped to this customer-tier
                                 tenant; same chromeless component the dealer /
                                 OEM portals use. --}}
                            <x-sidebar-link :href="route('customer.display')" target="_blank" rel="noopener" :active="request()->routeIs('customer.display')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></x-slot:icon>
                                Live Display
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Resources</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.documents')" :active="request()->routeIs('customer.documents')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('customer.locations.index')" :active="request()->routeIs('customer.locations.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Address Book
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- Body-builder integration — dealer-side hub.
                     Movement Requests page (linked dealers' BBs raise
                     next-fitment / collection requests here) sits in its
                     own Workflow section so the pending-count badge has
                     a stable home.  Linked Body Builders sits with team
                     management because it's an admin-level setup task. --}}
                @php
                    $pendingBbRequests = $isOemCustomer ? 0 : (
                        $user->canApproveBbRequests()
                            ? \App\Models\MovementRequest::query()
                                ->whereIn('target_company_id', $user->companies->pluck('id'))
                                ->where('status', \App\Models\MovementRequest::STATUS_PENDING)
                                ->count()
                            : 0
                    );
                @endphp

                @if(!$isOemCustomer && ($user->canApproveBbRequests() || $user->canManageBbLinks()))
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Body Builders</p>
                    <ul role="list" class="space-y-0.5">
                        @if($user->canApproveBbRequests())
                        <x-sidebar-link :href="route('customer.movement-requests.index')" :active="request()->routeIs('customer.movement-requests.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></x-slot:icon>
                            Movement Requests
                            @if($pendingBbRequests > 0)
                                <span class="ml-auto inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $pendingBbRequests }}</span>
                            @endif
                        </x-sidebar-link>
                        @endif

                        @if($user->canManageBbLinks())
                        <x-sidebar-link :href="route('customer.body-builders.index')" :active="request()->routeIs('customer.body-builders.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg></x-slot:icon>
                            Linked Body Builders
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

                @if($user->canManageCompanyData())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Account</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.team.index')" :active="request()->routeIs('customer.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>

                        {{-- Internal driver pool — dealer-only feature.
                             OEM tenants don't run their own drivers; everything
                             goes through ProSelver. --}}
                        @if(!$isOemCustomer)
                        <x-sidebar-link :href="route('customer.drivers.index')" :active="request()->routeIs('customer.drivers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- DEALER PORTAL                                                  --}}
            {{-- ============================================================ --}}
            @if($isDealer)

                {{-- OVERVIEW --}}
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('dealer.dashboard')" :active="request()->routeIs('dealer.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- BOOKINGS --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Movements</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Primary CTA: the unified booking flow with the
                             executor selector (use my driver / 3rd-party /
                             self-collect / ProSelver). Lives in /customer/*
                             but EnsureCustomerAccess admits legacy dealer
                             users so the URL resolves cleanly. --}}
                        <x-sidebar-link :href="route('customer.orders.create')" :active="request()->routeIs('customer.orders.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Movement
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.bookings.create')" :active="request()->routeIs('dealer.bookings.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M21 3 9 15"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg></x-slot:icon>
                            ProSelver Booking (legacy)
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.bookings.index')" :active="request()->routeIs('dealer.bookings.index') || request()->routeIs('dealer.bookings.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Bookings
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.jobs.index')" :active="request()->routeIs('dealer.jobs.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></x-slot:icon>
                            Active Jobs
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('customer.stock.at-body-builder')" :active="request()->routeIs('customer.stock.at-body-builder')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"/><polyline points="3 7 12 13 21 7"/><line x1="12" x2="12" y1="13" y2="21"/></svg></x-slot:icon>
                            Stock In Transit
                        </x-sidebar-link>

                        @if($user->hasPermission('view_all_bookings'))
                            {{-- Opens in a new tab so the dispatcher can drop it on
                                 the wall-mounted monitor without losing their main
                                 working session.  Same shared component the
                                 customer + admin portals use. --}}
                            <x-sidebar-link :href="route('customer.display')" target="_blank" rel="noopener" :active="request()->routeIs('customer.display')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></x-slot:icon>
                                Live Display
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                {{-- TRIPS (legacy dealers using their own drivers) --}}
                @if($user->canPlanMovements() || $user->isDriver())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Trips</p>
                    <ul role="list" class="space-y-0.5">
                        @if($user->canPlanMovements())
                            <x-sidebar-link :href="route('customer.trips.index')" :active="request()->routeIs('customer.trips.index') || request()->routeIs('customer.trips.show') || request()->routeIs('customer.trips.create')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 0-8-3-8-6s3-6 8-6h6c5 0 8 3 8 6s-3 6-8 6"/><path d="M5 13h.01"/><path d="M19 13h.01"/></svg></x-slot:icon>
                                Trip Planner
                            </x-sidebar-link>
                        @endif
                        @if($user->isDriver())
                            <x-sidebar-link :href="route('customer.trips.my-day')" :active="request()->routeIs('customer.trips.my-day')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></x-slot:icon>
                                My Day
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

                {{-- REPORTS --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Reports</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.reports.deliveries')" :active="request()->routeIs('customer.reports.deliveries')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></x-slot:icon>
                            Deliveries
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- WORKSPACE --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Workspace</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('dealer.locations.index')" :active="request()->routeIs('dealer.locations.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Address Book
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.team.index')" :active="request()->routeIs('dealer.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>

                        @if($user->canManageCompanyData())
                            <x-sidebar-link :href="route('customer.drivers.index')" :active="request()->routeIs('customer.drivers.index')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3 3-1.5-1.5"/></svg></x-slot:icon>
                                My Drivers
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                {{-- SETTINGS (dealer principal only) --}}
                @if($user->hasRole('dealer_principal'))
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Account</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('dealer.settings.roles')" :active="request()->routeIs('dealer.settings.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></x-slot:icon>
                            Roles &amp; Access
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- HELP --}}
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('dealer.help')" :active="request()->routeIs('dealer.help')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg></x-slot:icon>
                            Help
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

            {{-- ============================================================ --}}
            {{-- OEM PORTAL                                                     --}}
            {{-- ============================================================ --}}
            @if($isOem)

                {{-- OVERVIEW --}}
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('oem.dashboard')" :active="request()->routeIs('oem.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- MOVEMENTS --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Movements</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Primary CTA: unified booking flow with executor
                             selector (use my driver / 3rd-party / self-collect
                             / ProSelver). Same /customer/* URL the migrated
                             OEM users will land in once they're moved to the
                             customer_* tier. --}}
                        <x-sidebar-link :href="route('customer.orders.create')" :active="request()->routeIs('customer.orders.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Movement
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('oem.bookings.create')" :active="request()->routeIs('oem.bookings.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M21 3 9 15"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg></x-slot:icon>
                            ProSelver Booking (legacy)
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('oem.bookings.index')" :active="request()->routeIs('oem.bookings.index') || request()->routeIs('oem.bookings.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Bookings
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('oem.jobs.index')" :active="request()->routeIs('oem.jobs.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></x-slot:icon>
                            Active Jobs
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('oem.vehicles.index')" :active="request()->routeIs('oem.vehicles.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg></x-slot:icon>
                            Vehicles
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('customer.stock.at-body-builder')" :active="request()->routeIs('customer.stock.at-body-builder')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"/><polyline points="3 7 12 13 21 7"/><line x1="12" x2="12" y1="13" y2="21"/></svg></x-slot:icon>
                            Stock In Transit
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- TRIPS — legacy OEM users get the planner only if they
                     somehow have a dealer-style flow (e.g. ProSelver-managed
                     OEMs in mixed tenancy). For pure OEM tenants the
                     ProSelver-only rule means there are no internal drivers
                     to plan trips for, so the link is hidden by default. --}}
                @if($user->isDriver())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Trips</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.trips.my-day')" :active="request()->routeIs('customer.trips.my-day')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></x-slot:icon>
                            My Day
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- REPORTS --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Reports</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.reports.deliveries')" :active="request()->routeIs('customer.reports.deliveries')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></x-slot:icon>
                            Deliveries
                        </x-sidebar-link>

                        @if($user->hasPermission('view_all_bookings'))
                            <x-sidebar-link :href="route('customer.display')" target="_blank" rel="noopener" :active="request()->routeIs('customer.display')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></x-slot:icon>
                                Live Display
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                {{-- WORKSPACE — no internal-driver pool for OEM tenants,
                     so the "My Drivers" link is omitted from this branch. --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Workspace</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('oem.locations.index')" :active="request()->routeIs('oem.locations.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Address Book
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('oem.team.index')" :active="request()->routeIs('oem.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- HELP --}}
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('oem.help')" :active="request()->routeIs('oem.help')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg></x-slot:icon>
                            Help
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

            {{-- ============================================================ --}}
            {{-- DRIVER PORTAL                                                  --}}
            {{-- ============================================================ --}}
            @if($isDriver)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">My Work</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('driver.dashboard')" :active="request()->routeIs('driver.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            My Jobs
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

        </ul>

        {{-- Bottom: tagline + build mark --}}
        <div class="mt-6 pt-4 border-t border-slate-100 mx-3">
            <p class="text-[10px] font-semibold tracking-[0.3em] uppercase text-slate-400 text-center">Control · Dispatch · Deliver</p>
        </div>
    </nav>
</div>
