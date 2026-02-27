<?php

namespace App\Services;

use App\Models\Cancellation;
use App\Models\Job;
use App\Models\SystemSetting;
use Carbon\Carbon;

class PenaltyService
{
    public function calculatePenalty(Job $job): array
    {
        $cutoffTime = SystemSetting::get('cancellation_cutoff_time', '16:00');
        $workingDays = SystemSetting::get('working_days', json_encode([1, 2, 3, 4, 5]));
        if (is_string($workingDays)) {
            $workingDays = json_decode($workingDays, true);
        }

        $scheduledDate = Carbon::parse($job->scheduled_date);
        $now = now();

        $previousWorkingDay = $this->getPreviousWorkingDay($scheduledDate, $workingDays);
        $cutoffDateTime = $previousWorkingDay->copy()->setTimeFromTimeString($cutoffTime);

        $isLate = $now->isAfter($cutoffDateTime);

        $penaltyAmount = 0;
        if ($isLate) {
            $penaltyAmount = $job->cost_driver ?? 0;
        }

        return [
            'is_late' => $isLate,
            'penalty_amount' => $penaltyAmount,
            'cutoff_datetime' => $cutoffDateTime,
        ];
    }

    public function applyCancellation(Job $job, int $userId, string $reason, bool $overridePenalty = false, ?string $overrideReason = null, ?int $overriddenBy = null): Cancellation
    {
        $penalty = $this->calculatePenalty($job);

        $cancellation = Cancellation::create([
            'job_id' => $job->id,
            'cancelled_by_user_id' => $userId,
            'reason' => $reason,
            'penalty_amount' => $overridePenalty ? 0 : $penalty['penalty_amount'],
            'penalty_overridden' => $overridePenalty,
            'override_reason' => $overrideReason,
            'overridden_by_user_id' => $overriddenBy,
            'is_late' => $penalty['is_late'],
        ]);

        if (!$overridePenalty && $penalty['penalty_amount'] > 0) {
            $job->penalty_amount = $penalty['penalty_amount'];
            $job->calculateFinancials();
        }

        $job->status = Job::STATUS_CANCELLED;
        $job->cancelled_at = now();
        $job->cancellation_reason = $reason;
        $job->save();

        return $cancellation;
    }

    protected function getPreviousWorkingDay(Carbon $date, array $workingDays): Carbon
    {
        $check = $date->copy()->subDay();
        while (!in_array($check->dayOfWeekIso, $workingDays)) {
            $check->subDay();
        }

        return $check;
    }
}
