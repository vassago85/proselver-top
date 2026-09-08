<?php

/**
 * Operations dashboard configuration.
 *
 * Thresholds are duplicated on the SystemSetting table as `ops.alert.*`
 * so ops managers can tune them without a deploy. The resolver in
 * app/Domain/Operations/OperationsThresholds.php reads the SystemSetting
 * first and falls back here — meaning the values in this file are the
 * baseline shipped defaults.
 *
 * DO NOT hardcode thresholds inside query classes. Take them from
 * OperationsThresholds::forExceptions() so a runtime override always
 * wins.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Exception thresholds (in hours)
    |--------------------------------------------------------------------------
    |
    | How long a job can sit at each stage before it gets counted as a
    | firing exception. All values are HOURS to avoid the mixed hours /
    | days confusion the old SystemSetting keys carried.
    |
    */
    'thresholds' => [
        // Awaiting customer confirmation (was 2 days).
        'awaiting_confirmation_hours'      => 48,

        // Confirmation issue is age-invariant — every one is an exception.
        // Kept here for symmetry, but a 0 means "fires on first appearance".
        'confirmation_issue_hours'         => 0,

        // Confirmed / planned but no driver assigned.
        'ready_no_driver_hours'            => 24,

        // Driver assigned but vehicle not yet collected.
        'dispatched_not_collected_hours'   => 48,

        // In transit for too long.
        'long_in_transit_hours'            => 72,

        // Delivered but paperwork / POD not closed.
        'pod_pending_hours'                => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | At-risk severity levels
    |--------------------------------------------------------------------------
    |
    | Drives the visual state of the "At risk" hero tile. A count of
    | zero is neutral; anything up to `warning_count` renders as warning;
    | anything above `critical_count` renders as critical.
    |
    */
    'at_risk' => [
        'warning_count'   => 5,
        'critical_count'  => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (in seconds)
    |--------------------------------------------------------------------------
    |
    | Live panels re-query on a 30s poll and cache for the same window so
    | two operators looking at the same page pay for one query. Range-bound
    | panels cache for longer because the underlying data barely moves.
    |
    */
    'cache' => [
        'live_ttl'   => 30,
        'range_ttl'  => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default performance window
    |--------------------------------------------------------------------------
    |
    | Ops is today and this week — the brief is explicit: 30 days is
    | wrong for a control centre. The Performance section defaults to
    | 7 days.
    |
    */
    'default_range_days' => 7,
];
