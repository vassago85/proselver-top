<?php

use App\Models\Job;
use App\Models\Location;
use App\Models\MovementRequest;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function with(): array
    {
        $user = auth()->user();
        $company = $user?->company();

        if (! $company) {
            return [
                'company' => null,
                'inboundCount' => 0,
                'onSiteCount' => 0,
                'pendingRequests' => 0,
                'outboundThisWeek' => 0,
                'linkedDealersCount' => 0,
                'recentJobs' => collect(),
            ];
        }

        // The body-builder's "inbox" is every job whose delivery
        // location belongs to one of our workshops (so the dealer has
        // sent it OR is sending it to us).  Status partition decides
        // which bucket the row shows up in.
        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');

        $baseInboundQuery = Job::query()
            ->whereIn('delivery_location_id', $myLocationIds)
            ->whereIn('company_id', $company->linkedDealers()
                ->wherePivot('is_active', true)
                ->pluck('companies.id'));

        $inboundCount = (clone $baseInboundQuery)
            ->whereIn('status', [Job::STATUS_IN_TRANSIT, Job::STATUS_ASSIGNED, Job::STATUS_PLANNED])
            ->count();

        $onSiteCount = (clone $baseInboundQuery)
            ->where('status', Job::STATUS_DELIVERED)
            ->count();

        $outboundThisWeek = MovementRequest::query()
            ->where('requesting_company_id', $company->id)
            ->where('status', MovementRequest::STATUS_APPROVED)
            ->where('decided_at', '>=', now()->startOfWeek())
            ->count();

        $pendingRequests = MovementRequest::query()
            ->where('requesting_company_id', $company->id)
            ->where('status', MovementRequest::STATUS_PENDING)
            ->count();

        $linkedDealersCount = $company->linkedDealers()
            ->wherePivot('is_active', true)
            ->count();

        $recentJobs = (clone $baseInboundQuery)
            ->with(['company:id,name', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return compact(
            'company', 'inboundCount', 'onSiteCount', 'pendingRequests',
            'outboundThisWeek', 'linkedDealersCount', 'recentJobs'
        );
    }
};
?>

<div>
    <x-slot:header>Body Builder Dashboard</x-slot:header>

    <div class="mb-6 rounded-2xl bg-gradient-to-br from-slate-900 via-slate-800 to-blue-900 text-white shadow-lg overflow-hidden">
        <div class="px-6 py-6 sm:px-8 sm:py-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-blue-200/80">Workshop · Confirm · Request</p>
            <h2 class="mt-1 text-xl sm:text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h2>
            <p class="mt-1 text-sm text-slate-300">{{ $company?->name ?? '—' }}</p>
        </div>
    </div>

    @if(!$company)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
            Your account is not attached to a body-builder company yet. Ask the dealer who invited you to complete your setup.
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('body-builder.jobs.index', ['bucket' => 'inbound']) }}" class="block rounded-xl border border-slate-200 bg-white p-5 hover:border-blue-400 hover:shadow-sm transition">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Inbound</p>
                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $inboundCount }}</p>
                <p class="mt-1 text-xs text-slate-500">Vehicles on their way to your workshops.</p>
            </a>
            <a href="{{ route('body-builder.jobs.index', ['bucket' => 'on_site']) }}" class="block rounded-xl border border-slate-200 bg-white p-5 hover:border-blue-400 hover:shadow-sm transition">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">On site</p>
                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $onSiteCount }}</p>
                <p class="mt-1 text-xs text-slate-500">Confirmed received — awaiting next move or collection.</p>
            </a>
            <a href="{{ route('body-builder.requests.index', ['status' => 'pending']) }}" class="block rounded-xl border border-slate-200 bg-white p-5 hover:border-blue-400 hover:shadow-sm transition">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending requests</p>
                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $pendingRequests }}</p>
                <p class="mt-1 text-xs text-slate-500">Movement requests waiting for dealer approval.</p>
            </a>
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Approved this week</p>
                <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $outboundThisWeek }}</p>
                <p class="mt-1 text-xs text-slate-500">Outbound moves approved since {{ now()->startOfWeek()->format('D, j M') }}.</p>
            </div>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Latest activity</h2>
                    <a href="{{ route('body-builder.jobs.index') }}" class="text-xs font-medium text-blue-600 hover:text-blue-800">View all →</a>
                </div>

                @if($recentJobs->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-slate-500">
                        No vehicles yet. Once a dealer dispatches one to your workshop it will appear here.
                    </div>
                @else
                    <ul role="list" class="divide-y divide-slate-100">
                        @foreach($recentJobs as $job)
                            <li>
                                <a href="{{ route('body-builder.jobs.show', $job) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-900">
                                            {{ $job->job_number ?: '—' }} ·
                                            {{ $job->brand?->name }} {{ $job->model_name ?: '' }}
                                            @if($job->vin) <span class="text-slate-400">VIN {{ $job->vin }}</span>@endif
                                        </p>
                                        <p class="truncate text-xs text-slate-500">
                                            From {{ $job->company?->name ?: '—' }}
                                            · to {{ $job->deliveryLocation?->company_name ?: '—' }}
                                        </p>
                                    </div>
                                    <span class="ml-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $job->status === Job::STATUS_DELIVERED ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' }}">
                                        {{ str_replace('_', ' ', $job->status) }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-900">Quick links</h2>
                </div>
                <ul role="list" class="divide-y divide-slate-100">
                    <li><a href="{{ route('body-builder.jobs.index', ['bucket' => 'on_site']) }}" class="block px-5 py-3 text-sm text-slate-700 hover:bg-slate-50">On-site vehicles ready for next move →</a></li>
                    <li><a href="{{ route('body-builder.requests.index') }}" class="block px-5 py-3 text-sm text-slate-700 hover:bg-slate-50">My movement requests →</a></li>
                    <li><a href="{{ route('body-builder.dealers.index') }}" class="block px-5 py-3 text-sm text-slate-700 hover:bg-slate-50">Linked dealers ({{ $linkedDealersCount }}) →</a></li>
                    <li><a href="{{ route('body-builder.help') }}" class="block px-5 py-3 text-sm text-slate-700 hover:bg-slate-50">How does this portal work? →</a></li>
                </ul>
            </div>
        </div>
    @endif
</div>
