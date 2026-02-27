<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PerformanceService
{
    public function calculateScore(int $delayMinutes): int
    {
        if ($delayMinutes <= 60) {
            return 100;
        }
        if ($delayMinutes <= 180) {
            return 50;
        }

        return 0;
    }

    public function getMonthlyAccuracy(int $companyId, int $year, int $month): array
    {
        $jobs = Job::where('company_id', $companyId)
            ->where('job_type', Job::TYPE_TRANSPORT)
            ->where('is_emergency', false)
            ->whereNotIn('status', [Job::STATUS_CANCELLED])
            ->whereYear('scheduled_date', $year)
            ->whereMonth('scheduled_date', $month)
            ->whereNotNull('delay_minutes')
            ->where('delay_reason_type', 'client')
            ->get();

        if ($jobs->isEmpty()) {
            return [
                'eligible_jobs' => 0,
                'total_score' => 0,
                'accuracy_percent' => 100.0,
                'total_waiting_hours' => 0,
                'breakdown' => ['on_time' => 0, 'moderate' => 0, 'excessive' => 0],
            ];
        }

        $totalScore = 0;
        $breakdown = ['on_time' => 0, 'moderate' => 0, 'excessive' => 0];
        $totalWaitingMinutes = 0;

        foreach ($jobs as $job) {
            $score = $this->calculateScore($job->delay_minutes);
            $totalScore += $score;
            $totalWaitingMinutes += max(0, $job->delay_minutes);

            if ($job->delay_minutes <= 60) {
                $breakdown['on_time']++;
            } elseif ($job->delay_minutes <= 180) {
                $breakdown['moderate']++;
            } else {
                $breakdown['excessive']++;
            }
        }

        $accuracy = $jobs->count() > 0 ? round($totalScore / $jobs->count(), 2) : 100;

        return [
            'eligible_jobs' => $jobs->count(),
            'total_score' => $totalScore,
            'accuracy_percent' => $accuracy,
            'total_waiting_hours' => round($totalWaitingMinutes / 60, 2),
            'breakdown' => $breakdown,
        ];
    }

    public function isEligibleForCredit(int $companyId, int $year, int $month): array
    {
        $metrics = $this->getMonthlyAccuracy($companyId, $year, $month);
        $minJobs = \App\Models\SystemSetting::get('min_monthly_jobs_for_discount', 10);
        $minAccuracy = \App\Models\SystemSetting::get('min_accuracy_for_credit', 90);
        $creditPercent = \App\Models\SystemSetting::get('performance_credit_percent', 3);

        $eligible = $metrics['accuracy_percent'] >= $minAccuracy
            && $metrics['eligible_jobs'] >= $minJobs;

        $creditAmount = 0;
        if ($eligible) {
            $baseCharges = Job::where('company_id', $companyId)
                ->where('job_type', Job::TYPE_TRANSPORT)
                ->whereYear('scheduled_date', $year)
                ->whereMonth('scheduled_date', $month)
                ->whereNotIn('status', [Job::STATUS_CANCELLED])
                ->sum('base_transport_price');

            $creditAmount = round($baseCharges * ($creditPercent / 100), 2);
        }

        return [
            'eligible' => $eligible,
            'accuracy_percent' => $metrics['accuracy_percent'],
            'eligible_jobs' => $metrics['eligible_jobs'],
            'credit_amount' => $creditAmount,
            'credit_percent' => $creditPercent,
        ];
    }
}
