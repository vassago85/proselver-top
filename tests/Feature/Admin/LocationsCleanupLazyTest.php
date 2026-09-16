<?php

use App\Models\Company;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
    config()->set('services.google_maps.api_key', 'test-key');
    // Any Google call in this test file is a bug -- the whole point of
    // openCleanup being lazy is that clicking "Clean up" makes ZERO
    // outbound API calls no matter how many stubs the book contains.
    Http::preventStrayRequests();
});

function cleanupAdminUser(): User
{
    $u = User::factory()->create(['is_active' => true, 'name' => 'Ops Admin']);
    $u->assignRole('super_admin');
    return $u;
}

/**
 * Seed N incomplete-looking locations (blank city / province, no
 * lat-lng).  These are the rows the cleanup flow used to hammer
 * Google for one-by-one on open.
 */
function cleanupSeedStubs(Company $company, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        Location::create([
            'company_id' => $company->id,
            'company_name' => "Stub Plant {$i}",
            'address' => "Stub Plant {$i}",
            'city' => '',
            'province' => '',
            'is_active' => true,
        ]);
    }
}

test('openCleanup(incomplete) does NOT call Google, no matter how many stubs there are', function () {
    $this->actingAs(cleanupAdminUser());
    $company = Company::factory()->create(['name' => 'FAW SA']);
    cleanupSeedStubs($company, 12);

    // If openCleanup did any HTTP work, preventStrayRequests() above
    // would throw here.  This is the whole regression guard.
    Volt::test('admin.settings.locations')
        ->call('openCleanup', 'incomplete')
        ->assertSet('cleanupTab', 'incomplete');

    // Sanity: cleanupIncomplete has all 12 rows seeded with a null
    // suggestions slot -- ready for on-demand look-up but empty.
    $state = Volt::test('admin.settings.locations')
        ->call('openCleanup', 'incomplete')
        ->get('cleanupIncomplete');

    expect($state)->toHaveCount(12);
    foreach ($state as $entry) {
        expect($entry['suggestions'])->toBeNull();
        expect($entry['chosen'])->toBeNull();
    }
});

test('lookupIncomplete fires exactly ONE Google request for the row it targets', function () {
    $this->actingAs(cleanupAdminUser());
    $company = Company::factory()->create(['name' => 'FAW SA']);
    cleanupSeedStubs($company, 3);
    $target = Location::where('company_name', 'Stub Plant 2')->first();

    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => '2 Real Rd, Sandton, 2196, South Africa',
                'geometry' => ['location' => ['lat' => -26.1, 'lng' => 28.05]],
                'address_components' => [
                    ['long_name' => 'Sandton', 'types' => ['locality']],
                    ['long_name' => 'Gauteng', 'types' => ['administrative_area_level_1']],
                    ['short_name' => 'ZA', 'types' => ['country']],
                ],
            ]],
        ], 200),
    ]);

    $component = Volt::test('admin.settings.locations')
        ->call('openCleanup', 'incomplete');

    Http::assertSentCount(0); // opening the panel did NOT call Google

    $component->call('lookupIncomplete', $target->id);

    Http::assertSentCount(1); // one row = one call

    $suggestions = $component->get('cleanupIncomplete')[$target->id]['suggestions'];
    expect($suggestions)->toBeArray()->not->toBeEmpty();
});

test('openCleanup(duplicates) opens the panel without touching Google', function () {
    $this->actingAs(cleanupAdminUser());
    $company = Company::factory()->create(['name' => 'FAW SA']);
    // Two duplicates on same address+name -- the merge service will
    // cluster these into one row.
    Location::create([
        'company_id' => $company->id, 'company_name' => 'Dup Plant',
        'address' => '1 Real Rd', 'city' => 'JHB', 'province' => 'GP',
        'latitude' => -26.1, 'longitude' => 28.05, 'is_active' => true,
    ]);
    Location::create([
        'company_id' => $company->id, 'company_name' => 'Dup Plant',
        'address' => '1 Real Rd', 'city' => 'JHB', 'province' => 'GP',
        'latitude' => -26.1, 'longitude' => 28.05, 'is_active' => true,
    ]);

    // preventStrayRequests() from beforeEach() still active -- if the
    // duplicates tab ever regresses into an API call, this test fails.
    Volt::test('admin.settings.locations')
        ->call('openCleanup', 'duplicates')
        ->assertSet('cleanupTab', 'duplicates');
});

test('openCleanup rejects unknown tabs', function () {
    $this->actingAs(cleanupAdminUser());

    Volt::test('admin.settings.locations')
        ->call('openCleanup', 'trash-can')
        ->assertSet('cleanupTab', null);
});
