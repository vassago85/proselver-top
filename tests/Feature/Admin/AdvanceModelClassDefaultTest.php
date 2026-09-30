<?php

/**
 * Petty cash toll class defaults from another trip of the same model.
 *
 * A dispatch queue is often a run of identical models (four FTS750DWAs
 * in a row). Once one of them has a class — either a vehicle class on
 * the order, or a toll class picked while assigning petty cash — the
 * next order of that model should open with that class already selected.
 * An order that already has its own class is left alone.
 */

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\ModelTollClassHint;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'operations_controller' => 'Ops Controller',
        'driver' => 'Driver',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $slug === 'driver' ? 'driver' : 'internal']);
    }

    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
    ]);
});

function modelClassOps(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    return $u;
}

function modelClassJob(Company $company, User $creator, ?int $vehicleClassId, string $model, array $extra = []): Job
{
    $location = fn (string $name) => Location::withoutEvents(fn () => Location::create([
        'uuid' => (string) Str::uuid(),
        'company_id' => null,
        'company_name' => $name,
        'address' => $name.', Gauteng',
        'is_active' => true,
    ]));

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-MDL-'.Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vehicle_class_id' => $vehicleClassId,
        'model_name' => $model,
        'vin' => 'MDLVIN'.Str::upper(Str::random(6)),
        'pickup_location_id' => $location('Plant '.$model)->id,
        'delivery_location_id' => $location('Dealer '.$model)->id,
        'scheduled_date' => now()->addDay()->toDateString(),
    ], $extra));
}

test('a classless order defaults to the toll class already on the same model', function () {
    $ops = modelClassOps();
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $heavy = VehicleClass::create([
        'name' => 'Extra Heavy', 'code' => 'EH', 'toll_class' => 3, 'is_active' => true,
    ]);

    modelClassJob($company, $ops, $heavy->id, 'FTS750DWA');
    $next = modelClassJob($company, $ops, null, ' fts750dwa ');

    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $next])
        ->call('openAdvancePanel')
        ->assertSet('advanceTollClassOverride', 3)
        ->assertSet('advanceTollClassDefaulted', 3);
});

test('a toll class picked while assigning petty cash becomes the default for the next same model', function () {
    $ops = modelClassOps();
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);

    $first = modelClassJob($company, $ops, null, 'NQR500AMT');
    $next = modelClassJob($company, $ops, null, 'NQR500AMT');

    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $first])
        ->call('openAdvancePanel')
        ->set('advanceTollClassOverride', 4)
        ->set('advanceAccommodation', 200)
        ->set('advanceForceAddressOverride', true)
        ->set('advanceAddressOverrideReason', 'Customer only supplied a GPS pin, no street address exists yet.')
        ->call('saveAdvance')
        ->assertHasNoErrors();

    expect(ModelTollClassHint::classFor('NQR500AMT'))->toBe(4);

    Volt::test('admin.orders.show', ['job' => $next])
        ->call('openAdvancePanel')
        ->assertSet('advanceTollClassOverride', 4)
        ->assertSet('advanceTollClassDefaulted', 4);
});

test('an order that already has its own class does not inherit a different class from the same model', function () {
    $ops = modelClassOps();
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $heavy = VehicleClass::create([
        'name' => 'Extra Heavy', 'code' => 'EH2', 'toll_class' => 3, 'is_active' => true,
    ]);
    $light = VehicleClass::create([
        'name' => 'MCV', 'code' => 'MCV', 'toll_class' => 2, 'is_active' => true,
    ]);

    modelClassJob($company, $ops, $heavy->id, 'FTR850AMT', [
        'advance_toll_class_override' => 4,
    ]);
    $ownClass = modelClassJob($company, $ops, $light->id, 'FTR850AMT');

    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $ownClass])
        ->call('openAdvancePanel')
        ->assertSet('advanceTollClassOverride', null)
        ->assertSet('advanceTollClassDefaulted', null);
});

test('a different model does not pick up the class', function () {
    $ops = modelClassOps();
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $heavy = VehicleClass::create([
        'name' => 'Extra Heavy', 'code' => 'EH3', 'toll_class' => 3, 'is_active' => true,
    ]);

    modelClassJob($company, $ops, $heavy->id, 'FTS750DWA');
    $other = modelClassJob($company, $ops, null, 'FRR550');

    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $other])
        ->call('openAdvancePanel')
        ->assertSet('advanceTollClassOverride', null)
        ->assertSet('advanceTollClassDefaulted', null);
});

test('this orders own toll class override is kept', function () {
    $ops = modelClassOps();
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $heavy = VehicleClass::create([
        'name' => 'Extra Heavy', 'code' => 'EH4', 'toll_class' => 3, 'is_active' => true,
    ]);

    modelClassJob($company, $ops, $heavy->id, 'FSR800');
    $own = modelClassJob($company, $ops, null, 'FSR800', [
        'advance_toll_class_override' => 2,
    ]);

    $this->actingAs($ops);

    Volt::test('admin.orders.show', ['job' => $own])
        ->call('openAdvancePanel')
        ->assertSet('advanceTollClassOverride', 2)
        ->assertSet('advanceTollClassDefaulted', null);
});
