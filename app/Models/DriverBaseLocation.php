<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controlled reference list backing the "Base Location" picker on the
 * driver create / edit forms and the "Base Location" filter on the
 * Driver Operations dashboard.
 *
 * Rows come from three places, layered:
 *   1. The migration seeds the canonical SA cities the platform
 *      operates out of (Johannesburg, Pretoria, ...).
 *   2. The same migration reads every historical value already sitting
 *      in driver_profiles.base_location and upserts anything it
 *      couldn't collapse onto a canonical row so ops isn't surprised
 *      by data disappearing.
 *   3. Admins can add / rename / hide rows via a settings surface
 *      (planned as a follow-up; for now they can insert directly
 *      through the console).
 *
 * Deliberately does NOT link to driver_profiles by FK. The profile
 * column is a plain string so audit logs, exports and search all keep
 * working without joins; new writes are constrained by the picker, and
 * historical drift is fixed by the same migration.
 *
 * @property int    $id
 * @property string $name
 * @property bool   $is_active
 * @property int    $sort_order
 */
class DriverBaseLocation extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Options the pickers show, in the order they should render.
     * Sort by sort_order (admin pinning) then name (alphabetical
     * within the same tier) so the seeded canonical cities sit
     * above ad-hoc entries.
     */
    public static function pickerOptions(): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
