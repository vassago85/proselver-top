<?php

use App\Services\ProselverLicenceBilling;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * ProSelver platform licence billing — owner + developer only.
 *
 * Live meter: completed ProSelver-executed movements × editable
 * per-move fee + 15% VAT. Copy block for pasting into any invoicing
 * system. Not customer freight invoicing (/admin/invoices).
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url]
    public string $month = '';

    public string $perMoveFee = '';

    public bool $copied = false;

    public function mount(): void
    {
        if (! auth()->user()?->canViewPlatformLicence()) {
            abort(403, 'The platform licence is restricted to the owner and developer.');
        }

        // Soft-hide via SystemSetting if needed (default enabled).
        if (! app(ProselverLicenceBilling::class)->isEnabled()) {
            abort(404);
        }

        if ($this->month === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }

        $billing = app(ProselverLicenceBilling::class);
        $this->perMoveFee = number_format($billing->perMoveFee(), 2, '.', '');
    }

    public function saveRates(): void
    {
        if (! auth()->user()?->canViewPlatformLicence()) {
            abort(403);
        }

        $this->validate([
            'perMoveFee' => 'required|numeric|min:0|max:99999',
        ]);

        app(ProselverLicenceBilling::class)->saveRates((float) $this->perMoveFee);

        $this->perMoveFee = number_format((float) $this->perMoveFee, 2, '.', '');

        session()->flash('success', 'Licence rate updated.');
    }

    public function selectMonth(string $month): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->month = $month;
            $this->copied = false;
        }
    }

    public function with(): array
    {
        $billing = app(ProselverLicenceBilling::class);
        $carbon = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $summary = $billing->summarise($carbon);

        return [
            'summary' => $summary,
            'recentMonths' => $billing->recentMonths(6),
            'invoiceCopyText' => $billing->invoiceCopyText($summary),
            'money' => fn (float $n) => 'R' . number_format($n, 2, '.', ','),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Platform licence</h1>
            <p class="mt-1 text-sm text-slate-500">
                Per completed ProSelver-executed vehicle + VAT. Owner and developer only.
                Customer freight invoices stay under <a href="{{ route('admin.invoices.index') }}" class="font-medium text-blue-600 hover:underline">Customer Invoicing</a>.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="billing-month">Month</label>
            <input
                id="billing-month"
                type="month"
                wire:model.live="month"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Recent months --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($recentMonths as $rm)
            <button
                type="button"
                wire:click="selectMonth('{{ $rm['month'] }}')"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition
                    {{ $rm['month'] === $summary['month']
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}"
            >
                <span>{{ $rm['label'] }}</span>
                <span class="tabular-nums opacity-80">{{ $rm['count'] }} · {{ $money($rm['total_incl_vat']) }}</span>
            </button>
        @endforeach
    </div>

    {{-- Headline --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Completed moves</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $summary['count'] }}</p>
            <p class="mt-0.5 text-[11px] text-slate-400">ProSelver-executed</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Excl. VAT</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $money($summary['total_excl_vat']) }}</p>
            <p class="mt-0.5 text-[11px] text-slate-400">{{ $summary['count'] }} × {{ $money($summary['per_move']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">VAT (15%)</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $money($summary['vat']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-900 bg-slate-900 px-4 py-3 text-white">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-300">Incl. VAT</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ $money($summary['total_incl_vat']) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Rates --}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Licence rate</h2>
            <p class="mt-1 text-xs text-slate-500">Saved in system settings. Applies to every month’s calculation.</p>
            <form wire:submit="saveRates" class="mt-4 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700">Per completed ProSelver move (excl. VAT, ZAR)</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model="perMoveFee"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm tabular-nums shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    @error('perMoveFee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button
                    type="submit"
                    class="inline-flex items-center rounded-md bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                >
                    Save rate
                </button>
            </form>
        </div>

        {{-- Generic invoice copy --}}
        <div
            class="rounded-lg border border-slate-200 bg-white p-5"
            x-data="{
                copied: false,
                async copy() {
                    const el = this.$refs.copyText;
                    try {
                        await navigator.clipboard.writeText(el.value);
                    } catch (e) {
                        el.select();
                        document.execCommand('copy');
                    }
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                }
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Copy for invoice</h2>
                    <p class="mt-1 text-xs text-slate-500">Paste into your invoicing system.</p>
                </div>
                <button
                    type="button"
                    @click="copy()"
                    class="shrink-0 inline-flex items-center rounded-md border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                >
                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </button>
            </div>
            <textarea
                x-ref="copyText"
                readonly
                rows="10"
                class="mt-4 w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-800"
            >{{ $invoiceCopyText }}</textarea>
        </div>
    </div>

    {{-- Drill-down --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-100 px-5 py-3">
            <h2 class="text-sm font-semibold text-slate-900">
                Billable movements — {{ $summary['label'] }}
            </h2>
            <p class="mt-0.5 text-xs text-slate-500">
                Executor = ProSelver · status delivered or completed · keyed on delivered date.
            </p>
        </div>

        @if ($summary['jobs']->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-400">No billable ProSelver movements in this month.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50/80 text-[10px] uppercase tracking-wider text-slate-500">
                            <th class="px-4 py-2 text-left font-semibold">Reference</th>
                            <th class="px-4 py-2 text-left font-semibold">Customer</th>
                            <th class="px-4 py-2 text-left font-semibold">Route</th>
                            <th class="px-4 py-2 text-left font-semibold">Delivered</th>
                            <th class="px-4 py-2 text-left font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($summary['jobs'] as $job)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('admin.orders.show', $job) }}" class="font-mono text-xs font-semibold text-blue-600 hover:underline">
                                        {{ $job->job_number ?? ('JOB-' . $job->id) }}
                                    </a>
                                    @if ($job->vin)
                                        <div class="font-mono text-[10px] text-slate-400">{{ $job->vin }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-700">{{ $job->company?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">
                                    {{ $job->pickupLocation?->city ?: ($job->pickupLocation?->company_name ?? '—') }}
                                    →
                                    {{ $job->deliveryLocation?->city ?: ($job->deliveryLocation?->company_name ?? '—') }}
                                </td>
                                <td class="px-4 py-2.5 text-xs tabular-nums text-slate-700">
                                    {{ $job->delivered_at?->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5"><x-status-badge :status="$job->status" size="sm" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
