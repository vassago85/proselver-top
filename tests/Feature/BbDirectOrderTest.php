<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\BookingService;
use App\Services\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * BB direct-order workflow:
 *
 *   1. BB places a direct order with Proselver.
 *   2. If the VIN matches a dealer's stock ledger, owner_company_id +
 *      requires_owner_approval get stamped on the new Job.
 *   3. The dealer sees the order in /customer/orders and can approve
 *      or reject it.
 *   4. Until approved, the job cannot be planned (TripPlanner blocks).
 *   5. Rand value (PO amounts etc.) is masked from the dealer when
 *      they view the order they don't own commercially.
 */

function bbOrderMakeBb(string $name = 'Test BB'): Company
{
    return Company::create(['name' => $name, 'type' => Company::TYPE_BODY_BUILDER]);
}

function bbOrderMakeDealer(string $name = 'Test Dealer'): Company
{
    return Company::factory()->create(['name' => $name, 'type' => Company::TYPE_DEALER]);
}

function bbOrderMakeBbUser(Company $bb, bool $withPlacePerm = true): User
{
    Role::firstOrCreate(['slug' => 'body_builder_owner'], ['name' => 'BB Owner', 'tier' => 'customer']);
    $role = Role::where('slug', 'body_builder_owner')->first();
    foreach ([
        'bb_confirm_receipt'    => 'Confirm receipt',
        'bb_request_movement'   => 'Request movement',
        'bb_place_direct_order' => 'Place direct order',
        'submit_booking'        => 'Submit booking',
    ] as $slug => $name) {
        $p = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => 'test']);
        if ($withPlacePerm || $slug !== 'bb_place_direct_order') {
            $role->permissions()->syncWithoutDetaching([$p->id]);
        }
    }
    $u = User::factory()->create();
    $u->assignRole('body_builder_owner');
    $bb->users()->attach($u->id, ['is_primary' => true]);
    return $u;
}

function bbOrderMakeDealerUser(Company $dealer): User
{
    Role::firstOrCreate(['slug' => 'stock_controller'], ['name' => 'Stock Controller', 'tier' => 'dealer']);
    $role = Role::where('slug', 'stock_controller')->first();
    foreach ([
        'view_all_bookings'       => 'view',
        'view_own_bookings'       => 'view',
        'view_dealer_stock'       => 'View dealer stock',
        'owner_approve_movement'  => 'Owner approve',
    ] as $slug => $name) {
        $p = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => 'test']);
        $role->permissions()->syncWithoutDetaching([$p->id]);
    }
    $u = User::factory()->create();
    $u->assignRole('stock_controller');
    $dealer->users()->attach($u->id);
    return $u;
}

function bbOrderMakeLocation(Company $owner, string $name = 'Loc'): Location
{
    return Location::create([
        'company_id'   => $owner->id,
        'type'         => Location::TYPE_BODY_BUILDER,
        'company_name' => $name,
        'address'      => '1 Test Rd',
        'is_active'    => true,
    ]);
}

function bbOrderMakeDealerStock(Company $dealer, string $vin = 'BBORDER0001'): DealerStock
{
    return DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => $vin,
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);
}

function bbOrderBookingPayload(Company $bb, User $bbUser, Location $pickup, Location $delivery, VehicleClass $vc, string $vin): array
{
    return [
        'pickup_location_id'   => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'destination_type'     => Job::DESTINATION_DEALER,
        'vehicle_class_id'     => $vc->id,
        'vin'                  => $vin,
        'scheduled_date'       => now()->addDays(2)->toDateString(),
        'company_id'           => $bb->id,
        'created_by_user_id'   => $bbUser->id,
        'executor_type'        => Job::EXECUTOR_PROSELVER,
        'bypass_po_verification' => true,
    ];
}

test('BB direct order auto-stamps owner_company_id when VIN matches dealer stock', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $dealer = bbOrderMakeDealer();
    bbOrderMakeDealerStock($dealer, 'DIRECTORDER001');

    $pickup = bbOrderMakeLocation($bb, 'BB Workshop');
    $delivery = bbOrderMakeLocation($dealer, 'Dealer Yard');
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'DIRECTORDER001')
    );

    expect($job->company_id)->toBe($bb->id);
    expect($job->owner_company_id)->toBe($dealer->id);
    expect($job->requires_owner_approval)->toBeTrue();
    expect($job->owner_approval_status)->toBe(Job::OWNER_APPROVAL_PENDING);
});

test('no owner gate when VIN does not match any dealer stock', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $pickup = bbOrderMakeLocation($bb);
    $delivery = bbOrderMakeLocation($bb, 'BB Other');
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'UNKNOWNVINNEW1')
    );

    expect($job->owner_company_id)->toBeNull();
    expect($job->requires_owner_approval)->toBeFalse();
    expect($job->owner_approval_status)->toBeNull();
});

test('dealer can approve a pending owner movement and the gate flips', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $dealer = bbOrderMakeDealer();
    $dealerUser = bbOrderMakeDealerUser($dealer);
    bbOrderMakeDealerStock($dealer, 'APPROVE0001');

    $pickup = bbOrderMakeLocation($bb);
    $delivery = bbOrderMakeLocation($dealer);
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'APPROVE0001')
    );

    $this->actingAs($dealerUser);
    Volt::test('customer.orders.show', ['job' => $job])
        ->call('openOwnerDecisionPanel')
        ->set('ownerDecisionNotes', 'Fine to move on Friday.')
        ->call('approveAsOwner');

    $job->refresh();
    expect($job->owner_approval_status)->toBe(Job::OWNER_APPROVAL_APPROVED);
    expect($job->owner_approved_by_user_id)->toBe($dealerUser->id);
    expect($job->owner_approved_at)->not->toBeNull();
    expect($job->owner_decision_notes)->toBe('Fine to move on Friday.');
});

test('dealer rejection cancels the job', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $dealer = bbOrderMakeDealer();
    $dealerUser = bbOrderMakeDealerUser($dealer);
    bbOrderMakeDealerStock($dealer, 'REJECT0001');

    $pickup = bbOrderMakeLocation($bb);
    $delivery = bbOrderMakeLocation($dealer);
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'REJECT0001')
    );

    $this->actingAs($dealerUser);
    Volt::test('customer.orders.show', ['job' => $job])
        ->call('openOwnerDecisionPanel')
        ->set('ownerDecisionNotes', 'Vehicle is on demo, not available.')
        ->call('rejectAsOwner');

    $job->refresh();
    expect($job->owner_approval_status)->toBe(Job::OWNER_APPROVAL_REJECTED);
    expect($job->status)->toBe(Job::STATUS_CANCELLED);
    expect($job->cancellation_reason)->toContain('Vehicle is on demo');
});

test('TripPlanner refuses to attach a job that is still pending owner approval', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $dealer = bbOrderMakeDealer();
    bbOrderMakeDealerStock($dealer, 'PLAN0001');

    $pickup = bbOrderMakeLocation($bb);
    $delivery = bbOrderMakeLocation($dealer);
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'PLAN0001')
    );

    $driver = User::factory()->create();
    $trip = Trip::create([
        'company_id'     => $bb->id,
        'driver_user_id' => $driver->id,
        'trip_date'      => now()->addDays(2)->toDateString(),
        'status'         => Trip::STATUS_PLANNED,
    ]);

    $threw = false;
    try {
        app(TripPlanner::class)->attachJob($trip, $job);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('awaiting approval');
    }
    expect($threw)->toBeTrue();
});

test('dealer sees BB direct order in their orders list with an approve badge', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    $dealer = bbOrderMakeDealer();
    $dealerUser = bbOrderMakeDealerUser($dealer);
    bbOrderMakeDealerStock($dealer, 'LISTSEE0001');

    $pickup = bbOrderMakeLocation($bb);
    $delivery = bbOrderMakeLocation($dealer);
    $vc = VehicleClass::create(['name' => 'TestClass']);

    $job = app(BookingService::class)->createTransportBooking(
        bbOrderBookingPayload($bb, $bbUser, $pickup, $delivery, $vc, 'LISTSEE0001')
    );

    $this->actingAs($dealerUser);
    Volt::test('customer.orders.index')
        ->assertSee($job->job_number)
        ->assertSee('approve'); // the per-row badge label
});

test('BB user without bb_place_direct_order permission is 403 on the create page', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb, withPlacePerm: false);

    $this->actingAs($bbUser);
    Volt::test('body-builder.orders.create')
        ->assertStatus(403);
});

test('BB user with permission can render the create page', function () {
    $bb     = bbOrderMakeBb();
    $bbUser = bbOrderMakeBbUser($bb);
    bbOrderMakeLocation($bb);

    $this->actingAs($bbUser);
    Volt::test('body-builder.orders.create')
        ->assertSuccessful();
});
