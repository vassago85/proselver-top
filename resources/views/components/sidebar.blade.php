@php
    $user = auth()->user();
    $isInternal = $user->isInternal();
    // Treat legacy dealer-tier and oem-tier users as customers for sidebar
    // gating: the /dealer/* and /oem/* portals were retired and these
    // tenants now share the /customer/* menus.  Without this widening,
    // dealer_admin / oem_owner users would see an empty sidebar with the
    // default "INTERNAL" label and no way to reach orders / locations /
    // team pages.  Matches EnsureCustomerAccess and resolveUserHomePath.
    $isCustomer = $user->isCustomer() || $user->isDealer() || $user->isOem();
    $isDriver = $user->isDriver();
    $isDeveloper = $user->isDeveloper();
    $isSuperAdmin = $user->isSuperAdmin();
    $isOpsController = $user->isOperationsController();
    $isDispatcher = $user->hasRole('dispatcher');
    $isOwner = $user->isOwner();
    $isAccounts = $user->isAccounts();

    // Internal nav is grouped by function: the three dashboards, then the
    // operational sections (booking / dispatch / fleet), then finance, then
    // the setup areas.  These flags mirror each destination page's own
    // mount() gate so the sidebar never offers a link that 403s -- if you
    // change a page's gate, change the matching flag here.
    $canSeeFinanceDash = $isAccounts || $isOwner || $isDeveloper || $isSuperAdmin || $isOpsController;
    // Owner command centre is business-oversight only: owner + developer.
    // super_admin keeps every other admin surface but not this one.
    $canSeeOwnerDash = $isOwner || $isDeveloper;
    // Customer invoicing is the only Finance link that is per-role gated
    // in the sidebar now; the Petty Cash tab strip handles the audience
    // split for Overview / Reconciliation / Driver Pay inside the section
    // (removed as sidebar duplicates in Phase 2 of the nav cut, 2026-08).
    $canSeeInvoicing = $isAccounts || $isOwner || $isDeveloper;

    // OEMs hold customer-tier roles for tenanting, so $isCustomer is true.
    // Treat the company type as the source of truth for the *portal* label
    // we present so an FAW or Isuzu operator sees "OEM" branding even
    // though the underlying role slug is customer_owner.  Same trick for
    // body-builder tenants — their roles are tier=customer so they also
    // pass $isCustomer, but the portal label / sidebar branch they get
    // is BB-specific.
    $userCompanyType = $isCustomer ? optional($user->company())->type : null;
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
        $isDriver => 'Driver',
        default => 'Internal',
    };
@endphp

{{-- overscroll-contain stops a swipe that reaches the end of the menu from
     scrolling the page behind it.  pwa-standalone-pad reserves room for the
     installed-app bottom nav, which is fixed at z-85 and would otherwise
     cover the last nav entry when the drawer is open. --}}
<div class="flex grow flex-col gap-y-4 overflow-y-auto overscroll-contain bg-white border-r border-slate-200 pb-4 pwa-standalone-pad">
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
                {{-- Three dashboards, one per audience: Operations (live
                     pipeline), Finance (billing / petty cash / driver pay)
                     and the Owner roll-up.  Everyone gets Operations; the
                     other two appear only for the roles that can open them.
                     /admin/dashboard is a redirect that resolves to whichever
                     of these the signed-in role belongs on. --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Overview</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.dashboard.ops')" :active="request()->routeIs('admin.dashboard.ops')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Operations
                        </x-sidebar-link>

                        @if($canSeeFinanceDash)
                        <x-sidebar-link :href="route('admin.dashboard.finance')" :active="request()->routeIs('admin.dashboard.finance')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></x-slot:icon>
                            Finance
                        </x-sidebar-link>
                        @endif

                        @if($canSeeOwnerDash)
                        <x-sidebar-link :href="route('admin.dashboard.owner')" :active="request()->routeIs('admin.dashboard.owner')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m2 4 3 12h14l3-12-6 7-4-7-4 7Z"/><path d="M5 20h14"/></svg></x-slot:icon>
                            Owner
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                {{-- OPS · BOOKING --}}
                {{-- Order intake and the paperwork attached to it.  The
                     finance pages that used to live in this group (Petty
                     Cash, Customer Invoicing, Platform Licence) moved to
                     the Finance group below -- they were never booking. --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Ops &middot; Booking</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Bulk Upload sat here as its own entry until Phase 2 of
                             the nav cut (2026-08).  Removed because it's a verb
                             attached to Orders, not a separate room -- the Orders
                             page now surfaces it as a page-action button gated to
                             the same account-wide roles. --}}
                        <x-sidebar-link :href="route('admin.orders.index')" :active="request()->routeIs('admin.orders.index') || request()->routeIs('admin.orders.show') || request()->routeIs('admin.orders.bulk-upload')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            Orders
                        </x-sidebar-link>

                        <x-sidebar-link :href="route('admin.documents.index')" :active="request()->routeIs('admin.documents.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></x-slot:icon>
                            Documents
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- OPS · DISPATCH --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isDispatcher || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Ops &middot; Dispatch</p>
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

                        {{-- One "Drivers" entry, not two.  Driver Ops (the
                             fleet-control lens) sat here as a second link
                             until Phase 2 of the nav cut (2026-08); it's the
                             same roster, viewed differently, so the pair is
                             now cross-linked by top-right buttons on each
                             page.  The active rule lights the entry on both
                             URLs so users can tell they're inside "Drivers"
                             either way. --}}
                        <x-sidebar-link :href="route('admin.drivers.index')" :active="request()->routeIs('admin.drivers.index') || request()->routeIs('admin.drivers.create') || request()->routeIs('admin.drivers.edit') || request()->routeIs('admin.drivers.operations')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>

                        {{-- Wallboard removed 2026-05-26 -- the dispatch-TV
                             view depended on the TrackSolid position API, which
                             the owner confirmed isn't going to be available.
                             Live Display used to sit here as its own entry
                             (removed 2026-08 as part of the nav cut) --
                             it's a Dispatch action, exposed as a new-tab
                             button on the Dispatch Board itself. --}}
                    </ul>
                </li>
                @endif

                {{-- OPS · FLEET --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Ops &middot; Fleet</p>
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
                        {{-- TFN Fuel Operations — balances, live pricing, place
                             diesel pre-auth orders, per-vehicle virtual card
                             status, recent transactions.  Page's mount() is the
                             source of truth on gating (internal + developer);
                             we surface the link to every internal role so no
                             one has to know the URL. Safe pre-go-live: page
                             renders demo fixtures until TFN_ENABLED=true. --}}
                        <x-sidebar-link :href="route('admin.fuel')" :active="request()->routeIs('admin.fuel')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v18"/><path d="M14 12h2a2 2 0 0 1 2 2v4a2 2 0 0 0 4 0V9l-3-3"/><path d="M3 22h11"/><path d="M6 14h5"/><path d="M6 18h5"/><path d="M6 10h5"/><path d="M6 6h5"/></svg></x-slot:icon>
                            Fuel &middot; TFN
                        </x-sidebar-link>
                    </ul>
                </li>

                {{-- FINANCE --}}
                {{-- Every money page in one place.  Previously these were
                     scattered: invoicing / petty cash / licence sat under
                     "Booking", while the petty-cash Overview and the driver
                     pay report had no sidebar entry at all and were only
                     reachable as tabs inside Petty Cash -- so accounts and
                     the owner had to know they existed.  They're surfaced
                     here now.

                     No group-level gate: the Petty Cash slip queue is open
                     to every internal role (the page's own policy decides
                     what they may do there), so the header always has at
                     least one visible child.  The rest are gated per item
                     to match their pages exactly. --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Finance</p>
                    <ul role="list" class="space-y-0.5">
                        {{-- Accounts-side: customer-invoicing capture +
                             OEM-shaped Excel export.  Owner / accounts /
                             developer only -- gated server-side in the
                             page mount() as well. --}}
                        @if($canSeeInvoicing)
                        <x-sidebar-link :href="route('admin.invoices.index')" :active="request()->routeIs('admin.invoices.*') || request()->routeIs('admin.reports.invoicing')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/><line x1="8" x2="16" y1="17" y2="17"/></svg></x-slot:icon>
                            Customer Invoicing
                        </x-sidebar-link>
                        @endif

                        {{-- Petty Cash is one entry, not four.  Cash Overview,
                             Reconciliation Queries and Driver Pay used to
                             sit here as sibling entries; the Petty Cash
                             pages already share a tab strip (section-tabs
                             partial) so the sidebar duplicates were pure
                             noise.  The active rule matches every route in
                             that strip so a user on Overview / Reconciliation
                             / Plans / Driver Pay still sees "Petty Cash"
                             lit up here.  Gating on the tab strip stays as
                             is: canViewPettyCashOverview() hides the three
                             admin-only tabs from users who shouldn't see
                             them, driver_pay stays owner/accounts/dev only. --}}
                        <x-sidebar-link
                            :href="route('admin.petty-cash.index')"
                            :active="request()->routeIs('admin.petty-cash.index')
                                || request()->routeIs('admin.petty-cash.plans')
                                || request()->routeIs('admin.overview')
                                || request()->routeIs('admin.petty-cash.reconciliation')
                                || request()->routeIs('admin.drivers.pay')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg></x-slot:icon>
                            Petty Cash
                        </x-sidebar-link>

                        {{-- ProSelver SaaS licence meter — owner + developer
                             only, not accounts, ops or super_admin.  Soft-hide
                             via SystemSetting if needed. --}}
                        @if($user->canViewPlatformLicence() && \App\Models\SystemSetting::get(\App\Services\ProselverLicenceBilling::SETTING_ENABLED, true))
                        <x-sidebar-link :href="route('admin.billing')" :active="request()->routeIs('admin.billing')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></x-slot:icon>
                            Platform Licence
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                {{-- COMPANIES (dealers, OEMs, body builders, internal tenants, etc.) --}}
                @if($isDeveloper || $isSuperAdmin || $isOpsController || $isOwner)
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Companies</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('admin.companies.index')" :active="(request()->routeIs('admin.companies.*') && !request()->routeIs('admin.companies.groups')) || request()->routeIs('admin.customers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg></x-slot:icon>
                            Companies
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('admin.companies.groups')" :active="request()->routeIs('admin.companies.groups')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Groups
                        </x-sidebar-link>
                        @php
                            // Cached for the duration of the request -- the sidebar
                            // renders on every admin page so we don't want a fresh
                            // count() query each time. Cheap and visible regardless.
                            $bbRequestPendingCount = \Illuminate\Support\Facades\Cache::remember(
                                'admin.body_builder_requests.pending_count',
                                15,
                                fn () => \App\Models\BodyBuilderRequest::where('status', 'pending')->count(),
                            );
                        @endphp
                        <x-sidebar-link :href="route('admin.body-builder-requests.index')" :active="request()->routeIs('admin.body-builder-requests.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg></x-slot:icon>
                            <span class="flex flex-1 items-center justify-between">
                                BB requests
                                @if($bbRequestPendingCount > 0)
                                    <span class="ml-2 inline-flex items-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white">{{ $bbRequestPendingCount }}</span>
                                @endif
                            </span>
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

                        <x-sidebar-link :href="route('admin.login-history')" :active="request()->routeIs('admin.login-history')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg></x-slot:icon>
                            Login History
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

                        <x-sidebar-link :href="route('body-builder.yard.index')" :active="request()->routeIs('body-builder.yard.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg></x-slot:icon>
                            Yard (touch)
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
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">{{ $isDealerCustomer ? 'Movements' : 'Orders' }}</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.dashboard')" :active="request()->routeIs('customer.dashboard')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></x-slot:icon>
                            Dashboard
                        </x-sidebar-link>

                        @if($user->hasPermission('submit_booking'))
                        <x-sidebar-link :href="route('customer.orders.create')" :active="request()->routeIs('customer.orders.create')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></x-slot:icon>
                            {{ $isDealerCustomer ? 'Book a delivery' : 'New Order' }}
                        </x-sidebar-link>
                        @endif

                        {{-- Bulk Upload (movements) -- gated to account-wide roles.
                             This is the JOB/MOVEMENT bulk importer; the STOCK
                             bulk importer lives in the Stock section below.
                             Depot dispatchers can't upload because the file may
                             span multiple branches and they're pinned to a
                             single branch.  Same gate enforced server-side. --}}
                        @if($user->canManageCompanyData())
                        <x-sidebar-link :href="route('customer.orders.bulk-upload')" :active="request()->routeIs('customer.orders.bulk-upload')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg></x-slot:icon>
                            {{ $isDealerCustomer ? 'Bulk upload movements' : 'Bulk Upload' }}
                        </x-sidebar-link>
                        @endif

                        <x-sidebar-link :href="route('customer.orders.index')" :active="request()->routeIs('customer.orders.index') || request()->routeIs('customer.orders.show')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg></x-slot:icon>
                            {{ $isDealerCustomer ? 'My movements' : 'My Orders' }}
                            @php
                                $pendingOwnerApprovals = $isDealerCustomer
                                    ? \App\Models\Job::query()
                                        ->whereIn('owner_company_id', $user->visibleCompanyIds())
                                        ->where('requires_owner_approval', true)
                                        ->where('owner_approval_status', \App\Models\Job::OWNER_APPROVAL_PENDING)
                                        ->count()
                                    : 0;
                            @endphp
                            @if($pendingOwnerApprovals > 0)
                                <span class="ml-auto inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white" title="Movements awaiting your approval">{{ $pendingOwnerApprovals }}</span>
                            @endif
                        </x-sidebar-link>

                        @if(!$isDealerCustomer || !$user->hasPermission('view_dealer_stock'))
                        <x-sidebar-link :href="route('customer.stock.at-body-builder')" :active="request()->routeIs('customer.stock.at-body-builder')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" x2="12" y1="22.08" y2="12"/></svg></x-slot:icon>
                            Stock In Transit
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>

                @if($isDealerCustomer && $user->hasPermission('view_dealer_stock'))
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Stock</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.stock.index')" :active="(request()->routeIs('customer.stock.index') || request()->routeIs('customer.stock.show')) && !request()->routeIs('customer.stock.import')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" x2="12" y1="22.08" y2="12"/></svg></x-slot:icon>
                            All stock
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('customer.stock.at-body-builder')" :active="request()->routeIs('customer.stock.at-body-builder')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-1"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg></x-slot:icon>
                            Off-site &amp; in transit
                        </x-sidebar-link>
                        {{-- Import stock is the bulk equivalent of adding rows
                             to dealer_stock one-by-one.  Gated to dealers with
                             manage_dealer_stock; if you can only view stock you
                             can't push new rows. --}}
                        @if($user->hasPermission('manage_dealer_stock'))
                            <x-sidebar-link :href="route('customer.stock.import')" :active="request()->routeIs('customer.stock.import')">
                                <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></x-slot:icon>
                                Import stock
                            </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

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
                                ->whereIn('target_company_id', $user->visibleCompanyIds())
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
                        <x-sidebar-link :href="route('customer.body-builders.index')" :active="request()->routeIs('customer.body-builders.index')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg></x-slot:icon>
                            Linked Body Builders
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('customer.body-builders.requests.index')" :active="request()->routeIs('customer.body-builders.requests.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg></x-slot:icon>
                            Request a BB
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

                {{-- In-app user guide.  Shown to every customer-tier
                     tenant (dealer, OEM, body builder) so anyone who
                     lands in the portal can find a how-to. --}}
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Help</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.help')" :active="request()->routeIs('customer.help')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg></x-slot:icon>
                            User Guide
                        </x-sidebar-link>
                    </ul>
                </li>

                @if($user->canManageCompanyData())
                <li>
                    <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-400">Account</p>
                    <ul role="list" class="space-y-0.5">
                        <x-sidebar-link :href="route('customer.team.index')" :active="request()->routeIs('customer.team.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></x-slot:icon>
                            Team
                        </x-sidebar-link>

                        {{-- Delivery-note branding (Phase 1B) — logo +
                             letterhead printed on the dealer's own notes. --}}
                        <x-sidebar-link :href="route('customer.settings.branding')" :active="request()->routeIs('customer.settings.branding')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></x-slot:icon>
                            Branding
                        </x-sidebar-link>

                        {{-- Internal driver pool — dealer-only feature.
                             OEM tenants don't run their own drivers; everything
                             goes through ProSelver. --}}
                        @if(!$isOemCustomer)
                        <x-sidebar-link :href="route('customer.drivers.index')" :active="request()->routeIs('customer.drivers.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg></x-slot:icon>
                            Drivers
                        </x-sidebar-link>
                        {{-- Petty cash queue for the dealer's drivers.
                             Shown to anyone who plans movements — admins
                             can act on slips, dispatchers see the queue
                             read-only.  Hidden for OEMs (no internal
                             driver pool, so nothing to reconcile). --}}
                        <x-sidebar-link :href="route('customer.petty-cash.index')" :active="request()->routeIs('customer.petty-cash.*')">
                            <x-slot:icon><svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg></x-slot:icon>
                            Petty Cash
                        </x-sidebar-link>
                        @endif
                    </ul>
                </li>
                @endif

            @endif

            {{-- The legacy DEALER and OEM sidebar branches (gated on
                 the dealer_* / oem_* role-tier slugs and pointing at
                 /dealer/* and /oem/*) were retired together with the
                 portal route prefixes.  Every modern tenant lives on
                 a customer-tier role with Company::$type driving the
                 dealer-vs-OEM behavioural differences (executor
                 selector, FAW confirmation workflow, OEM-only
                 restrictions) inside the customer Volt pages.  Body
                 builders get their own branch above. --}}

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
