{{--
    Cross-page navigation strip for the Petty Cash section.  Sits at
    the top of each petty-cash page (Slips, Plans · Sign-off, Overview)
    so the three are visibly part of one tool, not three separate
    sidebar entries.  The sidebar now has a single "Petty Cash" entry
    that lights up on any of the three routes.

    Overview is visible to owner, developer, accounts and the
    operations controller -- accounts uses it for month-end recon,
    ops uses it to see driver spend and delivery cost per movement.
    The link is rendered only when the viewer can actually land on
    the page (matches the Overview mount() gate) to avoid teasing a
    403.
--}}
@php
    $u = auth()->user();
    $canSeeOverview = $u && ($u->isOwner() || $u->isDeveloper() || $u->isAccounts() || $u->isOperationsController());
    $canSeeDriverPay = $u && ($u->isOwner() || $u->isDeveloper() || $u->isAccounts());
    $isAccountsOnly = $u && $u->isAccounts() && !$u->isOwner() && !$u->isDeveloper();
    $current = match (true) {
        request()->routeIs('admin.petty-cash.plans') => 'plans',
        request()->routeIs('admin.overview') => 'overview',
        request()->routeIs('admin.drivers.pay') => 'driver_pay',
        default => 'slips',
    };
@endphp
<nav class="mb-4 flex items-center gap-1 rounded-xl bg-slate-100 p-1 w-full sm:w-fit overflow-x-auto" aria-label="Petty Cash sections">
    <a href="{{ route('admin.petty-cash.index') }}"
        class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition
        {{ $current === 'slips' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        Slips &amp; reconcile
    </a>
    <a href="{{ route('admin.petty-cash.plans') }}"
        class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition
        {{ $current === 'plans' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Plans &middot; Sign-off
    </a>
    @if($canSeeOverview)
        <a href="{{ route('admin.overview') }}"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition
            {{ $current === 'overview' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            Overview
            @if($u && ($u->isOwner() || $u->isDeveloper()))
                <span class="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-amber-800">Owner</span>
            @endif
        </a>
    @endif
    @if($canSeeDriverPay)
        <a href="{{ route('admin.drivers.pay') }}"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition
            {{ $current === 'driver_pay' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Driver pay
        </a>
    @endif
</nav>
