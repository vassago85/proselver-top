<?php

/**
 * The dwell-time clock lives in `transport_jobs.status_entered_at`,
 * bumped ONLY when the status column changes.
 *
 * This is the root-cause fix for the "six buckets read zero while 21
 * jobs are stuck" bug: the old dashboard aged on updated_at, which
 * moves every time anyone touches the row (invoice capture, note,
 * cost update). If a future refactor accidentally bumps
 * status_entered_at on every save this test fails immediately, and if
 * a future refactor forgets to bump it on a status change it also
 * fails immediately.
 */

use App\Models\Company;
use App\Models\Job;
use App\Models\JobStatusEvent;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function sxJob(string $status, array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_name' => 'Pickup', 'address' => 'Pickup', 'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_name' => 'Delivery', 'address' => 'Delivery', 'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid'                 => (string) Str::uuid(),
        'job_number'           => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'             => Job::TYPE_TRANSPORT,
        'status'               => $status,
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
    ], $overrides));
}

it('sets status_entered_at on creation when the caller does not supply one', function () {
    $before = now();
    $job = sxJob(Job::STATUS_RECEIVED);

    expect($job->status_entered_at)->not->toBeNull();
    expect($job->status_entered_at->timestamp)->toBeGreaterThanOrEqual($before->timestamp - 1);
});

it('preserves an explicitly-supplied status_entered_at on creation', function () {
    // Tests seed exception jobs with old timestamps -- must not be
    // silently overwritten by the observer.
    $backdated = now()->subDays(3);
    $job = sxJob(Job::STATUS_AWAITING_CUSTOMER_CONFIRMATION, [
        'status_entered_at' => $backdated,
    ]);

    expect($job->status_entered_at->timestamp)
        ->toBe($backdated->timestamp);
});

it('does NOT bump status_entered_at on a plain save that leaves status alone', function () {
    $job = sxJob(Job::STATUS_CONFIRMED, ['status_entered_at' => now()->subDays(3)]);
    $original = $job->status_entered_at;

    // Simulate an unrelated update -- a note, a cost adjustment, etc.
    $job->customer_notes = 'ops phoned to confirm';
    $job->save();
    $job->refresh();

    expect($job->status_entered_at->timestamp)->toBe($original->timestamp);
});

it('bumps status_entered_at when the status column moves, regardless of code path', function () {
    // Path 1: transitionTo() (the sanctioned state machine).
    $job = sxJob(Job::STATUS_RECEIVED, ['status_entered_at' => now()->subDays(2)]);
    $job->transitionTo(Job::STATUS_CONFIRMED);
    $job->refresh();
    expect($job->status_entered_at->diffInMinutes(now()))->toBeLessThan(1);

    // Path 2: forceFill/->save() (ops override paths like recall).
    $job2 = sxJob(Job::STATUS_IN_TRANSIT, ['status_entered_at' => now()->subDays(4)]);
    $job2->forceFill(['status' => Job::STATUS_DELIVERED])->save();
    $job2->refresh();
    expect($job2->status_entered_at->diffInMinutes(now()))->toBeLessThan(1);
});

it('writes a job_status_events row on every status change', function () {
    $job = sxJob(Job::STATUS_RECEIVED);
    $job->transitionTo(Job::STATUS_CONFIRMED);
    $job->transitionTo(Job::STATUS_PLANNED);

    $events = JobStatusEvent::where('job_id', $job->id)->orderBy('id')->get();
    expect($events)->toHaveCount(2);

    expect($events[0]->from_status)->toBe(Job::STATUS_RECEIVED);
    expect($events[0]->to_status)->toBe(Job::STATUS_CONFIRMED);

    expect($events[1]->from_status)->toBe(Job::STATUS_CONFIRMED);
    expect($events[1]->to_status)->toBe(Job::STATUS_PLANNED);
});
