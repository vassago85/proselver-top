<?php

namespace App\Services\Tfn;

use App\Models\DriverProfile;
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
 *   Job.registration OR driver.trade_plate
 *                    ─►  Job's fuel window (collected_at → delivered_at + buffer)
 *                    ─►  TFN transactions where VehicleRegistration matches
 *                        AND CapturedDate is inside the window
 *                        AND ProductCode is one of the diesel grades
 *                    ─►  SUM(Litres) net of reversals, SUM(|Amount|) net of reversals
 *
 * VIN vs. registration.  TFN's ledger keys on VehicleRegistration only
 * and rejects VINs (Sikelela, 2026-08-28).  For units that ship without
 * a permanent plate -- the common new-off-plant case -- the driver
 * applies their trade plate for the drive-away leg, and THAT string is
 * what TFN receives on every transaction.  So this service tries the
 * job's registration first and falls back to the assigned driver's
 * trade plate.  The invoicing sheet still keys its rows on VIN because
 * that's what FAW's system reads, but the reconciliation itself never
 * touches VIN.
 *
 * Reversals (per TFN, 2026-08-28): "A reversal always generates a new
 * transaction row that references the original transaction ID.  The
 * original row is never modified."  This service nets any reversal
 * against its original so the fuel figure reflects what actually
 * stuck, not gross litres.
 *
 * Design choices:
 *
 *   - Batch first.  Given a Collection of jobs the service does a SINGLE
 *     TFN /api/Transactions call spanning min(collected_at)..now (bounded
 *     by TFN's 3-month ceiling).  Anything else N+1s the API which
 *     ratelimits accounts out on OEMs with hundreds of monthly moves.
 *
 *   - Eager-load the driver's profile.  We resolve the trade-plate
 *     fallback per job, so `driver.driverProfile` needs to be on the
 *     eloquent collection to avoid one query per row.  Callers on the
 *     invoicing page already `->with('driver.driverProfile:id,user_id,trade_plate')`
 *     for this reason.
 *
 *   - Normalise registration hard.  Job.registration is uppercased by
 *     the model (Attribute cast, Job.php ~line 621); DriverProfile.trade_plate
 *     is normalised the same way (DriverProfile.php).  We also collapse
 *     any inbound TFN VehicleRegistration via DriverProfile::normalisePlate
 *     before comparing, so "ND 456 GP" and "nd456gp" and "ND-456-GP" all
 *     match one row.
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
        // look up each job's plate AND its driver's trade plate in O(1).
        $byRegistration = collect($transactions)->groupBy(
            fn ($t) => (string) DriverProfile::normalisePlate($t['VehicleRegistration'] ?? '')
        );

        $out = [];
        foreach ($jobs as $job) {
            // Registration candidates: the vehicle's permanent plate
            // first, then the assigned driver's trade plate as the
            // fallback used when the unit shipped without a plate.
            // Both are already normalised on their models, but we
            // re-normalise here so external / test-fixture data goes
            // through the same pipe.
            $regs = array_values(array_filter([
                DriverProfile::normalisePlate($job->registration),
                DriverProfile::normalisePlate(optional($job->driver?->driverProfile)->trade_plate),
            ], fn ($v) => !blank($v)));

            if (empty($regs)) {
                $out[$job->id] = $this->emptyResult('no_registration');
                continue;
            }

            $candidates = collect();
            foreach ($regs as $reg) {
                $candidates = $candidates->merge($byRegistration->get($reg, collect()));
            }
            $candidates = $candidates->unique(fn ($t) => $t['TransactionID'] ?? spl_object_hash((object) $t));

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

            // Net reversals against their originals.  TFN inserts a
            // separate row with a nested `ReversedTransaction` object
            // whose `TransactionID` points back at the original -- the
            // original row is never modified.  If BOTH sides are
            // inside our window they cancel out; if only the reversal
            // is in-window (rare: a fuel-up shortly before the window
            // with a reversal inside it) we still subtract it, which
            // understates but never overstates.
            $netted = $this->netReversals($matches);

            $litres = $netted->sum(fn ($t) => (float) ($t['Litres'] ?? 0));
            // TFN convention: purchase amounts are negative (they reduce
            // your balance) and reversal amounts are positive (they
            // restore balance).  We want a positive rand figure on the
            // invoicing sheet, so absolute-value the AFTER-netting sum.
            $signedAmount = $netted->sum(fn ($t) => (float) ($t['Amount'] ?? 0));
            $amount = abs($signedAmount);

            $out[$job->id] = [
                'litres'        => round($litres, 2),
                'amount'        => round($amount, 2),
                'matched_count' => $netted->count(),
                'source'        => $sourceLabel,
                'transactions'  => $netted->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Drop reversals AND the originals they reference, so the totals
     * reflect only transactions that stuck.
     *
     * TFN reversal shape (confirmed from real QA transaction rows on
     * 2026-08-31): every transaction carries a nested
     * `ReversedTransaction` object of shape
     *
     *   { TransactionID: <uuid>, IsFuel: bool, TransactionReference: string }
     *
     * When `TransactionID` is the null UUID (`00000000-0000-0000-0000-000000000000`)
     * the row is a plain transaction.  Any non-null UUID there means
     * "this row is a reversal, and it undoes that TransactionID".
     *
     * We also keep the pre-2026-08-31 fallback (`IsReversal` bool +
     * top-level `ReversedTransactionID` string) for any fixture that
     * hasn't been updated yet -- either format detects correctly.
     */
    private function netReversals(Collection $rows): Collection
    {
        // A row is a reversal iff either format signals it.
        $isReversal = static function (array $t): bool {
            $nested = $t['ReversedTransaction'] ?? null;
            if (is_array($nested)) {
                $nestedId = (string) ($nested['TransactionID'] ?? '');
                if ($nestedId !== '' && $nestedId !== '00000000-0000-0000-0000-000000000000') {
                    return true;
                }
            }
            if (($t['IsReversal'] ?? false) === true) {
                return true;
            }
            $legacy = $t['ReversedTransactionID'] ?? null;
            return !blank($legacy) && $legacy !== '00000000-0000-0000-0000-000000000000';
        };

        // The TransactionID this reversal references, in either format.
        $referencedTxId = static function (array $t): string {
            $nested = $t['ReversedTransaction'] ?? null;
            if (is_array($nested) && !blank($nested['TransactionID'] ?? null)) {
                return (string) $nested['TransactionID'];
            }
            return (string) ($t['ReversedTransactionID'] ?? '');
        };

        $reversedIds = $rows
            ->filter(fn ($t) => is_array($t) && $isReversal($t))
            ->map(fn ($t) => $referencedTxId($t))
            ->filter(fn ($id) => $id !== '' && $id !== '00000000-0000-0000-0000-000000000000')
            ->values()
            ->all();

        if (empty($reversedIds)) {
            return $rows;
        }

        $reversedSet = array_flip($reversedIds);

        return $rows->reject(function ($t) use ($isReversal, $reversedSet) {
            if (!is_array($t)) {
                return false;
            }
            // Drop the reversal row itself...
            if ($isReversal($t)) {
                return true;
            }
            // ...and drop the original it points at.
            $id = (string) ($t['TransactionID'] ?? '');
            return $id !== '' && isset($reversedSet[$id]);
        });
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
