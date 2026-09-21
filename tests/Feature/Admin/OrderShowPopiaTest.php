<?php

/**
 * POPIA masking on the order-show page.
 *
 * The audit flagged that the driver's SA ID, cellphone (twice, in two
 * labels), and the raw job UUID were rendered in plain text on every
 * order page.  The fix routes personal identifiers through
 * App\Support\PopiaMask and drops the UUID footer entirely; these
 * tests lock those UX contracts.
 */

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
});

/**
 * Build a delivered-ish job with a driver whose profile carries a
 * populated SA ID + cellphone, so the order-show template exercises
 * every branch the mask has to defend.
 */
function popiaOrderScenario(array $driverProfile = [], ?string $driverPhone = null): array
{
    $ops = User::factory()->create(['is_active' => true]);
    $ops->assignRole('operations_controller');

    $driver = User::factory()->create([
        'name'      => 'Sipho Driver',
        'is_active' => true,
        'phone'     => $driverPhone,
    ]);
    $driver->assignRole('driver');
    DriverProfile::create(array_merge(
        ['user_id' => $driver->id],
        $driverProfile,
    ));

    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $pickup = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'Plant',
        'address' => 'Plant',
    ]);
    $delivery = Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'Dealer',
        'address' => 'Dealer',
    ]);

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'company_id' => $company->id,
        'created_by_user_id' => $ops->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'registration' => 'ABC123GP',
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->addDay()->toDateString(),
        'driver_user_id' => $driver->id,
    ]);

    return ['ops' => $ops, 'driver' => $driver, 'job' => $job->fresh(['driver.driverProfile'])];
}

test('the order page masks the drivers SA ID and never prints the raw digits', function () {
    ['ops' => $ops, 'job' => $job] = popiaOrderScenario([
        'id_number' => '9001015800089',
    ]);

    $response = $this->actingAs($ops)->get(route('admin.orders.show', $job))->assertOk();

    // Mask must show, raw ID must not — the digits are a POPIA
    // hazard on every render of every job page.
    $response->assertSee('•••••••••0089', escape: false);
    $response->assertDontSee('9001015800089');
});

test('the order page renders a single cellphone row when Phone and Cellphone match', function () {
    // users.phone stored as +27 form, driver_profiles.cellphone stored
    // as 0 form — both must collapse to one masked line, not two.
    ['ops' => $ops, 'job' => $job] = popiaOrderScenario(
        driverProfile: ['cellphone' => '0821234567'],
        driverPhone:   '+27821234567',
    );

    $response = $this->actingAs($ops)->get(route('admin.orders.show', $job))->assertOk();

    // No "Phone" label lingers alongside the "Cellphone" one when
    // they carry the same number.  We assert on the labels because
    // the mask itself is identical for both rows.
    $response->assertSee('Cellphone');
    $response->assertDontSee('<dt class="text-gray-500">Phone</dt>', escape: false);
    $response->assertDontSee('0821234567');
    $response->assertDontSee('+27821234567');
});

test('the order page footer no longer prints the raw UUID', function () {
    ['ops' => $ops, 'job' => $job] = popiaOrderScenario();

    $response = $this->actingAs($ops)->get(route('admin.orders.show', $job))->assertOk();

    // Job number stays as the operational identifier; the UUID is
    // gone from the footer.  We assert on the exact "UUID" label
    // because the uuid string might still appear elsewhere in the
    // page in a URL query string that includes ?job=... — the
    // labelled footer row is the leak we removed.
    $response->assertSee('Job number');
    $response->assertDontSee('<dt class="text-gray-400">UUID</dt>', escape: false);
});
