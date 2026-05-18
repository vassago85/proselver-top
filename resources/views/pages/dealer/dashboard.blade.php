<?php

use App\Models\Job;
use App\Models\Location;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

/*
 * Dealer dashboard.
 *
 * The dealer portal already has full CRUD screens for bookings, jobs,
 * address book (locations) and team. This dashboard is the glue: a quick
 * operational snapshot + deep links so a newly-landed dealer user can
 * get anywhere in one click instead of hunting around the sidebar.
 *
 * All queries are scoped to the signed-in user's company via
 * Job.company_id / Location.company_id so a dealer only ever sees their
 * own data. If the user somehow has no company attached (edge case from
 * demo seeding), we short-circuit to empty state rather than 500.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $company = auth()->user()->company();

        if (!$company) {
            return [
                'hasCompany' => false,
                'stats'      => [],
                'recentJobs' => collect(),
                'addresses'  => collect(),
                'addressCount' => 0,
                'teamCount'  => 0,
            ];
        }

        $companyId = $company->id;

        $pending = Job::where('company_id', $companyId)
            ->whereIn('status', [Job::STATUS_PENDING_VERIFICATION, Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_VERIFIED, Job::STATUS_APPROVED])
            ->count();

        $inTransit = Job::where('company_id', $companyId)
            ->whereIn('status', [Job::STATUS_ASSIGNED, Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION, Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT, Job::STATUS_IN_PROGRESS])
            ->count();

        $deliveredThisMonth = Job::where('company_id', $companyId)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_READY_FOR_INVOICING, Job::STATUS_INVOICED])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $totalCompleted = Job::where('company_id', $companyId)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_READY_FOR_INVOICING, Job::STATUS_INVOICED])
            ->count();

        $recentJobs = Job::where('company_id', $companyId)
            ->with(['driver:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'brand:id,name'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $addresses = Location::where('company_id', $companyId)
            ->orderBy('company_name')
            ->limit(5)
            ->get(['id', 'company_name', 'city', 'province', 'customer_name']);

        $addressCount = Location::where('company_id', $companyId)->count();

        // users ↔ companies is a many-to-many via the company_users pivot —
        // there's no users.company_id column, so we have to go through the
        // relationship. Matches the pattern used in customer/team/index.
        $teamCount = \App\Models\User::whereHas('companies', fn($q) => $q->where('companies.id', $companyId))->count();

        return [
            'hasCompany' => true,
            'stats' => [
                'pending'            => $pending,
                'in_transit'         => $inTransit,
                'delivered_month'    => $deliveredThisMonth,
                'total_completed'    => $totalCompleted,
            ],
            'recentJobs'  => $recentJobs,
            'addresses'   => $addresses,
            'addressCount'=> $addressCount,
            'teamCount'   => $teamCount,
        ];
    }
};
?>

<div>
    <x-slot:header>Dealer Dashboard</x-slot:header>

    @if(!$hasCompany)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
            <h2 class="text-lg font-semibold text-amber-900">No dealership linked to your account</h2>
            <p class="mt-2 text-sm text-amber-800">Ask your operations controller to attach your user to a dealership before you can submit bookings.</p>
        </div>
    @else

        {{-- Hero: welcome + primary CTA --}}
        <div class="mb-6 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 text-white shadow-lg overflow-hidden">
            <div class="px-6 py-6 sm:px-8 sm:py-7 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-blue-200/80">Control · Dispatch · Deliver</p>
                    <h2 class="mt-1 text-xl sm:text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="mt-1 text-sm text-slate-300">{{ auth()->user()->company()?->name ?? '—' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    {{-- Primary CTA: the unified booking flow with the
                         executor selector. This is what most dealer users
                         will reach for now that they can run local
                         movements with their own drivers / a courier /
                         self-collect. The legacy ProSelver-only flow
                         stays one click away under "View Bookings". --}}
                    <a href="{{ route('customer.orders.create') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        New Movement
                    </a>
                    <a href="{{ route('dealer.bookings.create') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-white/10 ring-1 ring-white/20 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M21 3 9 15"/></svg>
                        ProSelver Booking
                    </a>
                    <a href="{{ route('dealer.bookings.index') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-white/10 ring-1 ring-white/20 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/15 transition">
                        View Bookings
                    </a>
                </div>

                {{-- Help banner pointing dealers at the new movement types
                     they can now book themselves (their own driver /
                     3rd-party courier / self-collect / body builder /
                     archive / drivers CRUD / deliveries report). The
                     content shows once and is dismissable so it doesn't
                     get in the way after the team has discovered it. --}}
                @if(auth()->user()->canPlanMovements())
                <details class="sm:basis-full mt-2 rounded-lg bg-white/10 ring-1 ring-white/15 text-white text-xs px-3 py-2">
                    <summary class="cursor-pointer font-semibold text-blue-100 select-none">What's new for dealers? <span class="text-blue-200/60 font-normal">(local movements, my drivers, body builder)</span></summary>
                    <div class="mt-2 text-blue-50/90 leading-relaxed">
                        You can now book a movement with your <span class="font-semibold text-white">own driver</span>, a <span class="font-semibold text-white">3rd-party courier</span>, or as a <span class="font-semibold text-white">self-collect</span> — pick the executor on the "New Movement" form. Your internal drivers live under <a href="{{ route('customer.drivers.index') }}" class="underline">My Drivers</a>; vehicles parked at a body builder show on <a href="{{ route('customer.stock.at-body-builder') }}" class="underline">At Body Builder</a>; finished deliveries can be archived from each order's page; the <a href="{{ route('customer.reports.deliveries') }}" class="underline">Deliveries Report</a> ties it all together. <span class="text-blue-200/70">The classic "ProSelver Booking" button is still there for jobs you want us to execute.</span>
                    </div>
                </details>
                @endif
            </div>
        </div>

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <a href="{{ route('dealer.bookings.index') }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-amber-300 hover:shadow-sm transition">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-amber-600">Pending</span>
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-amber-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                </div>
                <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['pending'] }}</p>
                <p class="mt-0.5 text-xs text-slate-500">Awaiting confirmation</p>
            </a>

            <a href="{{ route('dealer.jobs.index') }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-blue-300 hover:shadow-sm transition">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-blue-600">In Transit</span>
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-blue-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                </div>
                <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['in_transit'] }}</p>
                <p class="mt-0.5 text-xs text-slate-500">Assigned &amp; moving</p>
            </a>

            <a href="{{ route('dealer.bookings.index') }}" class="group rounded-xl border border-slate-200 bg-white p-4 hover:border-emerald-300 hover:shadow-sm transition">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-emerald-600">Delivered {{ now()->format('M') }}</span>
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-300 group-hover:text-emerald-500 transition" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                </div>
                <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['delivered_month'] }}</p>
                <p class="mt-0.5 text-xs text-slate-500">This month</p>
            </a>

            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">Lifetime</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-slate-900 tabular-nums">{{ $stats['total_completed'] }}</p>
                <p class="mt-0.5 text-xs text-slate-500">Vehicles delivered</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

            {{-- Recent jobs (spans 2 cols) --}}
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Recent movements</h2>
                    <a href="{{ route('dealer.bookings.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500">View all →</a>
                </div>

                @if($recentJobs->isEmpty())
                    <div class="px-6 py-12 text-center">
                        <svg viewBox="0 0 24 24" class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
                        <p class="mt-3 text-sm font-medium text-slate-900">No movements yet</p>
                        <p class="mt-1 text-xs text-slate-500">Submit your first booking to get a vehicle collected.</p>
                        <a href="{{ route('dealer.bookings.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 transition">
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            Create first booking
                        </a>
                    </div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach($recentJobs as $job)
                            <a href="{{ route('dealer.bookings.show', $job) }}" class="flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 transition group">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-900 truncate">{{ $job->job_number ?? '—' }}</span>
                                        <span class="text-xs text-slate-500 truncate">· {{ $job->brand?->name }} {{ $job->model_name }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500 truncate">
                                        @if($job->isTransport())
                                            {{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}
                                        @else
                                            Yard work
                                        @endif
                                        @if($job->vin) · <span class="text-slate-400">{{ $job->vin }}</span>@endif
                                    </p>
                                </div>
                                <div class="shrink-0 flex items-center gap-3">
                                    <x-status-badge :status="$job->status" />
                                    <span class="text-xs text-slate-400 tabular-nums">{{ $job->scheduled_date?->format('d M') ?? $job->created_at->format('d M') }}</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Address book preview --}}
            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Address Book</h2>
                        <p class="text-[11px] text-slate-500">{{ $addressCount }} saved {{ Str::plural('location', $addressCount) }}</p>
                    </div>
                    <a href="{{ route('dealer.locations.index') }}" class="text-xs font-semibold text-blue-600 hover:text-blue-500">Open →</a>
                </div>

                @if($addresses->isEmpty())
                    <div class="px-5 py-8 text-center">
                        <svg viewBox="0 0 24 24" class="mx-auto h-9 w-9 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        <p class="mt-3 text-xs font-medium text-slate-900">No addresses saved</p>
                        <p class="mt-1 text-[11px] text-slate-500">Save dealerships, body builders and yards for one-click bookings.</p>
                        <a href="{{ route('dealer.locations.index') }}" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 transition">
                            Add first address
                        </a>
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($addresses as $loc)
                            <li class="px-5 py-3">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ $loc->company_name }}</p>
                                <p class="text-[11px] text-slate-500 truncate">
                                    {{ collect([$loc->city, $loc->province])->filter()->join(' · ') ?: 'No city' }}
                                    @if($loc->customer_name)
                                        · <span class="text-slate-400">{{ $loc->customer_name }}</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-5 py-3 bg-slate-50/60 border-t border-slate-100">
                        <a href="{{ route('dealer.locations.index') }}" class="text-[11px] font-semibold text-slate-600 hover:text-slate-900">Manage all {{ $addressCount }} addresses</a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Quick actions grid --}}
        <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-sm font-semibold text-slate-900">Quick actions</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
                <a href="{{ route('dealer.bookings.create') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">New Booking</p>
                        <p class="text-[11px] text-slate-500 truncate">Request a vehicle movement</p>
                    </div>
                </a>
                <a href="{{ route('dealer.locations.index') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">Address Book</p>
                        <p class="text-[11px] text-slate-500 truncate">{{ $addressCount }} saved {{ Str::plural('location', $addressCount) }}</p>
                    </div>
                </a>
                <a href="{{ route('dealer.team.index') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">Team</p>
                        <p class="text-[11px] text-slate-500 truncate">{{ $teamCount }} {{ Str::plural('user', $teamCount) }}</p>
                    </div>
                </a>
                <a href="{{ route('dealer.help') }}" class="flex items-center gap-3 px-5 py-4 hover:bg-slate-50 transition">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">Help &amp; Support</p>
                        <p class="text-[11px] text-slate-500 truncate">Guides &amp; contact</p>
                    </div>
                </a>
            </div>
        </div>

    @endif
</div>
