<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleClass extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'is_active', 'toll_class', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'toll_class' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Canonical display order: lowest `sort_order` first (commercial
     * classes are pinned to the top — see the seeder + the
     * 2026_05_18_000020 migration), then alphabetic by name as a
     * deterministic tie-breaker so two classes sharing a sort_order
     * never reshuffle between requests.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
