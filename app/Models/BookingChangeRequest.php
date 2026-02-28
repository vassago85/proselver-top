<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BookingChangeRequest extends Model
{
    protected $fillable = [
        'uuid', 'job_id', 'requested_by_user_id', 'request_type',
        'current_value', 'requested_value', 'reason', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'array',
            'requested_value' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
