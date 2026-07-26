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

    /**
     * COARSE ACCESS GATE — not a tenant discriminator.
     *
     * Returns true for any tenant-tier user (legacy dealer-tier OR the
     * modern customer-tier that dealers/OEMs/customers now share), so it
     * cannot tell you "is this specifically a dealer". To branch on what
     * KIND of tenant a user is, use the company type instead:
     * `$user->company()?->isDealer()`. This method only answers "may this
     * user reach the customer/dealer portal at all".
     */
    public function isDealer(): bool
    {
        $roles = $this->effectiveRoles();
        return $roles->where('tier', 'dealer')->isNotEmpty()
            || $roles->where('tier', 'customer')->isNotEmpty();
    }

    /**
     * COARSE ACCESS GATE — not a tenant discriminator. See isDealer().
     * For "is this an OEM tenant" use `$user->company()?->isOem()`
     * (a.k.a. $user->companyIsOem()).
     */
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

    public function isAccounts(): bool
    {
        return $this->hasRole('accounts');
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

    /**
     * May see what Trident costs the business — the ProSelver platform licence
     * meter and every figure derived from it.
     *
     * Owner and developer only, deliberately narrower than any other finance
     * gate. This is the shareholders' cost of running the platform, not an
     * operating expense accounts reconciles or ops plans against, so it is
     * withheld from accounts, ops AND super_admin. Keep this list to two.
     */
    public function canViewPlatformLicence(): bool
    {
        return $this->isOwner() || $this->isDeveloper();
    }

    /**
     * May reach the petty-cash oversight pages: the Overview dashboard and the
     * reconciliation report. Accounts owns month-end recon, ops needs driver
     * spend per movement, and the owner signs off. Previously this list was
     * written out by hand in three places and had drifted — super_admin could
     * not open the Overview at all.
     */
    public function canViewPettyCashOverview(): bool
    {
        return $this->hasAnyRole([
            'super_admin', 'developer', 'owner', 'accounts', 'operations_controller',
        ]);
    }

    /**
     * May sign off an issued-on-cancelled reconciliation query — a trip that
     * was cancelled after the driver's cash advance had already left the till.
     *
     * Operations is included because ops is usually the only party that knows
     * where the money actually went (reassigned to another vehicle, applied to
     * a swap trip, returned at the depot), and routing every one of those
     * through accounts was leaving queries open for weeks. Both sides can
     * clear, and every clearance is audited with a written explanation, so
     * widening this costs oversight nothing.
     */
    public function canClearReconciliationQuery(): bool
    {
        return $this->hasAnyRole([
            'super_admin', 'developer', 'owner', 'accounts', 'operations_controller',
        ]);
    }

    public function canBookTransport(): bool
    {
        return $this->hasPermission('submit_booking');
    }

    public function canConfirmCustomerOrder(): bool
    {
        return $this->hasAnyRole(['customer_dispatcher', 'customer_owner']);
    }

    /**
     * Company-side "admin" — can run CRUD on the company's settings, users,
     * drivers, locations, and is the audience for the manage-archive /
     * change-executor surfaces in the customer portal.
     *
     * Includes BOTH the modern unified-customer roles (customer_owner /
     * customer_admin) AND the legacy dealer / OEM equivalents so a dealer
     * principal or OEM admin who hasn't yet been migrated to the customer
     * tier still gets the same surfaces as their migrated peers. Without
     * this bridge, anyone landing on /dealer/dashboard would hit a wall
     * of 403s the moment they clicked into a new feature.
     */
    public function canManageCompanyData(): bool
    {
        return $this->hasAnyRole([
            'customer_owner', 'customer_admin',
            'dealer_principal', 'stock_controller',
            'sales_manager_new', 'sales_manager_used',
            'oem_admin',
        ]);
    }

    /**
     * Company-side "dispatcher and above" — can plan and execute
     * movements: book orders, assign drivers, plan trips, confirm
     * readiness, archive deliveries. Strict superset of
     * canManageCompanyData() with dispatcher-grade roles added on top.
     */
    public function canPlanMovements(): bool
    {
        return $this->canManageCompanyData()
            || $this->hasAnyRole([
                'customer_dispatcher',
                'sales_person_new', 'sales_person_used',
                'oem_planner',
            ]);
    }

    public function canPlanOrders(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'operations_controller']);
    }

    public function canGenerateCollectionNote(): bool
    {
        return $this->hasAnyRole(['super_admin', 'developer', 'operations_controller', 'dispatcher']);
    }

    // -----------------------------------------------------------------
    // Body-builder portal shortcuts
    // -----------------------------------------------------------------

    /**
     * True for users whose role lives in the body-builder portal
     * (BB owner / BB user). These are the only roles that should land
     * on /body-builder/* routes; the EnsureBodyBuilderAccess middleware
     * gates on this + on the user's company.type=body_builder.
     */
    public function isBodyBuilderTenant(): bool
    {
        return $this->hasAnyRole(['body_builder_owner', 'body_builder_user']);
    }

    /**
     * Dealer-side: can this user approve / reject movement requests
     * from linked body builders? Owner / admin / dispatcher tier get
     * the permission via the seeder, plus ops + super-admin / dev.
     */
    public function canApproveBbRequests(): bool
    {
        return $this->hasPermission('dealer_approve_bb_requests');
    }

    /**
     * Dealer-side: can this user link / pause / remove body builders
     * authorised to confirm receipts on their behalf?
     */
    public function canManageBbLinks(): bool
    {
        return $this->hasPermission('manage_bb_links');
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
