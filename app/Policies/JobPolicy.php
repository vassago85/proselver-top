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
        if ($user->isInternal() || $user->isDeveloper()) {
            return true;
        }

        if ($user->isDriver()) {
            return $job->driver_user_id === $user->id;
        }

        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            return $user->companies->pluck('id')->contains($job->company_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->canBookTransport() || $user->isInternal() || $user->isDeveloper();
    }

    public function update(User $user, Job $job): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->hasRole('ops_manager') || $user->isOperationsController()) {
            return true;
        }

        if ($user->hasRole('dispatcher') && in_array($job->status, [
            Job::STATUS_APPROVED,
            Job::STATUS_ASSIGNED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
        ])) {
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

    public function confirmCustomerOrder(User $user, Job $job): bool
    {
        if ($job->status !== Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION) {
            return false;
        }

        // Internal ProSelver staff / Developer retain an override for support
        // cases (e.g. confirming on a customer's behalf after a phone call,
        // with the audit log recording who did it).
        if ($user->isInternal() || $user->isDeveloper()) {
            return true;
        }

        if (! $user->canConfirmCustomerOrder()) {
            return false;
        }

        if (! $user->companies->pluck('id')->contains($job->company_id)) {
            return false;
        }

        // Hard rule: the person confirming must be physically at the pickup
        // depot. The whole purpose of this step is "truck is here, ready to
        // roll" — a statement only someone pinned to that depot can honestly
        // make. Account-wide roles (customer_owner / customer_admin in JHB)
        // are deliberately blocked here, otherwise they re-create the exact
        // problem this step exists to prevent (driver dispatched to a depot
        // where the vehicle isn't present or not driveable).
        $userDepotId = $user->assignedLocationId();
        if ($userDepotId === null || $job->pickup_location_id !== $userDepotId) {
            return false;
        }

        return true;
    }

    public function plan(User $user, Job $job): bool
    {
        return $user->canPlanOrders() && $job->status === Job::STATUS_CONFIRMED;
    }

    public function assignDriver(User $user, Job $job): bool
    {
        return $user->canAssignDrivers() && in_array($job->status, [
            Job::STATUS_APPROVED,
            Job::STATUS_ASSIGNED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
        ]);
    }

    public function cancel(User $user, Job $job): bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->hasRole('ops_manager') || $user->isOperationsController()) {
            return true;
        }

        if (($user->isCustomer() || $user->isDealer()) && $user->companies->pluck('id')->contains($job->company_id)) {
            return in_array($job->status, [
                Job::STATUS_PENDING_VERIFICATION,
                Job::STATUS_VERIFIED,
                Job::STATUS_APPROVED,
                Job::STATUS_ASSIGNED,
                Job::STATUS_RECEIVED,
                Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
                Job::STATUS_CONFIRMED,
                Job::STATUS_PLANNED,
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

    public function generateCollectionNote(User $user, Job $job): bool
    {
        return $user->canGenerateCollectionNote() && in_array($job->status, [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_DELIVERED,
            Job::STATUS_COMPLETED,
        ]);
    }
}
