<?php

use App\Models\Company;
use App\Models\Job;
use App\Services\AuditService;
use App\Services\MovementInvoiceExport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Customer invoicing.  Accounts picks a customer + a billing window
 * (default: last calendar month -- 2nd of previous month through 1st
 * of current month, matching how the OEMs cut their books), fills in
 * the per-trip invoice numbers / amounts / extras / fuel for the
 * ProSelver-executed movements in that window, and exports the FAW-
 * shaped Excel sheet.
 *
 * Gate: internal middleware on the admin group + an explicit
 * accounts/owner/developer check in mount() so a generic ops user
 * can't read finance numbers.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    /**
     * How many rows we render per page.  The table has 5 wire:model
     * inputs per row and the whole $rows array is serialised into
     * every Livewire round-trip, so the page size is deliberately
     * modest -- larger pages made the page "jam" for accounts on
     * OEMs with hundreds of monthly movements.
     */
    private const PAGE_SIZE = 50;

    #[Url] public ?int $companyId = null;
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

    /**
     * Visibility filter:
     *   'incomplete' (default working list -- excludes completed AND
     *                 excluded rows, only what still needs capturing),
     *   'all'        (everything in the window incl. excluded rows,
     *                 each one badged so it's obvious),
     *   'complete'   (finance has finished, audit/review view),
     *   'excluded'   (owner/dev review of the not-required pile).
     *
     * Saved into the URL so a refresh or share-link preserves the view.
     */
    #[Url] public string $completion = 'incomplete';

    /**
     * Per-job finance inputs, keyed by job id.  Hydrated from the DB on
     * every render so the inputs reflect the current persisted values;
     * save() writes the diff back.
     */
    public array $rows = [];

    public function mount(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'Customer invoicing is restricted to accounts.');
        }

        if (!$this->dateFrom || !$this->dateTo) {
            [$from, $to] = $this->lastMonthWindow();
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        }
    }

    /**
     * When any of the top-level filters change, reset the paginator
     * and clear the per-row input buffer so we don't carry the old
     * customer's edits over into the new selection (and don't ship
     * a growing $rows blob across every Livewire request).
     */
    public function updated($field): void
    {
        if (in_array($field, ['companyId', 'dateFrom', 'dateTo', 'completion'], true)) {
            $this->resetPage();
            $this->rows = [];
        }
    }

    /**
     * Switching the completion view should reset the page and rows
     * for the same reason as updated() -- otherwise the paginator
     * ends up on page 4 of an empty result and $rows still holds
     * job ids from a different filter.
     */
    public function setCompletion(string $completion): void
    {
        if (!in_array($completion, ['incomplete', 'all', 'complete', 'excluded'], true)) {
            return;
        }
        $this->completion = $completion;
        $this->resetPage();
        $this->rows = [];
    }

    /**
     * "Last month" = the 2nd of the previous month through the 1st of
     * the current month, per the OEM billing convention.  Built today,
     * not the calendar month, so a run on the 3rd of June covers
     * 2 May -> 1 June.
     */
    private function lastMonthWindow(): array
    {
        $now = now();
        $from = $now->copy()->subMonthNoOverflow()->startOfMonth()->addDay(); // day 2
        $to   = $now->copy()->startOfMonth();                                  // day 1
        return [$from, $to];
    }

    public function applyRange(string $range): void
    {
        $now = now();
        [$from, $to] = match ($range) {
            'last_month'  => $this->lastMonthWindow(),
            'this_month'  => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_30'     => [$now->copy()->subDays(29),    $now->copy()],
            'this_year'   => [$now->copy()->startOfYear(),  $now->copy()->endOfYear()],
            default       => $this->lastMonthWindow(),
        };
        $this->dateFrom = $from->toDateString();
        $this->dateTo   = $to->toDateString();
        $this->rows = []; // force re-hydrate from DB
        $this->resetPage();
    }

    /**
     * Build the base query for the selected customer + window.  Scoped
     * to ProSelver-executed jobs because the customer invoicing
     * spreadsheet only covers movements ProSelver bills the OEM for --
     * jobs executed by the customer themselves or a third-party
     * courier are not billed by us.
     */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [
                Job::STATUS_DELIVERED,
                Job::STATUS_COMPLETED,
                Job::STATUS_INVOICED,
            ])
            ->whereNotNull('delivered_at');

        if ($this->companyId) {
            $q->where('company_id', (int) $this->companyId);
        }

        if ($this->dateFrom && $this->dateTo) {
            $from = Carbon::parse($this->dateFrom)->startOfDay();
            $to   = Carbon::parse($this->dateTo)->endOfDay();
            $q->whereBetween('delivered_at', [$from, $to]);
        }

        if ($this->completion === 'incomplete') {
            $q->whereNull('invoicing_completed_at')
              ->whereNull('invoicing_excluded_at');
        } elseif ($this->completion === 'complete') {
            $q->whereNotNull('invoicing_completed_at')
              ->whereNull('invoicing_excluded_at');
        } elseif ($this->completion === 'excluded') {
            $q->whereNotNull('invoicing_excluded_at');
        }
        // 'all' applies no completion / excluded filter.

        return $q->with([
            'company:id,name',
            'pickupLocation:id,company_name',
            'deliveryLocation:id,company_name',
        ])->orderBy('delivered_at');
    }

    /**
     * Persist edited finance fields.  Empty inputs are stored as NULL
     * (the columns are optional), numeric inputs are coerced via the
     * model casts.  Anything else for that job is left alone.
     */
    public function save(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        // Only touch jobs for which we actually have per-row input in
        // scope on this page.  Since with() prunes $rows to the visible
        // page, this is both faster than re-scanning the full window
        // and safer -- no risk of clobbering rows the user isn't
        // looking at.
        $rowIds = array_keys($this->rows);
        if (empty($rowIds)) {
            session()->flash('error', 'Nothing to save -- no rows on this page.');
            return;
        }

        $jobs = $this->baseQuery()
            ->whereIn('id', $rowIds)
            ->whereNull('invoicing_excluded_at') // never write finance data onto excluded rows
            ->get(['id', 'invoice_number', 'invoice_amount', 'extras_amount', 'fuel_litres', 'fuel_amount']);
        $touched = 0;

        foreach ($jobs as $job) {
            $input = $this->rows[$job->id] ?? null;
            if (!is_array($input)) {
                continue;
            }

            $next = [
                'invoice_number' => $this->normaliseString($input['invoice_number'] ?? null),
                'invoice_amount' => $this->normaliseDecimal($input['invoice_amount'] ?? null),
                'extras_amount'  => $this->normaliseDecimal($input['extras_amount'] ?? null),
                'fuel_litres'    => $this->normaliseDecimal($input['fuel_litres'] ?? null),
                'fuel_amount'    => $this->normaliseDecimal($input['fuel_amount'] ?? null),
            ];

            $changed = false;
            foreach ($next as $col => $val) {
                if ((string) ($job->{$col} ?? '') !== (string) ($val ?? '')) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                continue;
            }

            $job->forceFill($next)->save();
            $touched++;
        }

        if ($touched > 0) {
            AuditService::log('movement_invoice_fields_saved', 'customer_invoicing', null, null, [
                'company_id' => $this->companyId,
                'from' => $this->dateFrom,
                'to'   => $this->dateTo,
                'rows' => $touched,
            ]);
            session()->flash('success', "Saved finance details on {$touched} movement" . ($touched === 1 ? '' : 's') . '.');
        } else {
            session()->flash('error', 'Nothing to save -- no rows were changed.');
        }
    }

    /**
     * Owner / developer only.  Toggle "this movement is not required to
     * be invoiced" -- test runs, internal shuffles, write-offs.  Hidden
     * from the working list and always excluded from the Excel export
     * regardless of which visibility filter is active.
     */
    public function toggleExclude(int $jobId, ?string $reason = null): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'Marking a movement as not-required is restricted to owner/developer.');
        }

        $job = Job::find($jobId);
        if (!$job) {
            return;
        }
        if ($job->executor_type !== Job::EXECUTOR_PROSELVER || $job->delivered_at === null) {
            return;
        }

        if ($job->invoicing_excluded_at) {
            $job->forceFill([
                'invoicing_excluded_at' => null,
                'invoicing_excluded_by_user_id' => null,
                'invoicing_excluded_reason' => null,
            ])->save();
            AuditService::log('movement_invoice_exclusion_cleared', 'transport_job', $job->id, null, [
                'company_id' => $job->company_id,
            ]);
        } else {
            $job->forceFill([
                'invoicing_excluded_at' => now(),
                'invoicing_excluded_by_user_id' => $u->id,
                'invoicing_excluded_reason' => trim((string) $reason) !== '' ? trim((string) $reason) : null,
                // Clear any completion stamp -- excluded supersedes it.
                'invoicing_completed_at' => null,
                'invoicing_completed_by_user_id' => null,
            ])->save();
            AuditService::log('movement_invoice_exclusion_marked', 'transport_job', $job->id, null, [
                'company_id' => $job->company_id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Toggle the "invoicing complete" flag on a single job.  Accounts +
     * owner + developer can flip this; that matches who can land on
     * this page.  We rely on the page-level gate (mount()) and re-check
     * here so a stray Livewire payload can't bypass it.
     *
     * No-op on excluded rows -- you have to un-exclude first.
     */
    public function toggleComplete(int $jobId): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403);
        }

        $job = Job::find($jobId);
        if (!$job) {
            return;
        }

        // Defence-in-depth: the page's baseQuery() only surfaces
        // ProSelver-executed delivered jobs; refuse to flip anything
        // that wouldn't normally appear here, in case someone replays
        // the call with a stale id.
        if ($job->executor_type !== Job::EXECUTOR_PROSELVER || $job->delivered_at === null) {
            return;
        }

        // Excluded rows can't be "completed" -- they're not work we're
        // doing.  Owner/dev has to un-exclude first.
        if ($job->invoicing_excluded_at) {
            session()->flash('error', 'This movement is marked "not required" -- un-exclude it first.');
            return;
        }

        if ($job->invoicing_completed_at) {
            $job->forceFill([
                'invoicing_completed_at' => null,
                'invoicing_completed_by_user_id' => null,
            ])->save();
            AuditService::log('movement_invoice_completion_cleared', 'transport_job', $job->id, null, [
                'company_id' => $job->company_id,
            ]);
        } else {
            $job->forceFill([
                'invoicing_completed_at' => now(),
                'invoicing_completed_by_user_id' => $u->id,
            ])->save();
            AuditService::log('movement_invoice_completion_marked', 'transport_job', $job->id, null, [
                'company_id' => $job->company_id,
            ]);
        }
    }

    /**
     * Stream the FAW-shaped Excel sheet for the current selection.
     */
    public function exportExcel(MovementInvoiceExport $exporter): StreamedResponse
    {
        if (!$this->companyId) {
            session()->flash('error', 'Pick a customer first.');
            return response()->streamDownload(fn () => null, 'no-customer.txt'); // 0-byte fallback to satisfy the return type
        }

        // Excluded rows are never billed, regardless of which filter
        // the user has active on screen.  Re-check here so a Live owner
        // can't accidentally ship a "not required" line to FAW.
        $jobs = $this->baseQuery()
            ->whereNull('invoicing_excluded_at')
            ->get();
        $company = Company::find($this->companyId);
        $customerName = $company?->name ?? 'Customer';

        $from = $this->dateFrom ? Carbon::parse($this->dateFrom) : null;
        $to   = $this->dateTo   ? Carbon::parse($this->dateTo)   : null;
        $period = $from && $to ? ($from->format('d-m-Y') . ' to ' . $to->format('d-m-Y')) : null;

        $contents = $exporter->build($jobs, $customerName, $period);
        $filename = $exporter->filename($customerName, $from, $to);

        AuditService::log('movement_invoice_exported', 'customer_invoicing', null, null, [
            'company_id' => $this->companyId,
            'from' => $this->dateFrom,
            'to'   => $this->dateTo,
            'rows' => $jobs->count(),
            'filename' => $filename,
        ]);

        return response()->streamDownload(function () use ($contents) {
            echo $contents;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function normaliseString($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function normaliseDecimal($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        return number_format((float) $v, 2, '.', '');
    }

    public function with(): array
    {
        // Paginate to a modest page size so the Livewire payload stays
        // flat regardless of how many movements the OEM has for the
        // month.  See PAGE_SIZE for context.
        $jobs = $this->baseQuery()->paginate(self::PAGE_SIZE);

        // Prune $rows down to the ids currently on screen and hydrate
        // any that are missing.  Previous behaviour appended forever,
        // so switching customer -> filter -> page kept growing the
        // public array shipped in every round-trip.
        $pageIds = collect($jobs->items())->pluck('id')->all();
        $this->rows = array_intersect_key($this->rows, array_flip($pageIds));
        foreach ($jobs as $job) {
            if (!array_key_exists($job->id, $this->rows)) {
                $this->rows[$job->id] = [
                    'invoice_number' => $job->invoice_number,
                    'invoice_amount' => $job->invoice_amount,
                    'extras_amount'  => $job->extras_amount,
                    'fuel_litres'    => $job->fuel_litres,
                    'fuel_amount'    => $job->fuel_amount,
                ];
            }
        }

        $proselverCompanyIds = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereNotNull('delivered_at')
            ->select('company_id')
            ->distinct();

        $customerCompanies = Company::query()
            ->whereIn('id', $proselverCompanyIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        // First option clears the filter (matches the X-button on the
        // dropdown) so accounts can flip back to "all customers" without
        // hunting for the corner X.
        $companyOptions = array_merge(
            [['value' => '', 'label' => 'All customers']],
            $customerCompanies->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
        );

        // Server-resolved label so the dropdown shows the right customer
        // name on first paint even when Livewire's JS state hasn't booted
        // yet (?companyId=1 deep-link, page refresh).
        $companyLabel = $this->companyId
            ? ($customerCompanies->firstWhere('id', (int) $this->companyId)?->name)
            : null;

        // Window aggregate: one query with conditional counts and sums
        // instead of four separate COUNTs plus in-PHP summing of a
        // fully-loaded collection.  Same numbers, ~1/6th the DB work,
        // and honest across all pages (not just the visible slice).
        $windowBase = Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereNotNull('delivered_at');
        if ($this->companyId) {
            $windowBase->where('company_id', (int) $this->companyId);
        }
        if ($this->dateFrom && $this->dateTo) {
            $windowBase->whereBetween('delivered_at', [
                Carbon::parse($this->dateFrom)->startOfDay(),
                Carbon::parse($this->dateTo)->endOfDay(),
            ]);
        }

        $windowAgg = (clone $windowBase)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_excluded_at IS NOT NULL THEN 1 END) AS excluded_count')
            ->selectRaw('COUNT(CASE WHEN invoicing_completed_at IS NOT NULL AND invoicing_excluded_at IS NULL THEN 1 END) AS done_count')
            ->first();

        // Filter-scoped totals (respect the completion selector) --
        // computed on the DB so pagination doesn't lie about "invoice
        // total = R x" once the user is on page 2 of 5.
        //
        // reorder() strips baseQuery()'s ORDER BY delivered_at -- Postgres
        // refuses an ORDER BY on a non-aggregated column when the SELECT
        // has no GROUP BY and only aggregates (SQLite lets it through,
        // which is why the test suite missed this on the first pass).
        $filterAgg = (clone $this->baseQuery())
            ->reorder()
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(SUM(invoice_amount), 0) AS invoice_sum')
            ->selectRaw('COALESCE(SUM(extras_amount), 0) AS extras_sum')
            ->selectRaw('COALESCE(SUM(fuel_litres), 0) AS litres_sum')
            ->selectRaw('COALESCE(SUM(fuel_amount), 0) AS fuel_sum')
            ->selectRaw('COUNT(CASE WHEN (invoice_number IS NULL OR invoice_number = ?) AND invoicing_excluded_at IS NULL THEN 1 END) AS missing_count', [''])
            ->first();

        $windowTotal = (int) ($windowAgg->total_count ?? 0);
        $windowExcluded = (int) ($windowAgg->excluded_count ?? 0);
        $windowDone = (int) ($windowAgg->done_count ?? 0);

        $totals = [
            'count'   => (int) ($filterAgg->row_count ?? 0),
            'invoice' => (float) ($filterAgg->invoice_sum ?? 0),
            'extras'  => (float) ($filterAgg->extras_sum ?? 0),
            'litres'  => (float) ($filterAgg->litres_sum ?? 0),
            'fuel'    => (float) ($filterAgg->fuel_sum ?? 0),
            'missing' => (int) ($filterAgg->missing_count ?? 0),
            'window_total'    => $windowTotal,
            'window_billable' => $windowTotal - $windowExcluded,
            'window_complete' => $windowDone,
            'window_excluded' => $windowExcluded,
        ];

        $viewer = auth()->user();
        $canExclude = $viewer && ($viewer->isOwner() || $viewer->isDeveloper());

        return [
            'jobs' => $jobs,
            'companyOptions' => $companyOptions,
            'companyLabel' => $companyLabel,
            'totals' => $totals,
            'canExclude' => $canExclude,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Invoicing</x-slot:header>

    @if (session('success'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-md bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Filters --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">ProSelver movements awaiting / past invoicing</h2>
                <p class="text-xs text-gray-500">
                    Captures the invoice number, amounts and fuel after delivery.  Defaults to last calendar month (2nd → 1st).
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button wire:click="applyRange('last_month')" class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Last month</button>
                <button wire:click="applyRange('this_month')" class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">This month</button>
                <button wire:click="applyRange('last_30')"    class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Last 30 days</button>
                <button wire:click="applyRange('this_year')"  class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">This year</button>

                {{-- Completion view selector: incomplete (default working
                     list) / all / complete (housekeeping review). Saved
                     to the URL so a refresh holds the chosen view. --}}
                <div class="inline-flex rounded-md border border-gray-200 bg-white text-xs">
                    <button wire:click="setCompletion('incomplete')"
                        class="px-2.5 py-1 font-medium rounded-l-md {{ $completion === 'incomplete' ? 'bg-slate-900 text-white' : 'text-gray-700 hover:bg-gray-50' }}">Incomplete</button>
                    <button wire:click="setCompletion('complete')"
                        class="px-2.5 py-1 font-medium border-l border-gray-200 {{ $completion === 'complete' ? 'bg-slate-900 text-white' : 'text-gray-700 hover:bg-gray-50' }}">Complete</button>
                    <button wire:click="setCompletion('excluded')"
                        class="px-2.5 py-1 font-medium border-l border-gray-200 {{ $completion === 'excluded' ? 'bg-slate-900 text-white' : 'text-gray-700 hover:bg-gray-50' }}">Not required</button>
                    <button wire:click="setCompletion('all')"
                        class="px-2.5 py-1 font-medium border-l border-gray-200 rounded-r-md {{ $completion === 'all' ? 'bg-slate-900 text-white' : 'text-gray-700 hover:bg-gray-50' }}">All</button>
                </div>

                <div class="flex flex-col items-end">
                    <button wire:click="save"
                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                        Save finance details
                    </button>
                    <span class="mt-0.5 text-[10px] text-slate-500">Saves this page. Save before moving on.</span>
                </div>
                <button wire:click="exportExcel"
                    @if(!$companyId || $totals['count'] === 0) disabled @endif
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Export Excel
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Customer</label>
                <x-searchable-select
                    wire:model.live="companyId"
                    :options="$companyOptions"
                    :selected-label="$companyLabel"
                    placeholder="Pick a customer"
                />
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">From</label>
                <input type="date" wire:model.blur="dateFrom" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">To</label>
                <input type="date" wire:model.blur="dateTo" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-700 flex flex-col justify-center">
                <div class="flex items-center justify-between gap-2"><span>Movements ({{ $completion }})</span><strong>{{ $totals['count'] }}</strong></div>
                <div class="flex items-center justify-between gap-2"><span>Window progress</span><strong>{{ $totals['window_complete'] }} / {{ $totals['window_billable'] }}</strong></div>
                @if($totals['window_excluded'])
                    <div class="flex items-center justify-between gap-2 text-slate-500"><span>Not required</span><strong>{{ $totals['window_excluded'] }}</strong></div>
                @endif
                <div class="flex items-center justify-between gap-2"><span>Missing invoice #</span><strong class="{{ $totals['missing'] ? 'text-rose-600' : 'text-emerald-600' }}">{{ $totals['missing'] }}</strong></div>
                <div class="flex items-center justify-between gap-2"><span>Invoiced total</span><strong>R {{ number_format($totals['invoice'], 2) }}</strong></div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        @if($totals['count'] === 0)
            <div class="px-5 py-10 text-center text-sm text-slate-500">
                @if(!$companyId)
                    Pick a customer to see their ProSelver movements in this window.
                @else
                    No ProSelver-executed movements delivered for this customer in this window.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Job number</th>
                            <th class="px-3 py-2 text-left">Order date</th>
                            <th class="px-3 py-2 text-left">Model</th>
                            <th class="px-3 py-2 text-left">Chassis no</th>
                            <th class="px-3 py-2 text-left">From → To</th>
                            <th class="px-3 py-2 text-left">Collected</th>
                            <th class="px-3 py-2 text-left">Delivered</th>
                            <th class="px-3 py-2 text-left">Invoice #</th>
                            <th class="px-3 py-2 text-right">Invoice amt (incl VAT)</th>
                            <th class="px-3 py-2 text-right">Extras (incl VAT)</th>
                            <th class="px-3 py-2 text-right">Litres</th>
                            <th class="px-3 py-2 text-right">Fuel (excl VAT)</th>
                            <th class="px-3 py-2 text-center">Complete</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($jobs as $job)
                            @php
                                $hasInv = !empty($rows[$job->id]['invoice_number'] ?? $job->invoice_number);
                                $isDone = (bool) $job->invoicing_completed_at;
                                $isExcluded = (bool) $job->invoicing_excluded_at;
                                $rowTint = $isExcluded
                                    ? 'bg-slate-100 text-slate-400'
                                    : ($isDone ? 'bg-emerald-50/50' : ($hasInv ? '' : 'bg-amber-50/40'));
                            @endphp
                            <tr class="hover:bg-slate-50 {{ $rowTint }}">
                                <td class="px-3 py-1.5">
                                    <a href="{{ route('admin.orders.show', $job) }}" target="_blank" class="font-semibold text-blue-700 hover:underline">{{ $job->job_number }}</a>
                                </td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->created_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ $job->model_name ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-slate-700">
                                    <x-vehicle-identifier :model="$job" layout="stacked" />
                                </td>
                                <td class="px-3 py-1.5 text-slate-600 truncate max-w-[260px]" title="{{ ($job->pickupLocation?->company_name ?? '') . ' → ' . ($job->deliveryLocation?->company_name ?? '') }}">
                                    {{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}
                                </td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->collected_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->delivered_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5">
                                    <input type="text" wire:model="rows.{{ $job->id }}.invoice_number"
                                        @disabled($isExcluded)
                                        class="w-32 rounded border border-slate-300 px-2 py-1 text-xs font-mono disabled:bg-slate-100 disabled:text-slate-400"
                                        placeholder="INV…">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.invoice_amount"
                                        @disabled($isExcluded)
                                        class="w-28 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums disabled:bg-slate-100 disabled:text-slate-400"
                                        placeholder="0.00">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.extras_amount"
                                        @disabled($isExcluded)
                                        class="w-24 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums disabled:bg-slate-100 disabled:text-slate-400"
                                        placeholder="0.00">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.fuel_litres"
                                        @disabled($isExcluded)
                                        class="w-20 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums disabled:bg-slate-100 disabled:text-slate-400"
                                        placeholder="0">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.fuel_amount"
                                        @disabled($isExcluded)
                                        class="w-24 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums disabled:bg-slate-100 disabled:text-slate-400"
                                        placeholder="0.00">
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <div class="inline-flex flex-col gap-1 items-stretch">
                                        @if($isExcluded)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-600"
                                                title="Excluded {{ $job->invoicing_excluded_at?->format('d-m-Y H:i') }}{{ $job->invoicing_excluded_reason ? ' -- ' . $job->invoicing_excluded_reason : '' }}">
                                                Not required
                                            </span>
                                            @if($canExclude)
                                                <button wire:click="toggleExclude({{ $job->id }})"
                                                    class="text-[10px] text-slate-500 underline hover:text-slate-700">
                                                    Un-exclude
                                                </button>
                                            @endif
                                        @else
                                            @if($isDone)
                                                <button wire:click="toggleComplete({{ $job->id }})"
                                                    title="Marked complete {{ $job->invoicing_completed_at?->format('d-m-Y H:i') }}. Click to undo."
                                                    class="inline-flex items-center justify-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 hover:bg-emerald-200">
                                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                    Done
                                                </button>
                                            @else
                                                <button wire:click="toggleComplete({{ $job->id }})"
                                                    title="Mark this row complete to hide it from the working list."
                                                    class="inline-flex items-center justify-center gap-1 rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                                                    Mark done
                                                </button>
                                            @endif
                                            @if($canExclude)
                                                <button wire:click="toggleExclude({{ $job->id }})"
                                                    wire:confirm="Mark this movement as not required to invoice? It will be dropped from the FAW export."
                                                    title="Owner/dev only: mark this movement as not required to invoice (test run, internal shuffle, write-off)."
                                                    class="text-[10px] text-slate-400 underline hover:text-rose-600">
                                                    Not required
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-4 py-3">
                {{ $jobs->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</div>
