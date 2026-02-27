<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CreditNote;
use App\Services\PerformanceService;
use Illuminate\Console\Command;

class CalculateMonthlyCredits extends Command
{
    protected $signature = 'performance:calculate-credits {--month= : Month (1-12)} {--year= : Year}';
    protected $description = 'Calculate monthly performance credit notes for eligible companies';

    public function handle(PerformanceService $performanceService): int
    {
        $month = $this->option('month') ?? now()->subMonth()->month;
        $year = $this->option('year') ?? now()->subMonth()->year;

        $this->info("Calculating credits for {$year}-{$month}...");

        $companies = Company::where('is_active', true)->get();
        $generated = 0;

        foreach ($companies as $company) {
            $result = $performanceService->isEligibleForCredit($company->id, $year, $month);

            if ($result['eligible'] && $result['credit_amount'] > 0) {
                $existing = CreditNote::where('company_id', $company->id)
                    ->where('period_month', $month)
                    ->where('period_year', $year)
                    ->exists();

                if (!$existing) {
                    $prefix = 'CR-' . date('ym', mktime(0, 0, 0, $month, 1, $year));
                    $lastCredit = CreditNote::where('credit_number', 'like', $prefix . '%')
                        ->orderByDesc('credit_number')->first();
                    $seq = $lastCredit ? ((int) substr($lastCredit->credit_number, -4)) + 1 : 1;

                    CreditNote::create([
                        'company_id' => $company->id,
                        'credit_number' => $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT),
                        'amount' => $result['credit_amount'],
                        'reason' => "Performance credit ({$result['credit_percent']}%) for {$year}-{$month}. Accuracy: {$result['accuracy_percent']}%, Jobs: {$result['eligible_jobs']}",
                        'period_month' => $month,
                        'period_year' => $year,
                    ]);

                    $this->line("  {$company->name}: R" . number_format($result['credit_amount'], 2));
                    $generated++;
                }
            }
        }

        $this->info("Generated {$generated} credit note(s).");
        return Command::SUCCESS;
    }
}
