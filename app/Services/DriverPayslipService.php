<?php

namespace App\Services;

use App\Models\DriverBusTicket;
use App\Models\Job;
use App\Models\PettyCashEntry;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Builds the single-driver, single-month payslip PDF.
 *
 * Mirrors the data layout of /admin/drivers/{user}/payslip so what
 * the driver reads matches what accounts signed off on screen.  The
 * numbers come from the same source of truth:
 *
 *   - Movements  = jobs assigned to this driver, delivered in the
 *                  window, in [DELIVERED, COMPLETED, INVOICED].
 *   - Pay / move = per-trip override (if non-null) OR
 *                  driver_profile.rate_per_movement_cents.
 *   - Bus       = DriverBusTicket rows with travel_date in the
 *                  window; charged_to_driver outcomes are deducted,
 *                  voided ones listed as "no impact".
 *   - Advance   = SUM(transport_jobs.advance_total) across this
 *                  month's movements -- the actual petty cash that
 *                  left the till for the driver.  Shown per-trip in
 *                  the movements table and summed in the totals strip.
 *   - Slips      = PettyCashEntry rows in the window -- reference
 *                  only, the reconciliation paperwork for the
 *                  advance above.
 *   - Cancelled = reference section; included so the driver can see
 *                  why the gross number may be lower than expected.
 */
class DriverPayslipService
{
    /**
     * Render the payslip PDF for a driver + anchor month.  Returns
     * raw PDF bytes -- the controller is responsible for choosing a
     * disposition and a filename.
     */
    public function generate(User $driver, Carbon $anchor): string
    {
        $driver->loadMissing('driverProfile');

        $from = $anchor->copy()->startOfMonth();
        $to   = $anchor->copy()->endOfMonth();

        $profileRate = $driver->driverProfile?->rate_per_movement_cents !== null
            ? (float) $driver->driverProfile->rate_per_movement_cents / 100
            : null;

        $movements = Job::query()
            ->where('driver_user_id', $driver->id)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
            ->whereBetween('delivered_at', [$from, $to])
            ->with([
                'pickupLocation:id,company_name,city,province',
                'deliveryLocation:id,company_name,city,province',
                'brand:id,name',
            ])
            ->orderBy('delivered_at')
            ->get();

        // Line items: one per movement, with the pay source captured
        // so the PDF can show "default rate" vs "override".
        $lines = $movements->map(function (Job $job) use ($driver, $profileRate) {
            $override = $job->driver_pay_amount !== null;
            $pay      = $override
                ? (float) $job->driver_pay_amount
                : ($profileRate ?? 0.0);
            return [
                'job'         => $job,
                'pay'         => $pay,
                'is_override' => $override,
                'note'        => $job->driver_pay_note,
            ];
        });

        $cancelled = Job::query()
            ->where('driver_user_id', $driver->id)
            ->where('status', Job::STATUS_CANCELLED)
            ->whereBetween('cancelled_at', [$from, $to])
            ->with([
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
                'brand:id,name',
                'issuedCancellationClearedBy:id,name',
                'advanceTransferredToJob:id,job_number',
            ])
            ->orderBy('cancelled_at')
            ->get();

        $pettyCash = PettyCashEntry::query()
            ->forDriver($driver)
            ->whereBetween('created_at', [$from, $to])
            ->with(['job:id,job_number'])
            ->orderByDesc('spent_at')
            ->get();

        $busTickets = DriverBusTicket::query()
            ->forDriver($driver)
            ->inMonth($anchor)
            ->with([
                'job:id,job_number',
                'originLocation:id,company_name,city',
                'destinationLocation:id,company_name,city',
            ])
            ->orderBy('travel_date')
            ->get();

        $grossEarnings = (float) $lines->sum('pay');
        $busDeductions = (float) $busTickets
            ->where('status', DriverBusTicket::STATUS_NOT_USED)
            ->where('not_used_outcome', DriverBusTicket::OUTCOME_CHARGED_TO_DRIVER)
            ->sum(fn (DriverBusTicket $t) => $t->amountRand());

        // Advances issued against this driver's movements this month.
        // This is the ACTUAL petty cash that left the till for the
        // driver -- recorded on each transport_jobs row when ops
        // assigns the advance (advance_total column, decimal rand).
        // Distinct from the slips below, which are after-the-fact
        // reconciliation paperwork for how the advance was spent.
        $advancesIssued = (float) $movements->sum(fn (Job $j) => (float) ($j->advance_total ?? 0));

        // Petty cash on cancelled trips (where the trip never ran).
        // Must be reconciled another way -- refunded, transferred to a
        // replacement vehicle, or absorbed with a written reason.
        // "Open" subset = cancelled rows with an advance where
        // issued_cancellation_cleared_at is still NULL.
        $cancelledAdvanceTotal = (float) $cancelled->sum(fn (Job $j) => (float) ($j->advance_total ?? 0));
        $cancelledAdvanceOpen  = (float) $cancelled
            ->filter(fn (Job $j) =>
                (float) ($j->advance_total ?? 0) > 0
                && is_null($j->issued_cancellation_cleared_at)
            )
            ->sum(fn (Job $j) => (float) $j->advance_total);

        // Petty cash slips the driver submitted in the window.
        // Rejected rows excluded (refused outright, no cash moved).
        // Everything else is counted -- the approval queue isn't driven
        // in practice so filtering on APPROVED/REIMBURSED would make
        // the PDF under-report the driver's reconciliation activity.
        $slipsSubmitted = (float) $pettyCash
            ->where('status', '!=', PettyCashEntry::STATUS_REJECTED)
            ->sum(fn (PettyCashEntry $e) => $e->amountRand());

        $html = view('pdfs.driver-payslip', [
            'driver'            => $driver,
            'anchor'            => $anchor,
            'from'              => $from,
            'to'                => $to,
            'profileRate'       => $profileRate,
            'lines'             => $lines,
            'cancelled'         => $cancelled,
            'pettyCash'         => $pettyCash,
            'busTickets'        => $busTickets,
            'grossEarnings'     => $grossEarnings,
            'busDeductions'     => $busDeductions,
            'netPay'            => $grossEarnings - $busDeductions,
            'advancesIssued'    => $advancesIssued,
            'slipsSubmitted'    => $slipsSubmitted,
            'cancelledAdvanceTotal' => $cancelledAdvanceTotal,
            'cancelledAdvanceOpen'  => $cancelledAdvanceOpen,
            'generatedAt'       => now(),
        ])->render();

        $options = new Options();
        // No remote fetches -- the template embeds the one brand mark
        // directly as a data URI, consistent with the other PDFs.
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
