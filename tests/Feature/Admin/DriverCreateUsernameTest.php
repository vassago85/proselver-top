<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

test('adding a driver whose username matches a removed account shows an error', function () {
    $existing = User::factory()->create(['username' => 'dlamini']);
    $existing->delete();

    Volt::test('admin.drivers.create')
        ->assertSee('Two drivers can share a full name')
        ->assertSee('dlamini2')
        ->set('name', 'DAVID')
        ->set('username', 'Dlamini')
        ->set('email', 'new.driver@example.com')
        ->set('phone', '0735789325')
        ->set('generatePassword', false)
        ->set('password', 'password12')
        ->call('save')
        ->assertHasErrors(['username']);

    expect(User::withTrashed()->whereRaw('lower(username) = ?', ['dlamini'])->count())->toBe(1);
});
