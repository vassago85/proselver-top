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

test('owner and developer can open the billing page; accounts and super_admin cannot', function () {
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

test('summarise counts only ProSelver-executed delivered/completed jobs in the month', function () {
    billingProselverJob(['delivered_at' => now()]);
    billingProselverJob(['delivered_at' => now(), 'status' => Job::STATUS_COMPLETED]);
    // Wrong executor
    billingProselverJob([
        'executor_type' => Job::EXECUTOR_INTERNAL,
        'delivered_at' => now(),
    ]);
    // Wrong month
    billingProselverJob(['delivered_at' => now()->subMonth()]);
    // Cancelled — not billable even if delivered_at set
    billingProselverJob([
        'status' => Job::STATUS_CANCELLED,
        'delivered_at' => now(),
    ]);

    $summary = app(ProselverLicenceBilling::class)->summarise(now()->startOfMonth());

    expect($summary['count'])->toBe(2)
        ->and($summary['base'])->toBe(3500.0)
        ->and($summary['per_move'])->toBe(50.0)
        ->and($summary['moves_subtotal'])->toBe(100.0)
        ->and($summary['total_excl_vat'])->toBe(3600.0)
        ->and($summary['vat'])->toBe(540.0)
        ->and($summary['total_incl_vat'])->toBe(4140.0);
});

test('saving rates updates SystemSetting and recalculates the bill', function () {
    billingProselverJob(['delivered_at' => now()]);
    billingProselverJob(['delivered_at' => now()]);

    $this->actingAs(billingOwner());

    Volt::test('admin.billing')
        ->set('baseFee', '4000')
        ->set('perMoveFee', '75')
        ->call('saveRates')
        ->assertHasNoErrors();

    expect((float) SystemSetting::get(ProselverLicenceBilling::SETTING_BASE))->toBe(4000.0)
        ->and((float) SystemSetting::get(ProselverLicenceBilling::SETTING_PER_MOVE))->toBe(75.0);

    $summary = app(ProselverLicenceBilling::class)->summarise(now()->startOfMonth());
    expect($summary['total_excl_vat'])->toBe(4150.0); // 4000 + 2×75
});

test('Invoice Ninja copy text includes period, counts and totals', function () {
    billingProselverJob(['delivered_at' => now()]);

    $billing = app(ProselverLicenceBilling::class);
    $summary = $billing->summarise(now()->startOfMonth());
    $text = $billing->invoiceNinjaText($summary);

    expect($text)
        ->toContain('ProSelver platform licence')
        ->toContain('Completed ProSelver movements: 1 × R50.00')
        ->toContain('Total excl. VAT: R3,550.00')
        ->toContain('Total incl. VAT:');
});
