<?php

use App\Models\Job;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * BB direct-order list.  These are jobs where the BB IS the paying
 * customer (i.e. company_id = BB's company).  Different table from
 * /body-builder/jobs, which is the inbound work that dealers have
 * sent TO the BB.
 *
 * We deliberately don't reuse customer.orders.index because:
 *   - the BB layout is tablet-first / 3-up bottom nav, not the
 *     sidebarred customer chrome;
 *   - the columns we care about (where it's coming FROM, owner of
 *     the vehicle, owner approval state) differ from the dealer one.
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    use WithPagination;

    #[Url] public string $q = '';
    #[Url] public string $status = '';

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u && $u->companyIsBodyBuilder(), 403);
    }

    public function updatedQ(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    public function with(): array
    {
        $company = auth()->user()->company();
        $query = Job::query()
            ->where('company_id', $company->id)
            ->with(['pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'ownerCompany:id,name'])
            ->latest('scheduled_date')
            ->latest('id');

        if ($this->q !== '') {
            $needle = trim($this->q);
            $query->where(function ($q) use ($needle) {
                $q->where('vin', 'like', "%{$needle}%")
                    ->orWhere('registration', 'like', "%{$needle}%")
                    ->orWhere('job_number', 'like', "%{$needle}%");
            });
        }

        if ($this->status !== '') {
            if ($this->status === 'awaiting_owner') {
                $query->where('requires_owner_approval', true)
                    ->where('owner_approval_status', Job::OWNER_APPROVAL_PENDING);
            } elseif ($this->status === 'open') {
                $query->whereNotIn('status', [Job::STATUS_DELIVERED, Job::STATUS_CANCELLED, Job::STATUS_REJECTED]);
            } elseif ($this->status === 'delivered') {
                $query->where('status', Job::STATUS_DELIVERED);
            } elseif ($this->status === 'cancelled') {
                $query->whereIn('status', [Job::STATUS_CANCELLED, Job::STATUS_REJECTED]);
            }
        }

        return [
            'orders' => $query->paginate(20),
        ];
    }
}; ?>

<x-slot:header>My orders</x-slot:header>

<div>
    <div class="mb-3 flex items-center justify-between gap-3">
        <h1 class="text-lg font-semibold text-slate-900">My orders</h1>
        <a href="{{ route('body-builder.orders.create') }}"
            class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New order
        </a>
    </div>

    <div class="mb-3 flex flex-wrap gap-2">
        @php
            $statusPills = [
                ''                 => 'All',
                'open'             => 'Open',
                'awaiting_owner'   => 'Awaiting owner',
                'delivered'        => 'Delivered',
                'cancelled'        => 'Cancelled',
            ];
        @endphp
        @foreach($statusPills as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')" type="button" @class([
                'inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                'bg-slate-900 text-white border-slate-900' => $status === $key,
                'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => $status !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <input type="search" wire:model.live.debounce.300ms="q"
        placeholder="VIN, registration, or order number"
        class="mb-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">

    <div class="space-y-2">
        @forelse($orders as $job)
            <a href="{{ route('body-builder.orders.show', $job) }}"
                class="block rounded-xl border border-slate-200 bg-white p-3 shadow-sm hover:border-blue-300 hover:shadow">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-mono text-xs text-slate-500">{{ $job->job_number }}</div>
                        <div class="font-semibold text-slate-900 truncate">{{ $job->vin }}</div>
                        <div class="text-xs text-slate-600 truncate">
                            {{ $job->pickupLocation?->company_name ?: '—' }}
                            →
                            {{ $job->deliveryLocation?->company_name ?: '—' }}
                        </div>
                        @if($job->ownerCompany && $job->ownerCompany->id !== $job->company_id)
                            <div class="mt-1 text-[11px] text-slate-500">Vehicle owned by {{ $job->ownerCompany->name }}</div>
                        @endif
                    </div>
                    <div class="text-right shrink-0">
                        @if($job->isPendingOwnerApproval())
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                                Awaiting owner
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                {{ ucfirst(str_replace('_', ' ', $job->status)) }}
                            </span>
                        @endif
                        <div class="mt-1 text-[11px] text-slate-500">{{ $job->scheduled_date?->format('d M') }}</div>
                    </div>
                </div>
            </a>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                You haven't placed any direct orders yet.
                <div class="mt-3">
                    <a href="{{ route('body-builder.orders.create') }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">
                        Place an order
                    </a>
                </div>
            </div>
        @endforelse
    </div>

    @if($orders->hasPages())
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
