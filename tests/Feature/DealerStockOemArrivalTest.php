<?php

use App\Models\BodyBuilderDealerLink;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use App\Services\DealerStockAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * OEM-direct arrival behaviour the BB yard workflow depends on:
 *
 *   - recordOemArrival creates a dealer_stock row with NULL dealer
 *     and the chosen OEM, at the BB's workshop location.
 *   - Re-recording the same VIN at the same BB is idempotent (no
 *     duplicate row, the existing one updates).
 *   - assignToDealer stamps dealer_company_id, refuses if already
 *     assigned, and auto-links the BB to the dealer.
 *   - The DealerStockMovementLinker claims unassigned rows when a
 *     job arrives matching the VIN with a dealer attached.
 */

function bbCompany(string $name = 'OEM Test BB'): Company
{
    return Company::create(['name' => $name, 'type' => Company::TYPE_BODY_BUILDER]);
}

function bbLocation(Company $bb, string $name = 'Workshop A'): Location
{
    return Location::create([
        'company_id'   => $bb->id,
        'type'         => Location::TYPE_BODY_BUILDER,
        'company_name' => $name,
        'address'      => '1 Workshop St',
        'is_active'    => true,
    ]);
}

function dealerCompany(string $name = 'Test Dealer'): Company
{
    return Company::factory()->create(['name' => $name, 'type' => Company::TYPE_DEALER]);
}

function oemCompany(string $name = 'Test OEM'): Company
{
    return Company::create(['name' => $name, 'type' => Company::TYPE_OEM]);
}

test('recordOemArrival creates an unassigned dealer_stock row at the BB workshop', function () {
    $bb    = bbCompany();
    $loc   = bbLocation($bb);
    $oem   = oemCompany('Isuzu Motors SA');
    $user  = User::factory()->create();

    $stock = app(DealerStockAssignmentService::class)
        ->recordOemArrival($bb, $loc, $user, [
            'vin'            => 'oemtestvin0001',
            'oem_company_id' => $oem->id,
            'model_name'     => 'NPS 300',
            'colour'         => 'White',
        ]);

    expect($stock->dealer_company_id)->toBeNull();
    expect($stock->oem_company_id)->toBe($oem->id);
    expect($stock->vin)->toBe('OEMTESTVIN0001'); // upper-cased
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_BODY_BUILDER);
    expect($stock->current_location_id)->toBe($loc->id);
    expect($stock->isUnassigned())->toBeTrue();
});

test('recording the same VIN twice at the same BB returns the same row', function () {
    $bb   = bbCompany();
    $loc  = bbLocation($bb);
    $user = User::factory()->create();

    $svc = app(DealerStockAssignmentService::class);

    $first  = $svc->recordOemArrival($bb, $loc, $user, ['vin' => 'IDEMP00001']);
    $second = $svc->recordOemArrival($bb, $loc, $user, ['vin' => 'IDEMP00001', 'model_name' => 'Updated']);

    expect($second->id)->toBe($first->id);
    expect(DealerStock::where('vin', 'IDEMP00001')->count())->toBe(1);
    expect($second->fresh()->model_name)->toBe('Updated');
});

test('an arrival for a VIN already on a dealers books updates the existing row instead of creating a new one', function () {
    $bb     = bbCompany();
    $loc    = bbLocation($bb);
    $dealer = dealerCompany();
    $user   = User::factory()->create();

    $existing = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'EXIST00001',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    $stock = app(DealerStockAssignmentService::class)
        ->recordOemArrival($bb, $loc, $user, ['vin' => 'EXIST00001']);

    expect($stock->id)->toBe($existing->id);
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_BODY_BUILDER);
    expect($stock->current_location_id)->toBe($loc->id);
    expect(DealerStock::where('vin', 'EXIST00001')->count())->toBe(1);
});

test('assignToDealer stamps the dealer, audits, and auto-links the BB and dealer', function () {
    $bb     = bbCompany();
    $loc    = bbLocation($bb);
    $dealer = dealerCompany();
    $user   = User::factory()->create();

    $svc = app(DealerStockAssignmentService::class);

    $stock = $svc->recordOemArrival($bb, $loc, $user, [
        'vin' => 'ASSIGN00001',
    ]);

    $stock = $svc->assignToDealer($stock, $dealer, $user);

    expect($stock->dealer_company_id)->toBe($dealer->id);
    expect($stock->isUnassigned())->toBeFalse();

    expect(BodyBuilderDealerLink::where('dealer_company_id', $dealer->id)
        ->where('body_builder_company_id', $bb->id)
        ->where('is_active', true)
        ->exists())->toBeTrue();
});

test('assignToDealer refuses to reassign an already-assigned row', function () {
    $bb     = bbCompany();
    $loc    = bbLocation($bb);
    $dealer = dealerCompany();
    $other  = dealerCompany('Other Dealer');
    $user   = User::factory()->create();

    $svc = app(DealerStockAssignmentService::class);
    $stock = $svc->recordOemArrival($bb, $loc, $user, ['vin' => 'NOSWAP0001']);
    $svc->assignToDealer($stock, $dealer, $user);

    $svc->assignToDealer($stock->fresh(), $other, $user);
})->throws(RuntimeException::class);

test('linker claims an unassigned VIN row when a job for the same VIN arrives with a dealer', function () {
    $bb     = bbCompany();
    $loc    = bbLocation($bb);
    $dealer = dealerCompany();
    $user   = User::factory()->create();

    // BB records the arrival before the dealer is known.
    $stock = app(DealerStockAssignmentService::class)
        ->recordOemArrival($bb, $loc, $user, ['vin' => 'CLAIMER0001']);

    expect($stock->dealer_company_id)->toBeNull();

    // Now a transport_jobs row is created with the same VIN and a
    // dealer attached -- this is the moment the linker should claim
    // the unassigned row.
    $creator = User::factory()->create();
    Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_type'             => 'transport',
        'status'               => Job::STATUS_PLANNED,
        'company_id'           => $dealer->id,
        'executing_company_id' => $dealer->id,
        'created_by_user_id'   => $creator->id,
        'vin'                  => 'CLAIMER0001',
        'scheduled_date'       => now()->addDay()->toDateString(),
    ]);

    expect($stock->fresh()->dealer_company_id)->toBe($dealer->id);
});

test('linker does NOT claim a VIN already held by a different dealer', function () {
    $bb       = bbCompany();
    $loc      = bbLocation($bb);
    $dealerA  = dealerCompany('Dealer A');
    $dealerB  = dealerCompany('Dealer B');
    $user     = User::factory()->create();

    // Dealer A already owns CONFLICT0001.
    DealerStock::create([
        'dealer_company_id'     => $dealerA->id,
        'vin'                   => 'CONFLICT0001',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    // BB recorded an unassigned arrival with the same VIN (data-entry
    // error somewhere upstream).
    $unassigned = DealerStock::create([
        'dealer_company_id'     => null,
        'vin'                   => 'CONFLICT0001',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $loc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    // Dealer B tries to lift it with a transport job.  The linker
    // must refuse to claim because dealer A already owns the VIN.
    $creator = User::factory()->create();
    Job::create([
        'uuid'                 => (string) Str::uuid(),
        'job_type'             => 'transport',
        'status'               => Job::STATUS_PLANNED,
        'company_id'           => $dealerB->id,
        'executing_company_id' => $dealerB->id,
        'created_by_user_id'   => $creator->id,
        'vin'                  => 'CONFLICT0001',
        'scheduled_date'       => now()->addDay()->toDateString(),
    ]);

    expect($unassigned->fresh()->dealer_company_id)->toBeNull();
});

test('unassigned scope returns only NULL-dealer rows', function () {
    $bb     = bbCompany();
    $loc    = bbLocation($bb);
    $dealer = dealerCompany();

    DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'SCOPE00001',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);
    DealerStock::create([
        'dealer_company_id'     => null,
        'vin'                   => 'SCOPE00002',
        'current_location_type' => DealerStock::LOCATION_BODY_BUILDER,
        'current_location_id'   => $loc->id,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    $unassigned = DealerStock::unassigned()->get();
    expect($unassigned->pluck('vin')->all())->toBe(['SCOPE00002']);
});
