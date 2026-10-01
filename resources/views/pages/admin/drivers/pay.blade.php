<?php

use App\Models\DriverBusTicket;
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
        //
        // We also sum driver_pay_amount so earnings can account for
        // per-trip overrides set on the payslip page.  The expression
        // "SUM(COALESCE(driver_pay_amount, 0))" is the override pot;
        // the "overridden_moves" count tells us how many of this
        // month's rides are overridden so the fallback-to-rate calc
        // can skip them.
        $moveAgg = Job::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereBetween('delivered_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id,
                COUNT(*) AS moves,
                COALESCE(SUM(total_cost), 0) AS cost_sum,
                COALESCE(SUM(driver_pay_amount), 0) AS override_sum,
                SUM(CASE WHEN driver_pay_amount IS NOT NULL THEN 1 ELSE 0 END) AS overridden_moves')
            ->get()
            ->keyBy('driver_user_id');

        // Bus-ticket deductions in the month, grouped by driver.  Only
        // rows explicitly charged to the driver count toward the net
        // pay; voided tickets are the company's loss.
        $busAgg = DriverBusTicket::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereBetween('travel_date', [$from, $to])
            ->chargedToDriver()
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COALESCE(SUM(amount_cents), 0) AS cents_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Advances issued in the month, grouped by driver.  Uses
        // advance_assigned_at (not delivered_at) so an advance issued
        // in July for a trip that only lands in August still counts
        // against July's cash-out.
        // excludingTransferredAdvances drops the receiving side of a
        // vehicle-to-vehicle transfer so the same physical cash-out is
        // only counted against the driver once.
        $advAgg = Job::query()
            ->excludingTransferredAdvances()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->whereNotNull('advance_assigned_at')
            ->whereBetween('advance_assigned_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COALESCE(SUM(advance_total), 0) AS adv_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Petty cash allocated to each driver in the month.  Ops don't
        // run the approval queue in practice, so filtering on APPROVED
        // / REIMBURSED would make this column read R0 for drivers who
        // have real petty cash out against them.  Rejected rows are
        // the only exclusion -- those were refused outright and never
        // represent money that left the till.
        $spendAgg = PettyCashEntry::query()
            ->whereIn('driver_user_id', $driverIds ?: [0])
            ->where('status', '!=', PettyCashEntry::STATUS_REJECTED)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('driver_user_id')
            ->selectRaw('driver_user_id, COALESCE(SUM(amount_cents), 0) AS cents_sum')
            ->get()
            ->keyBy('driver_user_id');

        // Stitch the per-driver rows together.
        $rows = $drivers->map(function (User $d) use ($moveAgg, $advAgg, $spendAgg, $busAgg) {
            $rateCents = $d->driverProfile?->rate_per_movement_cents;
            $rate = $rateCents === null ? null : (float) $rateCents / 100;

            $move = $moveAgg->get($d->id);
            $moves = (int) ($move->moves ?? 0);
            $cost  = (float) ($move->cost_sum ?? 0);

            // Earnings = sum of per-trip overrides (actual values) +
            // profile rate for the remaining non-overridden moves.
            // Rows without a rate AND without any overrides show null
            // so the "set a rate" warning still fires.
            $overrideSum   = (float) ($move->override_sum ?? 0);
            $overriddenN   = (int)   ($move->overridden_moves ?? 0);
            $remainingN    = max(0, $moves - $overriddenN);
            $rateForRest   = $rate !== null ? $rate * $remainingN : 0.0;
            if ($rate === null && $overrideSum === 0.0 && $moves > 0) {
                $earnings = null; // no rate set + no overrides -> flag it
            } else {
                $earnings = $overrideSum + $rateForRest;
            }

            $advances = (float) ($advAgg->get($d->id)->adv_sum ?? 0);
            $spend    = (float) ($spendAgg->get($d->id)->cents_sum ?? 0) / 100;
            $busCharged = (float) ($busAgg->get($d->id)->cents_sum ?? 0) / 100;

            return [
                'id'          => $d->id,
                'name'        => $d->name,
                'rate'        => $rate,
                'moves'       => $moves,
                'earnings'    => $earnings,
                'cost'        => $cost,
                'advances'    => $advances,
                'spend'       => $spend,
                'bus_charged' => $busCharged,
                'net_pay'     => $earnings !== null ? max(0.0, $earnings - $busCharged) : null,
            ];
        });

        $totals = [
            'moves'       => (int) $rows->sum('moves'),
            'earnings'    => (float) $rows->sum(fn ($r) => $r['earnings'] ?? 0),
            'cost'        => (float) $rows->sum('cost'),
            'advances'    => (float) $rows->sum('advances'),
            'spend'       => (float) $rows->sum('spend'),
            'bus_charged' => (float) $rows->sum('bus_charged'),
            'net_pay'     => (float) $rows->sum(fn ($r) => $r['net_pay'] ?? 0),
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
        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-4 sm:grid-cols-6">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Completed movements</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums">{{ $totals['moves'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Gross earnings</p>
                <p class="mt-1 text-lg font-bold text-emerald-900 tabular-nums">R {{ number_format($totals['earnings'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-emerald-700">rate + per-trip overrides</p>
            </div>
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-rose-700">Bus deductions</p>
                <p class="mt-1 text-lg font-bold text-rose-900 tabular-nums">R {{ number_format($totals['bus_charged'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-rose-700">tickets charged to driver</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Net pay</p>
                <p class="mt-1 text-lg font-bold text-blue-900 tabular-nums">R {{ number_format($totals['net_pay'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-blue-700">earnings &minus; bus</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-white p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Advances issued</p>
                <p class="mt-1 text-lg font-bold text-blue-900 tabular-nums">R {{ number_format($totals['advances'], 2) }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800">Petty cash</p>
                <p class="mt-1 text-lg font-bold text-amber-900 tabular-nums">R {{ number_format($totals['spend'], 2) }}</p>
                <p class="mt-0.5 text-[10px] text-amber-700">allocated this month</p>
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
                        <th class="px-3 py-2 text-right">Bus charged</th>
                        <th class="px-3 py-2 text-right">Net pay</th>
                        <th class="px-3 py-2 text-right">Advances</th>
                        <th class="px-3 py-2 text-right">Petty cash</th>
                        <th class="px-3 py-2 text-center">Payslip</th>
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
                                    <span class="text-slate-400" title="Set a rate (or per-trip override) to compute earnings">—</span>
                                @else
                                    <span class="font-semibold text-emerald-700">R {{ number_format($row['earnings'], 2) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                @if($row['bus_charged'] > 0)
                                    <span class="text-rose-700">R {{ number_format($row['bus_charged'], 2) }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                @if($row['net_pay'] === null)
                                    <span class="text-slate-400">—</span>
                                @else
                                    <span class="font-semibold text-blue-700">R {{ number_format($row['net_pay'], 2) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">R {{ number_format($row['advances'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">R {{ number_format($row['spend'], 2) }}</td>
                            <td class="px-3 py-2 text-center">
                                <div class="flex flex-col gap-0.5">
                                    <a href="{{ route('admin.drivers.payslip', ['user' => $row['id'], 'month' => $month]) }}"
                                        class="text-[11px] font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                        View payslip
                                    </a>
                                    <a href="{{ route('admin.drivers.cash-audit', ['user' => $row['id']]) }}"
                                        class="text-[10px] font-medium text-rose-700 hover:text-rose-900 hover:underline">
                                        Cash audit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-10 text-center text-sm text-slate-500">
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
                            <td class="px-3 py-2 text-right tabular-nums text-rose-700">R {{ number_format($totals['bus_charged'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-blue-700">R {{ number_format($totals['net_pay'], 2) }}</td>
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
                in delivered / completed / invoiced status.  <strong>Earnings</strong> = sum of per-trip pay (manual override on
                the payslip page, else the driver's profile rate).  <strong>Bus charged</strong> = bus tickets this month marked
                "charged to driver".  <strong>Net pay</strong> = earnings &minus; bus deductions.  <strong>Advances</strong> track
                cash issued in the same window; <strong>Petty cash</strong> is the full amount allocated to the driver this
                month (every slip except flat-out rejections).  Click <em>View payslip</em> for the full per-trip breakdown.
            </p>
        </div>
    </div>
</div>
