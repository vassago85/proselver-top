<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner' => 'Owner',
        'developer' => 'Developer',
        'super_admin' => 'Super Admin',
        'operations_controller' => 'Ops Controller',
        'accounts' => 'Accounts',
        'dispatcher' => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function loginHistoryUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);

    return $u;
}

test('a developer can open login history', function () {
    $this->actingAs(loginHistoryUser('developer'))
        ->get(route('admin.login-history'))
        ->assertOk();
});

test('every other internal role cannot open login history', function (string $slug) {
    $this->actingAs(loginHistoryUser($slug))
        ->get(route('admin.login-history'))
        ->assertForbidden();
})->with(['owner', 'super_admin', 'operations_controller', 'accounts', 'dispatcher']);

test('a developer previewing as another role loses login history', function (string $role) {
    $dev = loginHistoryUser('developer');
    session(['dev_role_override' => $role]);

    $this->actingAs($dev)
        ->get(route('admin.login-history'))
        ->assertForbidden();
})->with(['owner', 'operations_controller']);

test('only the developer gets the login history link in the sidebar', function () {
    $this->actingAs(loginHistoryUser('owner'))
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertDontSee(route('admin.login-history'), false);

    $this->actingAs(loginHistoryUser('developer'))
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(route('admin.login-history'), false);
});
