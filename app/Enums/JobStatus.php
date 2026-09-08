<?php

namespace App\Enums;

/**
 * Backed enum wrapper around the string statuses on transport_jobs.status.
 *
 * The DB column stays a plain string (touching every write path to
 * cast the column to enum would be a Phase-2 problem); this enum is
 * the ops-side truth about what each status MEANS — which pipeline
 * stage group it belongs to, whether it is active, and its label.
 *
 * The Ops dashboard queries MUST NOT reference raw status strings
 * outside this enum. If you find yourself typing 'in_transit' in a
 * query builder, use JobStatus::InTransit->value instead so the enum
 * remains the single source of truth.
 */
enum JobStatus: string
{
    // Phase 1 lifecycle — the operational chain
    case PendingVerification         = 'pending_verification';
    case Received                    = 'received';
    case AwaitingCustomerConfirmation = 'awaiting_customer_confirmation';
    case ConfirmationIssue           = 'confirmation_issue';
    case Confirmed                   = 'confirmed';
    case Planned                     = 'planned';
    case DriverAssigned              = 'driver_assigned';
    case ReadyForCollection          = 'ready_for_collection'; // legacy alias for Confirmed collection window
    case Collected                   = 'collected';
    case InTransit                   = 'in_transit';
    case Delivered                   = 'delivered';
    case Completed                   = 'completed';
    case Cancelled                   = 'cancelled';

    // Legacy statuses — kept so every DB value maps somewhere and
    // StageEnumTest stays green. They are excluded from live pipeline
    // buckets because they belong to the pre-Phase-1 invoicing workflow.
    case Verified           = 'verified';
    case Approved           = 'approved';
    case Rejected           = 'rejected';
    case Assigned           = 'assigned';
    case InProgress         = 'in_progress';
    case ReadyForInvoicing  = 'ready_for_invoicing';
    case Invoiced           = 'invoiced';

    /**
     * The pipeline group this status belongs to, or null when the
     * status is not part of the live pipeline (legacy, terminal, or
     * a pre-Phase-1 workflow).
     *
     * The ops brief calls the second group "ready"; on Trident,
     * planned lives here too — a job with a route plan but no driver
     * is still "ready to dispatch", not "dispatched".
     */
    public function group(): ?StageGroup
    {
        return match ($this) {
            self::PendingVerification,
            self::Received,
            self::AwaitingCustomerConfirmation,
            self::ConfirmationIssue          => StageGroup::Intake,

            self::Confirmed,
            self::Planned                    => StageGroup::Ready,

            self::DriverAssigned,
            self::ReadyForCollection         => StageGroup::Dispatched,

            self::Collected,
            self::InTransit                  => StageGroup::OnRoad,

            self::Delivered                  => StageGroup::Delivered,
            self::Completed                  => StageGroup::Closed,

            // Legacy + terminal-cancelled excluded from pipeline.
            self::Cancelled,
            self::Verified,
            self::Approved,
            self::Rejected,
            self::Assigned,
            self::InProgress,
            self::ReadyForInvoicing,
            self::Invoiced                   => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification         => 'Pending Verification',
            self::Received                    => 'Received',
            self::AwaitingCustomerConfirmation => 'Awaiting Confirmation',
            self::ConfirmationIssue           => 'Confirmation Issue',
            self::Confirmed                   => 'Collection Confirmed',
            self::Planned                     => 'Planned',
            self::DriverAssigned              => 'Driver Assigned',
            self::ReadyForCollection          => 'Collection Confirmed',
            self::Collected                   => 'Collected',
            self::InTransit                   => 'In Transit',
            self::Delivered                   => 'Delivered',
            self::Completed                   => 'Completed',
            self::Cancelled                   => 'Cancelled',
            self::Verified                    => 'Verified',
            self::Approved                    => 'Approved',
            self::Rejected                    => 'Rejected',
            self::Assigned                    => 'Assigned',
            self::InProgress                  => 'In Progress',
            self::ReadyForInvoicing           => 'Ready for Invoicing',
            self::Invoiced                    => 'Invoiced',
        };
    }

    /**
     * An "active" status is one that still needs ops attention.
     * Anything terminal — completed, cancelled, invoiced — is not.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Completed,
            self::Cancelled,
            self::Invoiced,
            self::Rejected => false,
            default        => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
