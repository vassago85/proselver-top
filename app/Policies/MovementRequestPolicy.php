<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\MovementRequest;
use App\Models\User;

/**
 * Movement-request authorisation.
 *
 * Two sides see a movement request:
 *
 *   - The body builder who raised it (requesting_company_id) — can
 *     view their own requests, cancel pending ones, but never decide.
 *   - The dealer who owns the source inventory (target_company_id) —
 *     can view requests against them and (if they have the perm)
 *     approve / reject pending ones.
 *
 * ProSelver ops (super_admin, developer, operations_controller) see
 * everything because they need to be able to support both sides when
 * a request goes sideways.
 */
class MovementRequestPolicy
{
    /**
     * Catch-all for ProSelver staff — keeps the per-method bodies
     * focused on the tenant-side rules.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->isInternal()) {
            return true;
        }
        return null;
    }

    /**
     * Can this user see the request at all? BB tenant on the
     * requesting side, dealer tenant on the target side.
     */
    public function view(User $user, MovementRequest $req): bool
    {
        $companyIds = $user->companies->pluck('id');

        return $companyIds->contains($req->requesting_company_id)
            || $companyIds->contains($req->target_company_id);
    }

    /**
     * BB user raises a new request against a specific source job.
     * Tested at the page-level when the user clicks "Request next
     * move" / "Request collection" so we can hide buttons early.
     */
    public function createFor(User $user, Job $sourceJob): bool
    {
        if (! $user->isBodyBuilderTenant()) {
            return false;
        }
        if (! $user->hasPermission('bb_request_movement')) {
            return false;
        }

        $bb = $user->company();
        if (! $bb || ! $sourceJob->company_id) {
            return false;
        }

        // The BB must own the delivery location of the source job
        // (i.e. the truck is physically at our workshop) AND have an
        // active link to the dealer that owns the inventory.
        $deliveryAtMine = $sourceJob->deliveryLocation?->company_id === $bb->id;
        $linkedToDealer = $bb->linkedDealers()
            ->wherePivot('is_active', true)
            ->whereKey($sourceJob->company_id)
            ->exists();

        return $deliveryAtMine && $linkedToDealer;
    }

    /**
     * Dealer planner approving / rejecting a pending request against
     * their own inventory.
     */
    public function decide(User $user, MovementRequest $req): bool
    {
        if (! $req->isPending()) {
            return false;
        }
        if (! $user->canApproveBbRequests()) {
            return false;
        }
        return $user->companies->pluck('id')->contains($req->target_company_id);
    }

    public function approve(User $user, MovementRequest $req): bool
    {
        return $this->decide($user, $req);
    }

    public function reject(User $user, MovementRequest $req): bool
    {
        return $this->decide($user, $req);
    }

    /**
     * Only the originating BB tenant can withdraw their own pending
     * request. Decided requests are immutable.
     */
    public function cancel(User $user, MovementRequest $req): bool
    {
        if (! $req->isPending()) {
            return false;
        }
        if (! $user->isBodyBuilderTenant()) {
            return false;
        }
        return $user->companies->pluck('id')->contains($req->requesting_company_id);
    }
}
