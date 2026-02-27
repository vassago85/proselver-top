<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Job $job): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        if ($user->isDriver()) {
            return $job->driver_user_id === $user->id;
        }

        if ($user->isDealer()) {
            return $user->companies->pluck('id')->contains($job->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->canBookTransport() || $user->isInternal();
    }

    public function update(User $user, Job $job): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('ops_manager')) {
            return true;
        }

        if ($user->hasRole('dispatcher') && in_array($job->status, [Job::STATUS_APPROVED, Job::STATUS_ASSIGNED])) {
            return true;
        }

        return false;
    }

    public function verify(User $user, Job $job): bool
    {
        return $user->canApproveBookings() && $job->status === Job::STATUS_PENDING_VERIFICATION;
    }

    public function approve(User $user, Job $job): bool
    {
        return $user->canApproveBookings() && $job->status === Job::STATUS_VERIFIED;
    }

    public function assignDriver(User $user, Job $job): bool
    {
        return $user->canAssignDrivers() && in_array($job->status, [Job::STATUS_APPROVED, Job::STATUS_ASSIGNED]);
    }

    public function cancel(User $user, Job $job): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole('ops_manager')) {
            return true;
        }

        if ($user->isDealer() && $user->companies->pluck('id')->contains($job->company_id)) {
            return in_array($job->status, [
                Job::STATUS_PENDING_VERIFICATION,
                Job::STATUS_VERIFIED,
                Job::STATUS_APPROVED,
                Job::STATUS_ASSIGNED,
            ]);
        }

        return false;
    }

    public function updateCosts(User $user, Job $job): bool
    {
        return $user->canViewFinancials();
    }

    public function invoice(User $user, Job $job): bool
    {
        return $user->canManageInvoices() && $job->status === Job::STATUS_READY_FOR_INVOICING;
    }

    public function viewFinancials(User $user, Job $job): bool
    {
        return $user->canViewFinancials();
    }
}
