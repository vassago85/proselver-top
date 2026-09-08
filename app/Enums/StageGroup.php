<?php

namespace App\Enums;

/**
 * Coarse operational buckets for the pipeline board.
 *
 * These are the ONLY groupings the ops dashboard understands. Every
 * JobStatus maps to exactly one StageGroup (or to null, for statuses
 * that don't belong on the live pipeline — pending_verification, the
 * legacy invoiced/approved workflow, cancellations, etc.).
 *
 * Adding a new group here means adding a live tile. Adding a new
 * status but not mapping it to a group means the status is invisible
 * to the ops dashboard — usually the right call for lifecycle states
 * that aren't a physical dispatch stage.
 */
enum StageGroup: string
{
    case Intake     = 'intake';
    case Ready      = 'ready';
    case Dispatched = 'dispatched';
    case OnRoad     = 'on_road';
    case Delivered  = 'delivered';
    case Closed     = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Intake     => 'Intake',
            self::Ready      => 'Ready to dispatch',
            self::Dispatched => 'Dispatched',
            self::OnRoad     => 'On the road',
            self::Delivered  => 'Delivered',
            self::Closed     => 'Closed',
        };
    }

    /**
     * @return list<JobStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            JobStatus::cases(),
            fn (JobStatus $s) => $s->group() === $this,
        ));
    }

    /**
     * Raw status strings for use directly in a whereIn on transport_jobs.
     *
     * @return list<string>
     */
    public function statusValues(): array
    {
        return array_map(fn (JobStatus $s) => $s->value, $this->statuses());
    }

    /**
     * The four groups the live pipeline board shows. Delivered and
     * Closed appear in performance metrics, not the live tiles.
     *
     * @return list<self>
     */
    public static function pipelineGroups(): array
    {
        return [self::Intake, self::Ready, self::Dispatched, self::OnRoad];
    }
}
