<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\SystemSetting;
use App\Models\User;

class JobPolicy
{
    /**
     * Roles that always retain the ability to cancel, even if an operator
     * accidentally saves an empty allow-list in the UI. This is a safety
     * net — nobody should be able to lock themselves out of cancellations
     * at the platform-owner tier, and support still needs a break-glass.
     */
    private const CANCEL_ALWAYS_ALLOWED = ['developer', 'super_admin', 'owner'];

    /**
     * Sensible default when the setting has never been configured. Keeps
     * the pre-existing behaviour intact on first boot.
     */
    private const CANCEL_DEFAULT_INTERNAL_ROLES = [
        'developer', 'super_admin', 'owner',
        'ops_manager', 'operations_controller',
    ];
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Job $job): bool
    {
        if ($user->isInternal() || $user->isDeveloper()) {
            return true;
        }

        // Platform-owner users (e.g. ProSelver / TCDC ops) see every job
        // regardless of role, matching the Phase 1 visibility rules. For any
        // user who is already isInternal() this is a no-op; it only matters
        // for future platform-owner users whose role tier is something other
        // than 'internal'.
        if ($user->belongsToPlatformOwner()) {
            return true;
        }

        if ($user->isDriver()) {
            return $job->driver_user_id === $user->id;
        }

        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            $companyIds = $user->companies->pluck('id');
            if ($companyIds->contains($job->company_id)) {
                return true;
            }
            // Future 3PL transporters: a user can view jobs their company is
            // executing, even when they didn't book them.
            if ($job->executing_company_id !== null && $companyIds->contains($job->executing_company_id)) {
                return true;
            }
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

        // Internal TCDC staff / Developer retain an override for support
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
        $assignableStatuses = [
            Job::STATUS_APPROVED,
            Job::STATUS_ASSIGNED,
            Job::STATUS_PLANNED,
            Job::STATUS_DRIVER_ASSIGNED,
        ];

        if (! in_array($job->status, $assignableStatuses, true)) {
            return false;
        }

        // ProSelver ops (dispatcher / ops_manager / operations_controller /
        // super_admin / developer) can always assign — they own the
        // platform-driver pool.
        if ($user->canAssignDrivers()) {
            return true;
        }

        // Dealer-side authority: customer_owner / customer_admin /
        // customer_dispatcher can assign on jobs their company owns,
        // but only when the executor is INTERNAL (the dealer is
        // running the move themselves and picking from their own
        // driver pool). They must NOT be able to touch executor_type
        // = proselver / third_party / self_collect jobs — those have
        // either ProSelver doing the assignment or no driver at all.
        // Use canPlanMovements() so legacy dealer (dealer_principal,
        // sales_manager_*, stock_controller) and OEM (oem_admin, oem_planner)
        // roles get the same authority as their unified-customer peers
        // until they're migrated to the customer_* tier.
        //
        // OEM tenants are explicitly excluded here: their jobs are
        // ProSelver-only by company policy, so even an OEM-side
        // owner / admin can't reassign drivers — only ProSelver ops
        // can, via the canAssignDrivers() branch above.
        if ($job->executor_type === Job::EXECUTOR_INTERNAL
            && $user->canPlanMovements()
            && $user->companies->pluck('id')->contains($job->company_id)
            && ! ($job->company?->isOem())
        ) {
            return true;
        }

        return false;
    }

    /**
     * Flip the executor on a job. Allowed for ProSelver ops on any job
     * they can see, and for the dealer's admin/owner/dispatcher on
     * their own jobs while the vehicle hasn't been collected yet.
     * Sales-only roles (customer_user) can pick the executor at booking
     * time via the create form but can't flip after the fact.
     */
    public function changeExecutor(User $user, Job $job): bool
    {
        if (! $job->canChangeExecutor()) {
            return false;
        }

        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->isInternal()) {
            return true;
        }

        // Dealer-side admin / owner / dispatcher can flip executor on
        // their own jobs. OEM tenants are deliberately blocked here:
        // they book ProSelver only, so we don't expose the "Change
        // executor" action for them even though they could otherwise
        // satisfy canPlanMovements() + own the company_id.
        if ($user->canPlanMovements()
            && $user->companies->pluck('id')->contains($job->company_id)
            && ! ($job->company?->isOem())
        ) {
            return true;
        }

        return false;
    }

    /**
     * Archive / unarchive a final-delivery job. The dealer can archive
     * their own non-body-builder deliveries; ops can archive anything
     * (including body-builder rows, with the opsOverride flag inside
     * Job::archive()).
     */
    public function archive(User $user, Job $job): bool
    {
        $isOps = $user->isDeveloper() || $user->isSuperAdmin() || $user->isInternal();

        if (! $job->canArchive($opsOverride = $isOps)) {
            return false;
        }

        if ($isOps) {
            return true;
        }

        if ($user->canManageCompanyData()
            && $user->companies->pluck('id')->contains($job->company_id)
        ) {
            return true;
        }

        return false;
    }

    public function unarchive(User $user, Job $job): bool
    {
        if (! $job->isArchived()) {
            return false;
        }

        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->isInternal()) {
            return true;
        }

        if ($user->canManageCompanyData()
            && $user->companies->pluck('id')->contains($job->company_id)
        ) {
            return true;
        }

        return false;
    }

    public function cancel(User $user, Job $job): bool
    {
        // Platform-owner tier staff: the owner curates the internal allow-list
        // from Admin → Settings → Cancellation Permissions. The list is
        // stored as a JSON array of role slugs in system_settings. A hard
        // allow-list floor (developer/super_admin/owner) stops the org from
        // locking itself out of cancellations by saving an empty list.
        $allowedInternal = array_unique(array_merge(
            self::CANCEL_ALWAYS_ALLOWED,
            self::allowedInternalCancelRoles(),
        ));

        if ($user->hasAnyRole($allowedInternal)) {
            return true;
        }

        // Customer / dealer self-service cancel while the movement has not
        // yet left the depot. This is a business-rule gate, not a policy
        // knob — the owner's allow-list only governs *internal* cancellers.
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

    /**
     * Internal role slugs the owner has authorised to cancel orders.
     * Falls back to the pre-configurable default so first-boot behaviour
     * matches what shipped before this setting existed.
     *
     * @return array<int,string>
     */
    public static function allowedInternalCancelRoles(): array
    {
        try {
            $raw = SystemSetting::get('order_cancel_allowed_roles', null);
        } catch (\Throwable) {
            $raw = null;
        }

        if (is_array($raw)) {
            $clean = array_values(array_filter(array_map('strval', $raw)));
            return $clean !== [] ? $clean : self::CANCEL_DEFAULT_INTERNAL_ROLES;
        }

        return self::CANCEL_DEFAULT_INTERNAL_ROLES;
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
        $allowedStatuses = [
            Job::STATUS_DRIVER_ASSIGNED,
            Job::STATUS_READY_FOR_COLLECTION,
            Job::STATUS_COLLECTED,
            Job::STATUS_IN_TRANSIT,
            Job::STATUS_DELIVERED,
            Job::STATUS_COMPLETED,
        ];

        if (! in_array($job->status, $allowedStatuses, true)) {
            return false;
        }

        // ProSelver ops staff can always pull the paperwork — same
        // rule as before the dealer-executor work.
        if ($user->canGenerateCollectionNote()) {
            return true;
        }

        // Dealer planners (canPlanMovements) can pull the delivery
        // note for their OWN non-ProSelver movements. They issue the
        // paperwork to their own driver / courier / self-collector,
        // so we let them generate the PDF inside their company scope.
        // ProSelver-executed jobs stay locked to ProSelver ops — the
        // dealer's copy of that paperwork comes from us, not from
        // their tenant.
        if (
            $user->canPlanMovements()
            && $job->executor_type !== Job::EXECUTOR_PROSELVER
            && $this->view($user, $job)
        ) {
            return true;
        }

        // Assigned internal driver pulling their own delivery note
        // from the PWA. Already gated by view() — drivers can only
        // see jobs assigned to them.
        if ($user->isDriver() && $job->driver_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Damage report download gate. Internal staff (ops, owner, super
     * admin, developer, assigned driver) can always pull the report so
     * they can review it before releasing it. External users
     * (customer / dealer / OEM) can only download the report once ops
     * has explicitly released it — i.e. `damage_report_released_at` is
     * set. This prevents the customer seeing a raw "driver flagged a
     * scratch" photo the moment it lands in the system, before ops have
     * had a chance to verify what actually happened.
     */
    public function generateDamageReport(User $user, Job $job): bool
    {
        if (!$this->view($user, $job)) {
            return false;
        }

        if ($user->isInternal()) {
            return true;
        }

        // External users (customer / dealer / OEM) must wait for ops
        // to release the report before they can pull it.
        return $job->damage_report_released_at !== null;
    }

    /**
     * Only trusted operators can release (or revoke the release of)
     * the damage report to the customer. We deliberately restrict this
     * to the same internal floor that guards cancellations — damage
     * claims have financial consequences so junior ops shouldn't be
     * able to silently release a report without a more senior pair of
     * eyes in the loop.
     */
    public function releaseDamageReport(User $user, Job $job): bool
    {
        return $user->hasAnyRole([
            'developer',
            'super_admin',
            'owner',
            'ops_manager',
            'operations_controller',
        ]);
    }
}
