<?php

use App\Livewire\Admin\PodUploadHint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    Role::create(['name' => 'Customer', 'slug' => 'customer_owner', 'tier' => 'customer']);
});

test('an internal user sees the POD upload hint once', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('operations_controller');

    Livewire::actingAs($user)
        ->test(PodUploadHint::class)
        ->assertSee('You can upload PODs now')
        ->assertSee('Documents')
        ->assertSee('Upload a POD')
        ->call('dismiss')
        ->assertDontSee('You can upload PODs now');

    expect($user->fresh()->hasDismissedHint(PodUploadHint::HINT))->toBeTrue();

    Livewire::actingAs($user->fresh())
        ->test(PodUploadHint::class)
        ->assertSet('show', false)
        ->assertDontSee('You can upload PODs now');
});

test('opening Documents from the hint dismisses it', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('operations_controller');

    Livewire::actingAs($user)
        ->test(PodUploadHint::class)
        ->call('goToDocuments')
        ->assertRedirect(route('admin.documents.index'));

    expect($user->fresh()->hasDismissedHint(PodUploadHint::HINT))->toBeTrue();
});

test('a customer does not see the POD upload hint', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('customer_owner');

    Livewire::actingAs($user)
        ->test(PodUploadHint::class)
        ->assertSet('show', false)
        ->assertDontSee('You can upload PODs now');
});
