<?php

namespace App\Services;

use App\Models\Job;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ProSelver platform licence meter.
 *
 * Counts ProSelver-executed jobs that reached delivered/completed in a
 * calendar month (by delivered_at), then applies an editable per-move
 * rate + 15% VAT. No monthly base fee — the supplier company invoices
 * ProSelver per completed vehicle only.
 */
class ProselverLicenceBilling
{
    public const SETTING_PER_MOVE = 'proselver_licence_per_move';
    /** When false the page and sidebar link stay hidden (pre-agreement). */
    public const SETTING_ENABLED = 'proselver_licence_billing_enabled';

    public const DEFAULT_PER_MOVE = 150.0;
    public const VAT_RATE = 0.15;

    public function isEnabled(): bool
    {
        return (bool) SystemSetting::get(self::SETTING_ENABLED, false);
    }

    public function setEnabled(bool $enabled): void
    {
        SystemSetting::set(
            self::SETTING_ENABLED,
            $enabled,
            'boolean',
            'Show ProSelver platform licence billing (owner/developer). Off until commercial agreement.',
        );
    }

    public function perMoveFee(): float
    {
        return (float) SystemSetting::get(self::SETTING_PER_MOVE, self::DEFAULT_PER_MOVE);
    }

    public function saveRates(float $perMove): void
    {
        SystemSetting::set(
            self::SETTING_PER_MOVE,
            $perMove,
            'float',
            'ProSelver platform licence — fee per completed ProSelver-executed move excl. VAT (ZAR)',
        );
    }

    /**
     * Billable jobs for a calendar month (YYYY-MM).
     */
    public function billableJobs(Carbon $month): Collection
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        return Job::query()
            ->where('executor_type', Job::EXECUTOR_PROSELVER)
            ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->with(['company:id,name', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city'])
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *   month: string,
     *   label: string,
     *   count: int,
     *   per_move: float,
     *   moves_subtotal: float,
     *   total_excl_vat: float,
     *   vat: float,
     *   total_incl_vat: float,
     *   jobs: Collection
     * }
     */
    public function summarise(Carbon $month): array
    {
        $jobs = $this->billableJobs($month);
        $perMove = $this->perMoveFee();
        $count = $jobs->count();
        $excl = $count * $perMove;
        $vat = round($excl * self::VAT_RATE, 2);

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'count' => $count,
            'per_move' => $perMove,
            'moves_subtotal' => $excl,
            'total_excl_vat' => $excl,
            'vat' => $vat,
            'total_incl_vat' => $excl + $vat,
            'jobs' => $jobs,
        ];
    }

    /**
     * Plain-text block ready to paste into any invoicing system.
     */
    public function invoiceCopyText(array $summary): string
    {
        $lines = [
            'ProSelver platform licence — ' . $summary['label'],
            '',
            sprintf(
                'Completed ProSelver movements: %d × R%s (excl. VAT) = R%s',
                $summary['count'],
                number_format($summary['per_move'], 2, '.', ','),
                number_format($summary['total_excl_vat'], 2, '.', ','),
            ),
            '',
            sprintf('Total excl. VAT: R%s', number_format($summary['total_excl_vat'], 2, '.', ',')),
            sprintf('VAT (15%%): R%s', number_format($summary['vat'], 2, '.', ',')),
            sprintf('Total incl. VAT: R%s', number_format($summary['total_incl_vat'], 2, '.', ',')),
            '',
            'Billable = executor ProSelver, status delivered/completed, by delivered_at.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Recent calendar months (newest first) with billable counts only.
     *
     * @return list<array{month: string, label: string, count: int, total_incl_vat: float}>
     */
    public function recentMonths(int $howMany = 6): array
    {
        $perMove = $this->perMoveFee();
        $out = [];

        for ($i = 0; $i < $howMany; $i++) {
            $month = now()->startOfMonth()->subMonths($i);
            $count = Job::query()
                ->where('executor_type', Job::EXECUTOR_PROSELVER)
                ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED])
                ->whereNotNull('delivered_at')
                ->whereBetween('delivered_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->count();

            $excl = $count * $perMove;
            $vat = round($excl * self::VAT_RATE, 2);

            $out[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'count' => $count,
                'total_incl_vat' => $excl + $vat,
            ];
        }

        return $out;
    }
}
