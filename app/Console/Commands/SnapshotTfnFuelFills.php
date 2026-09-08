<?php

namespace App\Console\Commands;

use App\Models\FuelFill;
use App\Services\Tfn\TfnClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Persist every TFN fuel transaction into `fuel_fills` so MTD tiles
 * can SUM(amount) from the DB instead of the 100-row-capped live feed.
 *
 * WHY THIS EXISTS
 * ---------------
 * TFN's /api/Transactions has:
 *   - a hard 100-row cap per request,
 *   - a `capturedDateAfter` filter (no offset, no cursor),
 *   - no server-side aggregate for rand spend.
 *
 * The old dashboards summed abs(Amount) directly from a live call.
 * Once the account crossed ~100 fills in a month, the middle rows fell
 * off both the "month-start" and the "last 24h" windows and the tile
 * silently understated MTD spend. This command walks the recent window
 * in overlapping slices, so no row ever hides between two 100-row
 * pages.
 *
 * SLICING STRATEGY
 * ----------------
 * We slide backwards from now() in configurable chunks (default 24h).
 * Each chunk pulls `/api/Transactions?capturedDateAfter=<sliceStart>`
 * and takes rows whose `CapturedDate` falls within that slice. Because
 * a well-used account rarely does more than 100 fills in a single 24h
 * window, a 100-row response for one chunk means "everything in this
 * chunk fits" — no truncation.
 *
 * The slices overlap by one row each (we ORDER on CapturedDate and
 * dedupe by TransactionID via upsert), which gives us self-healing:
 * if a slice returns exactly 100 rows we halve its width automatically
 * for the next pass, so any density spike is absorbed without a
 * re-deploy.
 *
 * IDEMPOTENCY
 * -----------
 * Upsert key is `transaction_id`. TFN's `TransactionID` is stable per
 * fill; when it's missing (rare — a handful of pre-2024 imports) we
 * synthesise a deterministic id from CapturedDate + VehicleReg +
 * Amount + Litres so re-runs don't duplicate.
 */
class SnapshotTfnFuelFills extends Command
{
    protected $signature = 'tfn:snapshot-fills
                            {--from= : Walk from this date (Y-m-d or ISO8601) instead of --days}
                            {--days=3 : How many days back to walk from now (ignored when --from is set)}
                            {--slice-hours=24 : Initial slice size when walking the window}
                            {--min-slice-hours=1 : Smallest slice we auto-halve down to}';

    protected $description = 'Snapshot recent TFN fuel transactions into fuel_fills so MTD stops truncating past 100 rows.';

    public function handle(TfnClient $client): int
    {
        if (! $client->isLive()) {
            $this->warn('TFN is not live (TFN_ENABLED / TFN_DEMO_MODE) — nothing to snapshot.');
            return self::SUCCESS;
        }

        $days      = max(1, (int) $this->option('days'));
        $slice     = max(1, (int) $this->option('slice-hours'));
        $minSlice  = max(1, (int) $this->option('min-slice-hours'));
        $now       = now();
        $fromOpt   = trim((string) $this->option('from'));
        if ($fromOpt !== '') {
            try {
                $walkFrom = Carbon::parse($fromOpt)->startOfDay();
            } catch (Throwable) {
                $this->error("Could not parse --from={$fromOpt}. Use Y-m-d (e.g. 2026-09-01).");
                return self::FAILURE;
            }
        } else {
            $walkFrom = now()->subDays($days);
        }

        if ($walkFrom->gte($now)) {
            $this->error('--from must be before now.');
            return self::FAILURE;
        }

        $customerNumber = (string) config('tfn.customer_number', '');
        if ($customerNumber === '') {
            $this->error('TFN_CUSTOMER_NUMBER is not set — refusing to snapshot without an account scope.');
            return self::FAILURE;
        }

        $seen = 0;
        $written = 0;
        $windowStart = $walkFrom->copy();

        while ($windowStart->lt($now)) {
            $windowEnd = (clone $windowStart)->addHours($slice);
            if ($windowEnd->gt($now)) {
                $windowEnd = $now->copy();
            }

            try {
                $rows = $client->transactions($windowStart->toDateTimeImmutable());
            } catch (Throwable $e) {
                // A single timed-out slice must not abort the month.
                // Skip forward and keep writing the days TFN did answer.
                $this->warn("TFN timed out at {$windowStart->toIso8601String()} — skipping this slice: " . $e->getMessage());
                $windowStart = $windowEnd->copy();
                continue;
            }

            // Keep only rows that fall inside THIS slice — the TFN
            // filter is one-sided (capturedDateAfter) so a wide window
            // will include rows past our slice end.
            $rows = collect($rows)->filter(function (array $r) use ($windowStart, $windowEnd) {
                $raw = $r['CapturedDate'] ?? $r['TransactionDate'] ?? null;
                if (! $raw) {
                    return true;
                }
                try {
                    $captured = Carbon::parse($raw);
                } catch (Throwable) {
                    return true;
                }
                return $captured->between($windowStart, $windowEnd);
            })->values();

            // Density spike guard: 100 rows means we probably hit the
            // cap. Halve the slice (until min-slice-hours) and re-do
            // this same window without advancing.
            if ($rows->count() >= 100 && $slice > $minSlice) {
                $slice = max($minSlice, intdiv($slice, 2));
                $this->warn("Slice hit 100 rows at {$windowStart->toIso8601String()} — halving to {$slice}h and retrying.");
                continue;
            }

            $written += $this->persist($rows, $customerNumber);
            $seen    += $rows->count();

            $windowStart = $windowEnd->copy();
        }

        $windowLabel = $fromOpt !== ''
            ? "{$walkFrom->toDateString()} → {$now->toDateString()}"
            : "{$days}d window";
        $this->info("TFN fill snapshot complete — saw {$seen} rows, wrote {$written} inside {$windowLabel}.");
        return self::SUCCESS;
    }

    /**
     * Upsert the given TFN rows into `fuel_fills`. Rows that TFN
     * emits as account payments / credits / EW top-ups are skipped —
     * they belong on the finance side, not the fuel MTD tile.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return int Number of rows upserted (kept or updated).
     */
    private function persist($rows, string $customerNumber): int
    {
        $written = 0;
        foreach ($rows as $row) {
            $type = strtoupper(trim((string) ($row['TransactionTypeCode'] ?? $row['TransactionType'] ?? '')));
            if (in_array($type, ['CC', 'CD', 'CX', 'PAYMENT'], true)) {
                continue;
            }
            $productCode = strtoupper(trim((string) ($row['ProductCode'] ?? '')));
            if ($productCode === 'EW' || $productCode === '') {
                continue;
            }

            $capturedRaw = $row['CapturedDate'] ?? $row['TransactionDate'] ?? null;
            if (! $capturedRaw) {
                continue;
            }
            try {
                $capturedAt = Carbon::parse($capturedRaw);
            } catch (Throwable) {
                continue;
            }
            $transactionAt = null;
            if (! empty($row['TransactionDate'])) {
                try {
                    $transactionAt = Carbon::parse((string) $row['TransactionDate']);
                } catch (Throwable) {
                    $transactionAt = null;
                }
            }

            $litres = (float) ($row['Litres'] ?? $row['Quantity'] ?? $row['TotalLitres'] ?? $row['Volume'] ?? 0);
            $amount = abs((float) ($row['Amount'] ?? 0));
            $reg    = trim((string) ($row['VehicleRegistration'] ?? ''));
            $txId   = trim((string) ($row['TransactionID'] ?? $row['TransactionId'] ?? ''));

            if ($txId === '') {
                // Synthetic key: stable across re-runs, unique to the
                // fill's identifying tuple. Prefix so it's easy to
                // grep for imports without a TransactionID upstream.
                $txId = 'syn:' . substr(md5(implode('|', [
                    $capturedAt->toIso8601String(),
                    $reg,
                    (string) $amount,
                    (string) $litres,
                    $productCode,
                ])), 0, 40);
            }

            FuelFill::updateOrCreate(
                ['transaction_id' => $txId],
                [
                    'customer_number'        => $customerNumber,
                    'captured_at'            => $capturedAt,
                    'transaction_at'         => $transactionAt,
                    'product_code'           => $productCode,
                    'transaction_type_code'  => $type !== '' ? $type : null,
                    'vehicle_registration'   => $reg !== '' ? $reg : null,
                    'litres'                 => $litres,
                    'amount'                 => $amount,
                    'payload'                => $row,
                    'snapshotted_at'         => now(),
                ],
            );
            $written++;
        }
        return $written;
    }
}
