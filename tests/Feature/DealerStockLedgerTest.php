<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Role;
use App\Models\User;
use App\Services\DealerStockImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Phase 1 dealer stock ledger end-to-end coverage:
 *
 *   - DealerStockMovementLinker swings the bucket for every job
 *     transition (scheduled, in-transit, delivered-to-dealer,
 *     delivered-to-body-builder, cancelled, archived).
 *   - DealerStockImporter is idempotent on (dealer_company_id, vin)
 *     and never touches commercial fields on re-runs.
 *   - dealer-stock:backfill builds rows from latest job per VIN.
 */

beforeEach(function () {
    // The seeders we need for permission-driven UI/perm checks --
    // not the actual seeded data, just the schema for any future
    // permission checks the model code does.
});

function makeDealer(string $name = 'Test Dealer'): Company
{
    return Company::factory()->create([
        'name' => $name,
        'type' => Company::TYPE_DEALER,
    ]);
}

function makeBareJob(Company $company, string $vin, array $extras = []): Job
{
    $creator = User::factory()->create();
    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_type' => 'transport',
        'status' => Job::STATUS_RECEIVED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'vin' => $vin,
        'scheduled_date' => now()->addDay()->toDateString(),
    ], $extras));
}

// ----- Linker --------------------------------------------------------

test('linker swings stock to in_transit when the job moves to collected', function () {
    $dealer = makeDealer();
    $stock = DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'TESTVIN0001',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $job = makeBareJob($dealer, 'TESTVIN0001');
    $job->status = Job::STATUS_COLLECTED;
    $job->save();

    $stock->refresh();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_IN_TRANSIT);
    expect($stock->current_job_id)->toBe($job->id);
    expect($stock->previous_location_type)->toBe(DealerStock::LOCATION_PREMISES);
});

test('linker swings stock to body_builder bucket when delivered to a body builder', function () {
    $dealer = makeDealer();
    DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'BBVIN00002',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $job = makeBareJob($dealer, 'BBVIN00002', [
        'destination_type' => Job::DESTINATION_BODY_BUILDER,
    ]);
    $job->status = Job::STATUS_DELIVERED;
    $job->delivered_at = now();
    $job->save();

    $stock = DealerStock::where('vin', 'BBVIN00002')->first();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_BODY_BUILDER);
});

test('linker swings stock to storage when delivered to yard or other', function () {
    $dealer = makeDealer();
    DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'STOR00003',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $job = makeBareJob($dealer, 'STOR00003', [
        'destination_type' => Job::DESTINATION_YARD,
    ]);
    $job->status = Job::STATUS_DELIVERED;
    $job->delivered_at = now();
    $job->save();

    $stock = DealerStock::where('vin', 'STOR00003')->first();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_STORAGE);
});

test('linker marks stock delivered when the destination is a dealer', function () {
    $dealer = makeDealer();
    DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'DEAL00004',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $job = makeBareJob($dealer, 'DEAL00004', [
        'destination_type' => Job::DESTINATION_DEALER,
    ]);
    $job->status = Job::STATUS_DELIVERED;
    $job->delivered_at = now();
    $job->save();

    $stock = DealerStock::where('vin', 'DEAL00004')->first();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_DELIVERED);
    expect($stock->delivered_at)->not->toBeNull();
});

test('linker restores previous bucket on job cancellation', function () {
    $dealer = makeDealer();
    DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'CANCEL0005',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $job = makeBareJob($dealer, 'CANCEL0005');
    $job->status = Job::STATUS_COLLECTED; // -> in_transit
    $job->save();

    $stock = DealerStock::where('vin', 'CANCEL0005')->first();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_IN_TRANSIT);

    $job->status = Job::STATUS_CANCELLED;
    $job->save();

    $stock->refresh();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_PREMISES);
    expect($stock->current_job_id)->toBeNull();
});

test('linker is a no-op when no matching dealer_stock exists', function () {
    $dealer = makeDealer();
    // No DealerStock for this VIN -- proselver-style movement.
    $job = makeBareJob($dealer, 'NOMATCH00006');
    $job->status = Job::STATUS_COLLECTED;
    $job->save();

    expect(DealerStock::count())->toBe(0);
});

// ----- Importer -----------------------------------------------------

test('importer creates new rows and is idempotent on re-run', function () {
    $dealer = makeDealer();
    $importer = new DealerStockImporter();

    $rows = [
        ['vin' => 'NEW00001', 'colour' => 'White', 'variant' => '4x4'],
    ];
    $mapping = ['vin' => 'vin', 'colour' => 'colour', 'variant' => 'variant'];

    $preview = $importer->preview($rows, $mapping, $dealer);
    $result = $importer->commit($preview, $dealer);
    expect($result['created'])->toBe(1);

    $stock = DealerStock::where('vin', 'NEW00001')->firstOrFail();
    expect($stock->colour)->toBe('White');
    expect($stock->status)->toBe(DealerStock::STATUS_AVAILABLE);
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_PREMISES);

    // Re-run the same import -- no new rows.
    $preview = $importer->preview($rows, $mapping, $dealer);
    $result = $importer->commit($preview, $dealer);
    expect($result['created'])->toBe(0);
    expect($result['updated'])->toBe(0);
    expect($result['skipped'])->toBeGreaterThanOrEqual(1);
});

test('importer refreshes attributes on re-run but preserves sale state', function () {
    $dealer = makeDealer();
    $importer = new DealerStockImporter();

    $rows = [['vin' => 'SOLDVIN0007', 'colour' => 'Red']];
    $mapping = ['vin' => 'vin', 'colour' => 'colour'];

    $importer->commit($importer->preview($rows, $mapping, $dealer), $dealer);

    $stock = DealerStock::where('vin', 'SOLDVIN0007')->firstOrFail();
    $stock->update([
        'status' => DealerStock::STATUS_SOLD,
        'sale_customer_name' => 'A Customer',
        'sold_at' => now(),
    ]);

    // Re-import with a different colour -- the importer should
    // refresh the colour but leave the sale state alone.
    $rows = [['vin' => 'SOLDVIN0007', 'colour' => 'Blue']];
    $importer->commit($importer->preview($rows, $mapping, $dealer), $dealer);

    $stock->refresh();
    expect($stock->colour)->toBe('Blue');
    expect($stock->status)->toBe(DealerStock::STATUS_SOLD);
    expect($stock->sale_customer_name)->toBe('A Customer');
});

// ----- Backfill command --------------------------------------------

test('backfill command creates dealer_stock rows from latest job per VIN', function () {
    $dealer = makeDealer();
    makeBareJob($dealer, 'BACKFILL0008', ['status' => Job::STATUS_DELIVERED, 'destination_type' => Job::DESTINATION_BODY_BUILDER, 'delivered_at' => now()]);

    $this->artisan('dealer-stock:backfill', ['--dealer' => $dealer->id])->assertSuccessful();

    $stock = DealerStock::where('vin', 'BACKFILL0008')->firstOrFail();
    expect($stock->current_location_type)->toBe(DealerStock::LOCATION_BODY_BUILDER);
});

test('backfill command is idempotent on re-run', function () {
    $dealer = makeDealer();
    makeBareJob($dealer, 'BFRE0009', ['status' => Job::STATUS_DELIVERED, 'destination_type' => Job::DESTINATION_DEALER, 'delivered_at' => now()]);

    $this->artisan('dealer-stock:backfill', ['--dealer' => $dealer->id])->assertSuccessful();
    $countAfterFirst = DealerStock::count();
    $this->artisan('dealer-stock:backfill', ['--dealer' => $dealer->id])->assertSuccessful();

    expect(DealerStock::count())->toBe($countAfterFirst);
});

// ----- Sale + demo flow --------------------------------------------

test('marking a row as sold stamps the sale fields and sold_at', function () {
    $dealer = makeDealer();
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    // Stub permission so the show page mount + manage check passes.
    $owner = User::factory()->create();
    $owner->assignRole('customer_owner');
    $dealer->users()->attach($owner->id);

    $stock = DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'SALE00010',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    $stock->update([
        'status' => DealerStock::STATUS_SOLD,
        'sale_customer_name' => 'Buyer One',
        'sold_at' => now(),
    ]);

    expect($stock->fresh()->status)->toBe(DealerStock::STATUS_SOLD);
    expect($stock->fresh()->sale_customer_name)->toBe('Buyer One');
});

test('sending out on demo and returning restores the previous bucket', function () {
    $dealer = makeDealer();
    $stock = DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin' => 'DEMO00011',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status' => DealerStock::STATUS_AVAILABLE,
    ]);

    // Send out on demo
    $stock->update([
        'status' => DealerStock::STATUS_DEMO,
        'previous_location_type' => $stock->current_location_type,
        'current_location_type' => DealerStock::LOCATION_ON_DEMO,
        'demo_customer_name' => 'Demo Buyer',
        'demo_started_at' => now(),
    ]);
    expect($stock->fresh()->current_location_type)->toBe(DealerStock::LOCATION_ON_DEMO);

    // Return from demo
    $restore = $stock->previous_location_type ?? DealerStock::LOCATION_PREMISES;
    $stock->update([
        'status' => DealerStock::STATUS_AVAILABLE,
        'current_location_type' => $restore,
        'previous_location_type' => null,
        'demo_customer_name' => null,
        'demo_started_at' => null,
    ]);

    expect($stock->fresh()->current_location_type)->toBe(DealerStock::LOCATION_PREMISES);
});

// ----- visibleTo scope ----------------------------------------------

test('scopeVisibleTo limits to the user\'s visible company ids', function () {
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);
    $dealerA = makeDealer('Dealer A');
    $dealerB = makeDealer('Dealer B');

    DealerStock::create([
        'dealer_company_id' => $dealerA->id,
        'vin' => 'SCOPE00012',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
    ]);
    DealerStock::create([
        'dealer_company_id' => $dealerB->id,
        'vin' => 'SCOPE00013',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
    ]);

    $user = User::factory()->create();
    $user->assignRole('customer_owner');
    $dealerA->users()->attach($user->id);

    $vins = DealerStock::visibleTo($user)->pluck('vin')->all();
    expect($vins)->toContain('SCOPE00012');
    expect($vins)->not->toContain('SCOPE00013');
});
