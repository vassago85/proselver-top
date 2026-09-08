<?php

/**
 * Every status string that exists in the database — legacy or Phase 1 —
 * must map to a JobStatus enum case. If a new status is added on the
 * Job model without a matching enum entry, StageGroup querying breaks
 * silently (jobs disappear from live tiles). This test catches that.
 */

use App\Enums\JobStatus;
use App\Enums\StageGroup;
use App\Models\Job;

it('every Job::STATUS_* constant maps to a JobStatus enum case', function () {
    $reflection = new ReflectionClass(Job::class);
    $constants = $reflection->getConstants();

    $statusStrings = array_values(array_filter(
        $constants,
        fn ($value, $name) => is_string($name) && str_starts_with($name, 'STATUS_') && is_string($value),
        ARRAY_FILTER_USE_BOTH,
    ));

    foreach ($statusStrings as $value) {
        expect(JobStatus::tryFrom($value))
            ->not->toBeNull("Job::STATUS_* value '{$value}' has no matching JobStatus enum case");
    }
});

it('every StageGroup exposes at least one JobStatus', function () {
    foreach (StageGroup::cases() as $group) {
        expect($group->statuses())
            ->not->toBeEmpty("StageGroup::{$group->name} has no statuses mapped to it");
    }
});

it('every non-legacy JobStatus lands in exactly one StageGroup, or is explicitly excluded', function () {
    // These statuses intentionally have no group: legacy pre-Phase-1
    // states and cancellation, which don't belong on the live pipeline.
    $expectedWithoutGroup = [
        JobStatus::Cancelled,
        JobStatus::Verified,
        JobStatus::Approved,
        JobStatus::Rejected,
        JobStatus::Assigned,
        JobStatus::InProgress,
        JobStatus::ReadyForInvoicing,
        JobStatus::Invoiced,
    ];

    foreach (JobStatus::cases() as $status) {
        if (in_array($status, $expectedWithoutGroup, true)) {
            expect($status->group())->toBeNull("Legacy status {$status->name} unexpectedly has a group");
            continue;
        }

        expect($status->group())->not->toBeNull("Active status {$status->name} must belong to a StageGroup");
    }
});
