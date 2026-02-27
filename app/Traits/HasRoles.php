<?php

namespace App\Traits;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    public function hasRole(string $roleSlug): bool
    {
        return $this->roles->contains('slug', $roleSlug);
    }

    public function hasAnyRole(array $roleSlugs): bool
    {
        return $this->roles->whereIn('slug', $roleSlugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isInternal(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager', 'dispatcher', 'accounts']);
    }

    public function isDealer(): bool
    {
        return $this->hasAnyRole(['dealer_admin', 'dealer_scheduler', 'dealer_accounts', 'dealer_viewer']);
    }

    public function isDriver(): bool
    {
        return $this->hasRole('driver');
    }

    public function canManageUsers(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager']);
    }

    public function canManagePricing(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function canAssignDrivers(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager', 'dispatcher']);
    }

    public function canApproveBookings(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager']);
    }

    public function canManageInvoices(): bool
    {
        return $this->hasAnyRole(['super_admin', 'accounts']);
    }

    public function canOverride(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager']);
    }

    public function canViewFinancials(): bool
    {
        return $this->hasAnyRole(['super_admin', 'ops_manager', 'accounts']);
    }

    public function canBookTransport(): bool
    {
        return $this->hasAnyRole(['dealer_admin', 'dealer_scheduler']);
    }

    public function assignRole(string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$role->id]);
    }

    public function removeRole(string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();
        if ($role) {
            $this->roles()->detach($role->id);
        }
    }

    public function syncRoles(array $roleSlugs): void
    {
        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id');
        $this->roles()->sync($roleIds);
    }

    public function getRoleNames(): array
    {
        return $this->roles->pluck('name')->toArray();
    }

    public function highestRole(): ?string
    {
        $hierarchy = [
            'super_admin' => 100,
            'ops_manager' => 90,
            'dispatcher' => 80,
            'accounts' => 70,
            'dealer_admin' => 60,
            'dealer_scheduler' => 50,
            'dealer_accounts' => 40,
            'dealer_viewer' => 30,
            'driver' => 20,
        ];

        $highest = null;
        $highestLevel = -1;

        foreach ($this->roles as $role) {
            $level = $hierarchy[$role->slug] ?? 0;
            if ($level > $highestLevel) {
                $highestLevel = $level;
                $highest = $role->slug;
            }
        }

        return $highest;
    }
}
