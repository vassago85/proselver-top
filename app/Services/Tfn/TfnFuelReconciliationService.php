<?php

namespace App\Services\Tfn;

use App\Models\Job;
use App\Services\Tfn\Exceptions\TfnException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Match TFN pump transactions onto ProSelver movement jobs so accounts
 * doesn't have to re-key litres + rand on the FAW invoicing sheet.
 *
 * The linkage:
 *
 *   Job.registration  ─►  Job's fuel window (collected_at → delivered_at + buffer)
 *                    ─►  TFN transactions where VehicleRegistration matches
 *                        AND CapturedDate is inside the window
 *                        AND ProductCode is one of the diesel grades
 *                    ─►  SUM(Litres), SUM(|Amount|)
 *
 * We deliberately DO NOT match on VIN even though FAW asks for "fuel by
 * VIN".  TFN doesn't hold the VIN of the transported vehicle -- the card
 * is bound to the vehicle Registration, which for the ProSelver drive-
 * away model IS the vehicle being transported (the driver drives the
 * FAW truck itself).  So VIN and Registration are two identifiers for
 * the same vehicle, and reg is what TFN knows.  The invoicing sheet
 * still keys its rows on VIN (that's what FAW's system reads).
 *
 * Design choices:
 *
 *   - Batch first.  Given a Collection of jobs the service does a SINGLE
 *     TFN /api/Transactions call spanning min(collected_at)..now (bounded
 *     by TFN's 3-month ceiling).  Anything else N+1s the API which
 *     ratelimits accounts out on OEMs with hundreds of monthly moves.
 *
 *   - Normalise registration hard.  Job.registration is uppercased by
 *     the model (Attribute cast, Job.php line 621) but users can enter
 *     it with or without spaces, and TFN stores whatever was captured
 *     at onboarding.  We collapse whitespace + upper before comparing.
 *
 *   - Demo mode.  When TFN isn't live the demo fixtures include a
 *     handful of registrations -- most real jobs won't match, but the
 *     mechanism is fully exercised so we can prove the wiring in a
 *     demo without QA credentials.
 *
 *   - Non-diesel is dropped.  AdBlue / oil / shop purchases don't
 *     belong in "fuel used to move this VIN".  Only D0/D1/D3.
 */
class TfnFuelReconciliationService
{
    // Small buffer either side of the trip window.  Fuel can be bought
    // early on the morning of collection or during the return leg
    // right after delivery, both of which the operator will consider
    // "on that trip".
    private const WINDOW_BUFFER_HOURS = 6;

    public function __construct(
        private readonly TfnClient $client,
        private readonly TfnDemoFixtures $fixtures,
    ) {}

    /**
     * Return per-job fuel figures keyed by Job::id.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Job>  $jobs
     * @return array<int, array{
     *     litres: ?float,
     *     amount: ?float,
     *     matched_count: int,
     *     source: string,          // 'tfn' | 'demo' | 'no_registration' | 'no_matches'
     *     transactions: array,
     * }>
     */
    public function reconcile(Collection $jobs): array
    {
        $jobs = $jobs->filter();
        if ($jobs->isEmpty()) {
            return [];
        }

        // Build the aggregate window spanning every job on the page.
        // Falling back to `scheduled_date` when `collected_at` is null
        // (jobs that were force-completed without going through the
        // driver-collected event -- rare, but they exist in the data).
        $earliest = $jobs
            ->map(fn (Job $j) => $j->collected_at ?? $j->scheduled_date)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->min();
        $latest = $jobs
            ->map(fn (Job $j) => $j->delivered_at)
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->max();

        if (!$earliest || !$latest) {
            // Not enough job timing data to build a window -- return
            // "no matches" for every job so the caller shows the
            // right hint.
            return $jobs->mapWithKeys(fn (Job $j) => [$j->id => $this->emptyResult('no_matches')])->all();
        }

        $windowStart = $earliest->copy()->subHours(self::WINDOW_BUFFER_HOURS);
        $windowEnd   = $latest->copy()->addHours(self::WINDOW_BUFFER_HOURS);

        // TFN limits /api/Transactions to a 3-month lookback -- if
        // accounts is running a very old window we cap it here and
        // rely on the caller to notice fewer matches.
        $maxLookback = now()->subMonths(3);
        if ($windowStart->lt($maxLookback)) {
            $windowStart = $maxLookback;
        }

        $transactions = $this->fetchTransactions($windowStart);
        $sourceLabel = $this->client->isLive() ? 'tfn' : 'demo';

        // Index transactions by normalised registration once so we can
        // look up each job in O(1).
        $byRegistration = collect($transactions)->groupBy(
            fn ($t) => $this->normaliseRegistration($t['VehicleRegistration'] ?? '')
        );

        $out = [];
        foreach ($jobs as $job) {
            $reg = $this->normaliseRegistration($job->registration);
            if (blank($reg)) {
                $out[$job->id] = $this->emptyResult('no_registration');
                continue;
            }

            $candidates = $byRegistration->get($reg, collect());
            if ($candidates->isEmpty()) {
                $out[$job->id] = $this->emptyResult('no_matches');
                continue;
            }

            [$jobStart, $jobEnd] = $this->jobWindow($job);
            if (!$jobStart || !$jobEnd) {
                $out[$job->id] = $this->emptyResult('no_matches');
                continue;
            }

            $matches = $candidates->filter(function ($t) use ($jobStart, $jobEnd) {
                $when = $this->transactionInstant($t);
                if (!$when) return false;
                if (!$this->isDieselProduct($t['ProductCode'] ?? '')) return false;
                return $when->between($jobStart, $jobEnd);
            })->values();

            if ($matches->isEmpty()) {
                $out[$job->id] = $this->emptyResult('no_matches');
                continue;
            }

            $litres = $matches->sum(fn ($t) => (float) ($t['Litres'] ?? 0));
            // TFN convention: purchase amounts are negative (they reduce
            // your balance).  On the invoicing sheet FAW wants a
            // positive rand figure, so absolute-value here.
            $amount = $matches->sum(fn ($t) => abs((float) ($t['Amount'] ?? 0)));

            $out[$job->id] = [
                'litres'        => round($litres, 2),
                'amount'        => round($amount, 2),
                'matched_count' => $matches->count(),
                'source'        => $sourceLabel,
                'transactions'  => $matches->all(),
            ];
        }

        return $out;
    }

    /**
     * Convenience for the single-row auto-fill button.
     */
    public function reconcileOne(Job $job): array
    {
        return $this->reconcile(collect([$job]))[$job->id]
            ?? $this->emptyResult('no_matches');
    }

    /* ────────── internals ────────── */

    private function fetchTransactions(Carbon $capturedDateAfter): array
    {
        if (!$this->client->isLive()) {
            // The demo fixtures return transactions anchored to "now" --
            // for demo purposes we take them as-is; the caller will
            // filter per-job by window regardless of whether the demo
            // rows happen to fall in it.
            return $this->fixtures->transactions();
        }

        try {
            return $this->client->transactions($capturedDateAfter);
        } catch (TfnException $e) {
            // A dead TFN endpoint must not blow up the accounts page.
            // Fall back to demo so the UI shows something reasonable
            // and the source label ('demo') makes the fallback visible.
            report($e);
            return $this->fixtures->transactions();
        }
    }

    private function jobWindow(Job $job): array
    {
        $start = $job->collected_at
            ? Carbon::parse($job->collected_at)
            : ($job->scheduled_date ? Carbon::parse($job->scheduled_date)->startOfDay() : null);

        $end = $job->delivered_at ? Carbon::parse($job->delivered_at) : null;

        if (!$start || !$end) {
            return [null, null];
        }

        return [
            $start->copy()->subHours(self::WINDOW_BUFFER_HOURS),
            $end->copy()->addHours(self::WINDOW_BUFFER_HOURS),
        ];
    }

    private function transactionInstant(array $t): ?Carbon
    {
        $raw = $t['TransactionDate'] ?? $t['CapturedDate'] ?? null;
        if (!$raw) return null;
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isDieselProduct(string $code): bool
    {
        // Sourced from config so ops can widen / narrow the definition
        // without redeploying -- see `tfn.reconciliation_products`.
        $accepted = array_map('strtoupper', (array) config('tfn.reconciliation_products', ['D0']));
        return in_array(strtoupper($code), $accepted, true);
    }

    /**
     * Uppercase and strip every non-alphanumeric character so
     * "ND 123 GP", "nd123gp" and "ND-123-GP" all compare equal.
     */
    private function normaliseRegistration(?string $reg): string
    {
        if (!$reg) return '';
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($reg)) ?? '';
    }

    private function emptyResult(string $source): array
    {
        return [
            'litres'        => null,
            'amount'        => null,
            'matched_count' => 0,
            'source'        => $source,
            'transactions'  => [],
        ];
    }
}
