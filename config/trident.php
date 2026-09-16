<?php

/**
 * Trident-wide operational tuning.
 *
 * One file, one place to argue about numbers. The ops dashboard reads
 * these to decide when a job in a given stage becomes "overdue" and
 * gets flagged on the queue.
 *
 * Values are hardcoded on purpose; if you ever want to derive them
 * from trailing-30-day p90 dwell per stage that is a follow-up, not a
 * prerequisite. Start with the tuned numbers below.
 *
 * The keys must match StageGroup::thresholdHours()'s config key
 * mapping. Anything not covered here means "never overdue" and rows
 * in that stage will render neutrally in the queue.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Stage thresholds (in hours)
    |--------------------------------------------------------------------------
    |
    | Hours in stage before a job appears as overdue on the ops queue.
    | Tuned to observed dwell: intake p90 ~19h, ready-to-dispatch p90
    | ~7h — this business moves in hours, not days.
    |
    */
    'stage_thresholds' => [
        'intake'            => 12,
        'ready_to_dispatch' => 8,
        'dispatched'        => 12,
        'on_the_road'       => 48,
        'pod_pending'       => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Percentile suppression
    |--------------------------------------------------------------------------
    |
    | Below this sample size the dwell card shows the raw count instead
    | of p50/p90 — "1 job in stage" is honest, "p50 7h · p90 7h · n=1"
    | is one job dressed as a statistic.
    |
    */
    'min_sample_for_percentiles' => 5,

    /*
    |--------------------------------------------------------------------------
    | Stale-action login gate (ops)
    |--------------------------------------------------------------------------
    |
    | The ops dashboard blocks landing when any active queue job has sat
    | in the same stage without a status change for `stale_action_days`.
    | Jobs the user created must be actioned (deep-link Cancel/Deliver
    | on the order page, or "Wait longer" with a comment) before the
    | modal can be dismissed; jobs created by someone else are visible
    | but never block dismiss.
    |
    | "Wait longer" writes a snooze that suppresses the row for a further
    | `stale_action_snooze_days` — the row re-appears if the job is still
    | in the same stage when the snooze expires.
    |
    */
    'stale_action_days'         => 7,
    'stale_action_snooze_days'  => 7,
];
