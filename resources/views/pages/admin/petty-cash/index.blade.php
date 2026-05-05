<?php

use App\Models\Company;
use App\Models\PettyCashEntry;
use App\Models\User;
use App\Services\AuditService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Admin petty cash review queue.
 *
 * Single pane of glass for every driver expense submission. Defaults
 * to the "submitted" (pending review) bucket so the operator's first
 * action on opening the page is to triage what's waiting.
 *
 * Filters:
 *   - status (submitted / approved / rejected / reimbursed / all)
 *   - driver
 *   - date range
 *
 * Actions:
 *   - Approve (single click; logs an audit row)
 *   - Reject with reason (modal-style inline reason input)
 *   - Mark reimbursed (with EFT reference)
 *
 * Auth: Internal staff + platform-owner only via PettyCashEntryPolicy.
 * Customers must NEVER see this page or its data — same reason ops
 * was given when JobDocumentPolicy was first written.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'submitted';

    #[Url]
    public string $range = 'this_month';

    #[Url]
    public string $driverSearch = '';

    /** Per-row reason input; keyed by entry id. */
    public array $reasonDrafts = [];

    /** Per-row reimbursement reference input; keyed by entry id. */
    public array $reimburseDrafts = [];

    public const RANGES = ['today', 'this_week', 'this_month', 'this_year', 'all'];
    public const STATUSES = ['submitted', 'approved', 'rejected', 'reimbursed', 'all'];

    public function mount(): void
    {
        $this->authorize('viewAny', PettyCashEntry::class);

        if (!in_array($this->status, self::STATUSES, true)) $this->status = 'submitted';
        if (!in_array($this->range, self::RANGES, true)) $this->range = 'this_month';
    }

    public function approveEntry(int $id): void
    {
        $entry = PettyCashEntry::findOrFail($id);
        $this->authorize('approve', $entry);

        $before = $entry->only(['status', 'approved_by_user_id', 'approved_at']);
        if ($entry->approve(auth()->user())) {
            AuditService::log('petty_cash_approved', 'petty_cash_entry', $entry->id, $before, $entry->fresh()->only(['status', 'approved_by_user_id', 'approved_at']));
            session()->flash('success', 'Approved R ' . number_format($entry->amountRand(), 2) . '.');
        }
    }

    public function rejectEntry(int $id): void
    {
        $entry = PettyCashEntry::findOrFail($id);
        $this->authorize('reject', $entry);

        $reason = trim($this->reasonDrafts[$id] ?? '');
        if ($reason === '') {
            $this->addError('reason_' . $id, 'Reason is required when rejecting.');
            return;
        }

        $before = $entry->only(['status', 'rejection_reason']);
        if ($entry->reject(auth()->user(), $reason)) {
            unset($this->reasonDrafts[$id]);
            AuditService::log('petty_cash_rejected', 'petty_cash_entry', $entry->id, $before, $entry->fresh()->only(['status', 'rejection_reason']));
            session()->flash('success', 'Rejected R ' . number_format($entry->amountRand(), 2) . '.');
        }
    }

    public function reimburseEntry(int $id): void
    {
        $entry = PettyCashEntry::findOrFail($id);
        $this->authorize('reimburse', $entry);

        $ref = trim($this->reimburseDrafts[$id] ?? '');
        $before = $entry->only(['status', 'reimbursed_at', 'reimbursement_reference']);
        if ($entry->reimburse(auth()->user(), $ref ?: null)) {
            unset($this->reimburseDrafts[$id]);
            AuditService::log('petty_cash_reimbursed', 'petty_cash_entry', $entry->id, $before, $entry->fresh()->only(['status', 'reimbursed_at', 'reimbursement_reference']));
            session()->flash('success', 'Marked reimbursed.');
        }
    }

    public function bulkApproveSubmitted(): void
    {
        $entries = $this->buildQuery()->where('status', PettyCashEntry::STATUS_SUBMITTED)->limit(50)->get();
        $count = 0;
        foreach ($entries as $entry) {
            if (auth()->user()->can('approve', $entry) && $entry->approve(auth()->user())) {
                AuditService::log('petty_cash_approved', 'petty_cash_entry', $entry->id, null, ['bulk' => true]);
                $count++;
            }
        }
        if ($count) {
            session()->flash('success', "Approved {$count} entries.");
        }
    }

    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = PettyCashEntry::query()
            ->with(['driver:id,name', 'job:id,job_number,company_id', 'job.company:id,name', 'document:id,disk,path,mime_type', 'approver:id,name']);

        if ($this->status !== 'all') {
            $q->where('status', $this->status);
        }

        $now = now();
        match ($this->range) {
            'today'      => $q->whereDate('created_at', $now->toDateString()),
            'this_week'  => $q->where('created_at', '>=', $now->copy()->startOfWeek()),
            'this_month' => $q->where('created_at', '>=', $now->copy()->startOfMonth()),
            'this_year'  => $q->where('created_at', '>=', $now->copy()->startOfYear()),
            default      => null,
        };

        if (trim($this->driverSearch) !== '') {
            $term = '%' . trim($this->driverSearch) . '%';
            $q->whereHas('driver', fn ($u) => $u->where('name', 'like', $term));
        }

        return $q->orderByDesc('created_at');
    }

    public function with(): array
    {
        $entries = $this->buildQuery()->paginate(25);

        // Counters across the chosen date range — useful "I've approved
        // R12,300 this month" feedback for the operator.
        $base = $this->buildQueryWithoutStatus();
        $counts = [
            'submitted' => (clone $base)->where('status', PettyCashEntry::STATUS_SUBMITTED)->sum('amount_cents'),
            'approved' => (clone $base)->where('status', PettyCashEntry::STATUS_APPROVED)->sum('amount_cents'),
            'rejected' => (clone $base)->where('status', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents'),
            'reimbursed' => (clone $base)->where('status', PettyCashEntry::STATUS_REIMBURSED)->sum('amount_cents'),
        ];

        return ['entries' => $entries, 'counts' => $counts];
    }

    private function buildQueryWithoutStatus(): \Illuminate\Database\Eloquent\Builder
    {
        $q = PettyCashEntry::query();

        $now = now();
        match ($this->range) {
            'today'      => $q->whereDate('created_at', $now->toDateString()),
            'this_week'  => $q->where('created_at', '>=', $now->copy()->startOfWeek()),
            'this_month' => $q->where('created_at', '>=', $now->copy()->startOfMonth()),
            'this_year'  => $q->where('created_at', '>=', $now->copy()->startOfYear()),
            default      => null,
        };

        if (trim($this->driverSearch) !== '') {
            $term = '%' . trim($this->driverSearch) . '%';
            $q->whereHas('driver', fn ($u) => $u->where('name', 'like', $term));
        }

        return $q;
    }
}; ?>

<div>
    <x-slot:header>Petty Cash</x-slot:header>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    {{-- Counter strip --}}
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <button type="button" wire:click="$set('status', 'submitted')" class="text-left rounded-xl bg-white border border-slate-200 p-3 hover:border-amber-300 transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Pending</p>
            <p class="mt-1 text-lg font-bold text-amber-600">R {{ number_format($counts['submitted'] / 100, 2) }}</p>
        </button>
        <button type="button" wire:click="$set('status', 'approved')" class="text-left rounded-xl bg-white border border-slate-200 p-3 hover:border-emerald-300 transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Approved (awaiting EFT)</p>
            <p class="mt-1 text-lg font-bold text-emerald-600">R {{ number_format($counts['approved'] / 100, 2) }}</p>
        </button>
        <button type="button" wire:click="$set('status', 'reimbursed')" class="text-left rounded-xl bg-white border border-slate-200 p-3 hover:border-blue-300 transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Reimbursed</p>
            <p class="mt-1 text-lg font-bold text-blue-600">R {{ number_format($counts['reimbursed'] / 100, 2) }}</p>
        </button>
        <button type="button" wire:click="$set('status', 'rejected')" class="text-left rounded-xl bg-white border border-slate-200 p-3 hover:border-rose-300 transition">
            <p class="text-[11px] uppercase tracking-wide text-slate-500">Rejected</p>
            <p class="mt-1 text-lg font-bold text-rose-600">R {{ number_format($counts['rejected'] / 100, 2) }}</p>
        </button>
    </section>

    {{-- Filters --}}
    <section class="mb-4 rounded-xl bg-white border border-slate-200 p-3 flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1 text-xs">
            <span class="text-slate-500 mr-1">Range:</span>
            @foreach (['today' => 'Today', 'this_week' => 'Week', 'this_month' => 'Month', 'this_year' => 'Year', 'all' => 'All'] as $val => $lbl)
                <button type="button" wire:click="$set('range', '{{ $val }}')"
                        @class([
                            'rounded-full px-2.5 py-1 font-semibold',
                            'bg-blue-600 text-white' => $range === $val,
                            'bg-slate-100 text-slate-600 hover:bg-slate-200' => $range !== $val,
                        ])>{{ $lbl }}</button>
            @endforeach
        </div>

        <div class="flex items-center gap-1 text-xs">
            <span class="text-slate-500 mr-1">Status:</span>
            @foreach (['submitted' => 'Pending', 'approved' => 'Approved', 'reimbursed' => 'Reimbursed', 'rejected' => 'Rejected', 'all' => 'All'] as $val => $lbl)
                <button type="button" wire:click="$set('status', '{{ $val }}')"
                        @class([
                            'rounded-full px-2.5 py-1 font-semibold',
                            'bg-blue-600 text-white' => $status === $val,
                            'bg-slate-100 text-slate-600 hover:bg-slate-200' => $status !== $val,
                        ])>{{ $lbl }}</button>
            @endforeach
        </div>

        <div class="flex items-center gap-2 text-xs ml-auto">
            <input type="search" wire:model.live.debounce.500ms="driverSearch" placeholder="Driver name…"
                   class="rounded border border-slate-300 px-3 py-1.5 text-xs w-44">
            @if($status === 'submitted' && $entries->total() > 0)
                <button type="button"
                        wire:click="bulkApproveSubmitted"
                        wire:confirm="Approve up to 50 pending entries on this page?"
                        class="rounded bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 text-xs font-semibold">
                    Bulk approve
                </button>
            @endif
        </div>
    </section>

    {{-- Entries --}}
    <section class="rounded-xl bg-white border border-slate-200 overflow-hidden">
        @if($entries->isEmpty())
            <p class="p-8 text-center text-sm text-slate-500">No entries match the current filters.</p>
        @else
        <ul class="divide-y divide-slate-100">
            @foreach($entries as $entry)
                <li class="p-4">
                    <div class="flex items-start gap-4">
                        {{-- Slip thumbnail --}}
                        <div class="h-20 w-20 shrink-0 rounded-lg bg-slate-100 overflow-hidden border border-slate-200">
                            @if($entry->document && str_starts_with((string) $entry->document->mime_type, 'image/'))
                                <a href="{{ route('document.view', $entry->document) }}" target="_blank">
                                    <img src="{{ route('document.view', $entry->document) }}" class="h-full w-full object-cover hover:opacity-90" alt="">
                                </a>
                            @endif
                        </div>

                        {{-- Body --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-base font-bold text-slate-900">R {{ number_format($entry->amount_cents / 100, 2) }}</span>
                                <span class="text-[10px] uppercase tracking-wide font-semibold rounded-full border px-2 py-0.5 {{ $entry->statusBadgeClasses() }}">{{ $entry->statusLabel() }}</span>
                                <span class="text-xs text-slate-500">{{ $entry->categoryLabel() }}</span>
                                @if($entry->merchant_name)
                                    <span class="text-xs text-slate-500">· {{ $entry->merchant_name }}</span>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-slate-600">
                                <strong>{{ $entry->driver->name ?? 'Driver' }}</strong>
                                @if($entry->job)
                                    · job <a href="{{ route('admin.orders.show', $entry->job_id) }}" class="text-blue-600 hover:underline">{{ $entry->job->job_number }}</a>
                                    @if($entry->job->company) · {{ $entry->job->company->name }} @endif
                                @endif
                                · submitted {{ $entry->created_at->diffForHumans() }}
                                @if($entry->spent_at) · slip dated {{ $entry->spent_at->format('d M Y') }} @endif
                            </p>

                            @if($entry->description)
                                <p class="mt-1 text-xs text-slate-700">{{ $entry->description }}</p>
                            @endif

                            @if($entry->ocr_amount_cents)
                                <p class="mt-1 text-[11px] text-slate-400">
                                    OCR read: R {{ number_format($entry->ocr_amount_cents / 100, 2) }}
                                    @if($entry->ocr_confidence) · {{ number_format($entry->ocr_confidence, 0) }}% confidence @endif
                                </p>
                            @endif

                            @if($entry->status === \App\Models\PettyCashEntry::STATUS_REJECTED && $entry->rejection_reason)
                                <p class="mt-1 text-xs text-rose-600"><strong>Rejected:</strong> {{ $entry->rejection_reason }}</p>
                            @endif
                            @if($entry->status === \App\Models\PettyCashEntry::STATUS_REIMBURSED)
                                <p class="mt-1 text-[11px] text-blue-600">
                                    Reimbursed {{ $entry->reimbursed_at?->format('d M Y') }}
                                    @if($entry->reimbursement_reference) · ref {{ $entry->reimbursement_reference }} @endif
                                </p>
                            @endif

                            {{-- Action row --}}
                            @if($entry->status === \App\Models\PettyCashEntry::STATUS_SUBMITTED)
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <button type="button"
                                            wire:click="approveEntry({{ $entry->id }})"
                                            wire:confirm="Approve R {{ number_format($entry->amount_cents / 100, 2) }} for {{ $entry->driver->name ?? 'driver' }}?"
                                            class="rounded bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1 text-xs font-semibold">Approve</button>

                                    <input type="text"
                                           wire:model="reasonDrafts.{{ $entry->id }}"
                                           placeholder="Reason for rejection…"
                                           class="rounded border border-slate-300 px-2 py-1 text-xs w-56">
                                    <button type="button"
                                            wire:click="rejectEntry({{ $entry->id }})"
                                            class="rounded bg-rose-600 hover:bg-rose-500 text-white px-3 py-1 text-xs font-semibold">Reject</button>
                                    @error('reason_' . $entry->id) <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                </div>
                            @elseif($entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <input type="text"
                                           wire:model="reimburseDrafts.{{ $entry->id }}"
                                           placeholder="EFT ref (optional)"
                                           class="rounded border border-slate-300 px-2 py-1 text-xs w-44">
                                    <button type="button"
                                            wire:click="reimburseEntry({{ $entry->id }})"
                                            wire:confirm="Mark this entry as reimbursed?"
                                            class="rounded bg-blue-600 hover:bg-blue-500 text-white px-3 py-1 text-xs font-semibold">Mark reimbursed</button>
                                </div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="p-3 border-t border-slate-100">{{ $entries->links() }}</div>
        @endif
    </section>
</div>
