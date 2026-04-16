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
@endphp

<div class="flex grow flex-col gap-y-5 overflow-y-auto bg-blue-900 px-6 pb-4">
    <div class="flex h-16 shrink-0 items-center">
        <span class="text-xl font-bold text-white">ProSelver<span class="text-blue-300">Tech</span></span>
    </div>

    <nav class="flex flex-1 flex-col">
        <ul role="list" class="flex flex-1 flex-col gap-y-5">

            {{-- ============================================================ --}}
            {{-- INTERNAL / ADMIN PORTAL                                       --}}
            {{-- ============================================================ --}}
            @if($isInternal)

                {{-- OPERATIONS section --}}
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Operations</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.orders.index')" :active="request()->routeIs('admin.orders.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Orders
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.documents.index')" :active="request()->routeIs('admin.documents.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- PLANNING & DISPATCH section --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isDispatcher || $isOwner)
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Planning & Dispatch</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                        <x-sidebar-link :href="route('admin.planning')" :active="request()->routeIs('admin.planning')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg></x-slot:icon>
                            Planning Queue
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('admin.dispatch')" :active="request()->routeIs('admin.dispatch')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></x-slot:icon>
                            Dispatch Board
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.drivers.index')" :active="request()->routeIs('admin.drivers.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- CUSTOMERS & DATA section --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Customers & Data</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('admin.customers.index')" :active="request()->routeIs('admin.customers.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg></x-slot:icon>
                            Customers
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

                {{-- ADMIN section (Super Admin, Developer only) --}}
                @if($isDeveloper || $isSuperAdmin)
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Administration</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Users
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.settings.index')" :active="request()->routeIs('admin.settings.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></x-slot:icon>
                            Settings
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.audit-log')" :active="request()->routeIs('admin.audit-log')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
                            Audit Log
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- CUSTOMER PORTAL                                               --}}
            {{-- ============================================================ --}}
            @if($isCustomer)

                {{-- ORDERS section --}}
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Orders</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('customer.dashboard')" :active="request()->routeIs('customer.dashboard')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>

                        @if($user->hasPermission('submit_booking'))
                        <x-sidebar-link :href="route('customer.orders.create')" :active="request()->routeIs('customer.orders.create')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Order
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('customer.orders.index')" :active="request()->routeIs('customer.orders.index') || request()->routeIs('customer.orders.show')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            My Orders
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- DOCUMENTS & LOCATIONS section --}}
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Resources</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('customer.documents')" :active="request()->routeIs('customer.documents')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('customer.locations.index')" :active="request()->routeIs('customer.locations.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></x-slot:icon>
                            Address Book
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- ACCOUNT section (Customer Owner, Customer Admin only) --}}
                @if($user->hasAnyRole(['customer_owner', 'customer_admin']))
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">Account</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('customer.team.index')" :active="request()->routeIs('customer.team.*')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>
                    </ul>
                </li>
                @endif

            @endif

            {{-- ============================================================ --}}
            {{-- LEGACY DEALER (backward compat)                               --}}
            {{-- ============================================================ --}}
            @if($isDealer)
                <li>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('dealer.dashboard')" :active="request()->routeIs('dealer.dashboard')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

            {{-- ============================================================ --}}
            {{-- LEGACY OEM (backward compat)                                  --}}
            {{-- ============================================================ --}}
            @if($isOem)
                <li>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('oem.dashboard')" :active="request()->routeIs('oem.dashboard')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

            {{-- ============================================================ --}}
            {{-- DRIVER PORTAL                                                 --}}
            {{-- ============================================================ --}}
            @if($isDriver)
                <li>
                    <p class="mb-2 px-2 text-xs font-semibold uppercase tracking-wider text-blue-400">My Work</p>
                    <ul role="list" class="-mx-2 space-y-1">
                        <x-sidebar-link :href="route('driver.dashboard')" :active="request()->routeIs('driver.dashboard')">
                            <x-slot:icon><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            My Jobs
                        </x-sidebar-link>
                    </ul>
                </li>
            @endif

        </ul>
    </nav>
</div>
