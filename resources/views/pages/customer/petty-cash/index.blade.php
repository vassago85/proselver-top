<?php

use App\Models\Company;
use App\Models\PettyCashEntry;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Dealer-side petty cash review queue.
 *
 * Scoped strictly to slips submitted by drivers belonging to the
 * authenticated user's company (and any sibling companies in the same
 * dealer group). Mirrors the admin queue's lifecycle — approve / reject
 * / mark reimbursed — but the cash float is the *dealer's* own, not
 * ProSelver's, so neither side touches the other's reconciliation.
 *
 * Auth:
 *  - canManageCompanyData() → can approve, reject and mark reimbursed
 *    (dealer_principal, customer_owner, customer_admin, sales managers,
 *    stock_controller).
 *  - canPlanMovements()      → can VIEW the queue (dispatcher tier) so
 *    operations can see what's outstanding for their drivers.
 *  - Per-slip authorisation is enforced via PettyCashEntryPolicy, which
 *    also intersects driver/job company with the user's operating
 *    companies — a crafted request from one dealer can't act on
 *    another dealer's slips.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url] public string $status = 'submitted';
    #[Url] public string $range = 'this_month';
    #[Url] public string $driverSearch = '';

    /** Per-row reason input; keyed by entry id. */
    public array $reasonDrafts = [];

    /** Per-row reimbursement reference input; keyed by entry id. */
    public array $reimburseDrafts = [];

    /** Per-driver EFT reference draft for the "Pay drivers" view. */
    public array $payDrafts = [];

    public ?Company $company = null;

    /** All company-ids the dealer is allowed to see (own + group siblings). */
    public array $scopeCompanyIds = [];

    public const RANGES = ['today', 'this_week', 'this_month', 'this_year', 'all'];
    public const STATUSES = ['submitted', 'approved', 'rejected', 'reimbursed', 'all'];

    public function mount(): void
    {
        $this->authorize('viewAny', PettyCashEntry::class);

        $u = auth()->user();
        $this->company = $u->companies()->first();
        abort_unless($this->company, 403, 'No company on file.');

        $this->scopeCompanyIds = array_values(array_unique(array_merge(
            $u->operatingCompanyIds(),
            $u->groupSiblingCompanyIds(),
        )));

        if (!in_array($this->status, self::STATUSES, true)) $this->status = 'submitted';
        if (!in_array($this->range, self::RANGES, true)) $this->range = 'this_month';
    }

    /** True for dealer-admin tier (can act on slips). */
    private function canManage(): bool
    {
        return (bool) auth()->user()?->canManageCompanyData();
    }

    public function approveEntry(int $id): void
    {
        $entry = $this->findScopedOrFail($id);
        $this->authorize('approve', $entry);

        $before = $entry->only(['status', 'approved_by_user_id', 'approved_at']);
        if ($entry->approve(auth()->user())) {
            AuditService::log('petty_cash_approved', 'petty_cash_entry', $entry->id, $before, [
                'status' => $entry->fresh()->status,
                'dealer_company_id' => $this->company?->id,
                'source' => 'dealer_portal',
            ]);
            session()->flash('success', 'Approved R ' . number_format($entry->amountRand(), 2) . '.');
        }
    }

    public function rejectEntry(int $id): void
    {
        $entry = $this->findScopedOrFail($id);
        $this->authorize('reject', $entry);

        $reason = trim($this->reasonDrafts[$id] ?? '');
        if ($reason === '') {
            $this->addError('reason_' . $id, 'Reason is required when rejecting.');
            return;
        }

        $before = $entry->only(['status', 'rejection_reason']);
        if ($entry->reject(auth()->user(), $reason)) {
            unset($this->reasonDrafts[$id]);
            AuditService::log('petty_cash_rejected', 'petty_cash_entry', $entry->id, $before, [
                'status' => $entry->fresh()->status,
                'rejection_reason' => $reason,
                'dealer_company_id' => $this->company?->id,
                'source' => 'dealer_portal',
            ]);
            session()->flash('success', 'Rejected R ' . number_format($entry->amountRand(), 2) . '.');
        }
    }

    public function reimburseEntry(int $id): void
    {
        if (!$this->canManage()) {
            abort(403);
        }

        $entry = $this->findScopedOrFail($id);
        $this->authorize('reimburse', $entry);

        // Same bank-send routing guard as the carrier-side flow: no
        // cellphone, no EFT.  Protects the audit trail from claiming a
        // payment was made when there's no way to actually send it.
        $driverPhone = $entry->driver?->phone ?: ($entry->driver?->driverProfile?->cellphone ?? null);
        if (!$driverPhone) {
            $this->addError('reimburse_' . $id, 'Driver has no cellphone on file — banking needs the cellphone to route the payment.');
            return;
        }

        $ref = trim($this->reimburseDrafts[$id] ?? '');
        $before = $entry->only(['status', 'reimbursed_at', 'reimbursement_reference']);
        if ($entry->reimburse(auth()->user(), $ref ?: null)) {
            unset($this->reimburseDrafts[$id]);
            AuditService::log('petty_cash_reimbursed', 'petty_cash_entry', $entry->id, $before, [
                'status' => $entry->fresh()->status,
                'reimbursed_at' => $entry->fresh()->reimbursed_at?->toIso8601String(),
                'reimbursement_reference' => $entry->fresh()->reimbursement_reference,
                'dealer_company_id' => $this->company?->id,
                'source' => 'dealer_portal',
            ]);
            session()->flash('success', 'Marked reimbursed.');
        }
    }

    /**
     * Mark every approved slip for a single driver as reimbursed with
     * the same EFT reference. Same one-click cash-out shortcut the
     * carrier Accounts user gets — but scoped to a dealer driver.
     */
    public function confirmDriverPayment(int $driverId): void
    {
        if (!$this->canManage()) {
            abort(403);
        }

        $driver = User::with('driverProfile')->findOrFail($driverId);
        $phone = $driver->phone ?: ($driver->driverProfile?->cellphone ?? null);
        if (!$phone) {
            $this->addError('pay_' . $driverId, 'Driver has no cellphone on file — banking needs the cellphone to route the payment.');
            return;
        }

        $ref = trim($this->payDrafts[$driverId] ?? '');

        $entries = $this->scopedQuery()
            ->where('driver_user_id', $driverId)
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->get();

        if ($entries->isEmpty()) {
            session()->flash('error', 'No approved slips to pay for that driver.');
            return;
        }

        $paid = 0;
        $totalCents = 0;
        foreach ($entries as $entry) {
            if (!auth()->user()?->can('reimburse', $entry)) {
                continue;
            }
            $before = $entry->only(['status', 'reimbursed_at', 'reimbursement_reference']);
            if ($entry->reimburse(auth()->user(), $ref ?: null)) {
                $paid++;
                $totalCents += (int) $entry->amount_cents;
                AuditService::log('petty_cash_reimbursed', 'petty_cash_entry', $entry->id, $before, [
                    'status' => $entry->status,
                    'reimbursed_at' => $entry->reimbursed_at?->toIso8601String(),
                    'reimbursement_reference' => $entry->reimbursement_reference,
                    'dealer_company_id' => $this->company?->id,
                    'bulk_driver_payout' => true,
                    'source' => 'dealer_portal',
                ]);
            }
        }

        if ($paid > 0) {
            AuditService::log('petty_cash_driver_payout', 'user', $driverId, null, [
                'slip_count' => $paid,
                'total' => round($totalCents / 100, 2),
                'reference' => $ref ?: null,
                'phone' => $phone,
                'dealer_company_id' => $this->company?->id,
                'paid_by_roles' => auth()->user()?->roles->pluck('slug')->values()->all() ?? [],
                'source' => 'dealer_portal',
            ]);
            unset($this->payDrafts[$driverId]);
            session()->flash('success', "Paid {$driver->name} — {$paid} slip" . ($paid === 1 ? '' : 's') . ' totalling R ' . number_format($totalCents / 100, 2) . '.');
        }
    }

    /**
     * Lookup helper that guarantees the entry the action is about to
     * mutate is one this user could see in the first place. Stops a
     * dealer admin from POSTing a foreign driver's id and acting on
     * a slip outside their scope.
     */
    private function findScopedOrFail(int $id): PettyCashEntry
    {
        $entry = PettyCashEntry::with(['driver.companies', 'driver.driverProfile', 'job:id,job_number,company_id'])
            ->whereKey($id)
            ->firstOrFail();

        // Reuse the policy's company-intersection logic — keeps the gate
        // in one place.
        if (!auth()->user()?->can('view', $entry)) {
            abort(403);
        }

        return $entry;
    }

    private function scopedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $companyIds = $this->scopeCompanyIds ?: [-1];

        return PettyCashEntry::query()
            ->with([
                'driver:id,name,phone',
                'driver.driverProfile:user_id,cellphone',
                'job:id,job_number,company_id',
                'job.company:id,name',
                'document:id,disk,path,mime_type',
                'approver:id,name',
            ])
            ->where(function ($q) use ($companyIds) {
                $q->whereHas('driver.companies', fn ($d) => $d->whereIn('companies.id', $companyIds))
                  ->orWhereHas('job', fn ($j) => $j->whereIn('company_id', $companyIds));
            });
    }

    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = $this->scopedQuery();

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
        $base = $this->scopedQuery();

        $now = now();
        match ($this->range) {
            'today'      => $base->whereDate('created_at', $now->toDateString()),
            'this_week'  => $base->where('created_at', '>=', $now->copy()->startOfWeek()),
            'this_month' => $base->where('created_at', '>=', $now->copy()->startOfMonth()),
            'this_year'  => $base->where('created_at', '>=', $now->copy()->startOfYear()),
            default      => null,
        };

        $counts = [
            'submitted' => (clone $base)->where('status', PettyCashEntry::STATUS_SUBMITTED)->sum('amount_cents'),
            'approved' => (clone $base)->where('status', PettyCashEntry::STATUS_APPROVED)->sum('amount_cents'),
            'rejected' => (clone $base)->where('status', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents'),
            'reimbursed' => (clone $base)->where('status', PettyCashEntry::STATUS_REIMBURSED)->sum('amount_cents'),
        ];

        // Pay-drivers rollup (window-independent, all outstanding approved
        // slips for this dealer's drivers).
        $payRows = $this->paymentRollup();

        return [
            'entries' => $this->buildQuery()->paginate(25),
            'counts' => $counts,
            'payRows' => $payRows,
            'canManage' => $this->canManage(),
        ];
    }

    private function paymentRollup(): \Illuminate\Support\Collection
    {
        $entries = $this->scopedQuery()
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->orderBy('created_at')
            ->get();

        return $entries
            ->groupBy('driver_user_id')
            ->map(function ($group, $driverUserId) {
                $first = $group->first();
                $driver = $first?->driver;
                $phone = $driver?->phone ?: ($driver?->driverProfile?->cellphone ?? null);
                return (object) [
                    'driver_user_id' => (int) $driverUserId,
                    'name' => $driver?->name ?? 'Unknown driver',
                    'phone' => $phone,
                    'slip_count' => $group->count(),
                    'total' => round($group->sum('amount_cents') / 100, 2),
                    'oldest_at' => $group->min('created_at'),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }
}; ?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <span>Petty Cash</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 border border-blue-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-blue-800">
                {{ $company?->name }}
            </span>
        </div>
    </x-slot:header>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-3 rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
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

    {{-- Pay drivers rollup --}}
    @if($payRows->isNotEmpty())
        <section class="mb-4 rounded-xl bg-white border-2 border-emerald-200 overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3 bg-emerald-50/60 border-b border-emerald-200">
                <div>
                    <p class="text-sm font-semibold text-emerald-900">Pay drivers — {{ $payRows->count() }} {{ Str::plural('driver', $payRows->count()) }} awaiting EFT</p>
                    <p class="text-[11px] text-emerald-800/80">
                        @if($canManage)
                            Pay each driver via cash-send to their cellphone, paste the EFT reference, then hit <strong>Confirm payment made</strong>.
                        @else
                            Read-only — only the account owner / admin can confirm payments.
                        @endif
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-[11px] uppercase tracking-wide text-emerald-700/80 font-semibold">Total outstanding</p>
                    <p class="text-lg font-bold tabular-nums text-emerald-900">R {{ number_format($payRows->sum('total'), 2) }}</p>
                </div>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach($payRows as $row)
                    <li class="p-4">
                        <div class="flex flex-wrap items-start gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-base font-bold text-slate-900">{{ $row->name }}</p>
                                <div class="mt-1 flex items-center gap-2">
                                    @if($row->phone)
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 font-mono text-sm font-semibold text-slate-900 select-all" title="Cellphone for bank-send">
                                            <svg class="h-3.5 w-3.5 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                                            {{ $row->phone }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-rose-50 border border-rose-200 px-2 py-1 font-mono text-xs font-semibold text-rose-700">No cellphone on file</span>
                                    @endif
                                    <span class="text-[11px] text-slate-500">{{ $row->slip_count }} {{ Str::plural('slip', $row->slip_count) }} · oldest {{ $row->oldest_at?->diffForHumans() }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-[10px] uppercase tracking-wide text-slate-500">To pay</p>
                                <p class="text-xl font-bold tabular-nums text-emerald-700">R {{ number_format((float) $row->total, 2) }}</p>
                            </div>
                            <div class="flex items-end gap-2 shrink-0 w-full sm:w-auto">
                                @if($canManage)
                                    <label class="block">
                                        <span class="block text-[10px] uppercase tracking-wide text-slate-500 mb-0.5">EFT reference (optional)</span>
                                        <input type="text" wire:model="payDrafts.{{ $row->driver_user_id }}" placeholder="e.g. EFT 27 May"
                                               class="rounded border border-slate-300 px-2 py-1.5 text-xs w-44">
                                    </label>
                                    <button type="button"
                                            wire:click="confirmDriverPayment({{ $row->driver_user_id }})"
                                            wire:confirm="Confirm payment of R {{ number_format((float) $row->total, 2) }} to {{ $row->name }}? Marks all {{ $row->slip_count }} approved slip(s) as reimbursed."
                                            @class([
                                                'rounded-lg px-3.5 py-2 text-xs font-semibold text-white transition-colors',
                                                'bg-blue-600 hover:bg-blue-500' => (bool) $row->phone,
                                                'bg-slate-300 cursor-not-allowed' => !$row->phone,
                                            ])
                                            @if(!$row->phone) disabled @endif>
                                        Confirm payment made
                                    </button>
                                @else
                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-3 py-2 text-[11px] font-semibold text-slate-500 italic">Owner/admin pays</span>
                                @endif
                            </div>
                        </div>
                        @error('pay_' . $row->driver_user_id) <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

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
        </div>
    </section>

    {{-- Slip list --}}
    <section class="rounded-xl bg-white border border-slate-200 overflow-hidden">
        @if($entries->isEmpty())
            <p class="p-8 text-center text-sm text-slate-500">No petty-cash entries match the current filters.</p>
        @else
        <ul class="divide-y divide-slate-100">
            @foreach($entries as $entry)
                @php
                    $driverPhone = $entry->driver?->phone ?: ($entry->driver?->driverProfile?->cellphone ?? null);
                @endphp
                <li class="p-4">
                    <div class="flex items-start gap-4">
                        <div class="h-20 w-20 shrink-0 rounded-lg bg-slate-100 overflow-hidden border border-slate-200">
                            @if($entry->document && str_starts_with((string) $entry->document->mime_type, 'image/'))
                                <a href="{{ route('document.view', $entry->document) }}" target="_blank">
                                    <img src="{{ route('document.view', $entry->document) }}" class="h-full w-full object-cover hover:opacity-90" alt="">
                                </a>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-base font-bold text-slate-900">R {{ number_format($entry->amount_cents / 100, 2) }}</span>
                                <span class="text-[10px] uppercase tracking-wide font-semibold rounded-full border px-2 py-0.5 {{ $entry->statusBadgeClasses() }}">{{ $entry->statusLabel() }}</span>
                                <span class="text-xs text-slate-500">{{ $entry->categoryLabel() }}</span>
                                @if($entry->merchant_name) <span class="text-xs text-slate-500">· {{ $entry->merchant_name }}</span> @endif
                            </div>

                            <p class="mt-1 text-xs text-slate-600">
                                <strong>{{ $entry->driver->name ?? 'Driver' }}</strong>
                                @if($driverPhone)
                                    @if($entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                        <span class="ml-1 inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] font-semibold text-slate-900 select-all" title="Pay to this cellphone">
                                            <svg class="h-3 w-3 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                                            {{ $driverPhone }}
                                        </span>
                                    @else
                                        <span class="ml-1 font-mono text-[11px] text-slate-500">{{ $driverPhone }}</span>
                                    @endif
                                @endif
                                @if($entry->job)
                                    · job <a href="{{ route('customer.orders.show', $entry->job) }}" class="text-blue-600 hover:underline">{{ $entry->job->job_number }}</a>
                                @endif
                                · submitted {{ $entry->created_at->diffForHumans() }}
                                @if($entry->spent_at) · slip dated {{ $entry->spent_at->format('d M Y') }} @endif
                            </p>

                            @if($entry->description)
                                <p class="mt-1 text-xs text-slate-700">{{ $entry->description }}</p>
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

                            {{-- Action row (admin tier only) --}}
                            @if($canManage)
                                @if($entry->status === \App\Models\PettyCashEntry::STATUS_SUBMITTED)
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <button type="button"
                                                wire:click="approveEntry({{ $entry->id }})"
                                                wire:confirm="Approve R {{ number_format($entry->amount_cents / 100, 2) }} for {{ $entry->driver->name ?? 'driver' }}?"
                                                class="rounded bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1 text-xs font-semibold">Approve</button>
                                        <input type="text" wire:model="reasonDrafts.{{ $entry->id }}" placeholder="Reason for rejection…"
                                               class="rounded border border-slate-300 px-2 py-1 text-xs w-56">
                                        <button type="button" wire:click="rejectEntry({{ $entry->id }})"
                                                class="rounded bg-rose-600 hover:bg-rose-500 text-white px-3 py-1 text-xs font-semibold">Reject</button>
                                        @error('reason_' . $entry->id) <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                    </div>
                                @elseif($entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <input type="text" wire:model="reimburseDrafts.{{ $entry->id }}" placeholder="EFT ref (optional)"
                                               class="rounded border border-slate-300 px-2 py-1 text-xs w-44">
                                        <button type="button" wire:click="reimburseEntry({{ $entry->id }})"
                                                wire:confirm="Mark this entry as reimbursed? (sent to {{ $driverPhone ?: 'driver' }})"
                                                class="rounded bg-blue-600 hover:bg-blue-500 text-white px-3 py-1 text-xs font-semibold">Mark reimbursed</button>
                                        @error('reimburse_' . $entry->id) <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                            @elseif($entry->status === \App\Models\PettyCashEntry::STATUS_SUBMITTED || $entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                <p class="mt-2 text-[11px] italic text-slate-500">View-only — only the account owner / admin can act on slips.</p>
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
