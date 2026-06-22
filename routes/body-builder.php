<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/**
 * Body-builder portal. Routes under /body-builder/*.
 *
 * Tenancy: the EnsureBodyBuilderAccess middleware guarantees the user
 * is attached to a Company::TYPE_BODY_BUILDER, so every page below
 * scopes its queries off auth()->user()->company() without having to
 * re-check the tenant type.
 */

Volt::route('dashboard', 'body-builder.dashboard')->name('dashboard');

// Job-side: inbound (vehicles on their way to us) + on-site (at our
// workshop, awaiting next move or collection). The same Volt page
// handles both via a tab/bucket filter, mirroring the dealer's Stock
// In Transit pattern.
Volt::route('jobs', 'body-builder.jobs.index')->name('jobs.index');
Volt::route('jobs/{job}', 'body-builder.jobs.show')->name('jobs.show');

// Movement requests we've raised: pending / decided / cancelled.
Volt::route('requests', 'body-builder.requests.index')->name('requests.index');
Volt::route('requests/{request}', 'body-builder.requests.show')->name('requests.show');

// Direct orders -- the BB IS the paying customer on the booking.
// Distinct from /requests (which routes through the dealer for
// approval and is unpaid).  If the VIN matches an existing dealer's
// stock the owner-approval gate fires automatically; see
// BookingService::resolveOwnerApprovalFields().
Volt::route('orders', 'body-builder.orders.index')->name('orders.index');
Volt::route('orders/create', 'body-builder.orders.create')->name('orders.create');
Volt::route('orders/{job}', 'body-builder.orders.show')->name('orders.show');

// Read-only — who has authorised us. Removing the link is the
// dealer's decision, not ours.
Volt::route('dealers', 'body-builder.dealers.index')->name('dealers.index');

// BB users manage their workshop locations + their own team through
// the same tenant-scoped Volt pages the dealer side uses (both pages
// auto-scope on auth()->user()->company()).  The body-builder sidebar
// points to /customer/team and /customer/locations directly so we
// don't duplicate the CRUD; EnsureCustomerAccess allows BB tenants
// through.  Help content is BB-specific.
// Mobile-first yard app -- the touch console workshop staff use to
// see what's on the premises, check vehicles in, and send them back to
// the dealer.  Uses its own layout (body-builder.blade.php) so the
// chrome is tablet-friendly with big tap targets.
Volt::route('yard', 'body-builder.yard.index')->name('yard.index');
Volt::route('yard/checkin', 'body-builder.yard.checkin')->name('yard.checkin');
// Stock-keyed yard show: for OEM-direct arrivals that don't yet have
// a transport_job binding.  Distinct from yard/{job} so URL parsing
// can resolve unambiguously even when stock and job IDs collide.
Volt::route('yard/stock/{stock}', 'body-builder.yard.stock-show')->name('yard.stock');
Volt::route('yard/{job}', 'body-builder.yard.show')->name('yard.show');

Volt::route('help', 'body-builder.help.index')->name('help');
