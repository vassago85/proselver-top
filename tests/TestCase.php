<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable Livewire's #[Lazy] deferral inside the test suite so
        // full-page GET assertions (e.g. assertSee('TPJHB011') on
        // /admin/fuel) see the fully-rendered component instead of the
        // placeholder skeleton.  In production those pages render the
        // skeleton first and hydrate via a follow-up XHR -- but in
        // tests we want the synchronous, all-in-one render so existing
        // assertions on the real DOM keep working without every test
        // needing to opt out individually.
        Livewire::withoutLazyLoading();
    }
}
