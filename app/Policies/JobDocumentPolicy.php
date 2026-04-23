<?php

namespace App\Policies;

use App\Models\JobDocument;
use App\Models\User;

/**
 * Who may view a driver-uploaded job document?
 *
 * Two-tier policy, per product decision 2026-04-23:
 *
 *   Tier 1 — "everyone on the job" may see:
 *     proof_of_delivery, collection_note, damage_photo, photo, purchase_order
 *   That's the paperwork dealers / customers need to confirm a movement
 *   happened and the vehicle arrived intact.
 *
 *   Tier 2 — "ops + owner-admin only":
 *     fuel_slip, food_slip, toll_slip, parking_slip, other
 *   These are petty-cash / expense slips. Dealers and customers must never
 *   see driver expenses — that data would leak carrier margins and create
 *   reimbursement disputes. Only internal staff (ops) and users belonging
 *   to the platform-owner (FAW admins) may view them.
 *
 * Access to "everyone on the job" piggy-backs on Job::scopeVisibleTo so the
 * rule is: if you can see the job on the /tracking / /orders boards, you
 * can see its non-expense paperwork.
 */
class JobDocumentPolicy
{
    public function view(User $user, JobDocument $document): bool
    {
        // Super_admin / developer bypass — needed for support + debugging.
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        // Tier 2 gate: petty-cash / expense slips are ops/owner-only.
        if (in_array($document->category, JobDocument::pettyCashCategories(), true)) {
            return $user->isInternal() || $user->belongsToPlatformOwner();
        }

        // Tier 1: anyone who can see the parent job can see this document.
        // Driver who uploaded it always wins (they might still be viewing
        // the PWA after a job transition that removed visibility elsewhere).
        if ($document->uploaded_by_user_id === $user->id) {
            return true;
        }

        $job = $document->job;
        if (!$job) {
            return false;
        }

        if ($user->isInternal() || $user->belongsToPlatformOwner()) {
            return true;
        }

        $companyIds = $user->operatingCompanyIds();
        if (empty($companyIds)) {
            return false;
        }

        return in_array($job->company_id, $companyIds, true)
            || in_array($job->executing_company_id, $companyIds, true);
    }

    /**
     * Deleting a job document is tighter than viewing it. Drivers can delete
     * their own uploads up until the job is completed (to fix bad photos).
     * After completion only ops / super_admin may delete, for audit integrity.
     */
    public function delete(User $user, JobDocument $document): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        $job = $document->job;
        if (!$job) {
            return false;
        }

        if ($document->uploaded_by_user_id === $user->id
            && !in_array($job->status, ['completed', 'cancelled'], true)
        ) {
            return true;
        }

        return $user->isInternal() && in_array('ops_manager', $user->roles->pluck('slug')->all(), true);
    }
}
