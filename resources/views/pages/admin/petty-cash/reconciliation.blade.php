<?php

use App\Models\Job;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  RECONCILIATION REPORT                                               ║
 * ╠══════════════════════════════════════════════════════════════════════╣
 * ║  One question: a driver was issued cash, the trip was then            ║
 * ║  cancelled — where did the money go?                                  ║
 * ║                                                                      ║
 * ║  Two lists, and they are scoped differently on purpose:               ║
 * ║                                                                      ║
 * ║    OPEN      never range-filtered. Cash that is out of the till is   ║
 * ║              outstanding regardless of which month you're looking at, ║
 * ║              and hiding an eight-month-old query because the range    ║
 * ║              says "this month" is exactly how it stays open.          ║
 * ║    SETTLED   range-filtered on the date it was cleared. This is the   ║
 * ║              report half: what was resolved in the period, by whom,   ║
 * ║              and the written explanation.                             ║
 * ║                                                                      ║
 * ║  Accounts and ops both clear from here (see                           ║
 * ║  User::canClearReconciliationQuery) — ops is usually the only party    ║
 * ║  that knows the advance was moved to another vehicle.                 ║
 * ║                                                                      ║
 * ║  "Days open" is computed in PHP rather than SQL: Postgres and SQLite  ║
 * ║  disagree on date arithmetic and this page is covered by the SQLite   ║
 * ║  test suite. Same reason there's no FILTER or ::date anywhere here.   ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    /** Window for the settled list. Open queries ignore this. */
    #[Url] public string $range = 'this_month';

    public const RANGES = [
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'all' => 'All time',
    ];

    // Clear-with-note modal. Mirrors the Overview and order-page actions so
    // all three entry points write the same trail.
    public ?int $clearQueryJobId = null;
    public string $clearQueryNote = '';
    public bool $showClearQueryModal = false;

    public function mount(): void
    {
        if (!auth()->user()?->canViewPettyCashOverview()) {
            abort(403, 'The reconciliation report is restricted to accounts, operations and management.');
        }

        if (!array_key_exists($this->range, self::RANGES)) {
            $this->range = 'this_month';
        }
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
        }
    }

    /** [from, to] for the settled list, or [null, null] for all time. */
    private function window(): array
    {
        $now = now();

        return match ($this->range) {
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    public function rangeLabel(): string
    {
        return self::RANGES[$this->range] ?? 'This month';
    }

    private const WITH_RELATIONS = [
        'company:id,name',
        'driver:id,name',
        'advanceIssuedBy:id,name',
        'issuedCancellationClearedBy:id,name',
    ];

    /** Cash still unaccounted for, oldest first — the work queue. */
    private function openQuery(): Builder
    {
        return Job::query()
            ->issuedCancellationQueryOpen()
            ->with(self::WITH_RELATIONS)
            ->orderBy('cancelled_at');
    }

    /** Settled queries, newest clearance first — the audit record. */
    private function settledQuery(): Builder
    {
        [$from, $to] = $this->window();

        return Job::query()
            ->where('status', Job::STATUS_CANCELLED)
            ->whereNotNull('advance_issued_at')
            ->whereNotNull('issued_cancellation_cleared_at')
            ->when($from, fn ($q) => $q->where('issued_cancellation_cleared_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('issued_cancellation_cleared_at', '<=', $to))
            ->with(self::WITH_RELATIONS)
            ->orderByDesc('issued_cancellation_cleared_at');
    }

    /** Whole days between cancellation and clearance (or today, if open). */
    public function daysOpen(Job $job): ?int
    {
        if (!$job->cancelled_at) {
            return null;
        }

        $end = $job->issued_cancellation_cleared_at ?? now();

        return (int) $job->cancelled_at->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());
    }

    // ── Clear with note ────────────────────────────────────────────────

    public function openClearQuery(int $jobId): void
    {
        if (!auth()->user()?->canClearReconciliationQuery()) {
            abort(403);
        }

        $this->clearQueryJobId = $jobId;
        $this->clearQueryNote = '';
        $this->showClearQueryModal = true;
    }

    public function cancelClearQuery(): void
    {
        $this->clearQueryJobId = null;
        $this->clearQueryNote = '';
        $this->showClearQueryModal = false;
    }

    public function submitClearQuery(): void
    {
        $u = auth()->user();

        if (!$u || !$u->canClearReconciliationQuery()) {
            abort(403);
        }

        $this->validate([
            'clearQueryJobId' => 'required|integer|exists:transport_jobs,id',
            'clearQueryNote' => 'required|string|min:5|max:2000',
        ], [
            'clearQueryNote.required' => 'Please describe how the cash was reconciled.',
            'clearQueryNote.min' => 'Explanation needs to be at least 5 characters so the audit trail makes sense.',
        ]);

        $job = Job::find($this->clearQueryJobId);

        if (!$job || !$job->hasOpenIssuedCancellationQuery()) {
            session()->flash('error', 'That query is no longer open.');
            $this->cancelClearQuery();

            return;
        }

        $job->issued_cancellation_cleared_at = now();
        $job->issued_cancellation_cleared_by_user_id = $u->id;
        $job->issued_cancellation_cleared_note = trim($this->clearQueryNote);
        $job->save();

        AuditService::log('issued_cancellation_query_cleared', 'job', $job->id, null, [
            'note' => $job->issued_cancellation_cleared_note,
            'cleared_by_roles' => $u->roles->pluck('slug')->values()->all(),
            'advance_total' => (float) ($job->advance_total ?? 0),
            'source' => 'reconciliation_report',
        ]);

        session()->flash('success', "Reconciliation query cleared on {$job->job_number}.");
        $this->cancelClearQuery();
    }

    // ── Export ─────────────────────────────────────────────────────────

    /**
     * Both lists in one file, status column first, so the owner can hand the
     * whole picture to an accountant. The export is audited like any other
     * bulk read of financial detail.
     */
    public function exportCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->canViewPettyCashOverview(), 403);

        [$from, $to] = $this->window();

        AuditService::log('reconciliation_report_exported', 'petty_cash_reconciliation', null, null, [
            'range' => $this->range,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);

        $open = $this->openQuery()->get();
        $settled = $this->settledQuery()->get();

        $filename = 'reconciliation_' . $this->range . '_' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($open, $settled) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Status', 'Job number', 'Customer', 'Driver', 'Issued (R)', 'Issued by',
                'Cancelled', 'Cancellation reason', 'Days open', 'Cleared', 'Cleared by', 'Explanation',
            ]);

            foreach ([['Open', $open], ['Settled', $settled]] as [$status, $rows]) {
                foreach ($rows as $job) {
                    fputcsv($out, [
                        $status,
                        $job->job_number ?? ('JOB-' . $job->id),
                        $job->company?->name ?? '',
                        $job->driver?->name ?? '',
                        number_format((float) $job->advance_total, 2, '.', ''),
                        $job->advanceIssuedBy?->name ?? '',
                        optional($job->cancelled_at)->format('Y-m-d'),
                        $job->cancellation_reason ?? '',
                        $this->daysOpen($job) ?? '',
                        optional($job->issued_cancellation_cleared_at)->format('Y-m-d H:i'),
                        $job->issuedCancellationClearedBy?->name ?? '',
                        $job->issued_cancellation_cleared_note ?? '',
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        [$from, $to] = $this->window();

        $open = $this->openQuery()->get();
        $settled = $this->settledQuery()->get();

        // Average days to settle, over the rows in the window that have both
        // ends of the clock. Null when there's nothing to average.
        $spans = $settled
            ->map(fn ($job) => $this->daysOpen($job))
            ->filter(fn ($d) => $d !== null);

        return [
            'ranges' => self::RANGES,
            'open' => $open,
            'settled' => $settled,
            'openTotal' => (float) $open->sum('advance_total'),
            'settledTotal' => (float) $settled->sum('advance_total'),
            'avgDaysToSettle' => $spans->isEmpty() ? null : (int) round($spans->avg()),
            'oldestOpenDays' => $open->isEmpty() ? null : $this->daysOpen($open->first()),
            'windowLabel' => $from && $to
                ? $from->format('d M Y') . ' – ' . $to->format('d M Y')
                : 'All recorded history',
            'canClear' => (bool) auth()->user()?->canClearReconciliationQuery(),
        ];
    }
}; ?>

@php
    // Cents matter on the rows, the modal and the export -- those are the
    // figures somebody reconciles against. The headline KPIs and the panel
    // pills drop them, because "R 3,390.00" wraps onto two lines in a quarter
    // -width KPI card on a 375px phone.
    $money = fn ($v) => 'R ' . number_format((float) $v, 2);
    $moneyShort = fn ($v) => 'R ' . number_format((float) $v, 0);
@endphp

<div>
    <x-slot:header>Reconciliation Queries</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    <x-page-header
        eyebrow="Petty cash"
        title="Reconciliation Queries"
        subtitle="Advances that left the till on trips which were then cancelled — what is still outstanding, and how the settled ones were explained.">
        <x-slot:actions>
            <button type="button" wire:click="exportCsv"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Export CSV
            </button>
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">{{ session('error') }}</div>
    @endif

    {{-- Open figures are all-time; settled figures follow the range chips. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-dash.kpi
            label="Open now"
            :value="$moneyShort($openTotal)"
            :color="$open->count() > 0 ? 'red' : 'green'"
            :helper="$open->count() . ' ' . \Illuminate\Support\Str::plural('trip', $open->count()) . ' — cash out, no explanation yet'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Settled"
            :value="$moneyShort($settledTotal)"
            color="green"
            :helper="$settled->count() . ' ' . \Illuminate\Support\Str::plural('trip', $settled->count()) . ' explained in ' . \Illuminate\Support\Str::lower($this->rangeLabel())">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Oldest"
            :value="$oldestOpenDays !== null ? $oldestOpenDays . 'd' : '—'"
            :color="$oldestOpenDays !== null && $oldestOpenDays > 14 ? 'red' : ($oldestOpenDays !== null ? 'amber' : 'green')"
            :helper="$oldestOpenDays !== null ? 'Days since that trip was cancelled' : 'Nothing outstanding'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi
            label="Avg settle"
            :value="$avgDaysToSettle !== null ? $avgDaysToSettle . 'd' : '—'"
            color="teal"
            :helper="$avgDaysToSettle !== null ? 'Cancellation to sign-off, this period' : 'Nothing settled this period'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </x-slot:icon>
        </x-dash.kpi>
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- OPEN — not range-filtered, on purpose                          --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <x-dash.panel
        class="mb-6"
        title="Open queries"
        subtitle="Every outstanding one, oldest first — regardless of the period selected below."
        :tight="true">
        <x-slot:actions>
            <x-dash.pill :variant="$open->count() > 0 ? 'red' : 'green'">{{ $moneyShort($openTotal) }}</x-dash.pill>
        </x-slot:actions>

        @if($open->isEmpty())
            <x-empty-state
                title="Nothing outstanding"
                description="Every advance issued on a cancelled trip has a written explanation against it.">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </x-slot:icon>
            </x-empty-state>
        @else
            {{-- Desktop table --}}
            <div class="hidden lg:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-[0.15em] text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">Job</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Customer</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Driver</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Issued</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Issued by</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Cancelled</th>
                            <th class="px-4 py-2.5 text-left font-semibold">Reason</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($open as $job)
                            @php $days = $this->daysOpen($job); @endphp
                            <tr class="hover:bg-rose-50/40">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('admin.orders.show', $job->id) }}" class="font-semibold text-blue-700 hover:underline">{{ $job->job_number ?? 'JOB-' . $job->id }}</a>
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $job->company?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $job->driver?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-rose-700">{{ $money($job->advance_total) }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ $job->advanceIssuedBy?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">
                                    {{ optional($job->cancelled_at)->format('d M Y') ?? '—' }}
                                    @if($days !== null)
                                        <span class="block text-[10px] {{ $days > 14 ? 'font-semibold text-rose-600' : 'text-slate-400' }}">{{ $days }} days open</span>
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-2.5 text-xs text-slate-600">
                                    <span class="line-clamp-2">{{ $job->cancellation_reason ?: '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if($canClear)
                                        <button type="button" wire:click="openClearQuery({{ $job->id }})"
                                            class="rounded-lg bg-rose-600 px-3 py-1.5 text-[11px] font-semibold text-white transition-colors hover:bg-rose-500">
                                            Clear with note
                                        </button>
                                    @else
                                        <span class="text-[10px] italic text-rose-700/70">Waiting on accounts or ops</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phone / tablet cards. An eight-column money table is unusable
                 below lg and ops clear these from the depot on a phone. --}}
            <ul role="list" class="divide-y divide-slate-100 lg:hidden">
                @foreach($open as $job)
                    @php $days = $this->daysOpen($job); @endphp
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.orders.show', $job->id) }}" class="text-sm font-semibold text-blue-700">{{ $job->job_number ?? 'JOB-' . $job->id }}</a>
                                <p class="truncate text-xs text-slate-500">{{ $job->company?->name ?? '—' }}</p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold tabular-nums text-rose-700">{{ $money($job->advance_total) }}</p>
                        </div>

                        <dl class="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Driver</dt>
                                <dd class="truncate text-slate-700">{{ $job->driver?->name ?? '—' }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Cancelled</dt>
                                <dd class="text-slate-700">
                                    {{ optional($job->cancelled_at)->format('d M Y') ?? '—' }}
                                    @if($days !== null)
                                        <span class="{{ $days > 14 ? 'font-semibold text-rose-600' : 'text-slate-400' }}">· {{ $days }}d</span>
                                    @endif
                                </dd>
                            </div>
                            @if($job->cancellation_reason)
                                <div class="col-span-2">
                                    <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Reason</dt>
                                    <dd class="text-slate-700">{{ $job->cancellation_reason }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if($canClear)
                            <button type="button" wire:click="openClearQuery({{ $job->id }})"
                                class="mt-3 w-full rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-rose-500">
                                Clear with note
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dash.panel>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- SETTLED — the report half                                      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="mb-3 flex flex-wrap items-center gap-1.5">
        @foreach($ranges as $key => $label)
            <button type="button" wire:click="setRange('{{ $key }}')"
                class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors
                {{ $range === $key
                    ? 'border-slate-900 bg-slate-900 text-white'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <x-dash.panel
        title="Settled queries"
        :subtitle="$windowLabel . ' — by the date the explanation was signed off.'"
        :tight="true">
        <x-slot:actions>
            <x-dash.pill variant="green">{{ $moneyShort($settledTotal) }}</x-dash.pill>
        </x-slot:actions>

        @if($settled->isEmpty())
            <x-empty-state
                title="Nothing settled in this period"
                description="No reconciliation queries were signed off in the selected window. Try a wider range.">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </x-slot:icon>
            </x-empty-state>
        @else
            <ul role="list" class="divide-y divide-slate-100">
                @foreach($settled as $job)
                    @php $days = $this->daysOpen($job); @endphp
                    <li class="px-4 py-3.5 sm:px-5">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('admin.orders.show', $job->id) }}" class="text-sm font-semibold text-blue-700 hover:underline">{{ $job->job_number ?? 'JOB-' . $job->id }}</a>
                                    <x-badge color="green" size="sm" dot>Settled</x-badge>
                                    @if($days !== null)
                                        <span class="text-[11px] text-slate-400">{{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} to settle</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $job->company?->name ?? '—' }}
                                    @if($job->driver) &middot; {{ $job->driver->name }} @endif
                                    @if($job->cancelled_at) &middot; cancelled {{ $job->cancelled_at->format('d M Y') }} @endif
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $money($job->advance_total) }}</p>
                        </div>

                        {{-- The explanation is the whole point of the report, so it
                             is shown in full rather than truncated. --}}
                        <div class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2">
                            <p class="text-xs text-emerald-900">{{ $job->issued_cancellation_cleared_note ?: 'No explanation recorded.' }}</p>
                            <p class="mt-1 text-[11px] text-emerald-800/70">
                                {{ $job->issuedCancellationClearedBy?->name ?? 'Unknown' }}
                                &middot; {{ optional($job->issued_cancellation_cleared_at)->format('d M Y · H:i') }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dash.panel>

    {{-- Clear-query modal — same shape as the Overview one. --}}
    @if($showClearQueryModal)
        @php $modalJob = $clearQueryJobId ? $open->firstWhere('id', $clearQueryJobId) : null; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:click.self="cancelClearQuery">
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">Clear reconciliation query</h3>
                    @if($modalJob)
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ $modalJob->job_number ?? 'JOB-' . $modalJob->id }} &middot; {{ $money($modalJob->advance_total) }} issued
                        </p>
                    @endif
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">
                        Describe how the cash was reconciled. The explanation is permanent and appears on this report and the order's audit trail.
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Explanation</label>
                        <textarea wire:model="clearQueryNote" rows="4"
                            placeholder="e.g. Advance moved to VIN …012345 on JOB-26070999, or driver returned cash on 27 May and it was booked back into the float."
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea>
                        @error('clearQueryNote') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" wire:click="cancelClearQuery" class="rounded-lg px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100">
                        Cancel
                    </button>
                    <button type="button" wire:click="submitClearQuery" class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-rose-500">
                        Clear query
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
