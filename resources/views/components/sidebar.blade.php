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
                        <x-sidebar-link :href="route('dealer.bookings.create')" :active="request()->routeIs('dealer.bookings.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Booking
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.bookings.index')" :active="request()->routeIs('dealer.bookings.index') || request()->routeIs('dealer.bookings.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Bookings
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('dealer.jobs.index')" :active="request()->routeIs('dealer.jobs.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg></x-slot:icon>
                            Active Jobs
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
                        <x-sidebar-link :href="route('oem.bookings.create')" :active="request()->routeIs('oem.bookings.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            New Booking
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
                    </ul>
                </li>

                {{-- WORKSPACE --}}
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
