<?php

/**
 * Fuel page deferred-load error state.
 *
 * with() wraps its whole body in try/catch so any fatal upstream of
 * the per-endpoint safely() catches surfaces as an actionable
 * $loadError + Retry button instead of 500ing the deferred XHR and
 * stranding the operator on the placeholder skeleton.
 *
 * These tests exercise the UI wiring: with $loadError set the banner
 * renders and no numeric KPI blows up; retryLoad() clears the flag
 * and re-renders. The outer try/catch itself is defence-in-depth
 * against a class of failures (aggregation math on malformed source
 * payloads, upstream contract drift) that only shows up in
 * production, so we don't try to reproduce a specific TFN fatal
 * here -- the every-page-renders smoke in PageRenderSmokeTest
 * already proves the happy path.
 */

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    // owner is the post-2026-10-01 whitelist role for /admin/fuel.
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'tier' => 'internal']);
});

test('the error banner renders and the KPI strip stays safe when loadError is set', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('owner');

    $component = Volt::actingAs($u)->test('admin.fuel');

    // Simulate a with() catch by flipping the flag the outer try/catch
    // would populate. The banner and Retry control must appear, and
    // the emptyViewData() fallback must have replaced the live payload
    // so no downstream @foreach / number_format sees a null / bad key.
    $component->set('loadError', 'TFN request failed: /api/Vehicles returned HTML');

    // assertSee escapes by default; the banner uses a raw apostrophe
    // in the copy so we assert the un-escaped substring with the
    // second arg turned off, plus the message body (no punctuation)
    // and the Retry action label.
    $component->assertSee("Fuel data didn't load", escape: false);
    $component->assertSee('/api/Vehicles returned HTML');
    $component->assertSee('Retry');
});

test('retryLoad clears the flag so the banner disappears on the next render', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('owner');

    $component = Volt::actingAs($u)->test('admin.fuel');
    $component->set('loadError', 'transient blip');
    $component->assertSet('loadError', 'transient blip');

    $component->call('retryLoad');

    $component->assertSet('loadError', null);
    $component->assertDontSee("Fuel data didn't load", escape: false);
});
