<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;

/**
 * Handles the lifecycle of a driver leaving the roster (retire, resign,
 * dismiss, deceased, other) and the associated trade-plate handover.
 *
 * Design decisions:
 *  - We do NOT soft-delete the user. They must remain referenceable from
 *    completed jobs, invoices, and audit trails. Flipping is_active=false
 *    removes them from planning, dispatch, and active rosters everywhere.
 *  - Trade plates are business assets. When a driver leaves we either
 *    release the plate back to the business pool (null) or hand it to
 *    another active driver. Either way the transition is logged.
 *  - We refuse to offboard drivers with open jobs. The caller is expected
 *    to reassign those jobs first. A clean roster beats silent orphans.
 */
class DriverOffboardingService
{
    public const PLATE_RELEASE  = 'release';
    public const PLATE_TRANSFER = 'transfer';

    /** Statuses that should block offboarding. */
    public const ACTIVE_JOB_STATUSES = [
        Job::STATUS_PENDING_VERIFICATION,
        Job::STATUS_VERIFIED,
        Job::STATUS_APPROVED,
        Job::STATUS_RECEIVED,
        Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION,
        Job::STATUS_CONFIRMATION_ISSUE,
        Job::STATUS_CONFIRMED,
        Job::STATUS_PLANNED,
        Job::STATUS_DRIVER_ASSIGNED,
        Job::STATUS_READY_FOR_COLLECTION,
        Job::STATUS_ASSIGNED,
        Job::STATUS_IN_PROGRESS,
        Job::STATUS_COLLECTED,
        Job::STATUS_IN_TRANSIT,
        Job::STATUS_DELIVERED,
    ];

    /**
     * Count jobs that should block offboarding.
     */
    public function activeJobCount(User $driver): int
    {
        return $driver->assignedJobs()
            ->whereIn('status', self::ACTIVE_JOB_STATUSES)
            ->count();
    }

    /**
     * Take a driver off the roster.
     *
     * @param  User         $driver             The driver leaving.
     * @param  string       $reason             One of DriverProfile::REASON_*.
     * @param  string|null  $notes              Free-text context (optional).
     * @param  string       $plateDisposition   PLATE_RELEASE | PLATE_TRANSFER.
     * @param  int|null     $transferToUserId   Target driver user id when transferring.
     *
     * @throws RuntimeException         When active jobs block the move or plate transfer is invalid.
     * @throws InvalidArgumentException When the reason / disposition is unknown.
     */
    public function offboard(
        User $driver,
        string $reason,
        ?string $notes,
        string $plateDisposition,
        ?int $transferToUserId = null
    ): void {
        if (!$driver->hasRole('driver')) {
            throw new RuntimeException('User is not a driver.');
        }

        if (!array_key_exists($reason, DriverProfile::REASON_LABELS)) {
            throw new InvalidArgumentException('Unknown offboarding reason.');
        }

        if (!in_array($plateDisposition, [self::PLATE_RELEASE, self::PLATE_TRANSFER], true)) {
            throw new InvalidArgumentException('Unknown trade-plate disposition.');
        }

        $openJobs = $this->activeJobCount($driver);
        if ($openJobs > 0) {
            throw new RuntimeException(
                "This driver still has {$openJobs} active job(s). Reassign them before taking the driver off the roster."
            );
        }

        DB::transaction(function () use ($driver, $reason, $notes, $plateDisposition, $transferToUserId) {
            $profile = $driver->driverProfile;
            $currentPlate = $profile?->trade_plate;
            $currentExpiry = $profile?->trade_plate_expiry;

            // Validate transfer target up front so we don't clear the
            // source plate only to fail on the destination.
            if ($plateDisposition === self::PLATE_TRANSFER) {
                if (!$currentPlate) {
                    throw new RuntimeException('Driver has no trade plate to transfer.');
                }
                if (!$transferToUserId || $transferToUserId === $driver->id) {
                    throw new RuntimeException('Select a different active driver to receive the trade plate.');
                }
                $target = User::whereKey($transferToUserId)
                    ->where('is_active', true)
                    ->first();
                if (!$target || !$target->hasRole('driver')) {
                    throw new RuntimeException('Target driver is not active or not a driver.');
                }
                $targetProfile = DriverProfile::firstOrCreate(['user_id' => $target->id]);
                if ($targetProfile->trade_plate) {
                    throw new RuntimeException(
                        "{$target->name} already holds trade plate {$targetProfile->trade_plate}. Release that plate before transferring another."
                    );
                }

                $targetProfile->update([
                    'trade_plate' => $currentPlate,
                    'trade_plate_expiry' => $currentExpiry,
                    'trade_plate_returned_at' => null,
                ]);

                $this->audit(
                    'driver.trade_plate.transferred_in',
                    $targetProfile,
                    ['trade_plate' => null],
                    [
                        'trade_plate' => $currentPlate,
                        'trade_plate_expiry' => optional($currentExpiry)->toDateString(),
                        'from_user_id' => $driver->id,
                    ],
                    "Received from {$driver->name} on offboarding."
                );
            }

            // Clear / release the source driver's plate.
            if ($profile) {
                $this->audit(
                    'driver.trade_plate.released',
                    $profile,
                    [
                        'trade_plate' => $currentPlate,
                        'trade_plate_expiry' => optional($currentExpiry)->toDateString(),
                    ],
                    [
                        'trade_plate' => null,
                        'trade_plate_expiry' => null,
                        'disposition' => $plateDisposition,
                        'transferred_to_user_id' => $plateDisposition === self::PLATE_TRANSFER ? $transferToUserId : null,
                    ],
                    $plateDisposition === self::PLATE_TRANSFER
                        ? "Trade plate transferred to user #{$transferToUserId} on offboarding."
                        : 'Trade plate released to business pool on offboarding.'
                );

                $profile->update([
                    'trade_plate' => null,
                    'trade_plate_expiry' => null,
                    'trade_plate_returned_at' => $currentPlate ? now() : null,
                    'off_roster_at' => now(),
                    'off_roster_reason' => $reason,
                    'off_roster_notes' => $notes,
                    'off_roster_by_user_id' => Auth::id(),
                ]);
            } else {
                // Edge case: driver never had a profile. Create a
                // minimal off-roster record so the lifecycle data is
                // still captured.
                DriverProfile::create([
                    'user_id' => $driver->id,
                    'off_roster_at' => now(),
                    'off_roster_reason' => $reason,
                    'off_roster_notes' => $notes,
                    'off_roster_by_user_id' => Auth::id(),
                ]);
            }

            $driver->update(['is_active' => false]);

            $this->audit(
                'driver.offboarded',
                $driver,
                ['is_active' => true],
                [
                    'is_active' => false,
                    'reason' => $reason,
                    'plate_disposition' => $plateDisposition,
                    'plate_transferred_to_user_id' => $plateDisposition === self::PLATE_TRANSFER ? $transferToUserId : null,
                ],
                $notes
            );
        });
    }

    /**
     * Put a previously off-rostered driver back on the roster.
     * Does NOT restore any previously held trade plate — assign the
     * plate explicitly once the driver is re-onboarded.
     */
    public function reinstate(User $driver): void
    {
        if (!$driver->hasRole('driver')) {
            throw new RuntimeException('User is not a driver.');
        }

        DB::transaction(function () use ($driver) {
            $driver->update(['is_active' => true]);

            $profile = $driver->driverProfile;
            if ($profile) {
                $profile->update([
                    'off_roster_at' => null,
                    'off_roster_reason' => null,
                    'off_roster_notes' => null,
                    'off_roster_by_user_id' => null,
                ]);
            }

            $this->audit(
                'driver.reinstated',
                $driver,
                ['is_active' => false],
                ['is_active' => true],
                'Driver reinstated to active roster.'
            );
        });
    }

    protected function audit(string $action, $model, ?array $before, ?array $after, ?string $reason = null): void
    {
        $user = Auth::user();

        AuditLog::create([
            'actor_user_id' => $user?->id,
            'actor_roles_snapshot' => $user ? implode(',', $user->getRoleNames()) : null,
            'action_type' => $action,
            'entity_type' => $model->getMorphClass(),
            'entity_id' => $model->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
