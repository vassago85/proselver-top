<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProselverCustomersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Volt\Volt;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

/*
 * Covers two adjacent features of the Admin > Companies screen:
 *
 *   1. The "Download CSV" button (downloadCsv() on the Volt
 *      component) -- streams a UTF-8 BOM'd CSV with the same
 *      filters applied as the on-screen list.
 *
 *   2. The ProselverCustomersSeeder which back-fills missing
 *      customers from the 2026 Customer Listing Report.  Must be
 *      idempotent on normalized_name -- production will re-seed
 *      on every release.
 */

beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
});

function asAdminUser(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');
    return $u;
}

/**
 * Helper: bypass Livewire's call mechanism (which serialises the
 * return value through JsonResponse) and capture the real
 * StreamedResponse the Volt component returns from downloadCsv().
 */
function captureCsvBody(\Closure $setUp = null): array
{
    $test = Volt::test('admin.companies.index');
    if ($setUp) {
        $setUp($test);
    }
    /** @var StreamedResponse $response */
    $response = $test->instance()->downloadCsv();

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    return [$response, $body];
}

test('downloadCsv streams a UTF-8 BOM CSV with the same rows the list shows', function () {
    Company::factory()->create(['name' => 'Demo Motors',    'type' => Company::TYPE_DEALER, 'phone' => '011 123 4567']);
    Company::factory()->create(['name' => 'Acme OEM',       'type' => Company::TYPE_OEM,    'phone' => '012 999 0000']);
    Company::factory()->create(['name' => 'Bobs Bodies',    'type' => Company::TYPE_BODY_BUILDER]);

    $this->actingAs(asAdminUser());

    [$response, $body] = captureCsvBody();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->headers->get('Content-Disposition'))->toContain('proselver-customers-');

    // UTF-8 BOM so Excel opens the file in the right encoding.
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");

    expect($body)->toContain('Name,Group,Type,Workflow,Phone');
    expect($body)->toContain('Demo Motors');
    expect($body)->toContain('Acme OEM');
    expect($body)->toContain('Bobs Bodies');
    expect($body)->toContain('Body Builder');
});

test('downloadCsv honours the active type filter so the file mirrors the screen', function () {
    Company::factory()->create(['name' => 'Demo Motors', 'type' => Company::TYPE_DEALER]);
    Company::factory()->create(['name' => 'Acme OEM',    'type' => Company::TYPE_OEM]);

    $this->actingAs(asAdminUser());

    [, $body] = captureCsvBody(function ($test) {
        $test->set('typeFilter', Company::TYPE_OEM);
    });

    expect($body)->toContain('Acme OEM');
    expect($body)->not->toContain('Demo Motors');
});

test('ProselverCustomersSeeder back-fills the customer list and is idempotent on re-run', function () {
    Company::factory()->create(['name' => 'Williams Hunt Midrand', 'type' => Company::TYPE_DEALER]);

    Artisan::call('db:seed', ['--class' => ProselverCustomersSeeder::class, '--force' => true]);

    $countAfterFirst = Company::count();
    expect($countAfterFirst)->toBeGreaterThan(50);
    expect(Company::where('normalized_name', 'williams hunt midrand')->count())->toBe(1);

    $isuzu = Company::where('normalized_name', 'isuzu motors south africa (pty) ltd')->first();
    expect($isuzu)->not->toBeNull();
    expect($isuzu->type)->toBe(Company::TYPE_OEM);
    expect($isuzu->is_active)->toBeTrue();

    $bb = Company::where('normalized_name', 'uni-spec bodies (pty) ltd')->first();
    expect($bb)->not->toBeNull();
    expect($bb->type)->toBe(Company::TYPE_BODY_BUILDER);

    $fawDealer = Company::where('normalized_name', 'bidvest mccarthy faw germiston')->first();
    expect($fawDealer)->not->toBeNull();
    expect($fawDealer->workflow_type)->toBe('faw');
    expect($fawDealer->phone)->toBe('011 437 2380');

    Artisan::call('db:seed', ['--class' => ProselverCustomersSeeder::class, '--force' => true]);
    expect(Company::count())->toBe($countAfterFirst);
});

test('ProselverCustomersSeeder maps OEM/Dealer to OEM and Group/Dealer to Dealer', function () {
    Artisan::call('db:seed', ['--class' => ProselverCustomersSeeder::class, '--force' => true]);

    $fawIsando = Company::where('normalized_name', 'faw trucks south africa isando showroom')->first();
    expect($fawIsando)->not->toBeNull();
    expect($fawIsando->type)->toBe(Company::TYPE_OEM);

    $cfao = Company::where('normalized_name', 'cfao mobility')->first();
    expect($cfao)->not->toBeNull();
    expect($cfao->type)->toBe(Company::TYPE_DEALER);
});
