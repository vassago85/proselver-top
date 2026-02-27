<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);
    Role::create(['name' => 'Dealer Admin', 'slug' => 'dealer_admin', 'tier' => 'dealer']);
});

test('login page loads without registration link', function () {
    $this->get('/login')
        ->assertStatus(200)
        ->assertDontSee('Register')
        ->assertDontSee('Sign up')
        ->assertSee('Sign in');
});

test('user can login with username and password', function () {
    $user = User::factory()->create(['username' => 'testuser', 'is_active' => true]);
    $user->assignRole('super_admin');

    $this->post('/login', ['username' => 'testuser', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

test('inactive user cannot login', function () {
    $user = User::factory()->create(['username' => 'inactive', 'is_active' => false]);

    $this->post('/login', ['username' => 'inactive', 'password' => 'password'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

test('registration route does not exist', function () {
    $this->get('/register')->assertStatus(404);
});

test('unauthenticated user is redirected to login', function () {
    $this->get('/admin/dashboard')->assertRedirect('/login');
    $this->get('/dealer/dashboard')->assertRedirect('/login');
    $this->get('/driver/dashboard')->assertRedirect('/login');
});
