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

// Read-only — who has authorised us. Removing the link is the
// dealer's decision, not ours.
Volt::route('dealers', 'body-builder.dealers.index')->name('dealers.index');

// BB users manage their workshop locations + their own team through
// the same tenant-scoped Volt pages the dealer side uses (both pages
// auto-scope on auth()->user()->company()).  The body-builder sidebar
// points to /customer/team and /customer/locations directly so we
// don't duplicate the CRUD; EnsureCustomerAccess allows BB tenants
// through.  Help content is BB-specific.
Volt::route('help', 'body-builder.help.index')->name('help');
