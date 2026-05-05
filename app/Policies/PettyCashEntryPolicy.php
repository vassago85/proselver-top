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
 *   - Customers / dealers / OEMs MUST NOT see driver expenses — same
 *     reasoning as JobDocumentPolicy: leaking expense data would expose
 *     carrier margin and create reimbursement disputes.
 *
 * Lifecycle:
 *   - Only the submitting driver can update a row that is still in
 *     `submitted` (e.g. fix the amount or category).
 *   - Approve / reject is internal-only.
 *   - Reimburse is internal-only AND requires the row to be approved.
 */
class PettyCashEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal() || $user->belongsToPlatformOwner();
    }

    public function view(User $user, PettyCashEntry $entry): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        if ($entry->driver_user_id === $user->id) {
            return true;
        }

        return $user->isInternal() || $user->belongsToPlatformOwner();
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
        return ($user->isInternal() || $user->isSuperAdmin())
            && $entry->status === PettyCashEntry::STATUS_SUBMITTED;
    }

    public function reject(User $user, PettyCashEntry $entry): bool
    {
        return ($user->isInternal() || $user->isSuperAdmin())
            && in_array($entry->status, [
                PettyCashEntry::STATUS_SUBMITTED,
                PettyCashEntry::STATUS_APPROVED,
            ], true);
    }

    public function reimburse(User $user, PettyCashEntry $entry): bool
    {
        return ($user->isInternal() || $user->isSuperAdmin())
            && $entry->status === PettyCashEntry::STATUS_APPROVED;
    }

    public function delete(User $user, PettyCashEntry $entry): bool
    {
        if ($user->isSuperAdmin() || $user->isDeveloper()) {
            return true;
        }

        return $entry->driver_user_id === $user->id
            && $entry->status === PettyCashEntry::STATUS_SUBMITTED;
    }
}
