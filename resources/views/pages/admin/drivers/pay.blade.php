<?php

use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Month-end driver pay and movement report.
 *
 * Bundles what accounts and CRM asked for on 22-23 July into one
 * screen so month-end doesn't need a side spreadsheet:
 *
 *   - Movements completed by each driver in the picked month
 *   - Rate per movement (from DriverProfile.rate_per_movement_cents)
 *   - Earnings (movements x rate)
 *   - Movement cost (SUM transport_jobs.total_cost) -- the fully-costed
 *     line-haul number, if the trip has been costed.
 *   - Advances issued (SUM advance_total, assigned in the month)
 *   - Actual petty cash spent by the driver (approved + reimbursed)
 *
 * Access: accounts / owner / developer only.  The screen exposes
 * salary + spend detail across every driver and is deliberately not
 * an ops screen -- ops sees per-driver operational metrics on the
 * Driver Operations page instead.
 */
new #[Layout('components.layouts.app')] class extends Component {
    /**
     * Picked month as YYYY-MM.  Defaults in mount() to the previous
     * calendar month, which is what accounts is usually running the
     * month-end payroll for.
     */
    #[Url] public string $month = '';

    public function mount(): void
    {
        $u = auth()->user();
        if (!$u || (!$u->isAccounts() && !$u->isOwner() && !$u->isDeveloper())) {
            abort(403, 'Driver pay report is restricted to accounts.');
        }

        if ($this->month === '' || !preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->subMonthNoOverflow()->format('Y-m');
        }
    }

    /**
     * Resolve the picked month to a Carbon range, defensively falling
     * back to last month if the URL param is bad.
     */
    private function monthRange(): array
    {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $this->month);
        } catch (\Throwable $e) {
            $anchor = now()->subMonthNoOverflow()->startOfMonth();
        }
        return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), $anchor];
    }

    public function with(): array
    {
        [$from, $to, $anchor] = $this->monthRange();

        // Base driver list.  We show every active platform driver, so
        // a zero-movement month still lists them with R0 -- that's the
        // signal accounts uses to spot rostered drivers who didn't turn
        // a wheel and to spot data-entry errors.
        $drivers = User::query()
            ->platformDrivers()
            ->with('driverProfile:user_id,rate_per_movement_cents,cellphone')
            ->orderBy('name')
            ->get(['id', 'name']);

        $driverIds = $drivers->pluck('id')->all();

        // Movements delivered in the month, grouped by driver.
        // We treat "completed movement" as: assigned to this driver,
        // delivered_at inside the window, and status in the
        // delivered/completed/invoiced buckets (i.e. we don't count
        // cancelled or recalled trips).
        $moveAgg = Job::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereBetween('delivered_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COUNT(*) AS moves, COALESCE(SUM(total_cost), 0) AS cost_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Advances issued in the month, grouped by driver.  Uses
        // advance_assigned_at (not delivered_at) so an advance issued
        // in July for a trip that only lands in August still counts
        // against July's cash-out.
        $advAgg = Job::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereNotNull('advance_assigned_at')
            ->whereBetween('advance_assigned_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COALESCE(SUM(advance_total), 0) AS adv_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Actual driver spend from petty cash: approved + reimbursed
        // slips created in the month.  Rejected/submitted are excluded
        // because they don't represent money the business has committed.
        $spendAgg = PettyCashEntry::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereIn('status', [PettyCashEntry::STATUS_APPROVED, PettyCashEntry::STATUS_REIMBURSED])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COALESCE(SUM(amount_cents), 0) AS cents_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Stitch the per-driver rows together.
        $rows = $drivers->map(function (User $d) use ($moveAgg, $advAgg, $spendAgg) {
            $rateCents = $d->driverProfile?->rate_per_movement_cents;
            $rate = $rateCents === null ? null : (float) $rateCents / 100;

            $move = $moveAgg->get($d->id);
            $moves = (int) ($move->moves ?? 0);
            $cost  = (float) ($move->cost_sum ?? 0);

            $advances = (float) ($advAgg->get($d->id)->adv_sum ?? 0);
            $spend    = (float) ($spendAgg->get($d->id)->cents_sum ?? 0) / 100;

            $earnings = $rate !== null ? $rate * $moves : null;

            return [
                'id'       => $d->id,
                'name'     => $d->name,
                'rate'     => $rate,
                'moves'    => $moves,
                'earnings' => $earnings,
                'cost'     => $cost,
                'advances' => $advances,
                'spend'    => $spend,
            ];
        });

        $totals = [
            'moves'    => (int) $rows->sum('moves'),
            'earnings' => (float) $rows->sum(fn ($r) => $r['earnings'] ?? 0),
            'cost'     => (float) $rows->sum('cost'),
            'advances' => (float) $rows->sum('advances'),
            'spend'    => (float) $rows->sum('spend'),
        ];

        return [
            'rows'   => $rows,
            'totals' => $totals,
            'from'   => $from,
            'to'     => $to,
            'anchor' => $anchor,
        ];
    }
}; ?>

<div class="space-y-4">
    <x-slot:header>Driver pay &amp; movements</x-slot:header>

    @include('pages.admin.petty-cash._partials.section-tabs')

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Month-end summary</h2>
                <p class="text-xs text-slate-500">
                    Movements completed &times; rate = earnings.  Pick a month; the report defaults to last month.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Month</span>
                    <input type="month"
                        wire:model.live="month"
                        max="{{ now()->format('Y-m') }}"
                        class="rounded-md border-slate-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </label>
                <span class="text-[11px] text-slate-500">
                    {{ $from->format('d M Y') }} &rarr; {{ $to->format('d M Y') }}
                </span>
            </div>
        </div>

        {{-- Headline totals --}}
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-4 sm:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Completed movements</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums">{{ $totals['moves'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Driver earnings</p>
                <p class="mt-1 text-lg font-bold text-emerald-900 tabular-nums">R {{ number_format($totals['earnings'], 2) }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Movement cost</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums">R {{ number_format($totals['cost'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-slate-400">from job total_cost</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Advances issued</p>
                <p class="mt-1 text-lg font-bold text-blue-900 tabular-nums">R {{ number_format($totals['advances'], 2) }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Petty cash spend</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">R {{ number_format($totals['spend'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-amber-700">approved + reimbursed</p>
            </div>
        </div>

        {{-- Per-driver table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Driver</th>
                        <th class="px-3 py-2 text-right">Rate / move</th>
                        <th class="px-3 py-2 text-right">Movements</th>
                        <th class="px-3 py-2 text-right">Earnings</th>
                        <th class="px-3 py-2 text-right">Movement cost</th>
                        <th class="px-3 py-2 text-right">Advances</th>
                        <th class="px-3 py-2 text-right">Petty cash</th>
                        <th class="px-3 py-2 text-center">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-600">
                                @if($row['rate'] === null)
                                    <span class="text-slate-400" title="No rate on the driver profile">—</span>
                                @else
                                    R {{ number_format($row['rate'], 2) }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-800 font-semibold">{{ $row['moves'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                @if($row['earnings'] === null)
                                    <span class="text-slate-400" title="Set a rate to compute earnings">—</span>
                                @else
                                    <span class="font-semibold text-emerald-700">R {{ number_format($row['earnings'], 2) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">R {{ number_format($row['cost'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">R {{ number_format($row['advances'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">R {{ number_format($row['spend'], 2) }}</td>
                            <td class="px-3 py-2 text-center">
                                <a href="{{ route('admin.drivers.edit', $row['id']) }}"
                                    class="text-[11px] font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                    Edit profile
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-slate-500">
                                No active platform drivers.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot class="bg-slate-50 text-[11px] font-semibold text-slate-700">
                        <tr>
                            <td class="px-3 py-2 text-left">Totals</td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $totals['moves'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-emerald-700">R {{ number_format($totals['earnings'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($totals['cost'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($totals['advances'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">R {{ number_format($totals['spend'], 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-500">
            <p>
                <strong>Movements</strong> = jobs assigned to the driver whose delivered_at falls in {{ $anchor->format('F Y') }},
                in delivered / completed / invoiced status.  <strong>Earnings</strong> = movements &times; rate from the driver
                profile.  <strong>Movement cost</strong> comes from job total_cost -- if a driver shows movements with R0 cost, the
                job hasn't been costed yet.  <strong>Advances</strong> track cash issued in the same window; <strong>Petty
                cash</strong> is what the driver actually spent (approved + reimbursed slips).
            </p>
        </div>
    </div>
</div>
