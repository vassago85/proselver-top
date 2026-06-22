<?php

use App\Models\BodyBuilderRequest;
use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * Dealer-side list of "request a new body builder" submissions.
 * Read-only timeline of what's pending and how earlier ones were
 * resolved (approved / merged / rejected with ops decision notes).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public ?Company $company = null;

    public function mount(): void
    {
        $this->company = auth()->user()?->company();
        abort_unless($this->company, 403);
        abort_unless(auth()->user()->canManageBbLinks(), 403);
    }

    public function with(): array
    {
        $requests = BodyBuilderRequest::query()
            ->where('dealer_company_id', $this->company->id)
            ->with(['requestedBy:id,name', 'decidedBy:id,name', 'resolvedBodyBuilder:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return [
            'requests' => $requests,
            'pendingCount' => $requests->where('status', BodyBuilderRequest::STATUS_PENDING)->count(),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Body builder requests</x-slot:header>

    @if(session('success'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            Requests you've sent to ProSelver to add a body builder to the directory.
            @if($pendingCount)
                <span class="font-semibold text-amber-700">{{ $pendingCount }} pending review.</span>
            @endif
        </p>
        <div class="flex items-center gap-2">
            <a href="{{ route('customer.body-builders.index') }}" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Linked Body Builders
            </a>
            <a href="{{ route('customer.body-builders.requests.create') }}" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">
                + New request
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        @if($requests->isEmpty())
            <div class="px-6 py-12 text-center text-sm text-slate-500">
                You haven't submitted any body builder requests yet.
                <a href="{{ route('customer.body-builders.requests.create') }}" class="font-semibold text-blue-600 underline">Submit one</a>.
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Proposed name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Address</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Submitted</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Outcome</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($requests as $r)
                        <tr>
                            <td class="px-4 py-2 align-top text-sm font-medium text-slate-900">{{ $r->proposed_name }}</td>
                            <td class="px-4 py-2 align-top text-xs text-slate-600 max-w-xs">{{ $r->proposed_address }}</td>
                            <td class="px-4 py-2 align-top text-xs text-slate-500 whitespace-nowrap">
                                {{ $r->created_at?->format('j M Y') }}<br>
                                <span class="text-[10px] text-slate-400">by {{ $r->requestedBy?->name ?: '—' }}</span>
                            </td>
                            <td class="px-4 py-2 align-top text-xs">
                                @php
                                    $badge = match($r->status) {
                                        BodyBuilderRequest::STATUS_PENDING  => 'bg-amber-100 text-amber-800',
                                        BodyBuilderRequest::STATUS_APPROVED => 'bg-emerald-100 text-emerald-800',
                                        BodyBuilderRequest::STATUS_MERGED   => 'bg-blue-100 text-blue-800',
                                        BodyBuilderRequest::STATUS_REJECTED => 'bg-rose-100 text-rose-800',
                                        default                             => 'bg-slate-100 text-slate-700',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">
                                    {{ $r->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-2 align-top text-xs text-slate-600">
                                @if($r->resolvedBodyBuilder)
                                    <strong>{{ $r->resolvedBodyBuilder->name }}</strong>
                                @endif
                                @if($r->decision_notes)
                                    <div class="mt-1 italic text-slate-500">"{{ $r->decision_notes }}"</div>
                                @endif
                                @if($r->decidedBy)
                                    <div class="mt-0.5 text-[10px] text-slate-400">
                                        Decided by {{ $r->decidedBy?->name }} on {{ $r->decided_at?->format('j M Y') }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
