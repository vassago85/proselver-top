{{--
    Cross-page navigation strip for the Petty Cash section.  Sits at
    the top of each petty-cash page (Slips, Overview, Driver pay, Bus
    tickets) so they read as one tool rather than four separate sidebar
    entries.  The sidebar has a single "Petty Cash" entry that lights up
    on any of them.

    Overview uses canViewPettyCashOverview() for its gate -- accounts
    plus owner/dev.  Reconciliation shared that gate but the tab has
    been retired below (see note on $current).
--}}
@php
    $u = auth()->user();
    $canSeeOverview = $u && $u->canViewPettyCashOverview();
    $canSeeDriverPay = $u && ($u->isOwner() || $u->isDeveloper() || $u->isAccounts());
    // Bus tickets: ops + accounts + owner / dev (same spirit as the
    // operational petty-cash queue -- ops books the ticket, accounts
    // reconciles the outcome on the payslip).
    $canSeeBusTickets = $u && (
        $u->isOwner() || $u->isDeveloper() || $u->isAccounts()
        || $u->isOperationsController()
        || $u->hasAnyRole(['super_admin', 'ops_manager', 'dispatcher'])
    );
    $isAccountsOnly = $u && $u->isAccounts() && !$u->isOwner() && !$u->isDeveloper();
    // NOTE: Plans · Sign-off and Reconciliation tabs were retired
    // 2026-10-01 because neither workflow is being driven in practice.
    // The routes (admin.petty-cash.plans, admin.petty-cash.reconciliation)
    // and their pages still exist because the Orders detail, Planning
    // board, Owner command centre and Finance tiles deep-link into
    // them -- the "advance issued, trip cancelled" cleanup path in
    // particular needs the Reconciliation page's openTransfer flow.
    // What's removed here is the always-on tab entry; the pages are
    // reachable via those contextual deep-links only.
    $current = match (true) {
        request()->routeIs('admin.overview') => 'overview',
        // Driver pay list AND the per-driver payslip AND the per-driver
        // cash audit all light up the "Driver pay" tab -- payslip is a
        // drill-down of the summary, cash audit is the forensic view of
        // the same data.
        request()->routeIs('admin.drivers.pay')         => 'driver_pay',
        request()->routeIs('admin.drivers.payslip')     => 'driver_pay',
        request()->routeIs('admin.drivers.cash-audit')  => 'driver_pay',
        request()->routeIs('admin.drivers.bus-tickets') => 'bus_tickets',
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
    @if($canSeeBusTickets)
        <a href="{{ route('admin.drivers.bus-tickets') }}"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap transition
            {{ $current === 'bus_tickets' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6v6"/><path d="M16 6v6"/><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><circle cx="8" cy="17" r="1.3"/><circle cx="16" cy="17" r="1.3"/></svg>
            Bus tickets
        </a>
    @endif
</nav>
