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

    // --- Developer role override (session-based for testing) ---

    protected function effectiveRoles()
    {
        $override = session('dev_role_override');
        if ($override && $this->roles->contains('slug', 'developer')) {
            $role = Role::where('slug', $override)->first();
            if ($role) {
                return collect([$role]);
            }
        }
        return $this->roles;
    }

    // --- Core role checks ---

    public function hasRole(string $roleSlug): bool
    {
        return $this->effectiveRoles()->contains('slug', $roleSlug);
    }

    public function hasAnyRole(array $roleSlugs): bool
    {
        return $this->effectiveRoles()->whereIn('slug', $roleSlugs)->isNotEmpty();
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isSuperAdmin() || $this->isDeveloper()) {
            return true;
        }

        foreach ($this->effectiveRoles() as $role) {
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
        if ($this->isSuperAdmin() || $this->isDeveloper()) {
            return true;
        }

        foreach ($this->effectiveRoles() as $role) {
            if ($role->permissions()->whereIn('slug', $permissionSlugs)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function getAllPermissionSlugs(): array
    {
        if ($this->isSuperAdmin() || $this->isDeveloper()) {
            return \App\Models\Permission::pluck('slug')->toArray();
        }

        return $this->effectiveRoles()
            ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->toArray();
    }

    // --- Tier checks ---

    public function isDeveloper(): bool
    {
        return $this->roles->contains('slug', 'developer');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isInternal(): bool
    {
        return $this->effectiveRoles()->where('tier', 'internal')->isNotEmpty();
    }

    public function isCustomer(): bool
    {
        return $this->effectiveRoles()->where('tier', 'customer')->isNotEmpty();
    }

    public function isDealer(): bool
    {
        $roles = $this->effectiveRoles();
        return $roles->where('tier', 'dealer')->isNotEmpty()
            || $roles->where('tier', 'customer')->isNotEmpty();
    }

    public function isOem(): bool
    {
        $roles = $this->effectiveRoles();
        return $roles->where('tier', 'oem')->isNotEmpty()
            || $roles->where('tier', 'customer')->isNotEmpty();
    }

    public function isDriver(): bool
    {
        return $this->hasRole('driver');
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function isOperationsController(): bool
    {
        return $this->hasRole('operations_controller');
    }

    public function isCustomerDispatcher(): bool
    {
        return $this->hasRole('customer_dispatcher');
    }

    // --- Capability checks ---

    public function canManageUsers(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'owner', 'ops_manager', 'operations_controller']);
    }

    public function canManagePricing(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer']);
    }

    public function canAssignDrivers(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'ops_manager', 'operations_controller', 'dispatcher']);
    }

    public function canApproveBookings(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'ops_manager', 'operations_controller']);
    }

    public function canManageInvoices(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'accounts']);
    }

    public function canOverride(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'ops_manager', 'operations_controller']);
    }

    public function canViewFinancials(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'ops_manager', 'operations_controller', 'accounts']);
    }

    public function canBookTransport(): bool
    {
        return $this->hasPermission('submit_booking');
    }

    public function canConfirmCustomerOrder(): bool
    {
        return $this->hasAnyRole(['customer_dispatcher', 'customer_owner']);
    }

    public function canPlanOrders(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'operations_controller']);
    }

    public function canGenerateCollectionNote(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'operations_controller', 'dispatcher']);
    }

    // --- Role mutation ---

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

    /**
     * Role hierarchy used by highestRole(), canAssignRole(), etc. Higher
     * numbers outrank lower. Unknown slugs collapse to a floor of 10 so
     * future roles default to the bottom of the pile until explicitly placed.
     */
    public const ROLE_HIERARCHY = [
        'developer' => 110,
        'super_admin' => 100,
        'owner' => 95,
        'operations_controller' => 90,
        'ops_manager' => 90,
        'dispatcher' => 80,
        'accounts' => 70,
        'customer_owner' => 65,
        'oem_admin' => 65,
        'customer_admin' => 62,
        'oem_planner' => 62,
        'customer_dispatcher' => 60,
        'dealer_principal' => 60,
        'customer_user' => 55,
        'sales_manager_new' => 55,
        'sales_manager_used' => 55,
        'stock_controller' => 50,
        'sales_person_new' => 40,
        'sales_person_used' => 40,
        'driver' => 20,
    ];

    public static function roleLevel(string $slug): int
    {
        return self::ROLE_HIERARCHY[$slug] ?? 10;
    }

    public function highestRoleLevel(): int
    {
        $highest = -1;
        foreach ($this->roles as $role) {
            $highest = max($highest, self::roleLevel($role->slug));
        }
        return $highest;
    }

    public function highestRole(): ?string
    {
        $highest = null;
        $highestLevel = -1;

        foreach ($this->roles as $role) {
            $level = self::roleLevel($role->slug);
            if ($level > $highestLevel) {
                $highestLevel = $level;
                $highest = $role->slug;
            }
        }

        return $highest;
    }

    /**
     * Can this user assign the given role to someone else?
     *
     * Prevents privilege escalation via the admin user form: a user can only
     * grant roles strictly below their own level (with developer as a special
     * case that may grant any role, including peer developers, because the
     * developer role is reserved for implementation support).
     *
     * Examples, given the hierarchy above:
     *  - developer (110)        → may grant any role
     *  - super_admin (100)      → may grant anything < 100 (NOT developer, NOT another super_admin)
     *  - ops_manager (90)       → may grant anything < 90 (NOT super_admin or developer)
     *  - dispatcher (80)        → cannot grant internal-tier roles above dispatcher
     *  - anything below ops tier should not reach the admin user form at all
     */
    public function canAssignRole(string $roleSlug): bool
    {
        if ($this->isDeveloper()) {
            return true;
        }

        $actorLevel = $this->highestRoleLevel();
        $targetLevel = self::roleLevel($roleSlug);

        return $targetLevel < $actorLevel;
    }

    /**
     * Filter an iterable of Role models (or role ids / slugs) down to only
     * those the current user is allowed to assign. Used to build the role
     * picker on admin/users/{create,edit}.
     *
     * @param  \Illuminate\Support\Collection|array $roles  Collection<Role>
     * @return \Illuminate\Support\Collection
     */
    public function assignableRoles($roles)
    {
        return collect($roles)->filter(fn ($role) => $this->canAssignRole($role->slug));
    }

    /**
     * May this user even reach the "manage users" form? This is stricter than
     * canManageUsers(): dispatchers can help ops run the board but should NOT
     * be creating or editing user accounts on the admin side.
     */
    public function canManageInternalUsers(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'owner', 'ops_manager', 'operations_controller']);
    }
}
