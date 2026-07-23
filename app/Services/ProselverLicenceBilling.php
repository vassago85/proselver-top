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
 * calendar month (by delivered_at), then applies editable rates from
 * SystemSetting. Other SaaS customers will use different structures —
 * this service is intentionally ProSelver-only.
 */
class ProselverLicenceBilling
{
    public const SETTING_BASE = 'proselver_licence_base_fee';
    public const SETTING_PER_MOVE = 'proselver_licence_per_move';

    public const DEFAULT_BASE = 3500.0;
    public const DEFAULT_PER_MOVE = 50.0;

    public function baseFee(): float
    {
        return (float) SystemSetting::get(self::SETTING_BASE, self::DEFAULT_BASE);
    }

    public function perMoveFee(): float
    {
        return (float) SystemSetting::get(self::SETTING_PER_MOVE, self::DEFAULT_PER_MOVE);
    }

    public function saveRates(float $base, float $perMove): void
    {
        SystemSetting::set(
            self::SETTING_BASE,
            $base,
            'float',
            'ProSelver platform licence — monthly hosting & maintenance (ZAR)',
        );
        SystemSetting::set(
            self::SETTING_PER_MOVE,
            $perMove,
            'float',
            'ProSelver platform licence — fee per completed ProSelver-executed move (ZAR)',
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
     *   base: float,
     *   per_move: float,
     *   moves_subtotal: float,
     *   total: float,
     *   jobs: Collection
     * }
     */
    public function summarise(Carbon $month): array
    {
        $jobs = $this->billableJobs($month);
        $base = $this->baseFee();
        $perMove = $this->perMoveFee();
        $count = $jobs->count();
        $movesSubtotal = $count * $perMove;
        $total = $base + $movesSubtotal;

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'count' => $count,
            'base' => $base,
            'per_move' => $perMove,
            'moves_subtotal' => $movesSubtotal,
            'total' => $total,
            'jobs' => $jobs,
        ];
    }

    /**
     * Plain-text block ready to paste into Invoice Ninja line items / notes.
     * No VAT — supplier is not VAT-registered.
     */
    public function invoiceNinjaText(array $summary): string
    {
        $lines = [
            'ProSelver platform licence — ' . $summary['label'],
            '',
            sprintf('Hosting & maintenance: R%s', number_format($summary['base'], 2, '.', ',')),
            sprintf(
                'Completed ProSelver movements: %d × R%s = R%s',
                $summary['count'],
                number_format($summary['per_move'], 2, '.', ','),
                number_format($summary['moves_subtotal'], 2, '.', ','),
            ),
            '',
            sprintf('Total: R%s', number_format($summary['total'], 2, '.', ',')),
            '(No VAT — not VAT registered)',
            '',
            'Billable = executor ProSelver, status delivered/completed, by delivered_at.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Recent calendar months (newest first) with billable counts only.
     *
     * @return list<array{month: string, label: string, count: int, total: float}>
     */
    public function recentMonths(int $howMany = 6): array
    {
        $base = $this->baseFee();
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

            $out[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'count' => $count,
                'total' => $base + ($count * $perMove),
            ];
        }

        return $out;
    }
}
