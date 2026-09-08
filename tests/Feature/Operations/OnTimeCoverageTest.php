<?php

/**
 * The On-time panel refuses to publish a % until coverage is real.
 *
 * A single value fabricated from a handful of deliveries with SLA
 * targets set is worse than saying "not measurable". This test pins
 * the ≥ COVERAGE_THRESHOLD gate and the fallback resolution order
 * (promised → per-job SLA → per-company SLA → null).
 */

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\OnTimeQuery;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function otJob(array $overrides = [], ?Company $company = null): Job
{
    $company ??= Company::factory()->create();
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
        'status'               => Job::STATUS_DELIVERED,
        'status_entered_at'    => now()->subDay(),
        'company_id'           => $company->id,
        'created_by_user_id'   => $creator->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'collected_at'         => now()->subDay(),
        'delivered_at'         => now(),
    ], $overrides));
}

it('reports an on-time % only when coverage is at least the threshold', function () {
    // 5 delivered, only 3 have any resolvable target — coverage = 60%.
    // Threshold is 80%, so the panel refuses to publish a %.
    for ($i = 0; $i < 2; $i++) {
        otJob(['sla_hours' => 48]);            // measurable
    }
    otJob(['promised_delivery_at' => now()]);  // measurable
    otJob();                                   // no target — not measurable
    otJob();                                   // no target — not measurable

    $data = (new OnTimeQuery())->get(new OperationsFilters());

    expect($data['delivered'])->toBe(5);
    expect($data['measurable'])->toBe(3);
    expect($data['coverage_pct'])->toBe(60);
    expect($data['is_measurable'])->toBeFalse();
    expect($data['on_time_pct'])->toBeNull();
    expect($data['missing_target'])->toBe(2);
});

it('publishes an on-time % when coverage meets the threshold', function () {
    // 8 of 10 have targets — coverage = 80%, right on threshold.
    // 6 delivered on time, 2 late.
    for ($i = 0; $i < 6; $i++) {
        otJob(['sla_hours' => 48]);
        // collected 24h ago, delivered now → 24 <= 48 → on-time
    }
    for ($i = 0; $i < 2; $i++) {
        otJob([
            'sla_hours'    => 1,             // 1h SLA
            'collected_at' => now()->subHours(5),
            'delivered_at' => now(),         // 5h vs 1h → late
        ]);
    }
    otJob(); // no target
    otJob(); // no target

    $data = (new OnTimeQuery())->get(new OperationsFilters());

    expect($data['delivered'])->toBe(10);
    expect($data['measurable'])->toBe(8);
    expect($data['coverage_pct'])->toBe(80);
    expect($data['is_measurable'])->toBeTrue();
    expect($data['on_time'])->toBe(6);
    expect($data['late'])->toBe(2);
    expect($data['on_time_pct'])->toBe(75);
});

it('uses the resolution order promised → per-job SLA → per-company SLA', function () {
    $customer = Company::factory()->create(['default_sla_hours' => 96]);

    // Explicit promise wins even when per-job / company SLA disagree.
    otJob([
        'promised_delivery_at' => now()->addHour(),   // future = on-time
        'sla_hours'            => 1,                  // per-job says late
    ], $customer);

    // No promised, per-job SLA wins over company default.
    otJob([
        'sla_hours' => 48,                            // 24h dwell, 48h SLA → on-time
    ], $customer);

    // No promised, no per-job SLA → company default fires.
    otJob([], $customer);

    // No sources at all — customer has no default either.
    $orphan = Company::factory()->create(['default_sla_hours' => null]);
    otJob([], $orphan);

    $data = (new OnTimeQuery())->get(new OperationsFilters());

    expect($data['delivered'])->toBe(4);
    expect($data['measurable'])->toBe(3);
    expect($data['on_time'])->toBe(3);
    expect($data['missing_target'])->toBe(1);
});
