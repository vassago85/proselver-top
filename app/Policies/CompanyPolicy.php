<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal();
    }

    public function view(User $user, Company $company): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->companies->pluck('id')->contains($company->id);
    }

    public function create(User $user): bool
    {
        // Mirror the sidebar's "Companies" section gate so the people
        // who can see the menu can also use the Add Company button —
        // developer, super admin, owner, ops controller, ops manager.
        return $user->isDeveloper()
            || $user->isSuperAdmin()
            || $user->isOwner()
            || $user->hasRole('ops_controller')
            || $user->hasRole('ops_manager');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isDeveloper()
            || $user->isSuperAdmin()
            || $user->isOwner()
            || $user->hasRole('ops_controller')
            || $user->hasRole('ops_manager');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageUsers(User $user, Company $company): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('ops_manager')) {
            return true;
        }

        if ($user->hasRole('dealer_admin') && $user->companies->pluck('id')->contains($company->id)) {
            return true;
        }

        return false;
    }
}
