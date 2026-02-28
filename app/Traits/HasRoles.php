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

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->relationLoaded('permissions')) {
                if ($role->permissions->contains('slug', $permissionSlug)) {
                    return true;
                }
            } else {
                if ($role->permissions()->where('slug', $permissionSlug)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasAnyPermission(array $permissionSlugs): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->permissions()->whereIn('slug', $permissionSlugs)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function getAllPermissionSlugs(): array
    {
        if ($this->isSuperAdmin()) {
            return \App\Models\Permission::pluck('slug')->toArray();
        }

        return $this->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->toArray();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isInternal(): bool
    {
        return $this->roles->where('tier', 'internal')->isNotEmpty();
    }

    public function isDealer(): bool
    {
        return $this->roles->where('tier', 'dealer')->isNotEmpty();
    }

    public function isOem(): bool
    {
        return $this->roles->where('tier', 'oem')->isNotEmpty();
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
        return $this->hasPermission('submit_booking');
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
            'oem_admin' => 65,
            'oem_planner' => 62,
            'dealer_principal' => 60,
            'sales_manager_new' => 55,
            'sales_manager_used' => 55,
            'stock_controller' => 50,
            'sales_person_new' => 40,
            'sales_person_used' => 40,
            'driver' => 20,
        ];

        $highest = null;
        $highestLevel = -1;

        foreach ($this->roles as $role) {
            $level = $hierarchy[$role->slug] ?? 10;
            if ($level > $highestLevel) {
                $highestLevel = $level;
                $highest = $role->slug;
            }
        }

        return $highest;
    }
}
