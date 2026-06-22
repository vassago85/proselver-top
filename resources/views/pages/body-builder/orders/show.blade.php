<?php

use App\Models\Job;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/*
 * BB direct-order detail.  Shows status, owner-approval state, and a
 * "cancel" affordance while the order is still pending the owner's
 * sign-off (after that, ops handles cancellations through the main
 * customer-order timeline).
 */
new #[Layout('components.layouts.body-builder')] class extends Component
{
    public Job $job;

    public function mount(Job $job): void
    {
        $u = auth()->user();
        abort_unless($u && $u->companyIsBodyBuilder(), 403);
        // Tenancy: BB can only see their own direct orders.
        abort_unless($job->company_id === $u->company()?->id, 403);
        $this->job = $job->load(['pickupLocation', 'deliveryLocation', 'brand', 'vehicleClass', 'ownerCompany', 'ownerApprovedBy']);
    }

    public function cancel(): void
    {
        if (!in_array($this->job->status, [Job::STATUS_RECEIVED, Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMED, Job::STATUS_PENDING_VERIFICATION], true)) {
            session()->flash('error', 'This order has already progressed -- contact Proselver ops to cancel.');
            return;
        }

        $this->job->update([
            'status' => Job::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cancelled by body builder before dispatch.',
        ]);

        session()->flash('success', 'Order cancelled.');
        $this->redirect(route('body-builder.orders.index'), navigate: true);
    }
}; ?>

<x-slot:header>Order {{ $job->job_number }}</x-slot:header>

<div class="space-y-4">

    <a href="{{ route('body-builder.orders.index') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-700">
        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Back to orders
    </a>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $job->job_number }}</div>
                <h1 class="text-lg font-semibold text-slate-900">{{ $job->vin }}</h1>
                <div class="text-xs text-slate-600">{{ $job->brand?->name }} {{ $job->model_name }}</div>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                {{ ucfirst(str_replace('_', ' ', $job->status)) }}
            </span>
        </div>
    </div>

    @if($job->isPendingOwnerApproval())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="text-sm font-semibold text-amber-900">Waiting for {{ $job->ownerCompany?->name }} to approve</div>
            <p class="mt-1 text-xs text-amber-800">
                Proselver won't dispatch this movement until the vehicle owner signs off.  You'll see the status
                change here once they do.
            </p>
        </div>
    @elseif($job->owner_approval_status === Job::OWNER_APPROVAL_REJECTED)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
            <div class="text-sm font-semibold text-rose-900">{{ $job->ownerCompany?->name }} rejected this movement</div>
            @if($job->owner_decision_notes)
                <p class="mt-1 text-xs text-rose-800">"{{ $job->owner_decision_notes }}"</p>
            @endif
            <p class="mt-1 text-xs text-rose-700">
                The vehicle stays put.  Speak to the owner before re-booking.
            </p>
        </div>
    @elseif($job->owner_approval_status === Job::OWNER_APPROVAL_APPROVED)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
            {{ $job->ownerCompany?->name }} approved on {{ $job->owner_approved_at?->format('d M Y H:i') }}.
        </div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm space-y-3 text-sm">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pickup</div>
            <div class="text-slate-900">{{ $job->pickupLocation?->company_name }}</div>
            <div class="text-xs text-slate-500">{{ $job->pickupLocation?->city }}</div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Delivery</div>
            <div class="text-slate-900">{{ $job->deliveryLocation?->company_name }}</div>
            <div class="text-xs text-slate-500">{{ $job->deliveryLocation?->city }}</div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Scheduled</div>
            <div class="text-slate-900">
                {{ $job->scheduled_date?->format('D, d M Y') }}
                @if($job->scheduled_ready_time)
                    · ready {{ $job->scheduled_ready_time->format('H:i') }}
                @endif
            </div>
        </div>
        @if($job->customer_notes)
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</div>
                <div class="text-slate-700 text-sm whitespace-pre-wrap">{{ $job->customer_notes }}</div>
            </div>
        @endif
    </div>

    @if(in_array($job->status, [Job::STATUS_RECEIVED, Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, Job::STATUS_CONFIRMED, Job::STATUS_PENDING_VERIFICATION], true))
        <button wire:click="cancel"
            wire:confirm="Cancel this order?"
            type="button"
            class="w-full rounded-xl border border-rose-300 bg-white px-4 py-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">
            Cancel order
        </button>
    @endif
</div>
