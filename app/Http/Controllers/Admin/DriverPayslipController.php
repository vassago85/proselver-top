<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DriverPayslipService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Streams a driver's monthly payslip PDF.
 *
 * The on-screen version lives in resources/views/pages/admin/drivers/
 * payslip.blade.php; this controller is the "take it off screen" path
 * that accounts clicks when the driver asks for a copy.  Same gate:
 * accounts / owner / developer only.
 */
class DriverPayslipController extends Controller
{
    public function download(Request $request, User $user, DriverPayslipService $service)
    {
        $actor = $request->user();
        if (!$actor || (!$actor->isAccounts() && !$actor->isOwner() && !$actor->isDeveloperNoOverride())) {
            abort(403, 'Payslips are restricted to accounts.');
        }

        if (!$user->isDriver()) {
            abort(404, 'Not a driver.');
        }

        $monthParam = (string) $request->query('month', '');
        if ($monthParam === '' || !preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            // Mirror the Volt page default so the two surfaces agree
            // when accounts hits "Download PDF" without a month query.
            $anchor = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        } else {
            try {
                $anchor = Carbon::createFromFormat('!Y-m', $monthParam);
            } catch (\Throwable $e) {
                $anchor = Carbon::now()->subMonthNoOverflow()->startOfMonth();
            }
        }

        $pdf = $service->generate($user, $anchor);

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $user->name);
        $slug = trim(strtolower((string) $slug), '-') ?: 'driver';
        $filename = sprintf('payslip-%s-%s.pdf', $slug, $anchor->format('Y-m'));

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
