<?php

/**
 * Phase 1 of the "internals say Order" cleanup: /admin/bookings and
 * /admin/jobs no longer serve their own pages -- both URLs (and the
 * named routes admin.bookings.* / admin.jobs.*) redirect at Orders.
 *
 * This test pins that promise down so nobody accidentally re-adds a
 * Volt page at those paths.  It also asserts the two internal mail
 * notifications now link at /admin/orders/{id} -- the whole point of
 * the redirects is that no live surface still hard-codes the old URL.
 *
 * SQLite in-memory DB (see phpunit.xml) is fine for this: we only need
 * routing and the two notifications' toMail() output.  No dashboard
 * PostgreSQL-only SQL is involved.
 */

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BookingCreatedNotification;
use App\Notifications\InvoiceReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The /admin/* prefix runs the `internal` middleware, so every hit
    // needs an internal role in the DB or the redirect can't be reached.
    Role::firstOrCreate(
        ['slug' => 'operations_controller'],
        ['name' => 'Ops Controller', 'tier' => 'internal']
    );
});

function legacyRedirectUser(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');

    return $u;
}

/** A minimal persisted Job, so /admin/bookings/{id} can bind the model. */
function legacyRedirectJob(): Job
{
    $company = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    $pickup = Location::create([
        'company_id' => null, 'company_name' => 'Plant', 'address' => 'Plant', 'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null, 'company_name' => 'Dealer', 'address' => 'Dealer', 'is_active' => true,
    ]);

    return Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(8)),
        'job_type' => 'transport',
        'status' => Job::STATUS_PENDING_VERIFICATION,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
    ]);
}

// -----------------------------------------------------------------
// 1. URL redirects
// -----------------------------------------------------------------

test('the legacy bookings index redirects to orders', function () {
    $this->actingAs(legacyRedirectUser())
        ->get('/admin/bookings')
        ->assertRedirect('/admin/orders');
});

test('the legacy jobs index redirects to orders', function () {
    $this->actingAs(legacyRedirectUser())
        ->get('/admin/jobs')
        ->assertRedirect('/admin/orders');
});

test('a legacy bookings detail URL redirects to the same order', function () {
    $job = legacyRedirectJob();

    $this->actingAs(legacyRedirectUser())
        ->get("/admin/bookings/{$job->id}")
        ->assertRedirect(route('admin.orders.show', $job));
});

test('a legacy jobs detail URL redirects to the same order', function () {
    $job = legacyRedirectJob();

    $this->actingAs(legacyRedirectUser())
        ->get("/admin/jobs/{$job->id}")
        ->assertRedirect(route('admin.orders.show', $job));
});

// -----------------------------------------------------------------
// 2. The named routes still resolve (nothing calling
//    route('admin.bookings.*') or route('admin.jobs.*') breaks)
// -----------------------------------------------------------------

test('route(admin.bookings.index) still resolves to /admin/bookings', function () {
    expect(route('admin.bookings.index', absolute: false))->toBe('/admin/bookings');
    expect(route('admin.jobs.index',     absolute: false))->toBe('/admin/jobs');
});

test('route(admin.bookings.show) and route(admin.jobs.show) still resolve', function () {
    $job = legacyRedirectJob();

    expect(route('admin.bookings.show', $job, absolute: false))->toBe("/admin/bookings/{$job->id}");
    expect(route('admin.jobs.show',     $job, absolute: false))->toBe("/admin/jobs/{$job->id}");
});

// -----------------------------------------------------------------
// 3. The two internal notifications point at Orders
// -----------------------------------------------------------------

test('the BookingCreated mail action links at the Orders page', function () {
    $job = legacyRedirectJob();
    $recipient = User::factory()->create();

    $mail = (new BookingCreatedNotification($job))->toMail($recipient);

    expect($mail->actionText)->toBe('View Order');
    expect($mail->actionUrl)->toBe(route('admin.orders.show', $job));
    expect($mail->actionUrl)->toContain("/admin/orders/{$job->id}");
});

test('the InvoiceReady mail action links at the Orders page', function () {
    $job = legacyRedirectJob();
    $recipient = User::factory()->create();

    $mail = (new InvoiceReadyNotification($job))->toMail($recipient);

    expect($mail->actionText)->toBe('View Order');
    expect($mail->actionUrl)->toBe(route('admin.orders.show', $job));
    expect($mail->actionUrl)->toContain("/admin/orders/{$job->id}");
});
