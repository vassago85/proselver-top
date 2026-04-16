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

    // Portal subtitle for the sidebar brand area
    $portalLabel = match(true) {
        $isDeveloper => 'Developer',
        $isSuperAdmin => 'Super Admin',
        $isOpsController => 'Operations',
        $isDispatcher => 'Dispatch',
        $isOwner => 'Owner',
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
            <img src="/logo.png" alt="Proselver Trident" class="h-11 w-auto object-contain" />
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
                        <x-sidebar-link :href="route('admin.orders.index')" :active="request()->routeIs('admin.orders.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Orders
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.documents.index')" :active="request()->routeIs('admin.documents.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
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

                        <x-sidebar-link :href="route('admin.drivers.index')" :active="request()->routeIs('admin.drivers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- TRACKING --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Tracking</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.tracking')" :active="request()->routeIs('admin.tracking')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Active Movements
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- CUSTOMERS --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Customers</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.customers.index')" :active="request()->routeIs('admin.customers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg></x-slot:icon>
                            Customers
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- ADMIN --}}
                @if($isDeveloper || $isSuperAdmin)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Administration</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Users
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.settings.index')" :active="request()->routeIs('admin.settings.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></x-slot:icon>
                            Settings
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.audit-log')" :active="request()->routeIs('admin.audit-log')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
                            Audit Log
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- CUSTOMER PORTAL                                                --}}
            {{-- ============================================================ --}}
            @if($isCustomer)

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

                        <x-sidebar-link :href="route('customer.orders.index')" :active="request()->routeIs('customer.orders.index') || request()->routeIs('customer.orders.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            My Orders
                        </x-sidebar-link>
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

                @if($user->hasAnyRole(['customer_owner', 'customer_admin']))
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Account</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.team.index')" :active="request()->routeIs('customer.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- LEGACY DEALER                                                  --}}
            {{-- ============================================================ --}}
            @if($isDealer)
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('dealer.dashboard')" :active="request()->routeIs('dealer.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

            {{-- ============================================================ --}}
            {{-- LEGACY OEM                                                     --}}
            {{-- ============================================================ --}}
            @if($isOem)
                <li>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('oem.dashboard')" :active="request()->routeIs('oem.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
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
