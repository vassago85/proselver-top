<?php

namespace App\Models;

use App\Traits\HasRoles;
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
}
