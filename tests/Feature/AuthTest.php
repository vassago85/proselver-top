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

    $this->post('/login', ['identity' => 'testuser', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

test('the identity field also accepts an email address or a phone number', function (string $column, string $value) {
    $user = User::factory()->create([$column => $value, 'is_active' => true]);
    $user->assignRole('super_admin');

    $this->post('/login', ['identity' => $value, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
})->with([
    'email' => ['email', 'driver@example.com'],
    'phone' => ['phone', '0821234567'],
]);

test('inactive user cannot login', function () {
    User::factory()->create(['username' => 'inactive', 'is_active' => false]);

    $this->post('/login', ['identity' => 'inactive', 'password' => 'password'])
        ->assertSessionHasErrors('identity');

    $this->assertGuest();
});

test('registration route does not exist', function () {
    $this->get('/register')->assertStatus(404);
});

test('unauthenticated user is redirected to login', function () {
    $this->get('/admin/dashboard')->assertRedirect('/login');
    $this->get('/customer/dashboard')->assertRedirect('/login');
    $this->get('/driver/dashboard')->assertRedirect('/login');
});
