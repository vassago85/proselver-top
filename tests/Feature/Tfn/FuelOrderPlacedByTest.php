<?php

/**
 * When an ops controller places a TFN pre-auth, we record who did it
 * locally (TFN's order payload has no placer field) and surface
 * "Placed by" on the open/closed orders tables.
 */

use App\Models\Role;
use App\Models\TfnFuelOrderPlacement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(
        ['slug' => 'operations_controller'],
        ['name' => 'Ops Controller', 'tier' => 'internal']
    );
});

test('placing a fuel order records the logged-in user as placer', function () {
    Log::spy();

    $user = User::factory()->create([
        'name'      => 'Lize Ops',
        'is_active' => true,
    ]);
    $user->assignRole('operations_controller');

    // Demo fixtures include Isuzu VIN ACVWR75LTG213611 with trade plate
    // TPJHB011 — the form accepts it and runs the demo placeOrder path.
    Volt::actingAs($user)
        ->test('admin.fuel')
        ->set('orderRegistration', 'ACVWR75LTG213611')
        ->set('orderProductCode', 'D0')
        ->set('orderLitres', '180')
        ->set('orderExpiresAt', now()->addDays(4)->format('Y-m-d\TH:i'))
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertSet('orderLitres', '');

    $row = TfnFuelOrderPlacement::query()->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->placed_by_name)->toBe('Lize Ops');
    expect($row->product_code)->toBe('D0');
    expect((float) $row->litres)->toBe(180.0);
    expect($row->vehicle_registration)->toBe('TPJHB011');
    expect($row->order_number)->toStartWith('DEMO/');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'TFN fuel order placed'
            && ($context['placed_by'] ?? null) === 'Lize Ops'
            && ($context['user_id'] ?? null) === $user->id)
        ->once();
});

test('open orders show Placed by from the local placement audit', function () {
    $user = User::factory()->create([
        'name'      => 'Abri Controller',
        'is_active' => true,
    ]);
    $user->assignRole('operations_controller');

    // Fixture open order numbers come from TfnDemoFixtures::orders().
    TfnFuelOrderPlacement::query()->create([
        'order_number'         => 'ORD/01/6454/00056',
        'vehicle_registration' => 'TPJHB011',
        'product_code'         => 'D0',
        'litres'               => 180,
        'customer_reference'   => '26082501',
        'user_id'              => $user->id,
        'placed_by_name'       => 'Abri Controller',
        'placed_at'            => now(),
    ]);

    Volt::actingAs($user)
        ->test('admin.fuel')
        ->assertSee('Placed by')
        ->assertSee('Abri Controller');
});

test('the fuel-order form accepts overnight stay (OS) as an orderable product', function () {
    $user = User::factory()->create([
        'name'      => 'Ops Overnight',
        'is_active' => true,
    ]);
    $user->assignRole('operations_controller');

    Volt::actingAs($user)
        ->test('admin.fuel')
        ->assertSee('OS — Overnight stay')
        ->set('orderRegistration', 'ACVWR75LTG213611')
        ->set('orderProductCode', 'OS')
        ->set('orderLitres', '2')
        ->set('orderExpiresAt', now()->addDays(4)->format('Y-m-d\TH:i'))
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertSet('orderLitres', '');

    $row = TfnFuelOrderPlacement::query()->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->product_code)->toBe('OS');
    expect((float) $row->litres)->toBe(2.0);
});
