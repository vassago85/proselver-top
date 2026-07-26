<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'actor_roles_snapshot',
        'action_type',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'reason',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            // Stamp only when the caller hasn't supplied a time. Overwriting
            // unconditionally made it impossible to backfill or import history
            // (created_at is fillable, so passing it was clearly meant to
            // work), and left the date filtering on the audit-log page
            // untestable. Immutability is still enforced by the hooks below --
            // the time can be set once, at insert, and never changed after.
            $log->created_at ??= now();
        });

        // Prevent updates and deletes - audit logs are immutable
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
