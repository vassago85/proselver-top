<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable ledger of every status transition on a transport_jobs row.
 *
 * Written by the Job model's `updating` observer whenever the status
 * changes. Powers dwell-time analytics (Phase 5 CFD + p50/p90 chart)
 * without having to reconstruct history from per-status timestamp
 * columns, which are lossy (the old workflow reset a bunch of them
 * on recall).
 */
class JobStatusEvent extends Model
{
    protected $table = 'job_status_events';

    protected $fillable = [
        'job_id',
        'from_status',
        'to_status',
        'entered_at',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'meta'       => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
