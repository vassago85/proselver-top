<?php

use App\Models\BodyBuilderDealerLink;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Phase 3 -- BB yard app.
 *
 * Behaviour we lock down:
 *   - Yard index only shows vehicles at the BB's own locations.
 *   - Vehicles from unlinked dealers do NOT leak across the tenant.
 *   - Dealer-shared metadata only renders when bb_share_with_body_builder = true.
 *   - BB user setting bb_internal_job_number writes to the stock row.
 *   - Non-BB users 403 on the yard pages.
 */

function makeBbCompany(string $name = 'Test Body Builder'): Company
{
    return Company::create([
        'name' => $name,
        'type' => Company::TYPE_BODY_BUILDER,
    ]);
}

function makeBbUser(Company $bb): User
{
    Role::firstOrCreate(['slug' => 'body_builder_owner'], ['name' => 'BB Owner', 'tier' => 'customer']);

    // Minimum permission set the yard pages reach for, defined here so
    // the test passes regardless of seeder state.
    foreach ([
        'bb_confirm_receipt'  => 'Confirm receipt',
        'bb_request_movement' => 'Raise movement requests',
    ] as $slug => $name) {
        $perm = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => 'bb']);
        Role::where('slug', 'body_builder_owner')->first()->permissions()->syncWithoutDetaching([$perm->id]);
    }

    $u = User::factory()->create();
    $u->assignRole('body_builder_owner');
    $bb->users()->attach($u->id, ['is_primary' => true]);
    return $u;
}

function makeBbLocation(Company $bb, string $name = 'Main Workshop'): Location
{
    return Location::create([
        'company_id'   => $bb->id,
        'type'         => Location::TYPE_BODY_BUILDER,
        'company_name' => $name,
        'address'      => '1 Workshop St',
        'is_active'    => true,
    ]);
}

function makeDealerCompany(string $name = 'Test Dealer'): Company
{
    return Company::factory()->create(['name' => $name, 'type' => Company::TYPE_DEALER]);
}

function linkBbToDealer(Company $bb, Company $dealer): void
{
    BodyBuilderDealerLink::create([
        'dealer_company_id'       => $dealer->id,
        'body_builder_company_id' => $bb->id,
        'is_active'               => true,
    ]);
}

function makeDeliveredJob(Company $dealer, Location $deliveryLoc, string $vin): Job
{
    $creator = User::factory()->create();
    return Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_type'             => 'transport',
        'status'               => Job::STATUS_DELIVERED,
        'company_id'           => $dealer->id,
        'executing_company_id' => $dealer->id,
        'created_by_user_id'   => $creator->id,
        'vin'                  => strtoupper($vin),
        'scheduled_date'       => now()->subDay()->toDateString(),
        'delivery_location_id' => $deliveryLoc->id,
        'delivered_at'         => now()->subHours(2),
        'destination_type'     => 'body_builder',
    ]);
}

test('yard index lists vehicles delivered to the BB and skips other workshops', function () {
    $bb     = makeBbCompany();
    $user   = makeBbUser($bb);
    $loc    = makeBbLocation($bb);
    $dealer = makeDealerCompany();
    linkBbToDealer($bb, $dealer);
    $this->actingAs($user);

    // Vehicle for us.
    $job = makeDeliveredJob($dealer, $loc, 'YARDTEST00001');

    // Vehicle for a DIFFERENT BB -- must not leak across.
    $otherBb  = makeBbCompany('Other BB');
    $otherLoc = makeBbLocation($otherBb, 'Other Workshop');
    makeDeliveredJob($dealer, $otherLoc, 'YARDLEAK00001');

    Volt::test('body-builder.yard.index')
        ->assertSee('YARDTEST00001')
        ->assertDontSee('YARDLEAK00001');
});

test('yard show renders dealer-shared metadata only when the toggle is on', function () {
    $bb     = makeBbCompany();
    $user   = makeBbUser($bb);
    $loc    = makeBbLocation($bb);
    $dealer = makeDealerCompany();
    linkBbToDealer($bb, $dealer);
    $this->actingAs($user);

    $job = makeDeliveredJob($dealer, $loc, 'YARDSHARE0001');

    // Stock with sharing OFF -- the meta should not appear.
    DealerStock::create([
        'dealer_company_id'         => $dealer->id,
        'vin'                       => 'YARDSHARE0001',
        'current_location_type'     => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'       => $loc->id,
        'status'                    => DealerStock::STATUS_AVAILABLE,
        'bb_share_with_body_builder' => false,
        'bb_share_end_customer'     => 'Hidden Customer (Pty) Ltd',
    ]);

    Volt::test('body-builder.yard.show', ['job' => $job])
        ->assertDontSee('Hidden Customer');

    DealerStock::where('vin', 'YARDSHARE0001')->update([
        'bb_share_with_body_builder' => true,
        'bb_share_end_customer'      => 'Visible Customer (Pty) Ltd',
    ]);

    Volt::test('body-builder.yard.show', ['job' => $job])
        ->assertSee('Visible Customer (Pty) Ltd');
});

test('BB user can set the internal job number on a stock row', function () {
    $bb     = makeBbCompany();
    $user   = makeBbUser($bb);
    $loc    = makeBbLocation($bb);
    $dealer = makeDealerCompany();
    linkBbToDealer($bb, $dealer);
    $this->actingAs($user);

    $job = makeDeliveredJob($dealer, $loc, 'YARDNUM00001');

    $stock = DealerStock::create([
        'dealer_company_id'      => $dealer->id,
        'vin'                    => 'YARDNUM00001',
        'current_location_type'  => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'    => $loc->id,
        'status'                 => DealerStock::STATUS_AVAILABLE,
    ]);

    Volt::test('body-builder.yard.show', ['job' => $job])
        ->set('bb_internal_job_number', 'BB-2026-0042')
        ->call('saveBbJobNumber')
        ->assertHasNoErrors();

    expect($stock->fresh()->bb_internal_job_number)->toBe('BB-2026-0042');
});

test('hitting the yard route as a non-BB user is blocked by middleware', function () {
    $bb     = makeBbCompany();
    $loc    = makeBbLocation($bb);
    $dealer = makeDealerCompany();
    linkBbToDealer($bb, $dealer);

    // A user belonging to the dealer (not the BB) tries the yard.
    $dealerUser = User::factory()->create();
    $dealer->users()->attach($dealerUser->id, ['is_primary' => true]);

    // EnsureBodyBuilderAccess middleware is applied to the route
    // group; hitting the URL must redirect / deny.  We don't care
    // which response it is -- only that it's not a 200 page render.
    $response = $this->actingAs($dealerUser)->get(route('body-builder.yard.index'));

    expect($response->status())->not->toBe(200);
});
