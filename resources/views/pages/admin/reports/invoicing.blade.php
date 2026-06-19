<?php

use App\Models\Company;
use App\Models\Job;
use App\Services\AuditService;
use App\Services\MovementInvoiceExport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
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
    #[Url] public ?int $companyId = null;
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

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

        $jobs = $this->baseQuery()->get(['id', 'invoice_number', 'invoice_amount', 'extras_amount', 'fuel_litres', 'fuel_amount']);
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
     * Stream the FAW-shaped Excel sheet for the current selection.
     */
    public function exportExcel(MovementInvoiceExport $exporter): StreamedResponse
    {
        if (!$this->companyId) {
            session()->flash('error', 'Pick a customer first.');
            return response()->streamDownload(fn () => null, 'no-customer.txt'); // 0-byte fallback to satisfy the return type
        }

        $jobs = $this->baseQuery()->get();
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
        $jobs = $this->baseQuery()->get();

        // Hydrate the per-row inputs from the latest persisted values
        // for any job we don't already have edits in flight for.
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

        $companyOptions = Company::query()
            ->whereIn('id', $proselverCompanyIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->all();

        $totals = [
            'count'   => $jobs->count(),
            'invoice' => (float) $jobs->sum(fn ($j) => (float) ($j->invoice_amount ?? 0)),
            'extras'  => (float) $jobs->sum(fn ($j) => (float) ($j->extras_amount ?? 0)),
            'litres'  => (float) $jobs->sum(fn ($j) => (float) ($j->fuel_litres ?? 0)),
            'fuel'    => (float) $jobs->sum(fn ($j) => (float) ($j->fuel_amount ?? 0)),
            'missing' => $jobs->filter(fn ($j) => empty($j->invoice_number))->count(),
        ];

        return [
            'jobs' => $jobs,
            'companyOptions' => $companyOptions,
            'totals' => $totals,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Customer invoicing</x-slot:header>

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
                <button wire:click="save"
                    class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500">
                    Save finance details
                </button>
                <button wire:click="exportExcel"
                    @if(!$companyId || $jobs->isEmpty()) disabled @endif
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
                    placeholder="Pick a customer"
                />
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">From</label>
                <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div>
                <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">To</label>
                <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"/>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-700 flex flex-col justify-center">
                <div class="flex items-center justify-between gap-2"><span>Movements</span><strong>{{ $totals['count'] }}</strong></div>
                <div class="flex items-center justify-between gap-2"><span>Missing invoice #</span><strong class="{{ $totals['missing'] ? 'text-rose-600' : 'text-emerald-600' }}">{{ $totals['missing'] }}</strong></div>
                <div class="flex items-center justify-between gap-2"><span>Invoiced total</span><strong>R {{ number_format($totals['invoice'], 2) }}</strong></div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        @if($jobs->isEmpty())
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($jobs as $job)
                            @php
                                $hasInv = !empty($rows[$job->id]['invoice_number'] ?? $job->invoice_number);
                            @endphp
                            <tr class="hover:bg-slate-50 {{ $hasInv ? '' : 'bg-amber-50/40' }}">
                                <td class="px-3 py-1.5">
                                    <a href="{{ route('admin.orders.show', $job) }}" target="_blank" class="font-semibold text-blue-700 hover:underline">{{ $job->job_number }}</a>
                                </td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->created_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ $job->model_name ?: '—' }}</td>
                                <td class="px-3 py-1.5 font-mono text-slate-700">{{ $job->vin ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-slate-600 truncate max-w-[260px]" title="{{ ($job->pickupLocation?->company_name ?? '') . ' → ' . ($job->deliveryLocation?->company_name ?? '') }}">
                                    {{ $job->pickupLocation?->company_name ?? '—' }} → {{ $job->deliveryLocation?->company_name ?? '—' }}
                                </td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->collected_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5 text-slate-500">{{ optional($job->delivered_at)->format('d-m-Y') }}</td>
                                <td class="px-3 py-1.5">
                                    <input type="text" wire:model="rows.{{ $job->id }}.invoice_number"
                                        class="w-32 rounded border border-slate-300 px-2 py-1 text-xs font-mono"
                                        placeholder="INV…">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.invoice_amount"
                                        class="w-28 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums"
                                        placeholder="0.00">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.extras_amount"
                                        class="w-24 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums"
                                        placeholder="0.00">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.fuel_litres"
                                        class="w-20 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums"
                                        placeholder="0">
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    <input type="number" step="0.01" min="0" wire:model="rows.{{ $job->id }}.fuel_amount"
                                        class="w-24 rounded border border-slate-300 px-2 py-1 text-xs text-right tabular-nums"
                                        placeholder="0.00">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
