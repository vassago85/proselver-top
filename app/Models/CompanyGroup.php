<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A holding-company / dealer-group umbrella over Company rows.
 *
 * Real-world examples: MCCARTHY, CFAO, Motus.  These don't operate as
 * trading entities themselves — every job, invoice and stock item still
 * sits on a member dealership — but a group-level user (e.g. a CFAO ops
 * manager) needs to see what's happening across all sibling dealerships
 * and individual dealerships can opt to share specific stock items so
 * sister branches can spot a unit they can sell.
 */
class CompanyGroup extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'normalized_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyGroup $group) {
            if (empty($group->uuid)) {
                $group->uuid = (string) Str::uuid();
            }
            $group->normalized_name = Str::lower(Str::ascii($group->name));
        });

        static::updating(function (CompanyGroup $group) {
            if ($group->isDirty('name')) {
                $group->normalized_name = Str::lower(Str::ascii($group->name));
            }
        });
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
