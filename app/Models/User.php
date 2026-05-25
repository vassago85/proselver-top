<?php

namespace App\Models;

use App\Traits\HasRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'username',
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'must_change_password',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot('location_id')
            ->withTimestamps();
    }

    public function company(): ?Company
    {
        return $this->companies()->first();
    }

    /**
     * "Does this user's primary company act as an OEM tenant?"
     *
     * Used to gate the dealer-only executor / drivers / trips
     * surfaces.  OEM-tenant customers (FAW South Africa, Isuzu South
     * Africa, …) book through ProSelver only — they don't run their
     * own driver pool or contract third-party couriers from this
     * portal, so we hide those choices for them.  The underlying
     * feature code stays in place (we may resell it to OEMs as a
     * third-party / direct option later); only the UI + policy gates
     * change.
     */
    public function companyIsOem(): bool
    {
        return (bool) $this->company()?->isOem();
    }

    /**
     * "Does this user's primary company act as a body-builder tenant?"
     *
     * Body builders are independent companies (type=body_builder) that
     * dealers authorise via the body_builder_dealer_links table.  Used
     * by the sidebar to swap to the BB-only branch, by middleware to
     * gate the /body-builder/* routes, and by policies to scope
     * job-visibility queries to "delivery_location_id ∈ this company's
     * locations AND source dealer is linked".
     */
    public function companyIsBodyBuilder(): bool
    {
        return $this->company()?->type === Company::TYPE_BODY_BUILDER;
    }

    /**
     * True when the user is linked to the platform-owner company. Used by the
     * visibility scopes on Job / Inventory so platform-owner users can see
     * everything, regardless of which other companies they may also belong to.
     */
    public function belongsToPlatformOwner(): bool
    {
        return $this->companies()->where('is_platform_owner', true)->exists();
    }

    /**
     * IDs of every company this user belongs to. Used by opt-in scopes that
     * filter by "companies I'm a member of" (booking customer OR executor).
     *
     * @return array<int, int>
     */
    public function operatingCompanyIds(): array
    {
        return $this->companies()->pluck('companies.id')->all();
    }

    /**
     * IDs of every sibling company in the same dealer group(s) as the
     * companies this user is a direct member of. Excludes the user's
     * own companies (operatingCompanyIds() already covers those) — the
     * caller is expected to merge the two lists when the goal is "any
     * company I or my group can see".
     *
     * Returns an empty array when the user belongs to no grouped
     * companies, so platform-wide queries that union this list with
     * operatingCompanyIds() degrade gracefully.
     *
     * @return array<int, int>
     */
    public function groupSiblingCompanyIds(): array
    {
        $groupIds = $this->companies()
            ->whereNotNull('companies.company_group_id')
            ->pluck('companies.company_group_id')
            ->unique()
            ->all();

        if (empty($groupIds)) {
            return [];
        }

        $myCompanyIds = $this->operatingCompanyIds();

        return \App\Models\Company::whereIn('company_group_id', $groupIds)
            ->whereNotIn('id', $myCompanyIds)
            ->pluck('id')
            ->all();
    }

    public function assignedLocation(): ?Location
    {
        $company = $this->companies()->first();
        if (!$company) {
            return null;
        }

        $locationId = $company->pivot->location_id;

        return $locationId ? Location::find($locationId) : null;
    }

    public function assignedLocationId(): ?int
    {
        $company = $this->companies()->first();

        return $company?->pivot?->location_id;
    }

    /**
     * A user is "location-restricted" when their pivot row pins them to a
     * specific branch/depot AND their role is not an account-wide one
     * (customer_owner / customer_admin always see / act across all locations
     * for that account). Internal / developer roles are never restricted.
     *
     * Used to scope both visibility (order lists, dashboards) and privileged
     * actions (confirming FAW-style external orders).
     */
    public function isLocationRestricted(): bool
    {
        if ($this->isInternal() || $this->isDeveloper() || $this->isDriver()) {
            return false;
        }

        if ($this->hasAnyRole(['customer_owner', 'customer_admin'])) {
            return false;
        }

        return $this->assignedLocationId() !== null;
    }

    public function driverProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function assignedJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'driver_user_id');
    }

    public function createdJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'created_by_user_id');
    }

    /* ----------------------------------------------------------------
     | Driver-pool scopes — used by the order-show driver dropdowns
     | (admin + customer) and the new dealer-drivers CRUD page. Two
     | distinct pools intentionally: ProSelver-executed jobs draw from
     | platformDrivers(), internal-executed jobs draw from
     | driversForCompany($dealerCompanyId). Mixing them up is a bug:
     | a dealer should never see ProSelver's drivers in their picker,
     | and ops shouldn't accidentally assign a dealer's driver to a
     | ProSelver movement.
     |-----------------------------------------------------------------*/

    /**
     * Active users with the 'driver' role who belong to the
     * platform-owner company. This is the ProSelver driver pool used
     * for executor_type=proselver jobs.
     */
    public function scopePlatformDrivers(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->whereHas('companies', fn ($q) => $q->where('is_platform_owner', true));
    }

    /**
     * Active users with the 'driver' role attached to a specific
     * company via the company_users pivot. This is the dealer driver
     * pool used for executor_type=internal jobs owned by that dealer.
     *
     * Note: the same User row CAN exist in both pools if a driver is
     * attached to both the platform-owner company and a dealer
     * company — that's not a data error, it just means they can be
     * deployed either way. The dropdowns scope by query, not by row.
     */
    public function scopeDriversForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId));
    }
}
