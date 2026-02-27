<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::logAudit('created', $model, null, $model->toArray());
        });

        static::updated(function ($model) {
            $before = $model->getOriginal();
            $after = $model->getChanges();
            if (!empty($after)) {
                unset($after['updated_at']);
                $filteredBefore = array_intersect_key($before, $after);
                static::logAudit('updated', $model, $filteredBefore, $after);
            }
        });

        static::deleted(function ($model) {
            static::logAudit('deleted', $model, $model->toArray(), null);
        });
    }

    protected static function logAudit(string $action, $model, ?array $before, ?array $after): void
    {
        $user = Auth::user();

        AuditLog::create([
            'actor_user_id' => $user?->id,
            'actor_roles_snapshot' => $user ? implode(',', $user->getRoleNames()) : null,
            'action_type' => $action,
            'entity_type' => $model->getMorphClass(),
            'entity_id' => $model->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    public function auditCustom(string $action, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $user = Auth::user();

        AuditLog::create([
            'actor_user_id' => $user?->id,
            'actor_roles_snapshot' => $user ? implode(',', $user->getRoleNames()) : null,
            'action_type' => $action,
            'entity_type' => $this->getMorphClass(),
            'entity_id' => $this->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
