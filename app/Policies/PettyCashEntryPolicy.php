<?php

namespace App\Policies;

use App\Models\PettyCashEntry;
use App\Models\User;

/**
 * Petty cash entry visibility + lifecycle gates.
 *
 * Visibility:
 *   - The driver who submitted the row can view it (PWA / /driver/expenses).
 *   - Internal staff + platform-owner users can view all (admin queue).
 *   - Dealer-tier company admins / dispatchers can view + manage the
 *     slips submitted by their own-company drivers (or slips attached
 *     to their own jobs). This lets dealers running executor=internal
 *     trips fund and reconcile cash for their own driver pool without
 *     looping ProSelver Accounts in.
 *   - All other customer tiers MUST NOT see driver expenses — same
 *     reasoning as JobDocumentPolicy: leaking expense data would expose
 *     carrier margin and create reimbursement disputes.
 *
 * Lifecycle:
 *   - Only the submitting driver can update a row that is still in
 *     `submitted` (e.g. fix the amount or category).
 *   - Approve / reject: internal staff OR the entry's home-company
 *     admin / planner.
 *   - Reimburse: Accounts (with Owner/Dev fallback) on the carrier
 *     side; on the dealer side, anyone who can manage the dealer's
 *     company data (principals + customer_owner/admin) can mark slips
 *     paid against their own float.
 */
class PettyCashEntryPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isInternal() || $user->belongsToPlatformOwner()) {
            return true;
        }

        // Dealer-tier admins / dispatchers can land on the new dealer
        // petty-cash page; the page itself is responsible for scoping
        // the list to their own-company slips.
        return $user->canManageCompanyData() || $user->canPlanMovements();
    }

    public function view(User $user, PettyCashEntry $entry): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        if ($entry->driver_user_id === $user->id) {
            return true;
        }

        if ($user->isInternal() || $user->belongsToPlatformOwner()) {
            return true;
        }

        return $this->belongsToUserCompany($user, $entry);
    }

    public function update(User $user, PettyCashEntry $entry): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        if ($entry->driver_user_id === $user->id && $entry->status === PettyCashEntry::STATUS_SUBMITTED) {
            return true;
        }

        return false;
    }

    public function approve(User $user, PettyCashEntry $entry): bool
    {
        if ($entry->status !== PettyCashEntry::STATUS_SUBMITTED) {
            return false;
        }

        if ($user->isInternal() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->canManageCompanyData() && $this->belongsToUserCompany($user, $entry);
    }

    public function reject(User $user, PettyCashEntry $entry): bool
    {
        if (!in_array($entry->status, [PettyCashEntry::STATUS_SUBMITTED, PettyCashEntry::STATUS_APPROVED], true)) {
            return false;
        }

        if ($user->isInternal() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->canManageCompanyData() && $this->belongsToUserCompany($user, $entry);
    }

    /**
     * Reimbursement is a financial cash-out and only Accounts (primary),
     * Owner (fallback), Super-Admin and Developer may sign it off.
     *
     * Ops are intentionally excluded — they approve the *slip* (it's a
     * legitimate expense), but the EFT/cash-send itself is Accounts'
     * responsibility. Same accounts-first / owner-fallback pattern used
     * for petty-cash plans and advance-removal sign-off.
     */
    public function reimburse(User $user, PettyCashEntry $entry): bool
    {
        if ($entry->status !== PettyCashEntry::STATUS_APPROVED) {
            return false;
        }

        // Carrier side: Accounts (primary) + Owner (fallback) + super-admins.
        if ($user->isAccounts() || $user->isOwner() || $user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        // Dealer side: a dealer-company admin may pay their own driver
        // off their own float. canManageCompanyData covers principals +
        // customer_owner/admin + sales managers + stock controllers.
        return $user->canManageCompanyData() && $this->belongsToUserCompany($user, $entry);
    }

    public function delete(User $user, PettyCashEntry $entry): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        return $entry->driver_user_id === $user->id
            && $entry->status === PettyCashEntry::STATUS_SUBMITTED;
    }

    /**
     * True when the petty-cash entry's home company (derived from the
     * driver who submitted it, or the job it's attached to) intersects
     * the user's own operating companies (and their group siblings).
     *
     * The driver-side join is the primary route: every slip is tied
     * to a driver_user_id, and every driver belongs to at least one
     * company via company_users. The job-side join is a fallback for
     * edge cases where the slip is attached to a job whose company the
     * dealer can already see even though the driver isn't on their
     * roster (e.g. ProSelver driver mid-handover).
     */
    private function belongsToUserCompany(User $user, PettyCashEntry $entry): bool
    {
        $userCompanyIds = array_unique(array_merge(
            $user->operatingCompanyIds(),
            $user->groupSiblingCompanyIds(),
        ));

        if (empty($userCompanyIds)) {
            return false;
        }

        $driverCompanyIds = $entry->driver
            ? $entry->driver->companies()->pluck('companies.id')->all()
            : [];
        if (!empty(array_intersect($userCompanyIds, $driverCompanyIds))) {
            return true;
        }

        if ($entry->job && in_array((int) $entry->job->company_id, $userCompanyIds, true)) {
            return true;
        }

        return false;
    }
}
