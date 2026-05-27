<?php

use App\Models\Job;
use App\Models\PettyCashPlan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TripCostEstimator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Pre-issue petty-cash plan + accounts-first sign-off.
 *
 * Flow:
 *   1. Ops picks a date range (default: tomorrow).
 *   2. System lists eligible trips (PROSELVER-executed, planned or
 *      ready, scheduled in the range, advance not yet issued).
 *   3. Ops ticks the trips to include, snapshots their breakdowns
 *      into a plan, clicks "Send for sign-off".
 *   4. Plan goes to status=pending.  Accounts (or owner fallback)
 *      opens the page, sees pending plans on the "Awaiting sign-off"
 *      tab, clicks Approve
 *      or Reject (with notes).
 *   5. Approved trips have advance_plan_id + advance_approved_at
 *      stamped -- the Issue Advance button on the order page checks
 *      these to decide whether to allow issue without an override.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url(as: 'tab', except: 'pending')] public string $tab = 'pending';

    public string $rangeFrom = '';
    public string $rangeTo = '';

    /** ids of trips ops has ticked for the new plan */
    public array $selectedJobIds = [];

    /** Per-item selection -- flat list of "planId-jobId" strings.
     *  We use a flat list rather than a nested [planId => [jobId,...]]
     *  array because Livewire 3 has a quirk where binding a checkbox
     *  to a nested array path can write `true` instead of an array
     *  when only one item is checked, which then crashes the render
     *  with "count(): Argument must be Countable|array, true given".
     *  Flat strings keep the binding unambiguous. */
    public array $selectedItemKeys = [];

    /** Optional deep-link from the planning page: ?preselect=NNN
     *  pre-ticks that job id on the create tab so ops can plan a
     *  single trip without manually finding it in the list. */
    #[Url(as: 'preselect')] public ?int $preselect = null;

    /** Per-plan notes input for approve/reject */
    public array $signOffNotes = [];

    public const TABS = ['pending', 'drafts', 'approved', 'rejected', 'create'];

    public function mount(): void
    {
        // Internal staff (ops, dispatchers, ops_manager, owner, accounts, super,
        // developer).  External users (customers, dealers, drivers) never
        // see this page.  Approval action is accounts/owner/developer -- see
        // approvePlan() / rejectPlan().
        if (!auth()->user()?->isInternal()) {
            abort(403);
        }
        if (!in_array($this->tab, self::TABS, true)) $this->tab = 'pending';

        // Default the date range to "tomorrow only" -- business's stated
        // workflow is "approve next-day trips end-of-day".  Ops can
        // widen the range if they want to batch a few days together.
        $this->rangeFrom = now()->copy()->addDay()->toDateString();
        $this->rangeTo = $this->rangeFrom;

        // If we came in via /admin/planning's "Petty Cash Plan" link,
        // jump to the create tab with the trip pre-ticked.  Also widen
        // the date range to bracket the trip's scheduled date so the
        // eligibility query actually surfaces it.
        if ($this->preselect) {
            $job = Job::find($this->preselect);
            if ($job) {
                $this->tab = 'create';
                $this->selectedJobIds = [$this->preselect];
                if ($job->scheduled_date) {
                    $d = $job->scheduled_date->toDateString();
                    if ($d < $this->rangeFrom) $this->rangeFrom = $d;
                    if ($d > $this->rangeTo)   $this->rangeTo = $d;
                } else {
                    // No scheduled date -- clear the range so the
                    // null-scheduled filter branch picks it up.
                    $this->rangeFrom = '';
                    $this->rangeTo = '';
                }
            }
        }
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) $this->tab = $tab;
    }

    /**
     * Build the list of eligible jobs for the "Create" tab.
     *
     * Eligible = ProSelver-executed (we don't approve dealer-internal
     * advances; those are the dealer's call), in a status that means
     * the trip is going to roll (planned / driver_assigned / ready),
     * scheduled within the chosen range, and not already approved by
     * another plan (advance_plan_id null OR linked plan is rejected).
     */
    private function eligibleJobs(): \Illuminate\Database\Eloquent\Collection
    {
        // "Not moving yet" = every pre-collection status.  Owner wants
        // ops able to add anything that hasn't actually left -- pre-
        // verification through ready-for-collection -- not just the
        // narrow confirmed-or-later set we started with.  Once a trip
        // is collected / in transit / delivered the cash is irrelevant
        // to a pay-run going out for tomorrow's work, so we cut there.
        $preMovementStatuses = [
            Job::STATUS_PENDING_VERIFICATION,
            Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
            Job::STATUS_RECEIVED,
            Job::STATUS_CONFIRMED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
        ];

        $q = Job::query()
            ->whereIn('status', $preMovementStatuses)
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->where(function ($q) {
                $q->whereNull('advance_plan_id')
                  ->orWhereHas('advancePlan', fn ($qq) => $qq->where('status', PettyCashPlan::STATUS_REJECTED));
            })
            ->with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'driver:id,name', 'vehicleClass:id,name,toll_class']);

        // Date range is lenient: when both ends are set we filter to
        // that window OR null-scheduled jobs (trips without a date
        // shouldn't be hidden just because they're not on a calendar
        // yet).  Either end empty = no date filter at all.
        if ($this->rangeFrom && $this->rangeTo) {
            $from = Carbon::parse($this->rangeFrom)->startOfDay();
            $to = Carbon::parse($this->rangeTo)->endOfDay();
            $q->where(function ($qq) use ($from, $to) {
                $qq->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
                   ->orWhereNull('scheduled_date');
            });
        }

        return $q->orderByRaw('scheduled_date IS NULL, scheduled_date asc')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Snapshot the selected trips into a new plan in draft status.
     * Computes each trip's breakdown right now (so the snapshot
     * matches what ops just looked at), totals them, and stores the
     * bundle.  Status starts as draft so ops can review before
     * sending for sign-off.
     */
    public function createPlanFromSelection(TripCostEstimator $estimator): void
    {
        if (empty($this->selectedJobIds)) {
            session()->flash('error', 'Pick at least one trip to add to the plan.');
            return;
        }

        $jobs = Job::query()
            ->whereIn('id', $this->selectedJobIds)
            ->with(['company:id,name', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'vehicleClass:id,name,toll_class'])
            ->get();

        $items = [];
        $total = 0.0;
        foreach ($jobs as $job) {
            $result = $estimator->estimateTolls($job, $job->advance_toll_class_override);
            $autoTolls = (float) ($result['toll_total'] ?? 0);
            $tolls = $job->advance_tolls !== null ? (float) $job->advance_tolls : $autoTolls;
            $accom = (float) ($job->advance_accommodation ?? 0);
            $taxi = (bool) $job->advance_taxi_included ? (float) ($job->advance_taxi ?? 0) : 0.0;
            $food = (bool) $job->advance_food_waived
                ? 0.0
                : ($job->advance_food !== null ? (float) $job->advance_food : (float) ($result['suggested_food'] ?? 0));
            $custom = collect($job->advance_custom_items ?? [])->map(fn ($i) => [
                'label' => (string) ($i['label'] ?? ''),
                'amount' => (float) ($i['amount'] ?? 0),
            ])->filter(fn ($i) => $i['label'] !== '' && $i['amount'] > 0)->values()->all();
            $customSum = array_sum(array_column($custom, 'amount'));
            $tripTotal = round($tolls + $accom + $taxi + $food + $customSum, 2);

            $items[] = [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'company' => $job->company?->name,
                'route' => trim(($job->pickupLocation?->company_name ?? '') . ' → ' . ($job->deliveryLocation?->company_name ?? '')),
                'scheduled_date' => $job->scheduled_date?->toDateString(),
                'vehicle_class' => $job->vehicleClass?->name,
                'toll_class' => (int) ($result['toll_class'] ?? $job->vehicleClass?->toll_class ?? 0),
                'tolls' => round($tolls, 2),
                'accommodation' => round($accom, 2),
                'taxi' => round($taxi, 2),
                'food' => round($food, 2),
                'custom_items' => $custom,
                'computed_total' => $tripTotal,
            ];
            $total += $tripTotal;
        }

        $plan = PettyCashPlan::create([
            'label' => 'Pay-run ' . Carbon::parse($this->rangeFrom)->format('D d M Y')
                . ($this->rangeFrom !== $this->rangeTo ? ' – ' . Carbon::parse($this->rangeTo)->format('d M') : ''),
            'status' => PettyCashPlan::STATUS_DRAFT,
            'total_amount' => round($total, 2),
            'items_json' => $items,
            'generated_by_user_id' => auth()->id(),
            'generated_at' => now(),
        ]);

        AuditService::log('petty_cash_plan_created', 'petty_cash_plan', $plan->id, null, [
            'item_count' => count($items),
            'total' => $total,
        ]);

        $this->selectedJobIds = [];
        $this->tab = 'drafts';
        session()->flash('success', count($items) . ' trip' . (count($items) === 1 ? '' : 's') . ' added to draft plan #' . $plan->id . '. Review and click "Send for sign-off" when ready.');
    }

    /**
     * Move a draft plan to pending (awaiting sign-off).  Owner sees it
     * on the Pending tab from here.
     */
    /**
     * Send a SUBSET of a draft's items off for sign-off.  Creates a
     * new pending plan with the chosen items; the original draft
     * retains the unselected ones (or is deleted if empty).  Lets
     * ops cherry-pick which trips are ready vs which need to wait
     * without rebuilding the bundle from scratch.
     */
    /**
     * Extract job_ids selected for a specific plan from the flat
     * selection list.  Keys look like "12-345" -- plan 12, job 345.
     */
    private function selectedJobIdsFor(int $planId): array
    {
        $prefix = $planId . '-';
        return collect($this->selectedItemKeys)
            ->filter(fn ($k) => is_string($k) && str_starts_with($k, $prefix))
            ->map(fn ($k) => (int) substr($k, strlen($prefix)))
            ->filter()
            ->values()
            ->all();
    }

    public function sendSelectedForSignOff(int $planId): void
    {
        $plan = PettyCashPlan::findOrFail($planId);
        if ($plan->status !== PettyCashPlan::STATUS_DRAFT) {
            session()->flash('error', 'Selection-based send only works on draft plans.');
            return;
        }

        $selectedJobIds = $this->selectedJobIdsFor($planId);
        if (empty($selectedJobIds)) {
            session()->flash('error', 'Tick the items you want to send for sign-off.');
            return;
        }

        $allItems = collect($plan->items_json ?? []);
        $selectedItems = $allItems->filter(fn ($i) => in_array((int) ($i['job_id'] ?? 0), $selectedJobIds, true))->values();
        $remainingItems = $allItems->reject(fn ($i) => in_array((int) ($i['job_id'] ?? 0), $selectedJobIds, true))->values();

        if ($selectedItems->isEmpty()) {
            session()->flash('error', 'No matching items found in this plan.');
            return;
        }

        DB::transaction(function () use ($plan, $selectedItems, $remainingItems, $selectedJobIds) {
            // Build the pending plan from the picked items.
            $pending = PettyCashPlan::create([
                'label' => $plan->label . ' · subset ' . now()->format('H:i'),
                'status' => PettyCashPlan::STATUS_PENDING,
                'total_amount' => round((float) $selectedItems->sum('computed_total'), 2),
                'items_json' => $selectedItems->all(),
                'generated_by_user_id' => auth()->id(),
                'generated_at' => now(),
            ]);

            // Re-tag those jobs to the new pending plan.
            Job::whereIn('id', $selectedJobIds)->update(['advance_plan_id' => $pending->id]);

            // The original draft keeps the remaining items, or is
            // soft-deleted if there's nothing left (auto-cleanup so
            // the drafts list doesn't fill with empty shells).
            if ($remainingItems->isEmpty()) {
                $plan->delete();
            } else {
                $plan->forceFill([
                    'items_json' => $remainingItems->all(),
                    'total_amount' => round((float) $remainingItems->sum('computed_total'), 2),
                ])->save();
            }

            AuditService::log('petty_cash_plan_subset_sent', 'petty_cash_plan', $pending->id, null, [
                'source_plan_id' => $plan->id,
                'item_count' => $selectedItems->count(),
                'total' => (float) $pending->total_amount,
            ]);
        });

        // Drop the selection keys for the plan that was just split.
        $prefix = $planId . '-';
        $this->selectedItemKeys = array_values(array_filter(
            $this->selectedItemKeys,
            fn ($k) => !is_string($k) || !str_starts_with($k, $prefix),
        ));

        $this->tab = 'pending';
        session()->flash('success', count($selectedJobIds) . ' trip' . (count($selectedJobIds) === 1 ? '' : 's') . ' sent for accounts sign-off.');
    }

    /**
     * Pull a single item out of a draft plan.  The job becomes
     * standalone again (advance_plan_id cleared); next time ops opens
     * its advance modal, saving will re-snapshot it into a fresh
     * draft per the auto-add rule.
     */
    public function removeItemFromPlan(int $planId, int $jobId): void
    {
        $plan = PettyCashPlan::findOrFail($planId);
        if ($plan->status !== PettyCashPlan::STATUS_DRAFT) {
            session()->flash('error', 'You can only remove items from draft plans.');
            return;
        }

        $remaining = collect($plan->items_json ?? [])
            ->reject(fn ($i) => (int) ($i['job_id'] ?? 0) === $jobId)
            ->values();

        DB::transaction(function () use ($plan, $remaining, $jobId) {
            Job::where('id', $jobId)->update(['advance_plan_id' => null]);
            if ($remaining->isEmpty()) {
                $plan->delete();
            } else {
                $plan->forceFill([
                    'items_json' => $remaining->all(),
                    'total_amount' => round((float) $remaining->sum('computed_total'), 2),
                ])->save();
            }
        });

        AuditService::log('petty_cash_plan_item_removed', 'petty_cash_plan', $plan->id, null, ['job_id' => $jobId]);
        session()->flash('success', 'Trip removed from the draft plan.');
    }

    public function sendForSignOff(int $planId): void
    {
        $plan = PettyCashPlan::findOrFail($planId);
        if (!$plan->isEditable()) {
            session()->flash('error', 'Only draft or rejected plans can be sent for sign-off.');
            return;
        }
        $plan->forceFill(['status' => PettyCashPlan::STATUS_PENDING])->save();
        AuditService::log('petty_cash_plan_sent', 'petty_cash_plan', $plan->id);
        $this->tab = 'pending';
        session()->flash('success', 'Plan #' . $plan->id . ' sent for accounts sign-off.');
    }

    public function approvePlan(int $planId): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }
        $plan = PettyCashPlan::findOrFail($planId);
        if (!$plan->isAwaitingSignOff()) {
            session()->flash('error', 'Only pending plans can be approved.');
            return;
        }

        $notes = trim((string) ($this->signOffNotes[$planId] ?? ''));
        $now = now();

        DB::transaction(function () use ($plan, $u, $notes, $now) {
            $plan->forceFill([
                'status' => PettyCashPlan::STATUS_APPROVED,
                'approved_by_user_id' => $u->id,
                'approved_at' => $now,
                'sign_off_notes' => $notes ?: null,
            ])->save();

            // Stamp every job referenced in the snapshot with the plan
            // and approval timestamp.  Issue Advance reads these on
            // the order page.
            $jobIds = collect($plan->items_json ?? [])->pluck('job_id')->filter()->all();
            Job::whereIn('id', $jobIds)->update([
                'advance_plan_id' => $plan->id,
                'advance_approved_at' => $now,
            ]);
        });

        AuditService::log('petty_cash_plan_approved', 'petty_cash_plan', $plan->id, null, [
            'item_count' => count($plan->items_json ?? []),
            'total' => (float) $plan->total_amount,
            'notes' => $notes,
        ], $notes ?: null);

        unset($this->signOffNotes[$planId]);
        session()->flash('success', 'Plan #' . $plan->id . ' approved. Ops can now issue advances on those trips.');
    }

    public function rejectPlan(int $planId): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }
        $plan = PettyCashPlan::findOrFail($planId);
        if (!$plan->isAwaitingSignOff()) {
            session()->flash('error', 'Only pending plans can be rejected.');
            return;
        }

        $notes = trim((string) ($this->signOffNotes[$planId] ?? ''));
        if ($notes === '') {
            $this->addError('signOffNotes_' . $planId, 'A reason is required when rejecting.');
            return;
        }

        $plan->forceFill([
            'status' => PettyCashPlan::STATUS_REJECTED,
            'approved_by_user_id' => $u->id,
            'approved_at' => now(),
            'sign_off_notes' => $notes,
        ])->save();

        AuditService::log('petty_cash_plan_rejected', 'petty_cash_plan', $plan->id, null, [
            'notes' => $notes,
        ], $notes);

        unset($this->signOffNotes[$planId]);
        session()->flash('success', 'Plan #' . $plan->id . ' rejected. Ops will see the reason.');
    }

    public function deleteDraft(int $planId): void
    {
        $plan = PettyCashPlan::findOrFail($planId);
        if ($plan->status !== PettyCashPlan::STATUS_DRAFT) {
            session()->flash('error', 'Only drafts can be deleted.');
            return;
        }
        $plan->delete();
        AuditService::log('petty_cash_plan_deleted', 'petty_cash_plan', $planId);
        session()->flash('success', 'Draft plan deleted.');
    }

    public function with(): array
    {
        $base = PettyCashPlan::query()
            ->with(['generatedBy:id,name', 'approvedBy:id,name']);

        return [
            'pendingPlans'  => (clone $base)->where('status', PettyCashPlan::STATUS_PENDING)->latest('generated_at')->get(),
            'draftPlans'    => (clone $base)->where('status', PettyCashPlan::STATUS_DRAFT)->latest('generated_at')->get(),
            'approvedPlans' => (clone $base)->where('status', PettyCashPlan::STATUS_APPROVED)->latest('approved_at')->limit(30)->get(),
            'rejectedPlans' => (clone $base)->where('status', PettyCashPlan::STATUS_REJECTED)->latest('approved_at')->limit(30)->get(),
            'eligibleJobs'  => $this->tab === 'create' ? $this->eligibleJobs() : collect(),
            'canApprove'    => auth()->user()?->isAccounts() || auth()->user()?->isOwner() || auth()->user()?->isDeveloper(),
        ];
    }
}; ?>

<div>
    <x-slot:header>
        <div class="flex items-center gap-3">
            <span>Petty Cash Plans · Sign-off</span>
            @if($canApprove)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800">Accounts sign-off</span>
            @endif
        </div>
    </x-slot:header>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-3 rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Tabs --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 rounded-xl bg-slate-100 p-1 w-fit">
        <button wire:click="switchTab('pending')" type="button"
            class="inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'pending' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            Awaiting sign-off
            @if($pendingPlans->isNotEmpty())
                <span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold bg-amber-100 text-amber-700">{{ $pendingPlans->count() }}</span>
            @endif
        </button>
        <button wire:click="switchTab('drafts')" type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'drafts' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            Drafts
            @if($draftPlans->isNotEmpty())
                <span class="ml-1 rounded-full px-1.5 py-0.5 text-[9px] font-bold bg-slate-200 text-slate-600">{{ $draftPlans->count() }}</span>
            @endif
        </button>
        <button wire:click="switchTab('approved')" type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'approved' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            Approved
        </button>
        <button wire:click="switchTab('rejected')" type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'rejected' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
            Rejected
        </button>
        <button wire:click="switchTab('create')" type="button"
            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition
            {{ $tab === 'create' ? 'bg-blue-600 text-white shadow-sm' : 'bg-blue-50 text-blue-700 hover:bg-blue-100' }}">
            + New plan
        </button>
    </div>

    {{-- ──────────────  CREATE TAB  ────────────── --}}
    @if($tab === 'create')
        <section class="rounded-xl bg-white border border-slate-200 p-4 mb-4">
            <div class="flex flex-wrap items-end gap-3 mb-4">
                <label class="text-xs">
                    <span class="block text-slate-500 mb-0.5">Scheduled from</span>
                    <input wire:model.live="rangeFrom" type="date" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                </label>
                <label class="text-xs">
                    <span class="block text-slate-500 mb-0.5">to</span>
                    <input wire:model.live="rangeTo" type="date" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                </label>
                <p class="text-xs text-slate-500">
                    Default is tomorrow. Eligible trips: ProSelver-executed, status planned/driver-assigned/ready, advance not yet approved.
                </p>
            </div>

            @if($eligibleJobs->isEmpty())
                <p class="text-center text-sm text-slate-500 py-6">No eligible trips in the selected range.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left w-10">
                                    <input type="checkbox"
                                        wire:click="$set('selectedJobIds', @js($eligibleJobs->pluck('id')->all()))"
                                        title="Select all">
                                </th>
                                <th class="px-3 py-2 text-left">Order</th>
                                <th class="px-3 py-2 text-left">Customer</th>
                                <th class="px-3 py-2 text-left">Route</th>
                                <th class="px-3 py-2 text-left">Driver</th>
                                <th class="px-3 py-2 text-left">Scheduled</th>
                                <th class="px-3 py-2 text-right">Computed advance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($eligibleJobs as $job)
                                @php
                                    // Quick live preview of the total -- saves ops opening each
                                    // order before deciding whether to add it.  Service call
                                    // is cheap because of bbox prefilter + cached routes.
                                    $est = app(\App\Services\TripCostEstimator::class)->estimateTolls($job, $job->advance_toll_class_override);
                                    $tolls = $job->advance_tolls !== null ? (float) $job->advance_tolls : (float) ($est['toll_total'] ?? 0);
                                    $accom = (float) ($job->advance_accommodation ?? 0);
                                    $taxi = $job->advance_taxi_included ? (float) ($job->advance_taxi ?? 0) : 0.0;
                                    $food = $job->advance_food_waived ? 0 : ($job->advance_food !== null ? (float) $job->advance_food : (float) ($est['suggested_food'] ?? 0));
                                    $custom = collect($job->advance_custom_items ?? [])->sum('amount');
                                    $rowTotal = round($tolls + $accom + $taxi + $food + $custom, 2);
                                @endphp
                                <tr class="hover:bg-slate-50">
                                    <td class="px-3 py-2"><input type="checkbox" wire:model.live="selectedJobIds" value="{{ $job->id }}"></td>
                                    <td class="px-3 py-2"><a href="{{ route('admin.orders.show', $job) }}" target="_blank" class="font-semibold text-blue-700 hover:underline">{{ $job->job_number }}</a></td>
                                    <td class="px-3 py-2 text-slate-700">{{ $job->company?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-600 text-xs">{{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ $job->driver?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ $job->scheduled_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">R {{ number_format($rowTotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">
                        Selected: <strong>{{ count($selectedJobIds) }}</strong> of {{ $eligibleJobs->count() }} eligible trips.
                    </p>
                    <button wire:click="createPlanFromSelection" type="button"
                        @if(empty($selectedJobIds)) disabled @endif
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        Create draft plan
                    </button>
                </div>
            @endif
        </section>
    @endif

    {{-- ──────────────  LIST TABS  ────────────── --}}
    @php
        $list = match($tab) {
            'pending'  => $pendingPlans,
            'drafts'   => $draftPlans,
            'approved' => $approvedPlans,
            'rejected' => $rejectedPlans,
            default    => collect(),
        };
    @endphp

    @if($tab !== 'create')
        @if($list->isEmpty())
            <div class="rounded-xl bg-white border border-slate-200 p-8 text-center text-sm text-slate-500">
                No plans in this state.
            </div>
        @else
            <div class="space-y-4">
                @foreach($list as $plan)
                    <article class="rounded-xl bg-white border border-slate-200 overflow-hidden">
                        <header class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900">#{{ $plan->id }} · {{ $plan->label }}</p>
                                <p class="text-[11px] text-slate-500">
                                    {{ count($plan->items_json ?? []) }} {{ Str::plural('trip', count($plan->items_json ?? [])) }} ·
                                    by {{ $plan->generatedBy?->name ?? 'Unknown' }} {{ $plan->generated_at?->diffForHumans() }}
                                    @if($plan->approved_at)
                                        · {{ $plan->status === 'approved' ? 'approved' : 'rejected' }}
                                        by {{ $plan->approvedBy?->name ?? 'Unknown' }} {{ $plan->approved_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-base font-bold tabular-nums font-mono text-slate-900">R {{ number_format((float) $plan->total_amount, 2) }}</span>
                                <span class="text-[10px] uppercase tracking-wide font-semibold rounded-full border px-2 py-0.5 {{ $plan->statusBadgeClasses() }}">{{ $plan->statusLabel() }}</span>
                            </div>
                        </header>

                        @if($plan->sign_off_notes)
                            <div class="px-4 py-2 bg-{{ $plan->status === 'rejected' ? 'rose' : 'emerald' }}-50 border-b border-slate-100 text-xs text-slate-700">
                                <strong>Owner notes:</strong> {{ $plan->sign_off_notes }}
                            </div>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50/60 text-[10px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        @if($plan->status === 'draft')
                                            <th class="px-2 py-1.5 w-8">
                                                @php
                                                    // Pre-compute the "all keys for this plan" array so
                                                    // the header check-box can toggle them via $set without
                                                    // a method call.  Wrapped with array_values to keep
                                                    // a clean numeric-indexed list.
                                                    $allKeysForThisPlan = collect($plan->items_json ?? [])
                                                        ->pluck('job_id')
                                                        ->filter()
                                                        ->map(fn ($id) => $plan->id . '-' . $id)
                                                        ->values()
                                                        ->all();
                                                    // Existing selection minus this plan's keys, so we can
                                                    // merge cleanly when ticking "select all".
                                                    $existingMinusThisPlan = collect($selectedItemKeys ?? [])
                                                        ->filter(fn ($k) => is_string($k) && !str_starts_with($k, $plan->id . '-'))
                                                        ->values()
                                                        ->all();
                                                @endphp
                                                <input type="checkbox"
                                                    wire:click="$set('selectedItemKeys', @js(array_merge($existingMinusThisPlan, $allKeysForThisPlan)))"
                                                    title="Select all in this draft">
                                            </th>
                                        @endif
                                        <th class="px-3 py-1.5 text-left">Order</th>
                                        <th class="px-3 py-1.5 text-left">Route</th>
                                        <th class="px-3 py-1.5 text-left">Scheduled</th>
                                        <th class="px-3 py-1.5 text-right">Tolls</th>
                                        <th class="px-3 py-1.5 text-right">Accom</th>
                                        <th class="px-3 py-1.5 text-right">Taxi</th>
                                        <th class="px-3 py-1.5 text-right">Food</th>
                                        <th class="px-3 py-1.5 text-right">Custom</th>
                                        <th class="px-3 py-1.5 text-right">Trip total</th>
                                        @if($plan->status === 'draft')
                                            <th class="px-2 py-1.5 w-10"></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($plan->items_json ?? [] as $item)
                                        <tr class="hover:bg-slate-50">
                                            @if($plan->status === 'draft')
                                                <td class="px-2 py-1.5">
                                                    <input type="checkbox"
                                                        wire:model.live="selectedItemKeys"
                                                        value="{{ $plan->id }}-{{ $item['job_id'] }}">
                                                </td>
                                            @endif
                                            <td class="px-3 py-1.5">
                                                <a href="{{ route('admin.orders.show', $item['job_id']) }}" target="_blank" class="font-semibold text-blue-700 hover:underline">{{ $item['job_number'] ?? '—' }}</a>
                                            </td>
                                            <td class="px-3 py-1.5 text-slate-600 truncate max-w-[280px]" title="{{ $item['route'] ?? '' }}">{{ $item['route'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-slate-500">{{ $item['scheduled_date'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">R {{ number_format((float)($item['tolls'] ?? 0), 2) }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">R {{ number_format((float)($item['accommodation'] ?? 0), 2) }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">R {{ number_format((float)($item['taxi'] ?? 0), 2) }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">R {{ number_format((float)($item['food'] ?? 0), 2) }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">R {{ number_format((float) array_sum(array_column($item['custom_items'] ?? [], 'amount')), 2) }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums font-bold">R {{ number_format((float)($item['computed_total'] ?? 0), 2) }}</td>
                                            @if($plan->status === 'draft')
                                                <td class="px-2 py-1.5 text-right">
                                                    <button wire:click="removeItemFromPlan({{ $plan->id }}, {{ $item['job_id'] }})"
                                                        wire:confirm="Remove order {{ $item['job_number'] ?? '' }} from this draft plan?"
                                                        type="button"
                                                        title="Remove this trip from the draft"
                                                        class="text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded p-1">
                                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Action row per status --}}
                        <footer class="px-4 py-3 bg-slate-50/60 border-t border-slate-100">
                            @if($plan->status === 'draft')
                                @php
                                    // Count keys belonging to this specific plan.  The
                                    // selectedItemKeys list is shared across plans, so
                                    // we filter by the "planId-" prefix.
                                    $planPrefix = $plan->id . '-';
                                    $selectedCount = collect($selectedItemKeys ?? [])
                                        ->filter(fn ($k) => is_string($k) && str_starts_with($k, $planPrefix))
                                        ->count();
                                    $itemsList = is_array($plan->items_json) ? $plan->items_json : [];
                                    $totalItems = count($itemsList);
                                @endphp
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if($selectedCount > 0 && $selectedCount < $totalItems)
                                        <span class="text-[11px] text-slate-500">{{ $selectedCount }} of {{ $totalItems }} selected</span>
                                        <button wire:click="sendSelectedForSignOff({{ $plan->id }})"
                                            wire:confirm="Send {{ $selectedCount }} selected trip{{ $selectedCount === 1 ? '' : 's' }} for accounts sign-off? Remaining items stay in this draft."
                                            class="text-xs rounded-md bg-amber-600 hover:bg-amber-500 text-white px-3 py-1.5 font-semibold">
                                            Send {{ $selectedCount }} selected for sign-off
                                        </button>
                                    @endif
                                    <button wire:click="deleteDraft({{ $plan->id }})" wire:confirm="Delete this draft plan?"
                                        class="text-xs rounded-md px-3 py-1.5 text-rose-600 hover:bg-rose-50 font-semibold">Delete draft</button>
                                    <button wire:click="sendForSignOff({{ $plan->id }})"
                                        wire:confirm="Send all {{ $totalItems }} trips on this draft for sign-off?"
                                        class="text-xs rounded-md bg-amber-700 hover:bg-amber-600 text-white px-3 py-1.5 font-semibold">
                                        Send all for sign-off
                                    </button>
                                </div>
                            @elseif($plan->status === 'pending' && $canApprove)
                                <div class="space-y-2">
                                    <textarea wire:model="signOffNotes.{{ $plan->id }}" rows="2" maxlength="500"
                                        placeholder="Notes (optional for approve, required to reject)…"
                                        class="w-full rounded border border-slate-300 px-3 py-2 text-xs"></textarea>
                                    @error('signOffNotes_' . $plan->id) <p class="text-[11px] text-rose-600">{{ $message }}</p> @enderror
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="rejectPlan({{ $plan->id }})"
                                            class="text-xs rounded-md bg-rose-600 hover:bg-rose-500 text-white px-3 py-1.5 font-semibold">Reject</button>
                                        <button wire:click="approvePlan({{ $plan->id }})"
                                            wire:confirm="Approve plan #{{ $plan->id }} for R {{ number_format((float) $plan->total_amount, 2) }}? Ops will be able to issue advances on these trips."
                                            class="text-xs rounded-md bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 font-semibold">Approve plan</button>
                                    </div>
                                </div>
                            @elseif($plan->status === 'pending')
                                <p class="text-xs text-slate-500 italic">Awaiting sign-off by Accounts (owner fallback).</p>
                            @elseif($plan->status === 'rejected')
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="sendForSignOff({{ $plan->id }})"
                                        class="text-xs rounded-md bg-amber-600 hover:bg-amber-500 text-white px-3 py-1.5 font-semibold">Re-send for sign-off</button>
                                </div>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>
        @endif
    @endif
</div>
