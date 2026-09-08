<?php

/**
 * StageGroup::thresholdHours() pulls from config/trident.php via a
 * separate config-key map. This test locks the mapping (enum value
 * `ready` -> config key `ready_to_dispatch`) so a rename on one side
 * does not silently detach thresholds from the queue.
 */

use App\Enums\StageGroup;

it('reads the shipped defaults from config/trident.php', function () {
    expect(StageGroup::Intake->thresholdHours())->toBe(12);
    expect(StageGroup::Ready->thresholdHours())->toBe(8);
    expect(StageGroup::Dispatched->thresholdHours())->toBe(12);
    expect(StageGroup::OnRoad->thresholdHours())->toBe(48);
    expect(StageGroup::Delivered->thresholdHours())->toBe(24);
});

it('returns null for Closed since terminal rows never flag', function () {
    expect(StageGroup::Closed->thresholdHours())->toBeNull();
});

it('exposes Delivered as the POD-pending group on the queue', function () {
    expect(StageGroup::queueGroups())->toContain(StageGroup::Delivered);
    expect(StageGroup::queueGroups())->not->toContain(StageGroup::Closed);
});

it('picks up runtime config overrides so ops can tune without a code change later', function () {
    config()->set('trident.stage_thresholds.intake', 4);
    expect(StageGroup::Intake->thresholdHours())->toBe(4);
});
