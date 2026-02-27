<?php

use App\Http\Middleware\EnsureDealerAccess;
use App\Http\Middleware\EnsureDriverAccess;
use App\Http\Middleware\EnsureInternalAccess;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Ops Manager', 'slug' => 'ops_manager', 'tier' => 'internal']);
    Role::create(['name' => 'Dispatcher', 'slug' => 'dispatcher', 'tier' => 'internal']);
    Role::create(['name' => 'Accounts', 'slug' => 'accounts', 'tier' => 'internal']);
    Role::create(['name' => 'Dealer Admin', 'slug' => 'dealer_admin', 'tier' => 'dealer']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);
});

test('internal middleware allows internal users', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super_admin');

    $middleware = new EnsureInternalAccess;
    $request = Request::create('/admin/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());

    $response = $middleware->handle($request, fn () => response('ok'));
    expect($response->getContent())->toBe('ok');
});

test('internal middleware blocks dealer users', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('dealer_admin');

    $middleware = new EnsureInternalAccess;
    $request = Request::create('/admin/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $middleware->handle($request, fn () => response('ok'));
});

test('dealer middleware allows dealer users', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('dealer_admin');

    $middleware = new EnsureDealerAccess;
    $request = Request::create('/dealer/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());

    $response = $middleware->handle($request, fn () => response('ok'));
    expect($response->getContent())->toBe('ok');
});

test('dealer middleware blocks internal users', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super_admin');

    $middleware = new EnsureDealerAccess;
    $request = Request::create('/dealer/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $middleware->handle($request, fn () => response('ok'));
});

test('driver middleware blocks non-drivers', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super_admin');

    $middleware = new EnsureDriverAccess;
    $request = Request::create('/driver/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());

    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $middleware->handle($request, fn () => response('ok'));
});

test('user can have multiple roles', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('ops_manager');
    $user->assignRole('dispatcher');

    $user = $user->fresh();
    expect($user->hasRole('ops_manager'))->toBeTrue();
    expect($user->hasRole('dispatcher'))->toBeTrue();
    expect($user->isInternal())->toBeTrue();
    expect($user->isDealer())->toBeFalse();
});

test('hasAnyRole works correctly', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('accounts');

    $user = $user->fresh();
    expect($user->hasAnyRole(['super_admin', 'accounts']))->toBeTrue();
    expect($user->hasAnyRole(['driver', 'dealer_admin']))->toBeFalse();
});

test('syncRoles replaces all roles', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('ops_manager');
    $user->assignRole('dispatcher');

    $user->syncRoles(['accounts']);
    $user = $user->fresh();

    expect($user->hasRole('accounts'))->toBeTrue();
    expect($user->hasRole('ops_manager'))->toBeFalse();
    expect($user->hasRole('dispatcher'))->toBeFalse();
});

test('highestRole returns correct role', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('dispatcher');
    $user->assignRole('accounts');

    expect($user->fresh()->highestRole())->toBe('dispatcher');
});
