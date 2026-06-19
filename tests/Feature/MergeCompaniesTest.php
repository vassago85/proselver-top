<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * companies:merge re-points every company foreign key onto the survivor,
 * dedups the company_users pivot (survivor wins), moves the platform-owner
 * flag, and soft-deletes the absorbed row. Modelled on the Proselver /
 * Trident transporter consolidation.
 */

function makeTransporter(string $name, bool $platform = false): Company
{
    return Company::factory()->create([
        'name' => $name,
        'type' => Company::TYPE_TRANSPORTER,
        'is_platform_owner' => $platform,
    ]);
}

test('merge re-points jobs and locations, moves platform flag, soft-deletes absorbed row', function () {
    $trident = makeTransporter('TRIDENT Control & Dispatch Center');
    $proselver = makeTransporter('Proselver Technologies', platform: true);

    $creator = User::factory()->create();

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_type' => 'transport',
        'status' => Job::STATUS_RECEIVED,
        'company_id' => $trident->id,
        'executing_company_id' => $trident->id,
        'created_by_user_id' => $creator->id,
        'vin' => 'MERGEVIN0001',
        'scheduled_date' => now()->addDay()->toDateString(),
    ]);

    $location = Location::create([
        'company_name' => 'Trident Yard',
        'address' => '1 Depot Rd',
        'type' => Location::TYPE_DEALER,
        'company_id' => $trident->id,
    ]);

    $this->artisan('companies:merge', [
        'from' => $trident->id,
        'into' => $proselver->id,
    ])->assertSuccessful();

    expect($job->fresh()->company_id)->toBe($proselver->id);
    expect($job->fresh()->executing_company_id)->toBe($proselver->id);
    expect($location->fresh()->company_id)->toBe($proselver->id);

    // Absorbed row is soft-deleted + deactivated, survivor keeps platform flag.
    expect(Company::find($trident->id))->toBeNull();
    expect(Company::withTrashed()->find($trident->id)->is_active)->toBeFalse();
    expect($proselver->fresh()->is_platform_owner)->toBeTrue();
});

test('merge dedups company_users so a shared user is not duplicated', function () {
    $trident = makeTransporter('Trident');
    $proselver = makeTransporter('Proselver', platform: true);

    $shared = User::factory()->create();
    $tridentOnly = User::factory()->create();

    $shared->companies()->attach([$trident->id, $proselver->id]);
    $tridentOnly->companies()->attach($trident->id);

    $this->artisan('companies:merge', [
        'from' => $trident->id,
        'into' => $proselver->id,
    ])->assertSuccessful();

    // Shared user keeps exactly one pivot row on the survivor (no duplicate).
    expect(DB::table('company_users')->where('user_id', $shared->id)->where('company_id', $proselver->id)->count())->toBe(1);
    expect(DB::table('company_users')->where('user_id', $shared->id)->where('company_id', $trident->id)->count())->toBe(0);

    // Trident-only user is moved over.
    expect(DB::table('company_users')->where('user_id', $tridentOnly->id)->where('company_id', $proselver->id)->count())->toBe(1);
});

test('dry run writes nothing', function () {
    $trident = makeTransporter('Trident');
    $proselver = makeTransporter('Proselver', platform: true);

    $u = User::factory()->create();
    $u->companies()->attach($trident->id);

    $this->artisan('companies:merge', [
        'from' => $trident->id,
        'into' => $proselver->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(Company::find($trident->id))->not->toBeNull();
    expect(DB::table('company_users')->where('user_id', $u->id)->where('company_id', $trident->id)->count())->toBe(1);
});

test('refuses to merge a company into itself', function () {
    $c = makeTransporter('Solo');

    $this->artisan('companies:merge', [
        'from' => $c->id,
        'into' => $c->id,
    ])->assertFailed();
});
