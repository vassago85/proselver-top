<?php

namespace App\Services\Tfn;

use App\Models\FuelFill;
use Illuminate\Support\Carbon;

/**
 * Value object describing a month's fuel activity for a single account
 * scope, with a `source` flag telling the caller how reliable the
 * spend number is.
 *
 * WHY A DEDICATED TYPE
 * --------------------
 * Both the Owner dashboard tile and the Fuel Ops KPI strip used to
 * compute MTD spend inline from the TFN transactions feed. That feed
 * silently truncates past 100 rows and has no rand-spend aggregate,
 * so the tile shrank as the month went on. The read now goes through
 * `FuelMtdSummary::forMonth()` which prefers `fuel_fills` (persisted
 * by the scheduled snapshotter) and falls back through progressively
 * less-precise sources — always tagging the source so the UI can hint
 * "est." when the number is not a straight SUM(amount).
 */
final class FuelMtdSummary
{
    public const SOURCE_DB          = 'db';         // SUM(amount) from fuel_fills — authoritative
    public const SOURCE_ESTIMATED   = 'estimated';  // aggregate litres × avg R/L from tx feed
    public const SOURCE_LIVE_TX     = 'live_tx';    // raw abs(Amount) from tx feed (may be truncated)
    public const SOURCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly float $spend,
        public readonly float $litres,
        public readonly int   $fillCount,
        public readonly string $source,
        public readonly ?Carbon $latestFillAt = null,
    ) {
    }

    /**
     * Best-effort MTD summary for the calendar month covered by
     * `$anchor` (any date inside the month), scoped to `$customerNumber`.
     *
     * Resolution order:
     *   1. `fuel_fills` for the month (the scheduled snapshot writes
     *      every fill here — this is the number the tile should show
     *      whenever it exists).
     *   2. If `fuel_fills` is empty for the month but we DO know the
     *      account's aggregate-litres and a live-feed avg R/L, we
     *      estimate spend from those (better than a truncated sum).
     *   3. Straight live-feed sum (kept only so the tile never renders
     *      blank on a fresh install before the snapshotter runs).
     *
     * @param  array<int, array<string, mixed>>  $liveTxRows
     *         Rows from `TfnClient::transactions()` (already deduped),
     *         used to compute a fallback avg R/L when the DB is empty.
     * @param  float  $aggregateLitres
     *         From `TfnClient::subAccountAggregateLitres()`; the
     *         server-side rollup TFN publishes for litres (there is
     *         no equivalent for rand spend).
     */
    public static function forMonth(
        Carbon $anchor,
        string $customerNumber,
        array $liveTxRows = [],
        float $aggregateLitres = 0.0,
    ): self {
        $from = $anchor->copy()->startOfMonth();
        $to   = $anchor->copy()->endOfMonth();

        // 1. Persisted fills — authoritative when present.
        $dbAgg = FuelFill::query()
            ->when($customerNumber !== '', fn ($q) => $q->where('customer_number', $customerNumber))
            ->liquid()
            ->capturedBetween($from, $to)
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(litres), 0) AS litres_sum, COALESCE(SUM(amount), 0) AS amount_sum, MAX(captured_at) AS latest_at')
            ->first();

        $dbCount  = (int) ($dbAgg->row_count ?? 0);
        $dbLitres = (float) ($dbAgg->litres_sum ?? 0);
        $dbSpend  = (float) ($dbAgg->amount_sum ?? 0);
        $latestAt = $dbAgg && $dbAgg->latest_at ? Carbon::parse((string) $dbAgg->latest_at) : null;

        if ($dbCount > 0) {
            return new self(
                spend:        $dbSpend,
                litres:       $dbLitres,
                fillCount:    $dbCount,
                source:       self::SOURCE_DB,
                latestFillAt: $latestAt,
            );
        }

        // 2/3. Live feed — filter to the month + liquid fuel, sum abs(Amount).
        [$txLitres, $txSpend, $txCount] = self::sumLiveTx($liveTxRows, $from, $to);

        // If we have both an aggregate-litres rollup AND a live-feed
        // avg R/L, extrapolate spend from the fuller litres number —
        // this is the "estimated" state that survives the 100-row cap
        // even before the snapshotter has caught up.
        if ($aggregateLitres > $txLitres && $txLitres > 0 && $txSpend > 0) {
            $avgRpl = $txSpend / $txLitres;
            $estimatedSpend = $aggregateLitres * $avgRpl;
            return new self(
                spend:     $estimatedSpend,
                litres:    $aggregateLitres,
                fillCount: $txCount,
                source:    self::SOURCE_ESTIMATED,
            );
        }

        if ($txCount === 0 && $aggregateLitres <= 0) {
            return new self(0.0, 0.0, 0, self::SOURCE_UNAVAILABLE);
        }

        return new self(
            spend:     $txSpend,
            litres:    max($aggregateLitres, $txLitres),
            fillCount: $txCount,
            source:    self::SOURCE_LIVE_TX,
        );
    }

    /**
     * True when the number the tile is about to render came from the
     * DB — i.e. the snapshotter did its job and MTD is safe.
     */
    public function isAuthoritative(): bool
    {
        return $this->source === self::SOURCE_DB;
    }

    /**
     * True when we had to compute rand spend from `litres × avg R/L`
     * because the live feed was truncated / the DB was empty. UI can
     * show a small "est." indicator.
     */
    public function isEstimated(): bool
    {
        return $this->source === self::SOURCE_ESTIMATED;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: float, 1: float, 2: int}  [litres, spend, count]
     */
    private static function sumLiveTx(array $rows, Carbon $from, Carbon $to): array
    {
        $litreProducts = FuelFill::LIQUID_PRODUCT_CODES;

        $litres = 0.0;
        $spend  = 0.0;
        $count  = 0;

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['ProductCode'] ?? '')));
            if (! in_array($code, $litreProducts, true)) {
                continue;
            }
            $type = strtoupper(trim((string) ($row['TransactionTypeCode'] ?? $row['TransactionType'] ?? '')));
            if (in_array($type, ['CC', 'CD', 'CX', 'PAYMENT'], true)) {
                continue;
            }

            $raw = $row['CapturedDate'] ?? $row['TransactionDate'] ?? null;
            if ($raw) {
                try {
                    $captured = Carbon::parse((string) $raw);
                    if ($captured->lt($from) || $captured->gt($to)) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Unparseable — keep the row rather than drop it.
                }
            }

            $rowLitres = (float) (
                $row['Litres'] ?? $row['Quantity'] ?? $row['TotalLitres'] ?? $row['Volume'] ?? 0
            );
            if ($rowLitres <= 0) {
                continue;
            }
            $litres += $rowLitres;
            $spend  += abs((float) ($row['Amount'] ?? 0));
            $count++;
        }

        return [$litres, $spend, $count];
    }
}
