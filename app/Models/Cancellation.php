<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cancellation extends Model
{
    use Auditable;

    protected $fillable = [
        'job_id',
        'cancelled_by_user_id',
        'reason',
        'penalty_amount',
        'penalty_overridden',
        'override_reason',
        'overridden_by_user_id',
        'is_late',
    ];

    protected function casts(): array
    {
        return [
            'penalty_amount' => 'decimal:2',
            'penalty_overridden' => 'boolean',
            'is_late' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
    }
}
