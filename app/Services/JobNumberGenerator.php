<?php

namespace App\Services;

use App\Models\Job;
use Illuminate\Support\Facades\DB;

class JobNumberGenerator
{
    public function generate(): string
    {
        return DB::transaction(function () {
            $now = now();
            $prefix = $now->format('ym');

            $lastJob = Job::where('job_number', 'like', $prefix . '%')
                ->orderByDesc('job_number')
                ->lockForUpdate()
                ->first();

            if ($lastJob) {
                $lastSeq = (int) substr($lastJob->job_number, -4);
                $nextSeq = $lastSeq + 1;
            } else {
                $nextSeq = 1;
            }

            return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }
}
