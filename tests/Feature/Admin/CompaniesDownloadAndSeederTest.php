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

/*
 * Delete / restore -- super-admin-only soft-delete path for cleaning
 * up duplicate companies from the listing.  The full lifecycle is:
 *
 *   1. super_admin clicks Delete -> row goes to the trashed set.
 *   2. Default list query no longer surfaces it.
 *   3. showDeleted=true flips the view to onlyTrashed().
 *   4. Restore puts the row back on the active board.
 *
 * Plus two safety rails:
 *   - The platform-owner row is never deletable from this surface.
 *   - Non-super-admins cannot call deleteCompany (403 from the policy).
 */
test('a super_admin can soft-delete a company and the row drops off the active list', function () {
    $dup = Company::factory()->create(['name' => 'Hino Eastrand', 'type' => Company::TYPE_DEALER]);

    $this->actingAs(asAdminUser());

    Volt::test('admin.companies.index')
        ->call('deleteCompany', $dup->id);

    expect(Company::find($dup->id))->toBeNull();
    expect(Company::withTrashed()->find($dup->id))->not->toBeNull();
});

test('toggling showDeleted reveals the soft-deleted rows so a super_admin can restore', function () {
    $dup = Company::factory()->create(['name' => 'WILLIAM HUNT THE GLEN', 'type' => Company::TYPE_DEALER]);
    $dup->delete();

    $this->actingAs(asAdminUser());

    [, $body] = captureCsvBody(function ($test) {
        $test->set('showDeleted', true);
    });
    expect($body)->toContain('WILLIAM HUNT THE GLEN');

    Volt::test('admin.companies.index')
        ->set('showDeleted', true)
        ->call('restoreCompany', $dup->id);

    expect(Company::find($dup->id))->not->toBeNull();
});

test('the platform-owner row can never be soft-deleted from this surface', function () {
    $owner = Company::factory()->create([
        'name'              => 'ProSelver Technologies',
        'type'              => Company::TYPE_INTERNAL,
        'is_platform_owner' => true,
    ]);

    $this->actingAs(asAdminUser());

    Volt::test('admin.companies.index')
        ->call('deleteCompany', $owner->id);

    expect(Company::find($owner->id))->not->toBeNull();
});

test('a non-super-admin user is rejected by the policy when trying to delete a company', function () {
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    $opsCtl = User::factory()->create(['is_active' => true]);
    $opsCtl->assignRole('operations_controller');

    $dup = Company::factory()->create(['name' => 'Some Duplicate', 'type' => Company::TYPE_DEALER]);

    $this->actingAs($opsCtl);

    Volt::test('admin.companies.index')
        ->call('deleteCompany', $dup->id)
        ->assertStatus(403);

    expect(Company::find($dup->id))->not->toBeNull();
});

/*
 * Name-collision validation -- the DB has a unique index on the
 * lower-ASCII normalized_name column, so case- and accent-only
 * duplicates would 500 with a SQLSTATE 23505 if we only validate
 * the literal name.  Both the create modal and the edit page have
 * to catch the collision at validation time.
 */
test('the edit page refuses to rename a company to a name that already exists (case-different)', function () {
    Company::factory()->create(['name' => 'Isuzu Motors South Africa (Pty) Ltd', 'type' => Company::TYPE_OEM]);
    $other = Company::factory()->create(['name' => 'Isuzu Motors SA', 'type' => Company::TYPE_OEM]);

    $this->actingAs(asAdminUser());

    Volt::test('admin.companies.show', ['company' => $other])
        ->set('editing', true)
        ->set('name', 'ISUZU MOTORS SOUTH AFRICA (PTY) LTD')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Company::find($other->id)->name)->toBe('Isuzu Motors SA');
});

test('the create modal refuses a name whose normalized form already exists on the platform', function () {
    Company::factory()->create(['name' => 'Isuzu Motors South Africa (Pty) Ltd', 'type' => Company::TYPE_OEM]);

    $this->actingAs(asAdminUser());

    Volt::test('admin.companies.index')
        ->set('newName', 'ISUZU MOTORS SOUTH AFRICA (Pty) Ltd')
        ->set('newType', Company::TYPE_OEM)
        ->call('createCompany')
        ->assertHasErrors(['newName']);

    expect(Company::count())->toBe(1);
});

test('the edit page lets you save with the original name unchanged (does not self-collide)', function () {
    $c = Company::factory()->create(['name' => 'Demo Motors', 'type' => Company::TYPE_DEALER]);

    $this->actingAs(asAdminUser());

    Volt::test('admin.companies.show', ['company' => $c])
        ->set('editing', true)
        ->set('phone', '012 345 6789')
        ->call('save')
        ->assertHasNoErrors();

    expect(Company::find($c->id)->phone)->toBe('012 345 6789');
});

test('the platform-owner cohort (developer / owner) can also soft-delete companies', function () {
    Role::create(['name' => 'Developer', 'slug' => 'developer',  'tier' => 'internal']);
    Role::create(['name' => 'Owner',     'slug' => 'owner',      'tier' => 'internal']);

    $developer = User::factory()->create(['is_active' => true]);
    $developer->assignRole('developer');

    $owner = User::factory()->create(['is_active' => true]);
    $owner->assignRole('owner');

    $dupA = Company::factory()->create(['name' => 'Duplicate A', 'type' => Company::TYPE_DEALER]);
    $dupB = Company::factory()->create(['name' => 'Duplicate B', 'type' => Company::TYPE_DEALER]);

    $this->actingAs($developer);
    Volt::test('admin.companies.index')->call('deleteCompany', $dupA->id);
    expect(Company::find($dupA->id))->toBeNull();
    expect(Company::withTrashed()->find($dupA->id))->not->toBeNull();

    $this->actingAs($owner);
    Volt::test('admin.companies.index')->call('deleteCompany', $dupB->id);
    expect(Company::find($dupB->id))->toBeNull();
    expect(Company::withTrashed()->find($dupB->id))->not->toBeNull();
});
