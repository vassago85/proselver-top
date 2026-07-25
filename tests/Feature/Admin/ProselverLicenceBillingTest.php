<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'developer'], ['name' => 'Developer', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
});

function billingOwner(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('owner');

    return $u;
}

function billingDeveloper(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('developer');

    return $u;
}

function billingProselverJob(array $extras = []): Job
{
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_id' => null,
        'company_name' => 'Plant',
        'address' => 'Plant',
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null,
        'company_name' => 'Dealer',
        'address' => 'Dealer',
        'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $oem->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
        'delivered_at' => now(),
    ], $extras));
}

test('billing page is hidden (404) until the enabled setting is flipped on', function () {
    expect(app(ProselverLicenceBilling::class)->isEnabled())->toBeFalse();

    $this->actingAs(billingOwner())
        ->get(route('admin.billing'))
        ->assertNotFound();
});

test('owner and developer can open the billing page once enabled; accounts and super_admin cannot', function () {
    app(ProselverLicenceBilling::class)->setEnabled(true);

    $this->actingAs(billingOwner())
        ->get(route('admin.billing'))
        ->assertOk();

    $this->actingAs(billingDeveloper())
        ->get(route('admin.billing'))
        ->assertOk();

    $accounts = User::factory()->create(['is_active' => true]);
    $accounts->assignRole('accounts');
    $this->actingAs($accounts)
        ->get(route('admin.billing'))
        ->assertForbidden();

    $sa = User::factory()->create(['is_active' => true]);
    $sa->assignRole('super_admin');
    $this->actingAs($sa)
        ->get(route('admin.billing'))
        ->assertForbidden();
});

test('summarise counts only ProSelver-executed delivered/completed jobs at R150 + VAT', function () {
    billingProselverJob(['delivered_at' => now()]);
    billingProselverJob(['delivered_at' => now(), 'status' => Job::STATUS_COMPLETED]);
    billingProselverJob([
        'executor_type' => Job::EXECUTOR_INTERNAL,
        'delivered_at' => now(),
    ]);
    billingProselverJob(['delivered_at' => now()->subMonth()]);
    billingProselverJob([
        'status' => Job::STATUS_CANCELLED,
        'delivered_at' => now(),
    ]);

    $summary = app(ProselverLicenceBilling::class)->summarise(now()->startOfMonth());

    expect($summary['count'])->toBe(2)
        ->and($summary['per_move'])->toBe(150.0)
        ->and($summary['total_excl_vat'])->toBe(300.0)
        ->and($summary['vat'])->toBe(45.0)
        ->and($summary['total_incl_vat'])->toBe(345.0);
});

test('saving the per-move rate updates SystemSetting and recalculates with VAT', function () {
    app(ProselverLicenceBilling::class)->setEnabled(true);
    billingProselverJob(['delivered_at' => now()]);
    billingProselverJob(['delivered_at' => now()]);

    $this->actingAs(billingOwner());

    Volt::test('admin.billing')
        ->set('perMoveFee', '200')
        ->call('saveRates')
        ->assertHasNoErrors();

    expect((float) SystemSetting::get(ProselverLicenceBilling::SETTING_PER_MOVE))->toBe(200.0);

    $summary = app(ProselverLicenceBilling::class)->summarise(now()->startOfMonth());
    expect($summary['total_excl_vat'])->toBe(400.0)
        ->and($summary['vat'])->toBe(60.0)
        ->and($summary['total_incl_vat'])->toBe(460.0);
});

test('invoice copy text includes period, counts, VAT and no Invoice Ninja branding', function () {
    billingProselverJob(['delivered_at' => now()]);

    $billing = app(ProselverLicenceBilling::class);
    $summary = $billing->summarise(now()->startOfMonth());
    $text = $billing->invoiceCopyText($summary);

    expect($text)
        ->toContain('ProSelver platform licence')
        ->toContain('Completed ProSelver movements: 1 × R150.00 (excl. VAT) = R150.00')
        ->toContain('Total excl. VAT: R150.00')
        ->toContain('VAT (15%): R22.50')
        ->toContain('Total incl. VAT: R172.50')
        ->not->toContain('Invoice Ninja')
        ->not->toContain('not VAT registered');
});
