<?php

use App\Models\Company;
use App\Models\PettyCashEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
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

    #[Url(as: 'view', except: 'slips')]
    public string $tab = 'slips';

    #[Url]
    public string $status = 'submitted';

    #[Url]
    public string $range = 'this_month';

    #[Url]
    public string $driverSearch = '';

    /**
     * When $range === 'month', the picked month as YYYY-MM.  Empty
     * otherwise (falls back to the current calendar month).  Added
     * at accounts' request so month-end recon can be run for any
     * specific month, not just "this month".
     */
    #[Url]
    public string $monthPick = '';

    /** Per-row reason input; keyed by entry id. */
    public array $reasonDrafts = [];

    /** Per-row reimbursement reference input; keyed by entry id. */
    public array $reimburseDrafts = [];

    // Owner-only: edit the slip-scan incentive rate (R per approved slip).
    public ?float $incentiveAmountDraft = null;

    /** Per-driver EFT reference draft for the "Pay drivers" tab. */
    public array $payDrafts = [];

    public const RANGES = ['today', 'this_week', 'this_month', 'this_year', 'all', 'month'];
    public const STATUSES = ['submitted', 'approved', 'rejected', 'reimbursed', 'all'];
    public const TABS = ['slips', 'pay', 'trips', 'incentives'];

    public function mount(): void
    {
        $this->authorize('viewAny', PettyCashEntry::class);

        if (!in_array($this->status, self::STATUSES, true)) $this->status = 'submitted';
        if (!in_array($this->range, self::RANGES, true)) $this->range = 'this_month';
        if (!in_array($this->tab, self::TABS, true)) $this->tab = 'slips';

        $this->incentiveAmountDraft = (float) SystemSetting::get('slip_scan_incentive_amount', 5);
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
            $this->resetPage();
        }
    }

    /**
     * When accounts picks a specific month from the native <input
     * type="month">, we also flip range to 'month' so the counters
     * and list refresh against the chosen window without them having
     * to click the chip afterwards.
     */
    public function updatedMonthPick($value): void
    {
        if (trim((string) $value) !== '') {
            $this->range = 'month';
            $this->resetPage();
        }
    }

    /**
     * Return [fromCarbon, toCarbon] for the current range, or [null, null]
     * for 'all'.  Shared by buildQuery() and buildQueryWithoutStatus() so
     * both the counters and the paginated list see the same window.
     */
    private function rangeBounds(): array
    {
        $now = now();
        return match ($this->range) {
            'today'      => [$now->copy()->startOfDay(),  $now->copy()->endOfDay()],
            'this_week'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'this_year'  => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'month'      => (function () use ($now) {
                $picked = $this->parseMonthPick() ?? $now->copy()->startOfMonth();
                return [$picked->copy()->startOfMonth(), $picked->copy()->endOfMonth()];
            })(),
            default      => [null, null],
        };
    }

    private function parseMonthPick(): ?\Illuminate\Support\Carbon
    {
        $raw = trim($this->monthPick);
        if ($raw === '' || !preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::createFromFormat('!Y-m', $raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Save the slip-scan incentive rate.  Owner-only; rejected with a
     * 403 for any other role (server-side guard since the UI hides the
     * input from non-owners anyway).
     */
    public function saveIncentiveRate(): void
    {
        if (!auth()->user()?->isOwner()) {
            abort(403);
        }

        $this->validate([
            'incentiveAmountDraft' => 'required|numeric|min:0|max:10000',
        ]);

        $before = (float) SystemSetting::get('slip_scan_incentive_amount', 5);
        $new = round((float) $this->incentiveAmountDraft, 2);

        if (abs($before - $new) > 0.001) {
            SystemSetting::set('slip_scan_incentive_amount', $new, 'float', 'ZAR earned per approved petty-cash slip. Owner only.');
            AuditService::log('slip_incentive_rate_changed', 'system_setting', 0, ['amount' => $before], ['amount' => $new]);
        }

        session()->flash('success', 'Incentive rate set to R ' . number_format($new, 2) . ' per approved slip.');
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
        // Ops can view approved slips and the driver phone, but only
        // Accounts (with Owner/Dev fallback) may actually flip a slip
        // to reimbursed — the EFT is their responsibility.
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        $entry = PettyCashEntry::findOrFail($id);
        $this->authorize('reimburse', $entry);

        // Bank-send routing: the reimbursement is paid to the driver's
        // cellphone (SA cash-send / Send-iMali style).  If the driver
        // has no phone on file the EFT can't actually be sent, so refuse
        // to mark the slip reimbursed -- protects the audit trail from
        // claiming a payment was made when it can't have been.
        $driverPhone = $entry->driver?->phone ?: ($entry->driver?->driverProfile?->cellphone ?? null);
        if (!$driverPhone) {
            $this->addError('reimburse_' . $id, 'Driver has no cellphone on file — banking needs the cellphone to route the payment.');
            return;
        }

        $ref = trim($this->reimburseDrafts[$id] ?? '');
        $before = $entry->only(['status', 'reimbursed_at', 'reimbursement_reference']);
        if ($entry->reimburse(auth()->user(), $ref ?: null)) {
            unset($this->reimburseDrafts[$id]);
            AuditService::log('petty_cash_reimbursed', 'petty_cash_entry', $entry->id, $before, $entry->fresh()->only(['status', 'reimbursed_at', 'reimbursement_reference']));
            session()->flash('success', 'Marked reimbursed.');
        }
    }

    /**
     * Confirm an EFT to a single driver — flips every approved slip for
     * that driver (across all time, not just the current range) to
     * reimbursed in one go, stamping the same EFT reference on each so
     * the bank statement reconciles cleanly back to the slips.
     *
     * Policy check is enforced per slip via the existing PettyCashEntry
     * policy ('reimburse') so a crafted Livewire call can't bypass the
     * server-side gate.
     *
     * Refuses to run if the driver has no cellphone on file — bank-send
     * needs the phone, same protection as reimburseEntry().
     */
    public function confirmDriverPayment(int $driverId): void
    {
        // Same role gate as the per-slip path. Belt-and-braces — the
        // policy is still enforced per slip inside the loop, but the
        // up-front check gives a clean 403 instead of silently looping
        // through zero authorised entries.
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        $driver = User::with('driverProfile')->findOrFail($driverId);
        $phone = $driver->phone ?: ($driver->driverProfile?->cellphone ?? null);
        if (!$phone) {
            $this->addError('pay_' . $driverId, 'Driver has no cellphone on file — banking needs the cellphone to route the payment.');
            return;
        }

        $ref = trim($this->payDrafts[$driverId] ?? '');

        $entries = PettyCashEntry::query()
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
                    'bulk_driver_payout' => true,
                ]);
            }
        }

        if ($paid > 0) {
            AuditService::log('petty_cash_driver_payout', 'user', $driverId, null, [
                'slip_count' => $paid,
                'total' => round($totalCents / 100, 2),
                'reference' => $ref ?: null,
                'phone' => $phone,
                'paid_by_roles' => auth()->user()?->roles->pluck('slug')->values()->all() ?? [],
            ]);
            unset($this->payDrafts[$driverId]);
            session()->flash('success', "Paid {$driver->name} — {$paid} slip" . ($paid === 1 ? '' : 's') . ' totalling R ' . number_format($totalCents / 100, 2) . '.');
        }
    }

    /**
     * Per-driver rollup of every approved (not yet reimbursed) slip,
     * regardless of date range.  Drives the "Pay drivers" tab so
     * Accounts can do one EFT per driver against the full outstanding
     * balance instead of one click per slip.
     */
    private function paymentRollup(): \Illuminate\Support\Collection
    {
        $entries = PettyCashEntry::query()
            ->with([
                'driver:id,name,phone',
                'driver.driverProfile:user_id,cellphone',
                'job:id,job_number',
            ])
            ->where('status', PettyCashEntry::STATUS_APPROVED)
            ->orderBy('created_at')
            ->get();

        return $entries
            ->groupBy('driver_user_id')
            ->map(function ($group, $driverUserId) {
                $first = $group->first();
                $driver = $first?->driver;
                $phone = $driver?->phone ?: ($driver?->driverProfile?->cellphone ?? null);
                $oldest = $group->min('created_at');
                return (object) [
                    'driver_user_id' => (int) $driverUserId,
                    'name' => $driver?->name ?? 'Unknown driver',
                    'phone' => $phone,
                    'slip_count' => $group->count(),
                    'total' => round($group->sum('amount_cents') / 100, 2),
                    'oldest_at' => $oldest,
                    'job_numbers' => $group->pluck('job.job_number')->filter()->unique()->take(6)->values()->all(),
                    'slips' => $group->values(),
                ];
            })
            ->sortByDesc('total')
            ->values();
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
            ->with([
                'driver:id,name,phone',
                'driver.driverProfile:user_id,cellphone',
                'job:id,job_number,company_id',
                'job.company:id,name',
                'document:id,disk,path,mime_type',
                'approver:id,name',
            ]);

        if ($this->status !== 'all') {
            $q->where('status', $this->status);
        }

        [$from, $to] = $this->rangeBounds();
        if ($from) $q->where('created_at', '>=', $from);
        if ($to)   $q->where('created_at', '<=', $to);

        if (trim($this->driverSearch) !== '') {
            $term = '%' . trim($this->driverSearch) . '%';
            $q->whereHas('driver', fn ($u) => $u->where('name', 'like', $term));
        }

        return $q->orderByDesc('created_at');
    }

    public function with(): array
    {
        // Counters across the chosen date range — shown on every tab so
        // the operator's "what's the financial state right now" question
        // is always one glance away.
        $base = $this->buildQueryWithoutStatus();
        $counts = [
            'submitted' => (clone $base)->where('status', PettyCashEntry::STATUS_SUBMITTED)->sum('amount_cents'),
            'approved' => (clone $base)->where('status', PettyCashEntry::STATUS_APPROVED)->sum('amount_cents'),
            'rejected' => (clone $base)->where('status', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents'),
            'reimbursed' => (clone $base)->where('status', PettyCashEntry::STATUS_REIMBURSED)->sum('amount_cents'),
        ];

        $incentiveAmount = (float) SystemSetting::get('slip_scan_incentive_amount', 5);
        $incentiveEnabled = (bool) SystemSetting::get('slip_scan_incentive_enabled', true);

        $payload = [
            'counts' => $counts,
            'incentiveAmount' => $incentiveAmount,
            'incentiveEnabled' => $incentiveEnabled,
        ];

        if ($this->tab === 'slips') {
            $payload['entries'] = $this->buildQuery()->paginate(25);
        } elseif ($this->tab === 'pay') {
            $payload['payRows'] = $this->paymentRollup();
        } elseif ($this->tab === 'trips') {
            $payload['tripGroups'] = $this->tripReconciliation();
        } else {
            $payload['incentiveRows'] = $this->incentiveRollup($incentiveAmount);
        }

        return $payload;
    }

    /**
     * Group submitted slips by job for the "By Trip" reconciliation view.
     * For each job we expose: the advance issued (from transport_jobs)
     * vs the per-category spend (from petty_cash_entries), per-category
     * variance, the slip list, and the driver's payment-routing phone.
     *
     * Excludes entries that have no job_id (in-between-jobs expenses) --
     * those don't have an advance to reconcile against and surface in
     * the slip-level view instead.
     */
    private function tripReconciliation(): \Illuminate\Support\Collection
    {
        $base = $this->buildQueryWithoutStatus()
            ->whereNotNull('job_id');

        $jobIds = (clone $base)->distinct()->pluck('job_id');

        if ($jobIds->isEmpty()) {
            return collect();
        }

        // Pre-load the slips so the per-trip card can show its slip list
        // without a per-job round-trip back to the DB.
        $slipsByJob = (clone $base)
            ->with(['driver:id,name,phone', 'driver.driverProfile:user_id,cellphone', 'document:id,disk,path,mime_type'])
            ->whereIn('job_id', $jobIds)
            ->get()
            ->groupBy('job_id');

        $jobs = \App\Models\Job::query()
            ->whereIn('id', $jobIds)
            ->with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'driver:id,name,phone', 'driver.driverProfile:user_id,cellphone'])
            ->orderByDesc('advance_assigned_at')
            ->orderByDesc('updated_at')
            ->get();

        return $jobs->map(function ($job) use ($slipsByJob) {
            $slips = $slipsByJob->get($job->id, collect());

            // Map slip categories onto the same buckets used for the
            // advance so the variance table compares like-with-like.
            $approvedByCategory = $slips
                ->where('status', PettyCashEntry::STATUS_APPROVED)
                ->groupBy('category')
                ->map(fn ($group) => $group->sum('amount_cents') / 100);

            $tollsSpent = (float) ($approvedByCategory->get(PettyCashEntry::CATEGORY_TOLL, 0));
            $foodSpent = (float) ($approvedByCategory->get(PettyCashEntry::CATEGORY_FOOD, 0));
            $accomSpent = (float) ($approvedByCategory->get(PettyCashEntry::CATEGORY_ACCOMMODATION, 0));
            // Parking + taxi roll into the "taxi" advance line for v1.
            $taxiSpent = (float) ($approvedByCategory->get(PettyCashEntry::CATEGORY_PARKING, 0) + ($approvedByCategory->get('taxi_slip', 0)));

            $totalSpent = round($slips->where('status', '!=', PettyCashEntry::STATUS_REJECTED)->sum('amount_cents') / 100, 2);
            $advanceIssued = (float) ($job->advance_total ?? 0);

            return (object) [
                'job' => $job,
                'driverPhone' => $job->driver?->phone ?: ($job->driver?->driverProfile?->cellphone ?? null),
                'advance' => [
                    'tolls' => (float) ($job->advance_tolls ?? 0),
                    'accommodation' => (float) ($job->advance_accommodation ?? 0),
                    'taxi' => (float) ($job->advance_taxi ?? 0),
                    'food' => (float) ($job->advance_food ?? 0),
                    'total' => $advanceIssued,
                ],
                'spent' => [
                    'tolls' => $tollsSpent,
                    'accommodation' => $accomSpent,
                    'taxi' => $taxiSpent,
                    'food' => $foodSpent,
                    'total' => $totalSpent,
                ],
                'variance' => round($totalSpent - $advanceIssued, 2),
                'slips' => $slips,
                'slipCount' => $slips->count(),
                'approvedSlipCount' => $slips->where('status', PettyCashEntry::STATUS_APPROVED)->count(),
                'pendingSlipCount' => $slips->where('status', PettyCashEntry::STATUS_SUBMITTED)->count(),
            ];
        });
    }

    /**
     * Per-driver rollup of approved scanned slips × the configured
     * incentive amount.  Drives the "Incentives" tab.  Only the owner
     * may change the rate; everyone with petty-cash view rights can
     * see the rollup.
     */
    private function incentiveRollup(float $rate): array
    {
        $base = $this->buildQueryWithoutStatus()
            ->where('status', PettyCashEntry::STATUS_APPROVED);

        $rows = (clone $base)
            ->select('driver_user_id', DB::raw('COUNT(*) as approved_count'))
            ->groupBy('driver_user_id')
            ->orderByDesc('approved_count')
            ->get();

        $userIds = $rows->pluck('driver_user_id')->filter()->values()->all();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->with('driverProfile:user_id,cellphone')
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        return $rows->map(function ($r) use ($users, $rate) {
            $u = $users->get($r->driver_user_id);
            return [
                'driver_user_id' => $r->driver_user_id,
                'name' => $u?->name ?? 'Unknown driver',
                'phone' => $u?->phone ?: $u?->driverProfile?->cellphone,
                'approved_count' => (int) $r->approved_count,
                'earned' => round($r->approved_count * $rate, 2),
            ];
        })->all();
    }

    private function buildQueryWithoutStatus(): \Illuminate\Database\Eloquent\Builder
    {
        $q = PettyCashEntry::query();

        [$from, $to] = $this->rangeBounds();
        if ($from) $q->where('created_at', '>=', $from);
        if ($to)   $q->where('created_at', '<=', $to);

        if (trim($this->driverSearch) !== '') {
            $term = '%' . trim($this->driverSearch) . '%';
            $q->whereHas('driver', fn ($u) => $u->where('name', 'like', $term));
        }

        return $q;
    }
}; ?>

<div>
    <x-slot:header>Petty Cash</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

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

    {{-- Tabs: slip-level | trip-level reconciliation | scan incentives --}}
    <div class="mb-4 flex items-center gap-1 rounded-xl bg-slate-100 p-1 w-full sm:w-fit">
        <button type="button" wire:click="switchTab('slips')"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'slips' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            By slip
        </button>
        <button type="button" wire:click="switchTab('pay')"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'pay' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            Pay drivers
        </button>
        <button type="button" wire:click="switchTab('trips')"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'trips' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            By trip · reconcile
        </button>
        <button type="button" wire:click="switchTab('incentives')"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'incentives' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            Scan incentives
        </button>
    </div>

    {{-- Range filter is shared by every tab (drives the counters and rollups). --}}
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
            {{-- Specific-month picker (month-end recon).  Setting a
                 value flips $range to 'month' via updatedMonthPick(). --}}
            <label class="ml-2 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">
                <span class="text-[10px] uppercase tracking-wider">Pick month</span>
                <input type="month"
                    wire:model.live="monthPick"
                    class="rounded-md border-slate-300 bg-white px-1.5 py-0.5 text-[11px] shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    max="{{ now()->format('Y-m') }}"/>
            </label>
        </div>

        @if($tab === 'slips')
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
        @endif

        <div class="flex items-center gap-2 text-xs ml-auto">
            <input type="search" wire:model.live.debounce.500ms="driverSearch" placeholder="Driver name…"
                   class="rounded border border-slate-300 px-3 py-1.5 text-xs w-44">
            @if($tab === 'slips' && $status === 'submitted' && isset($entries) && $entries->total() > 0)
                <button type="button"
                        wire:click="bulkApproveSubmitted"
                        wire:confirm="Approve up to 50 pending entries on this page?"
                        class="rounded bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 text-xs font-semibold">
                    Bulk approve
                </button>
            @endif
        </div>
    </section>

    @if($tab === 'slips')
    {{-- Slip-level entries (existing behaviour). --}}
    <section class="rounded-xl bg-white border border-slate-200 overflow-hidden">
        @if($entries->isEmpty())
            <p class="p-8 text-center text-sm text-slate-500">No entries match the current filters.</p>
        @else
        <ul class="divide-y divide-slate-100">
            @foreach($entries as $entry)
                @php
                    $driverPhone = $entry->driver?->phone ?: ($entry->driver?->driverProfile?->cellphone ?? null);
                @endphp
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
                                @if($driverPhone)
                                    @if($entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                        <span class="ml-1 inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[12px] font-semibold text-slate-900 select-all" title="Pay to this cellphone">
                                            <svg class="h-3 w-3 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                                            {{ $driverPhone }}
                                        </span>
                                    @else
                                        <span class="ml-1 font-mono text-[11px] text-slate-500" title="Bank-send routing key">{{ $driverPhone }}</span>
                                    @endif
                                @elseif($entry->status === \App\Models\PettyCashEntry::STATUS_APPROVED)
                                    <span class="ml-1 inline-flex items-center gap-1 rounded bg-rose-50 border border-rose-200 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-rose-700" title="Cellphone required for bank-send">
                                        no phone on file
                                    </span>
                                @endif
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
                                @php
                                    $canPay = auth()->user()?->isAccounts() || auth()->user()?->isOwner() || auth()->user()?->isDeveloper();
                                @endphp
                                @if($canPay)
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <input type="text"
                                               wire:model="reimburseDrafts.{{ $entry->id }}"
                                               placeholder="EFT ref (optional)"
                                               class="rounded border border-slate-300 px-2 py-1 text-xs w-44">
                                        <button type="button"
                                                wire:click="reimburseEntry({{ $entry->id }})"
                                                wire:confirm="Mark this entry as reimbursed? (sent to {{ $driverPhone ?: 'driver' }})"
                                                class="rounded bg-blue-600 hover:bg-blue-500 text-white px-3 py-1 text-xs font-semibold">Mark reimbursed</button>
                                        @error('reimburse_' . $entry->id) <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                    </div>
                                @else
                                    <p class="mt-2 text-[11px] italic text-slate-500">Awaiting Accounts EFT (view only — only Accounts can mark reimbursed).</p>
                                @endif
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="p-3 border-t border-slate-100">{{ $entries->links() }}</div>
        @endif
    </section>

    @elseif($tab === 'pay')
    {{-- ──────────────────────────────────────────────────────────────
         Pay drivers — one row per driver with prominent name + phone,
         the outstanding rand total, and a single "Confirm payment made"
         button that reimburses every approved slip for that driver in
         one shot (across all dates, not just the current range — the
         payment is a cash-out, it doesn't care about analysis windows).

         Auth: only Accounts (with Owner/Developer fallback) can confirm
         payment.  Ops see the list (and the phones) so they can answer
         a driver query, but the EFT itself is Accounts' responsibility.
         ────────────────────────────────────────────────────────────── --}}
    @php
        $canPay = auth()->user()?->isAccounts() || auth()->user()?->isOwner() || auth()->user()?->isDeveloper();
    @endphp
    <section class="space-y-3">
        @php
            $grandTotal = $payRows->sum('total');
            $grandSlips = $payRows->sum('slip_count');
        @endphp

        <div class="rounded-xl bg-emerald-50/60 border border-emerald-200 p-3 flex flex-wrap items-center gap-3">
            <div>
                <p class="text-[11px] uppercase tracking-wide text-emerald-800/80 font-semibold">Approved — awaiting EFT</p>
                <p class="text-xl font-bold tabular-nums text-emerald-900">R {{ number_format($grandTotal, 2) }}</p>
                <p class="text-[11px] text-emerald-700/80">{{ $grandSlips }} {{ Str::plural('slip', $grandSlips) }} across {{ $payRows->count() }} {{ Str::plural('driver', $payRows->count()) }}</p>
            </div>
            <div class="ml-auto text-[11px] text-emerald-700/80 max-w-md">
                @if($canPay)
                    Pay each driver via cash-send to their cellphone, paste the EFT reference, then hit <strong>Confirm payment made</strong> — every approved slip for that driver flips to reimbursed.
                @else
                    Read-only view. Only <strong>Accounts</strong> (with Owner fallback) can confirm payments — ops sees this so they can answer driver queries about outstanding amounts.
                @endif
            </div>
        </div>

        @if($payRows->isEmpty())
            <div class="rounded-xl bg-white border border-slate-200 p-8 text-center text-sm text-slate-500">
                Nothing to pay — no approved slips waiting for EFT.
            </div>
        @else
            <div class="rounded-xl bg-white border border-slate-200 overflow-hidden">
                <ul class="divide-y divide-slate-100">
                    @foreach($payRows as $row)
                        <li class="p-4">
                            <div class="flex flex-wrap items-start gap-4">
                                {{-- Identity --}}
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-base font-bold text-slate-900">{{ $row->name }}</p>
                                        <span class="text-[10px] uppercase tracking-wide rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 font-semibold">{{ $row->slip_count }} {{ Str::plural('slip', $row->slip_count) }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center gap-2">
                                        @if($row->phone)
                                            <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 font-mono text-sm font-semibold text-slate-900 select-all" title="Cellphone for bank-send">
                                                <svg class="h-3.5 w-3.5 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>
                                                {{ $row->phone }}
                                            </span>
                                            <span class="text-[11px] text-slate-500">cellphone · bank-send routing</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-lg bg-rose-50 border border-rose-200 px-2 py-1 font-mono text-xs font-semibold text-rose-700">
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" x2="19.07" y1="4.93" y2="19.07"/></svg>
                                                No cellphone on file — can't bank-send
                                            </span>
                                        @endif
                                    </div>
                                    @if(!empty($row->job_numbers))
                                        <p class="mt-1.5 text-[11px] text-slate-500">
                                            Trips:
                                            @foreach($row->job_numbers as $jn)
                                                <span class="font-mono text-slate-700">{{ $jn }}</span>@if(!$loop->last), @endif
                                            @endforeach
                                            @if($row->slip_count > count($row->job_numbers)) … @endif
                                        </p>
                                    @endif
                                    @if($row->oldest_at)
                                        <p class="mt-0.5 text-[11px] text-slate-400">Oldest slip submitted {{ $row->oldest_at->diffForHumans() }}</p>
                                    @endif
                                </div>

                                {{-- Amount --}}
                                <div class="text-right shrink-0">
                                    <p class="text-[10px] uppercase tracking-wide text-slate-500">To pay</p>
                                    <p class="text-2xl font-bold tabular-nums text-emerald-700">R {{ number_format((float) $row->total, 2) }}</p>
                                </div>

                                {{-- Action --}}
                                <div class="flex items-end gap-2 shrink-0 w-full sm:w-auto">
                                    @if($canPay)
                                        <label class="block">
                                            <span class="block text-[10px] uppercase tracking-wide text-slate-500 mb-0.5">EFT reference (optional)</span>
                                            <input type="text"
                                                   wire:model="payDrafts.{{ $row->driver_user_id }}"
                                                   placeholder="e.g. EFT 27 May"
                                                   class="rounded border border-slate-300 px-2 py-1.5 text-xs w-44">
                                        </label>
                                        <button type="button"
                                                wire:click="confirmDriverPayment({{ $row->driver_user_id }})"
                                                wire:confirm="Confirm payment of R {{ number_format((float) $row->total, 2) }} to {{ $row->name }}? This marks all {{ $row->slip_count }} approved slip(s) as reimbursed."
                                                @class([
                                                    'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-semibold text-white transition-colors',
                                                    'bg-blue-600 hover:bg-blue-500' => (bool) $row->phone,
                                                    'bg-slate-300 cursor-not-allowed' => !$row->phone,
                                                ])
                                                @if(!$row->phone) disabled @endif>
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>
                                            Confirm payment made
                                        </button>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-2 text-[11px] font-semibold text-slate-500 italic">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/></svg>
                                            Accounts pays
                                        </span>
                                    @endif
                                </div>
                            </div>
                            @error('pay_' . $row->driver_user_id) <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    @elseif($tab === 'trips')
    {{-- Trip-level reconciliation.  Each card shows advance-vs-spend per
         category, total variance, the slip list with thumbnails, and the
         driver's bank-send phone number. --}}
    <section class="space-y-4">
        @forelse($tripGroups as $tg)
            @php
                $isOver = $tg->variance > 0.5;
                $isUnder = $tg->variance < -0.5;
                $advTotal = $tg->advance['total'];
                $spentTotal = $tg->spent['total'];
            @endphp
            <article class="rounded-xl bg-white border border-slate-200 overflow-hidden">
                <header class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('admin.orders.show', $tg->job) }}" class="text-sm font-bold text-blue-700 hover:underline">{{ $tg->job->job_number }}</a>
                        <span class="ml-2 text-xs text-slate-500">
                            {{ $tg->job->company?->name ?? '—' }} ·
                            {{ $tg->job->pickupLocation?->company_name ?? '—' }} → {{ $tg->job->deliveryLocation?->company_name ?? '—' }}
                        </span>
                        <p class="text-[11px] text-slate-500 mt-0.5">
                            Driver:
                            <span class="font-semibold text-slate-700">{{ $tg->job->driver?->name ?? 'unassigned' }}</span>
                            @if($tg->driverPhone)
                                <span class="ml-1 font-mono text-slate-500" title="Bank-send routing key">{{ $tg->driverPhone }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        @if($advTotal > 0)
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">Issued / Spent</p>
                            <p class="text-sm font-semibold tabular-nums">
                                <span class="text-emerald-700">R {{ number_format($advTotal, 2) }}</span>
                                <span class="text-slate-400">/</span>
                                <span class="text-slate-900">R {{ number_format($spentTotal, 2) }}</span>
                            </p>
                            <p class="text-[11px] font-semibold tabular-nums
                                {{ $isOver ? 'text-rose-600' : ($isUnder ? 'text-emerald-700' : 'text-slate-500') }}">
                                Variance {{ $isOver ? '+' : '' }}R {{ number_format($tg->variance, 2) }}
                            </p>
                        @else
                            <p class="text-[11px] italic text-slate-500">No advance issued</p>
                            <p class="text-sm font-semibold text-slate-900 tabular-nums">Spent R {{ number_format($spentTotal, 2) }}</p>
                        @endif
                    </div>
                </header>

                @if($advTotal > 0)
                <div class="px-4 py-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    @foreach (['tolls' => 'Tolls', 'accommodation' => 'Accommodation', 'taxi' => 'Taxi', 'food' => 'Food'] as $k => $lbl)
                        @php
                            $a = (float) ($tg->advance[$k] ?? 0);
                            $s = (float) ($tg->spent[$k] ?? 0);
                            $vk = round($s - $a, 2);
                        @endphp
                        <div class="rounded-lg bg-slate-50 border border-slate-100 p-2">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">{{ $lbl }}</p>
                            <p class="text-[11px] mt-0.5 tabular-nums">
                                <span class="text-slate-500">R {{ number_format($a, 2) }}</span>
                                <span class="text-slate-400 mx-1">/</span>
                                <span class="font-semibold text-slate-900">R {{ number_format($s, 2) }}</span>
                            </p>
                            @if(abs($vk) > 0.5)
                                <p class="text-[10px] font-semibold tabular-nums {{ $vk > 0 ? 'text-rose-600' : 'text-emerald-700' }}">
                                    {{ $vk > 0 ? '+' : '' }}R {{ number_format($vk, 2) }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
                @endif

                @if($tg->slips->isNotEmpty())
                <div class="px-4 pb-4">
                    <p class="text-[11px] font-semibold text-slate-500 mb-2">
                        Slips · {{ $tg->slipCount }} total
                        @if($tg->pendingSlipCount > 0) · <span class="text-amber-700">{{ $tg->pendingSlipCount }} pending</span> @endif
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                        @foreach($tg->slips as $slip)
                            <div class="rounded-lg border border-slate-200 overflow-hidden">
                                <div class="h-24 bg-slate-100">
                                    @if($slip->document && str_starts_with((string) $slip->document->mime_type, 'image/'))
                                        <a href="{{ route('document.view', $slip->document) }}" target="_blank">
                                            <img src="{{ route('document.view', $slip->document) }}" class="h-full w-full object-cover hover:opacity-90" alt="">
                                        </a>
                                    @else
                                        <div class="flex items-center justify-center h-full text-[10px] text-slate-400">no image</div>
                                    @endif
                                </div>
                                <div class="p-1.5">
                                    <p class="text-[11px] font-semibold tabular-nums">R {{ number_format($slip->amount_cents / 100, 2) }}</p>
                                    <p class="text-[10px] text-slate-500 truncate">{{ $slip->categoryLabel() }}</p>
                                    <p class="text-[9px] uppercase tracking-wide font-semibold rounded {{ $slip->statusBadgeClasses() }} inline-block px-1 mt-0.5">{{ $slip->statusLabel() }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </article>
        @empty
            <div class="rounded-xl bg-white border border-slate-200 p-8 text-center text-sm text-slate-500">
                No trips with petty-cash activity in the selected range.
            </div>
        @endforelse
    </section>

    @else
    {{-- Scan incentives.  Owner can edit the rate; everyone with view
         rights can see the rollup.  Default is a "displayed earnings"
         counter -- payroll acts on it manually each pay cycle. --}}
    <section class="space-y-4">
        <div class="rounded-xl bg-white border border-slate-200 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-wide text-slate-500">Slip-scan incentive</p>
                    @if($incentiveEnabled)
                        <p class="text-sm">Drivers earn <strong>R {{ number_format($incentiveAmount, 2) }}</strong> for every approved petty-cash slip.</p>
                    @else
                        <p class="text-sm text-slate-500"><em>Currently disabled.</em></p>
                    @endif
                </div>
                @if(auth()->user()?->isOwner())
                    <div class="ml-auto flex items-end gap-2">
                        <label class="block">
                            <span class="block text-[10px] uppercase tracking-wide text-slate-500 mb-0.5">Owner — set rate (R)</span>
                            <input wire:model="incentiveAmountDraft" type="number" min="0" step="0.01"
                                class="rounded border border-slate-300 px-3 py-1.5 text-sm w-32">
                        </label>
                        <button type="button" wire:click="saveIncentiveRate"
                            class="rounded bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 text-sm font-semibold">
                            Save rate
                        </button>
                    </div>
                @endif
            </div>
            @error('incentiveAmountDraft') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-xl bg-white border border-slate-200 overflow-hidden">
            @if(empty($incentiveRows))
                <p class="p-8 text-center text-sm text-slate-500">No approved slips in the selected range.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Driver</th>
                            <th class="text-left px-4 py-2 font-medium">Phone (bank send)</th>
                            <th class="text-right px-4 py-2 font-medium">Approved slips</th>
                            <th class="text-right px-4 py-2 font-medium">Earned (R)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($incentiveRows as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2 font-semibold text-slate-900">{{ $row['name'] }}</td>
                                <td class="px-4 py-2 font-mono text-slate-600">
                                    @if($row['phone']) {{ $row['phone'] }} @else <span class="text-rose-600">— no phone on file</span> @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['approved_count'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-semibold text-emerald-700">R {{ number_format($row['earned'], 2) }}</td>
                            </tr>
                        @endforeach
                        @php
                            $totalSlips = collect($incentiveRows)->sum('approved_count');
                            $totalEarned = collect($incentiveRows)->sum('earned');
                        @endphp
                        <tr class="bg-slate-50 font-semibold">
                            <td class="px-4 py-2" colspan="2">Total</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $totalSlips }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-emerald-700">R {{ number_format($totalEarned, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    </section>
    @endif
</div>
