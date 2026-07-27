<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest gets the marketing landing page with a way to sign in', function () {
    $this->get('/')
        ->assertStatus(200)
        ->assertSee('Sign in')
        ->assertSee(route('login'));
});

test('a signed-in user is sent on to the portal for their role', function () {
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('driver');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('driver.dashboard'));
});
