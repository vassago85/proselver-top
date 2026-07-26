{{--
    Cross-page navigation strip for the three internal dashboards
    (Operations, Finance, Owner).  Sits at the top of each so they read
    as three views of one command centre rather than three unrelated
    pages, and so a role that can see more than one can move between
    them without going back to the sidebar.

    Each tab renders only when the viewer can actually land on the page
    -- the conditions below mirror each component's mount() gate exactly,
    so we never tease a link that 403s.  Keep them in sync:

        Operations  every internal role
        Finance     accounts, owner, developer, super admin, ops controller
        Owner       owner, developer, super admin

    A viewer who can only reach one dashboard gets no strip at all, since
    a single-tab switcher is just noise.
--}}
@php
    $u = auth()->user();

    $canSeeOps = (bool) $u?->isInternal();
    $canSeeFinance = $u && (
        $u->isAccounts()
        || $u->isOwner()
        || $u->isDeveloper()
        || $u->isSuperAdmin()
        || $u->isOperationsController()
    );
    $canSeeOwner = $u && ($u->isOwner() || $u->isDeveloper() || $u->isSuperAdmin());

    $visibleCount = (int) $canSeeOps + (int) $canSeeFinance + (int) $canSeeOwner;

    $current = match (true) {
        request()->routeIs('admin.dashboard.finance') => 'finance',
        request()->routeIs('admin.dashboard.owner') => 'owner',
        default => 'ops',
    };

    $tabClass = fn (bool $active) => $active
        ? 'bg-white text-slate-900 shadow-sm'
        : 'text-slate-600 hover:text-slate-900';
@endphp

@if($visibleCount > 1)
    <nav class="mb-4 flex items-center gap-1 rounded-xl bg-slate-100 p-1 w-full sm:w-fit overflow-x-auto" aria-label="Dashboards">
        @if($canSeeOps)
            <a href="{{ route('admin.dashboard.ops') }}"
                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition {{ $tabClass($current === 'ops') }}">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
                Operations
            </a>
        @endif

        @if($canSeeFinance)
            <a href="{{ route('admin.dashboard.finance') }}"
                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition {{ $tabClass($current === 'finance') }}">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Finance
            </a>
        @endif

        @if($canSeeOwner)
            <a href="{{ route('admin.dashboard.owner') }}"
                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition {{ $tabClass($current === 'owner') }}">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 4 3 12h14l3-12-6 7-4-7-4 7Z"/><path d="M5 20h14"/></svg>
                Owner
            </a>
        @endif
    </nav>
@endif
