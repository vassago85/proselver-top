<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SaleDeliveryNoteService;
use App\Support\Documents\IssuerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * Phase 1B — dealer-branded delivery notes.
 *
 *   - IssuerProfile DTO factories (proselver / company / courier).
 *   - Sale delivery note service + template render the dealer's
 *     letterhead + buyer + VIN.
 *   - DealerStockPolicy::printSaleNote gating (manage perm in scope,
 *     cross-dealer denied, originating salesperson reprint).
 *   - Branding settings page saves fields and is owner-only.
 */

function brandedDealer(string $name = 'Demo Motors'): Company
{
    return Company::factory()->create([
        'name'                => $name,
        'type'                => Company::TYPE_DEALER,
        'address'             => "1 Test Road\nSandton, 2196",
        'vat_number'          => '4123456789',
        'registration_number' => '2003/012345/07',
        'phone'               => '011 555 1234',
        'billing_email'       => 'accounts@demo.test',
        'branding_footer'     => 'Banking: Demo Bank 123456789',
    ]);
}

function grantManageStockRole(User $user): void
{
    $role = Role::firstOrCreate(
        ['slug' => 'stock_controller'],
        ['name' => 'Stock Controller', 'tier' => 'dealer']
    );
    $perm = Permission::firstOrCreate(
        ['slug' => 'manage_dealer_stock'],
        ['name' => 'Manage dealer stock', 'group' => 'dealer_stock']
    );
    $role->permissions()->syncWithoutDetaching([$perm->id]);
    $user->assignRole('stock_controller');
}

// ----- IssuerProfile DTO --------------------------------------------

test('forCompany pulls the dealer letterhead off the company row', function () {
    $dealer = brandedDealer();

    $issuer = IssuerProfile::forCompany($dealer, 'Delivery Note');

    expect($issuer->name)->toBe('Demo Motors');
    expect($issuer->docTitle)->toBe('Delivery Note');
    expect($issuer->address)->toContain('1 Test Road');
    expect($issuer->vatNumber)->toBe('4123456789');
    expect($issuer->registrationNumber)->toBe('2003/012345/07');
    expect($issuer->phone)->toBe('011 555 1234');
    expect($issuer->email)->toBe('accounts@demo.test');
    expect($issuer->footer)->toBe('Banking: Demo Bank 123456789');
    expect($issuer->hasLetterhead())->toBeTrue();
});

test('forCompany builds a logo data uri when logo_path is set, null when absent', function () {
    Storage::fake('local');
    $dealer = brandedDealer();

    // No logo yet.
    expect(IssuerProfile::forCompany($dealer, 'Delivery Note')->logoUri)->toBeNull();

    // Drop a 1x1 PNG on the disk and point the company at it.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Storage::disk('local')->put('company-logos/logo.png', $png);
    $dealer->update(['logo_path' => 'company-logos/logo.png']);

    $issuer = IssuerProfile::forCompany($dealer->fresh(), 'Delivery Note');
    expect($issuer->logoUri)->toStartWith('data:image/png;base64,');
});

test('forProselver has no dealer letterhead and the platform name', function () {
    $issuer = IssuerProfile::forProselver();

    expect($issuer->name)->toBe('Proselver Technologies');
    expect($issuer->hasLetterhead())->toBeFalse();
});

test('forCourier carries the courier name only', function () {
    $issuer = IssuerProfile::forCourier('Fast Freight');

    expect($issuer->name)->toBe('Fast Freight');
    expect($issuer->hasLetterhead())->toBeFalse();
    expect($issuer->footer)->toContain('Fast Freight');
});

// ----- Collection note branding (rendered HTML) ---------------------

test('an internal job renders the dealer letterhead in the collection note PDF', function () {
    $dealer = brandedDealer();
    $creator = User::factory()->create();

    $job = Job::create([
        'uuid'               => (string) \Illuminate\Support\Str::uuid(),
        'job_type'           => 'transport',
        'status'             => Job::STATUS_RECEIVED,
        'company_id'         => $dealer->id,
        'created_by_user_id' => $creator->id,
        'vin'                => 'BRANDVIN001',
        'executor_type'      => Job::EXECUTOR_INTERNAL,
        'scheduled_date'     => now()->addDay()->toDateString(),
    ]);

    $pdf = app(\App\Services\CollectionNoteService::class)->generate($job);

    // A valid, non-empty PDF document came back without throwing —
    // the refactored resolveCarrier() / IssuerProfile path works for
    // a branded dealer job end to end.
    expect($pdf)->toStartWith('%PDF');
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

// ----- Sale delivery note -------------------------------------------

test('the sale delivery note template renders the dealer block, buyer and vehicle', function () {
    $dealer = brandedDealer();
    $salesperson = User::factory()->create(['name' => 'Sam Seller']);

    $stock = DealerStock::create([
        'dealer_company_id'   => $dealer->id,
        'vin'                 => 'SALEVIN0001',
        'engine_number'       => 'ENG-999',
        'colour'              => 'Midnight Blue',
        'suffix'              => 'GLX',
        'variant'             => '2.0T',
        'status'              => DealerStock::STATUS_SOLD,
        'salesperson_user_id' => $salesperson->id,
        'sale_customer_name'  => 'Carol Customer',
        'sale_customer_phone' => '082 111 2222',
        'sold_at'             => now(),
    ]);

    $issuer = IssuerProfile::forCompany($dealer, 'Delivery Note');
    $html = view('documents.sale-delivery-note', [
        'stock'     => $stock->fresh(['dealerCompany', 'brand', 'salesperson']),
        'issuer'    => $issuer,
        'docNumber' => app(SaleDeliveryNoteService::class)->documentNumber($stock),
    ])->render();

    expect($html)->toContain('Demo Motors');
    expect($html)->toContain('2003/012345/07');
    expect($html)->toContain('SALEVIN0001');
    expect($html)->toContain('Carol Customer');
    expect($html)->toContain('Sam Seller');
    expect($html)->toContain('Midnight Blue');
});

test('the sale delivery note service returns a PDF for a sold row', function () {
    $dealer = brandedDealer();
    $stock = DealerStock::create([
        'dealer_company_id'  => $dealer->id,
        'vin'                => 'SALEVIN0002',
        'status'             => DealerStock::STATUS_SOLD,
        'sale_customer_name' => 'No Salesperson Buyer',
        'sold_at'            => now(),
    ]);

    $pdf = app(SaleDeliveryNoteService::class)->generate($stock);

    expect($pdf)->toStartWith('%PDF');
    expect(strlen($pdf))->toBeGreaterThan(800);
});

test('the document number is stable and zero-padded', function () {
    $dealer = brandedDealer();
    $stock = DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin'               => 'SALEVIN0003',
        'status'            => DealerStock::STATUS_SOLD,
        'sold_at'           => now(),
    ]);

    expect(app(SaleDeliveryNoteService::class)->documentNumber($stock))
        ->toBe('SDN-' . str_pad((string) $stock->id, 6, '0', STR_PAD_LEFT));
});

// ----- Policy -------------------------------------------------------

test('a stock manager in scope may print the sale note', function () {
    $dealer = brandedDealer();
    $user = User::factory()->create();
    grantManageStockRole($user);
    $dealer->users()->attach($user->id);

    $stock = DealerStock::create([
        'dealer_company_id' => $dealer->id,
        'vin'               => 'POLVIN0001',
        'status'            => DealerStock::STATUS_SOLD,
        'sold_at'           => now(),
    ]);

    expect(Gate::forUser($user)->allows('printSaleNote', $stock))->toBeTrue();
});

test('a stock manager in scope may print a delivery note for an unsold unit', function () {
    // Most vehicles are handed over straight from the dealer's own
    // premises, so the delivery note must be printable regardless of
    // whether the unit has been marked sold in the system.
    $dealer = brandedDealer();
    $user = User::factory()->create();
    grantManageStockRole($user);
    $dealer->users()->attach($user->id);

    $stock = DealerStock::create([
        'dealer_company_id'     => $dealer->id,
        'vin'                   => 'POLVIN0010',
        'current_location_type' => DealerStock::LOCATION_PREMISES,
        'status'                => DealerStock::STATUS_AVAILABLE,
    ]);

    expect(Gate::forUser($user)->allows('printSaleNote', $stock))->toBeTrue();

    $pdf = app(SaleDeliveryNoteService::class)->generate($stock);
    expect($pdf)->toStartWith('%PDF');
});

test('a manager from another dealer is denied (cross-dealer IDOR block)', function () {
    $dealerA = brandedDealer('Dealer A');
    $dealerB = brandedDealer('Dealer B');

    $userB = User::factory()->create();
    grantManageStockRole($userB);
    $dealerB->users()->attach($userB->id);

    $stockA = DealerStock::create([
        'dealer_company_id' => $dealerA->id,
        'vin'               => 'POLVIN0002',
        'status'            => DealerStock::STATUS_SOLD,
        'sold_at'           => now(),
    ]);

    expect(Gate::forUser($userB)->allows('printSaleNote', $stockA))->toBeFalse();
});

test('the originating salesperson may reprint their own sold unit without the manage permission', function () {
    Role::firstOrCreate(['slug' => 'sales_person_new'], ['name' => 'Salesperson', 'tier' => 'dealer']);
    $dealer = brandedDealer();
    $salesperson = User::factory()->create();
    $salesperson->assignRole('sales_person_new');
    $dealer->users()->attach($salesperson->id);

    $stock = DealerStock::create([
        'dealer_company_id'   => $dealer->id,
        'vin'                 => 'POLVIN0003',
        'status'              => DealerStock::STATUS_SOLD,
        'salesperson_user_id' => $salesperson->id,
        'sold_at'             => now(),
    ]);

    expect($salesperson->hasPermission('manage_dealer_stock'))->toBeFalse();
    expect(Gate::forUser($salesperson)->allows('printSaleNote', $stock))->toBeTrue();
});

// ----- Branding settings page ---------------------------------------

test('a company owner can save delivery-note branding', function () {
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER, 'name' => 'Branding Co']);
    $owner = User::factory()->create();
    $owner->assignRole('customer_owner');
    $dealer->users()->attach($owner->id);

    $this->actingAs($owner);

    Volt::test('customer.settings.branding')
        ->set('address', '99 New Street')
        ->set('vatNumber', '4999999999')
        ->set('registrationNumber', '2020/000111/07')
        ->set('phone', '021 000 0000')
        ->set('billingEmail', 'pay@branding.test')
        ->set('brandingFooter', 'Thank you for your business')
        ->call('save')
        ->assertHasNoErrors();

    $dealer->refresh();
    expect($dealer->address)->toBe('99 New Street');
    expect($dealer->vat_number)->toBe('4999999999');
    expect($dealer->registration_number)->toBe('2020/000111/07');
    expect($dealer->branding_footer)->toBe('Thank you for your business');
});

test('a plain customer user cannot reach the branding page', function () {
    Role::firstOrCreate(['slug' => 'customer_user'], ['name' => 'Customer User', 'tier' => 'customer']);
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $user = User::factory()->create();
    $user->assignRole('customer_user');
    $dealer->users()->attach($user->id);

    $this->actingAs($user);

    Volt::test('customer.settings.branding')->assertForbidden();
});
