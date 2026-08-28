<?php

/**
 * Pins the shape our TFN consumers must handle after the 2026-08-28
 * QA probe.  Two rules the fuel screen has to obey:
 *
 *   1. The Balance KPI must render whether the response uses TFN's
 *      real v3 keys (`AccountBalance`, `AccountAvailableBalance`) or
 *      the legacy demo keys (`Balance`, `AvailableCredit`, `CreditLimit`).
 *      A negative `AccountBalance` (sub-account in arrears) is a
 *      real state on QA today, and must not render as a minus-sign
 *      typo -- it re-labels the KPI as "in arrears" and colours red.
 *
 *   2. The pricingBundle() at the top of the fuel Volt page must
 *      accept either the real v3 pricing row (`SupplierName` +
 *      `PriceIncludingGrid`) or the legacy fixture row
 *      (`DepotTitle` + `PricePerLitre`), and the R/L it emits must
 *      be the driver-paid price -- i.e. `PriceIncludingGrid` wins
 *      over `Price` (ex-grid product cost).
 *
 * Both are exercised via /admin/fuel because the aliasing rule lives
 * in the Volt page.
 */

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use App\Services\Tfn\TfnDemoFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'operations_controller'], ['name' => 'Ops Controller', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    // Keep the licence meter deterministic.
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, true, 'boolean');
});

function alignmentUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

test('the Balance KPI reads TFNs real v3 keys and renders a negative balance as in-arrears', function () {
    // Swap in a fixture whose balance() returns ONLY the real v3
    // keys (AccountBalance / AccountAvailableBalance), no legacy
    // aliases, and a negative account balance -- exactly the shape
    // QA is returning today (2026-08-28: -R 41 056.35).
    app()->instance(TfnDemoFixtures::class, new class extends TfnDemoFixtures {
        public function balance(): array
        {
            return [
                'CustomerNumber'          => '501/12623',
                'AccountBalance'          => -41056.35,
                'AccountAvailableBalance' => 108791.65,
            ];
        }
    });

    $accounts = alignmentUser('accounts');
    $response = $this->actingAs($accounts)->get('/admin/fuel');
    $response->assertOk()
        // The absolute value is shown, sign is conveyed by the label.
        ->assertSee('R 41,056.35')
        // Label swaps from "Sub-account balance" to "in arrears".
        ->assertSee('in arrears')
        // Available credit still renders under the tile.
        ->assertSee('R 108,791.65');
});

test('the Balance KPI accepts the legacy demo keys too', function () {
    // Legacy fixture (Balance / AvailableCredit / CreditLimit) --
    // the fallback path in the KPI block.  Positive balance -> the
    // default "Sub-account balance" label + blue chip.
    app()->instance(TfnDemoFixtures::class, new class extends TfnDemoFixtures {
        public function balance(): array
        {
            return [
                'CustomerNumber'  => '10021',
                'Balance'         => 148_732.55,
                'AvailableCredit' => 101_267.45,
                'CreditLimit'     => 250_000.00,
            ];
        }
    });

    $accounts = alignmentUser('accounts');
    $this->actingAs($accounts)
        ->get('/admin/fuel')
        ->assertOk()
        ->assertSee('Sub-account balance')
        ->assertSee('R 148,732.55')
        ->assertSee('Credit limit')
        ->assertSee('R 250,000.00');
});

test('the fuel page renders when TFN pricing rows only carry real v3 keys', function () {
    // Real v3 /api/Pricing rows do NOT carry ProductCode / DepotTitle /
    // PricePerLitre.  The fuel Volt page's pricingBundle() must
    // aliases-then-map SupplierName -> DepotTitle and PriceIncludingGrid
    // -> PricePerLitre so the KPI + "Live pricing" panel keep working.
    app()->instance(TfnDemoFixtures::class, new class extends TfnDemoFixtures {
        public function pricing(): array
        {
            return [
                [
                    'SupplierName'              => 'Agile Fuel Depot',
                    'SupplierNumber'            => '0603',
                    'Price'                     => 4.90,
                    'PriceIncludingGrid'        => 27.90,
                    'VolumeDiscount'            => 0.10,
                    'PromotionalDiscount'       => 0.00,
                    'HasSpecificPricing'        => false,
                    'SpecificPricing'           => [],
                    'CustomerExternalReference' => '',
                    'ParentExternalReference'   => '',
                ],
                [
                    'SupplierName'              => 'Alex Depot',
                    'SupplierNumber'            => '0165',
                    'Price'                     => 0.99,
                    'PriceIncludingGrid'        => 23.99,
                    'VolumeDiscount'            => 0.01,
                    'PromotionalDiscount'       => 0.00,
                    'HasSpecificPricing'        => false,
                    'SpecificPricing'           => [],
                    'CustomerExternalReference' => '',
                    'ParentExternalReference'   => '',
                ],
            ];
        }
    });

    $this->actingAs(alignmentUser('operations_controller'))
        ->get('/admin/fuel')
        ->assertOk()
        // Depot label is derived from SupplierName.
        ->assertSee('Agile Fuel Depot')
        ->assertSee('Alex Depot')
        // R/L is the DRIVER-PAID PriceIncludingGrid, not the ex-grid
        // sub-total.  If the alias order regresses this assertion
        // fails because Price=0.99 would be picked instead.
        ->assertSee('R 27.90')
        ->assertSee('R 23.99');
});
