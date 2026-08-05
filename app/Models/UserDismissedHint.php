<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single "user X has permanently dismissed in-app hint Y" row.
 *
 * Written to by User::dismissHint(), read by User::hasDismissedHint().
 * The hint_key is a plain namespaced string so retiring a hint is a
 * code-only change; see the migration for the design note.
 *
 * @property int    $user_id
 * @property string $hint_key
 * @property \Illuminate\Support\Carbon $dismissed_at
 */
class UserDismissedHint extends Model
{
    protected $fillable = [
        'user_id',
        'hint_key',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
