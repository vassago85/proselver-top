<?php
use App\Models\PettyCashEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Driver-facing consolidated petty cash view.
 *
 * Shows the driver's own submissions across ALL their jobs, broken into
 * "this week / this month / everything", with totals and status pills.
 * The driver only ever sees their own rows (auth() in the query).
 */
new #[Layout('components.layouts.driver')] class extends Component {

    /** @var string week / month / all */
    public string $range = 'week';

    public function with(): array
    {
        $query = PettyCashEntry::with(['document:id,disk,path,mime_type', 'job:id,job_number'])
            ->where('driver_user_id', auth()->id())
            ->orderByDesc('created_at');

        $now = now();
        if ($this->range === 'week') {
            $query->where('created_at', '>=', $now->copy()->startOfWeek());
        } elseif ($this->range === 'month') {
            $query->where('created_at', '>=', $now->copy()->startOfMonth());
        }

        $entries = $query->limit(200)->get();

        // Totals broken out by status so the driver can see at a glance
        // "I have R3,200 still pending" — useful when they're chasing
        // an approval before pay day.
        $totals = [
            'submitted' => $entries->where('status', PettyCashEntry::STATUS_SUBMITTED)->sum('amount_cents'),
            'approved' => $entries->where('status', PettyCashEntry::STATUS_APPROVED)->sum('amount_cents'),
            'rejected' => $entries->where('status', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents'),
            'reimbursed' => $entries->where('status', PettyCashEntry::STATUS_REIMBURSED)->sum('amount_cents'),
        ];
        $totals['total'] = $totals['submitted'] + $totals['approved'] + $totals['reimbursed'];

        return [
            'entries' => $entries,
            'totals' => $totals,
        ];
    }
}; ?>

<div>
    <x-slot:header>My expenses</x-slot:header>

    {{-- Range selector --}}
    <div class="mt-3 flex gap-1 rounded-full bg-slate-100 p-1 text-xs font-semibold">
        @foreach (['week' => 'This week', 'month' => 'This month', 'all' => 'All'] as $val => $lbl)
            <button type="button"
                    wire:click="$set('range', '{{ $val }}')"
                    @class([
                        'flex-1 rounded-full py-1.5 transition',
                        'bg-white shadow-sm text-blue-700' => $range === $val,
                        'text-slate-500' => $range !== $val,
                    ])>
                {{ $lbl }}
            </button>
        @endforeach
    </div>

    {{-- Totals tiles --}}
    <section class="mt-4 grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-white border border-slate-200 p-3">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Pending</p>
            <p class="mt-1 text-lg font-semibold text-amber-600">R {{ number_format($totals['submitted'] / 100, 2) }}</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-3">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Approved</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600">R {{ number_format($totals['approved'] / 100, 2) }}</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-3">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Reimbursed</p>
            <p class="mt-1 text-lg font-semibold text-blue-600">R {{ number_format($totals['reimbursed'] / 100, 2) }}</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-3">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Rejected</p>
            <p class="mt-1 text-lg font-semibold text-rose-600">R {{ number_format($totals['rejected'] / 100, 2) }}</p>
        </div>
    </section>

    {{-- Entries --}}
    <section class="mt-4 rounded-xl bg-white border border-slate-200 divide-y divide-slate-100">
        @forelse($entries as $entry)
            <div class="flex items-start gap-3 p-3">
                <div class="h-12 w-12 shrink-0 rounded bg-slate-100 overflow-hidden">
                    @if($entry->document && str_starts_with((string) $entry->document->mime_type, 'image/'))
                        <a href="{{ route('document.view', $entry->document) }}" target="_blank">
                            <img src="{{ route('document.view', $entry->document) }}" class="h-full w-full object-cover" alt="">
                        </a>
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-900">{{ $entry->amountForDisplay() }}</p>
                        <span class="text-[10px] uppercase tracking-wide font-semibold rounded-full border px-2 py-0.5 {{ $entry->statusBadgeClasses() }}">
                            {{ $entry->statusLabel() }}
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">
                        {{ $entry->categoryLabel() }}
                        @if($entry->merchant_name) · {{ $entry->merchant_name }} @endif
                        @if($entry->job) ·
                            <a href="{{ route('driver.job', $entry->job_id) }}" class="text-blue-600 hover:underline">{{ $entry->job->job_number }}</a>
                        @endif
                    </p>
                    <p class="text-[11px] text-slate-400 mt-0.5">
                        {{ $entry->created_at->format('D, d M H:i') }}
                        @if($entry->spent_at && $entry->spent_at->toDateString() !== $entry->created_at->toDateString())
                            · slip {{ $entry->spent_at->format('d M') }}
                        @endif
                    </p>
                    @if($entry->status === \App\Models\PettyCashEntry::STATUS_REJECTED && $entry->rejection_reason)
                        <p class="mt-1 text-[11px] text-rose-600">{{ $entry->rejection_reason }}</p>
                    @endif
                </div>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-slate-500">No expenses for this range.</p>
        @endforelse
    </section>
</div>
