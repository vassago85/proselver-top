<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Recall-to-planning behaviour pins:
 *
 *   - From every allowed pre-delivery status the job ends up at
 *     STATUS_CONFIRMED with driver/schedule/collection timestamps cleared.
 *   - URGENT flag survives a recall (priority is preserved through the
 *     reset; only dispatch arrangements are reset).
 *   - Audit log entry is written so disputes about "why is this job
 *     back in the queue" have an answer beyond the last-recall column.
 *   - Policy gates: internal users yes, customers/dealers/drivers no.
 *   - Terminal statuses (DELIVERED / COMPLETED / CANCELLED) are not
 *     recallable -- the model throws and the policy denies.
 */
beforeEach(function () {
    Role::create(['name' => 'Super Admin',  'slug' => 'super_admin',   'tier' => 'internal']);
    Role::create(['name' => 'Dispatcher',   'slug' => 'dispatcher',    'tier' => 'internal']);
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    Role::create(['name' => 'Dealer Admin', 'slug' => 'dealer_admin',  'tier' => 'dealer']);
    Role::create(['name' => 'Driver',       'slug' => 'driver',        'tier' => 'driver']);
});

function recallTestUser(string $slug = 'dispatcher'): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function recallTestJob(string $status, array $overrides = []): Job
{
    $company  = Company::factory()->create(['type' => Company::TYPE_CUSTOMER]);
    $pickup   = Location::create([
        'company_id' => $company->id, 'company_name' => 'Pickup', 'address' => 'Pickup',
        'type' => Location::TYPE_PLANT, 'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => $company->id, 'company_name' => 'Delivery', 'address' => 'Delivery',
        'type' => Location::TYPE_PLANT, 'is_active' => true,
    ]);
    $driver   = User::factory()->create(['is_active' => true]);
    $driver->assignRole('driver');
    $vc       = VehicleClass::firstOrCreate(['name' => 'Truck Class 4']);
    $creator  = User::factory()->create();

    // Fill ALL the timestamp fields that recall is supposed to wipe so
    // we can assert on each one individually being nulled.
    return Job::create(array_merge([
        'uuid'                    => (string) Str::uuid(),
        'job_number'              => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'                => Job::TYPE_TRANSPORT,
        'status'                  => $status,
        'company_id'              => $company->id,
        'created_by_user_id'      => $creator->id,
        'driver_user_id'          => $driver->id,
        'pickup_location_id'      => $pickup->id,
        'delivery_location_id'    => $delivery->id,
        'vehicle_class_id'        => $vc->id,
        'scheduled_date'          => now()->addDay(),
        'scheduled_ready_time'    => now()->addDay(),
        'actual_ready_time'       => now()->addDay(),
        'planned_at'              => now()->subHour(),
        'ready_for_collection_at' => now()->subMinutes(30),
        'collected_at'            => now()->subMinutes(20),
        'in_transit_at'           => now()->subMinutes(10),
    ], $overrides));
}

$recallableStatuses = [
    Job::STATUS_PLANNED,
    Job::STATUS_DRIVER_ASSIGNED,
    Job::STATUS_READY_FOR_COLLECTION,
    Job::STATUS_COLLECTED,
    Job::STATUS_IN_TRANSIT,
];

foreach ($recallableStatuses as $status) {
    it("recalls a job from {$status} back to CONFIRMED, clearing dispatch fields", function () use ($status) {
        $by  = recallTestUser('dispatcher');
        $job = recallTestJob($status);

        $job->recallToPlanning($by, 'Driver unavailable');
        $job->refresh();

        expect($job->status)->toBe(Job::STATUS_CONFIRMED);
        expect($job->driver_user_id)->toBeNull();
        expect($job->scheduled_date)->toBeNull();
        expect($job->scheduled_ready_time)->toBeNull();
        expect($job->actual_ready_time)->toBeNull();
        expect($job->planned_at)->toBeNull();
        expect($job->ready_for_collection_at)->toBeNull();
        expect($job->collected_at)->toBeNull();
        expect($job->in_transit_at)->toBeNull();
        expect($job->recalled_at)->not->toBeNull();
        expect($job->recalled_by_user_id)->toBe($by->id);
        expect($job->recall_reason)->toBe('Driver unavailable');
    });
}

it('writes an audit log row when a job is recalled', function () {
    $by  = recallTestUser('dispatcher');
    $job = recallTestJob(Job::STATUS_DRIVER_ASSIGNED);

    // AuditService reads the actor from Auth::user(); the Volt component
    // is always authenticated when recallToPlanning() fires in practice,
    // so we mirror that here rather than passing $by through twice.
    $this->actingAs($by);
    $job->recallToPlanning($by, 'Vehicle not ready');

    $audit = AuditLog::where('action_type', 'job_recalled')
        ->where('entity_type', 'job')
        ->where('entity_id', $job->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_user_id)->toBe($by->id);
    expect($audit->reason)->toBe('Vehicle not ready');
    expect($audit->before_json['status'] ?? null)->toBe(Job::STATUS_DRIVER_ASSIGNED);
    expect($audit->after_json['status'] ?? null)->toBe(Job::STATUS_CONFIRMED);
});

it('writes a recalled_to_planning job event so the order timeline shows it', function () {
    $by  = recallTestUser('dispatcher');
    $job = recallTestJob(Job::STATUS_IN_TRANSIT);

    $job->recallToPlanning($by, 'Recalled to depot');
    $job->refresh();

    $event = $job->events()->where('event_type', 'recalled_to_planning')->first();
    expect($event)->not->toBeNull();
    expect($event->user_id)->toBe($by->id);
    expect($event->notes)->toBe('Recalled to depot');
});

it('preserves the URGENT flag through a recall', function () {
    $by   = recallTestUser('dispatcher');
    $cust = recallTestUser('customer_owner');
    $job  = recallTestJob(Job::STATUS_DRIVER_ASSIGNED);
    $job->markUrgent($cust, 'Customer collecting tomorrow');

    $job->recallToPlanning($by);
    $job->refresh();

    expect($job->status)->toBe(Job::STATUS_CONFIRMED);
    expect($job->is_urgent)->toBeTrue();
    expect($job->urgent_reason)->toBe('Customer collecting tomorrow');
});

it('refuses to recall a DELIVERED job (model throws)', function () {
    $by  = recallTestUser('dispatcher');
    $job = recallTestJob(Job::STATUS_DELIVERED);

    $job->recallToPlanning($by);
})->throws(RuntimeException::class, 'not recallable');

it('refuses to recall a CANCELLED job', function () {
    $by  = recallTestUser('dispatcher');
    $job = recallTestJob(Job::STATUS_CANCELLED);

    $job->recallToPlanning($by);
})->throws(RuntimeException::class, 'not recallable');

it('policy allows internal users to recall pre-delivery jobs', function () {
    $internal = recallTestUser('dispatcher');
    $job      = recallTestJob(Job::STATUS_DRIVER_ASSIGNED);

    expect($internal->can('recallToPlanning', $job))->toBeTrue();
});

it('policy denies customers, dealers and drivers regardless of status', function () {
    $customer = recallTestUser('customer_owner');
    $dealer   = recallTestUser('dealer_admin');
    $driver   = recallTestUser('driver');
    $job      = recallTestJob(Job::STATUS_DRIVER_ASSIGNED);

    expect($customer->can('recallToPlanning', $job))->toBeFalse();
    expect($dealer->can('recallToPlanning', $job))->toBeFalse();
    expect($driver->can('recallToPlanning', $job))->toBeFalse();
});

it('policy denies recall on terminal statuses even for internal users', function () {
    $internal = recallTestUser('dispatcher');

    foreach ([Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_CANCELLED] as $status) {
        $job = recallTestJob($status);
        expect($internal->can('recallToPlanning', $job))->toBeFalse();
    }
});
