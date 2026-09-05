<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable authentication-event row.  Mirrors the AuditLog immutability
 * guarantees: once inserted, a login_history row can never be updated or
 * deleted through Eloquent — the trail is the trail.
 *
 * The listener that populates this table lives in App\Listeners\LogLoginActivity.
 */
class LoginHistory extends Model
{
    protected $table = 'login_history';

    // Single-column timestamp (created_at only), same as AuditLog.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'identity',
        'event',
        'ip_address',
        'user_agent',
        'session_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoginHistory $row) {
            $row->created_at ??= now();
        });

        // Same immutability guarantees as AuditLog — this is an audit trail,
        // not a working dataset.  Ops staff might want to CORRECT a mistyped
        // identity, but they can do that with a fresh row + a comment, not
        // by mutating history.
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
