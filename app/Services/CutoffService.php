<?php

namespace App\Services;

use App\Models\Job;
use App\Models\SystemSetting;
use Carbon\Carbon;

class CutoffService
{
    public static function deadline(Carbon $collectionAt): Carbon
    {
        $mode = SystemSetting::get('collection_cutoff_mode', 'hours_before');

        if ($mode === 'day_before_at_time') {
            $days = (int) SystemSetting::get('collection_cutoff_days', 1);
            $time = SystemSetting::get('collection_cutoff_time', '15:00');
            $tz = SystemSetting::get('timezone', 'Africa/Johannesburg');

            return $collectionAt->copy()
                ->subDays($days)
                ->setTimeFromTimeString($time)
                ->shiftTimezone($tz);
        }

        $hours = (int) SystemSetting::get('collection_cutoff_hours', 24);
        return $collectionAt->copy()->subHours($hours);
    }

    public static function isPastCutoff(Job $job): bool
    {
        $collectionAt = self::collectionDateTime($job);
        if (!$collectionAt) {
            return false;
        }

        $deadline = self::deadline($collectionAt);
        return now()->isAfter($deadline);
    }

    public static function collectionDateTime(Job $job): ?Carbon
    {
        if (!$job->scheduled_date) {
            return null;
        }

        $date = $job->scheduled_date instanceof Carbon
            ? $job->scheduled_date->copy()
            : Carbon::parse($job->scheduled_date);

        if ($job->scheduled_ready_time) {
            $time = $job->scheduled_ready_time instanceof Carbon
                ? $job->scheduled_ready_time
                : Carbon::parse($job->scheduled_ready_time);
            $date->setTimeFrom($time);
        }

        return $date;
    }
}
