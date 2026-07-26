<?php

/**
 * Covers the three-way internal dashboard split:
 *
 *   /admin/dashboard             role-resolving redirect
 *   /admin/dashboard/operations  the original ops command centre
 *   /admin/dashboard/finance     billing / petty cash / driver pay
 *   /admin/dashboard/owner       thin roll-up + "waiting on you"
 *
 * Note that the Operations dashboard is NOT rendered anywhere in here.
 * It uses Postgres-only SQL (`count(*) filter (...)`, `delivered_at::date`)
 * and cannot execute on the SQLite test connection, which is exactly why a
 * Postgres grouping error in it would reach production unnoticed.  The two
 * new dashboards are written with portable SQL specifically so they can be
 * rendered here -- keep them that way.
 */

use App\Models\BodyBuilderRequest;
use App\Models\BookingChangeRequest;
use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\PettyCashEntry;
use App\Models\PettyCashPlan;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProselverLicenceBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner' => 'Owner',
        'developer' => 'Developer',
        'accounts' => 'Accounts',
        'super_admin' => 'Super Admin',
        'operations_controller' => 'Ops Controller',
        'dispatcher' => 'Dispatcher',
        'ops_manager' => 'Ops Manager',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
    Role::firstOrCreate(['slug' => 'driver'], ['name' => 'Driver', 'tier' => 'driver']);
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);

    // The licence meter is on by default in production; pinning both the
    // flag and the rate keeps the money assertions deterministic.
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, true, 'boolean');
    SystemSetting::set(ProselverLicenceBilling::SETTING_PER_MOVE, 150, 'float');
});

function dashUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);

    return $u;
}

/**
 * A delivered ProSelver movement -- the unit every finance figure counts.
 * Company and creator are reused when passed so a test can build many jobs
 * for one customer without tripping the companies.normalized_name unique
 * index.
 */
function dashJob(array $extras = [], ?Company $company = null, ?User $creator = null): Job
{
    $company ??= Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator ??= User::factory()->create();

    $pickup = Location::create([
        'company_id' => null,
        'company_name' => 'Plant',
        'address' => 'Plant',
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null,
        'company_name' => 'Dealer',
        'address' => 'Dealer',
        'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(8)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
        'delivered_at' => now(),
    ], $extras));
}

// -----------------------------------------------------------------
// 1. /admin/dashboard resolves to the right dashboard per role
// -----------------------------------------------------------------

test('accounts lands on the finance dashboard', function () {
    $this->actingAs(dashUser('accounts'))
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.finance'));
});

test('owner lands on the owner roll-up', function () {
    $this->actingAs(dashUser('owner'))
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.owner'));
});

test('super admin and developer land on the owner roll-up', function () {
    $this->actingAs(dashUser('super_admin'))
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.owner'));

    $this->actingAs(dashUser('developer'))
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.owner'));
});

test('operations roles land on the operations dashboard', function (string $slug) {
    $this->actingAs(dashUser($slug))
        ->get('/admin/dashboard')
        ->assertRedirect(route('admin.dashboard.ops'));
})->with(['operations_controller', 'dispatcher', 'ops_manager']);

test('the resolver is the single source of truth for the post-login home', function () {
    // resolveUserHomePath() and /admin/dashboard must never disagree, or a
    // role could be sent somewhere it then gets bounced out of.
    foreach (['accounts', 'owner', 'developer', 'super_admin', 'operations_controller', 'dispatcher'] as $slug) {
        $u = dashUser($slug);
        expect(resolveUserHomePath($u))->toBe(route(resolveInternalDashboardRoute($u)));
    }
});

// -----------------------------------------------------------------
// 2. Access gating on the two new pages
// -----------------------------------------------------------------

test('finance dashboard is open to accounts, management and the ops controller', function (string $slug) {
    $this->actingAs(dashUser($slug))
        ->get(route('admin.dashboard.finance'))
        ->assertOk();
})->with(['accounts', 'owner', 'developer', 'super_admin', 'operations_controller']);

test('finance dashboard is closed to a plain dispatcher', function () {
    $this->actingAs(dashUser('dispatcher'))
        ->get(route('admin.dashboard.finance'))
        ->assertForbidden();
});

test('owner dashboard is open only to owner, developer and super admin', function (string $slug) {
    $this->actingAs(dashUser($slug))
        ->get(route('admin.dashboard.owner'))
        ->assertOk();
})->with(['owner', 'developer', 'super_admin']);

test('owner dashboard is closed to accounts and to ops', function (string $slug) {
    $this->actingAs(dashUser($slug))
        ->get(route('admin.dashboard.owner'))
        ->assertForbidden();
})->with(['accounts', 'operations_controller', 'dispatcher']);

test('both new dashboards are closed to customers by the internal middleware', function () {
    $customer = dashUser('customer_owner');

    $this->actingAs($customer)->get(route('admin.dashboard.finance'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.dashboard.owner'))->assertForbidden();
});

// -----------------------------------------------------------------
// 3. Finance dashboard figures
// -----------------------------------------------------------------

test('finance dashboard buckets billable movements into captured, open and excluded', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    // 2 captured, 3 still open, 1 excluded => 5 in scope, 40% captured.
    foreach (range(1, 2) as $_) {
        dashJob(['invoicing_completed_at' => now(), 'invoice_number' => 'INV-1'], $oem, $creator);
    }
    foreach (range(1, 3) as $_) {
        dashJob(['total_sell_price' => 1000], $oem, $creator);
    }
    dashJob(['invoicing_excluded_at' => now()], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    expect($c->viewData('billableTotal'))->toBe(6);
    expect($c->viewData('doneCount'))->toBe(2);
    expect($c->viewData('openCount'))->toBe(3);
    expect($c->viewData('excludedCount'))->toBe(1);
    expect($c->viewData('inScope'))->toBe(5);
    expect($c->viewData('capturedPct'))->toBe(40);
    expect($c->viewData('unbilledValue'))->toBe(3000.0);
});

test('finance dashboard counts movements missing an invoice number but ignores excluded ones', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    dashJob(['invoice_number' => 'INV-100'], $oem, $creator);
    dashJob(['invoice_number' => null], $oem, $creator);
    dashJob(['invoice_number' => ''], $oem, $creator);
    // Excluded rows are not required to carry a number, so they must not
    // inflate the "missing" count that finance chases.
    dashJob(['invoice_number' => null, 'invoicing_excluded_at' => now()], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    expect($c->viewData('missingNumberCount'))->toBe(2);
});

test('finance dashboard sums captured invoice value including extras', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    dashJob(['invoice_amount' => 1500.50, 'extras_amount' => 250.00], $oem, $creator);
    dashJob(['invoice_amount' => 1000.00, 'extras_amount' => null], $oem, $creator);
    // Excluded rows carry no billable value.
    dashJob(['invoice_amount' => 9999.00, 'invoicing_excluded_at' => now()], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    expect($c->viewData('capturedValue'))->toBe(2750.50);
});

test('finance dashboard variance is driver spend minus cash issued', function () {
    $platform = Company::factory()->create([
        'name' => 'ProSelver Platform',
        'type' => Company::TYPE_OEM,
        'is_platform_owner' => true,
    ]);
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole('driver');
    $driver->companies()->syncWithoutDetaching([$platform->id]);

    // R2 000 issued, R2 350 spent => R350 over.
    dashJob(['advance_total' => 2000, 'advance_assigned_at' => now(), 'driver_user_id' => $driver->id]);

    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_FUEL,
        'amount_cents' => 200000,
        'status' => PettyCashEntry::STATUS_APPROVED,
    ]);
    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_TOLL,
        'amount_cents' => 35000,
        'status' => PettyCashEntry::STATUS_REIMBURSED,
    ]);
    // Rejected slips are money the business never committed to, so they
    // must not move the variance.
    PettyCashEntry::create([
        'driver_user_id' => $driver->id,
        'category' => PettyCashEntry::CATEGORY_FOOD,
        'amount_cents' => 500000,
        'status' => PettyCashEntry::STATUS_REJECTED,
    ]);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');
    $cash = $c->viewData('cash');

    expect($cash['issued'])->toBe(2000.0);
    expect($cash['spent'])->toBe(2350.0);
    expect($cash['reimbursed'])->toBe(350.0);
    expect($c->viewData('variance'))->toBe(350.0);
});

test('finance dashboard earnings use the driver rate and flag drivers with none', function () {
    $platform = Company::factory()->create([
        'name' => 'ProSelver Rates',
        'type' => Company::TYPE_OEM,
        'is_platform_owner' => true,
    ]);

    $makeDriver = function (?int $rateCents) use ($platform) {
        $d = User::factory()->create(['is_active' => true]);
        $d->assignRole('driver');
        $d->companies()->syncWithoutDetaching([$platform->id]);
        DriverProfile::create(['user_id' => $d->id, 'rate_per_movement_cents' => $rateCents]);

        return $d;
    };

    $rated = $makeDriver(45000);   // R450 a movement
    $unrated = $makeDriver(null);

    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    foreach (range(1, 3) as $_) {
        dashJob(['driver_user_id' => $rated->id], $oem, $creator);
    }
    dashJob(['driver_user_id' => $unrated->id], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    // All 4 movements count, but only the rated driver's 3 can be priced.
    expect($c->viewData('totalMoves'))->toBe(4);
    expect($c->viewData('driverEarnings'))->toBe(1350.0);
    expect($c->viewData('driversMissingRate'))->toBe(1);
});

test('finance dashboard ignores movements delivered outside the picked month', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(10);

    dashJob(['delivered_at' => now()], $oem, $creator);
    dashJob(['delivered_at' => $lastMonth], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');
    expect($c->viewData('billableTotal'))->toBe(1);

    $c->set('month', $lastMonth->format('Y-m'));
    expect($c->viewData('billableTotal'))->toBe(1);
    expect($c->viewData('anchor')->format('Y-m'))->toBe($lastMonth->format('Y-m'));
});

test('finance dashboard month stepper will not walk into the future', function () {
    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    expect($c->get('month'))->toBe(now()->format('Y-m'));

    $c->call('stepMonth', 1);
    expect($c->get('month'))->toBe(now()->format('Y-m'));

    $c->call('stepMonth', -1);
    expect($c->get('month'))->toBe(now()->subMonthNoOverflow()->format('Y-m'));
});

test('finance dashboard hides the platform licence figure from accounts', function () {
    dashJob();

    $accounts = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');
    expect($accounts->viewData('licence'))->toBeNull();

    $owner = Volt::actingAs(dashUser('owner'))->test('admin.dashboard.finance');
    expect($owner->viewData('licence'))->not->toBeNull();
    expect($owner->viewData('licence')['moves'])->toBe(1);
    // 1 move x R150 + 15% VAT.
    expect($owner->viewData('licence')['total_incl_vat'])->toBe(172.5);
});

test('finance dashboard excludes movements ProSelver did not execute', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    dashJob([], $oem, $creator);
    dashJob(['executor_type' => Job::EXECUTOR_SELF_COLLECT], $oem, $creator);
    dashJob(['executor_type' => Job::EXECUTOR_THIRD_PARTY], $oem, $creator);

    $c = Volt::actingAs(dashUser('accounts'))->test('admin.dashboard.finance');

    expect($c->viewData('billableTotal'))->toBe(1);
});

test('finance dashboard groups outstanding value by customer', function () {
    $busy = Company::factory()->create(['type' => Company::TYPE_OEM, 'name' => 'Busy Motors']);
    $quiet = Company::factory()->create(['type' => Company::TYPE_OEM, 'name' => 'Quiet Motors']);
    $creator = User::factory()->create();

    foreach (range(1, 3) as $_) {
        dashJob(['total_sell_price' => 500], $busy, $creator);
    }
    dashJob(['total_sell_price' => 900], $quiet, $creator);
    // Already captured, so it must not appear in the outstanding list.
    dashJob(['total_sell_price' => 4000, 'invoicing_completed_at' => now()], $quiet, $creator);

    $rows = Volt::actingAs(dashUser('accounts'))
        ->test('admin.dashboard.finance')
        ->viewData('unbilledRows');

    expect($rows)->toHaveCount(2);
    expect($rows->first()->company_name)->toBe('Busy Motors');
    expect((int) $rows->first()->moves)->toBe(3);
    expect((float) $rows->first()->sell_sum)->toBe(1500.0);
});

// -----------------------------------------------------------------
// 4. Owner roll-up
// -----------------------------------------------------------------

test('owner roll-up reports an empty attention list when nothing is outstanding', function () {
    $c = Volt::actingAs(dashUser('owner'))->test('admin.dashboard.owner');

    expect($c->viewData('attention'))->toBeEmpty();
    $c->assertSee('All clear.');
});

test('owner roll-up surfaces only non-zero attention rows, most urgent first', function () {
    // One low-severity item and one high-severity item, with the low one
    // carrying the bigger count -- severity must still win.
    $dealer = Company::factory()->create(['type' => Company::TYPE_DEALER]);
    $requester = User::factory()->create();

    foreach (range(1, 9) as $i) {
        BodyBuilderRequest::create([
            'dealer_company_id' => $dealer->id,
            'requested_by_user_id' => $requester->id,
            'proposed_name' => "Workshop {$i}",
            'status' => 'pending',
        ]);
    }

    PettyCashPlan::create([
        'uuid' => (string) Str::uuid(),
        'label' => 'Pay-run for tomorrow',
        'status' => PettyCashPlan::STATUS_PENDING,
        'total_amount' => 4200,
        'generated_by_user_id' => User::factory()->create()->id,
        'generated_at' => now(),
    ]);

    $c = Volt::actingAs(dashUser('owner'))->test('admin.dashboard.owner');
    $attention = $c->viewData('attention');

    expect($attention)->toHaveCount(2);
    expect($attention->first()['label'])->toBe('Petty cash plans awaiting your sign-off');
    expect($attention->first()['count'])->toBe(1);
    expect($attention->last()['count'])->toBe(9);
});

test('owner roll-up counts open reconciliation queries', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    // Cancelled after the advance went out and never explained.
    dashJob([
        'status' => Job::STATUS_CANCELLED,
        'delivered_at' => null,
        'advance_issued_at' => now(),
    ], $oem, $creator);

    // Same situation but signed off, so it must not appear.
    dashJob([
        'status' => Job::STATUS_CANCELLED,
        'delivered_at' => null,
        'advance_issued_at' => now(),
        'issued_cancellation_cleared_at' => now(),
    ], $oem, $creator);

    $attention = Volt::actingAs(dashUser('owner'))
        ->test('admin.dashboard.owner')
        ->viewData('attention');

    $queries = $attention->firstWhere('label', 'Open reconciliation queries');

    expect($queries)->not->toBeNull();
    expect($queries['count'])->toBe(1);
});

test('owner roll-up counts pending booking change requests', function () {
    $job = dashJob();

    BookingChangeRequest::create([
        'uuid' => (string) Str::uuid(),
        'job_id' => $job->id,
        'requested_by_user_id' => User::factory()->create()->id,
        'request_type' => 'collection_date_change',
        'current_value' => ['scheduled_date' => now()->toDateString()],
        'requested_value' => ['scheduled_date' => now()->addWeek()->toDateString()],
        'reason' => 'Customer asked to push the delivery out a week.',
        'status' => 'pending',
    ]);

    $attention = Volt::actingAs(dashUser('owner'))
        ->test('admin.dashboard.owner')
        ->viewData('attention');

    expect($attention->firstWhere('label', 'Booking change requests pending')['count'])->toBe(1);
});

test('owner roll-up ops strip separates in-flight from on-the-road and delivered', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    dashJob(['status' => Job::STATUS_RECEIVED, 'delivered_at' => null], $oem, $creator);
    dashJob(['status' => Job::STATUS_CONFIRMED, 'delivered_at' => null], $oem, $creator);
    dashJob(['status' => Job::STATUS_IN_TRANSIT, 'delivered_at' => null], $oem, $creator);
    dashJob(['status' => Job::STATUS_COLLECTED, 'delivered_at' => null], $oem, $creator);
    dashJob(['status' => Job::STATUS_DELIVERED, 'delivered_at' => now()->subDays(3)], $oem, $creator);
    // Older than the 30-day throughput window.
    dashJob(['status' => Job::STATUS_DELIVERED, 'delivered_at' => now()->subDays(45)], $oem, $creator);

    $c = Volt::actingAs(dashUser('owner'))->test('admin.dashboard.owner');

    expect($c->viewData('activeTotal'))->toBe(4);
    expect($c->viewData('onRoad'))->toBe(2);
    expect($c->viewData('deliveredRecent'))->toBe(1);
});

test('owner roll-up finance strip agrees with the finance dashboard', function () {
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();

    dashJob(['total_sell_price' => 1200], $oem, $creator);
    dashJob(['total_sell_price' => 800], $oem, $creator);
    dashJob(['invoice_amount' => 2500, 'invoicing_completed_at' => now()], $oem, $creator);

    $owner = dashUser('owner');

    $ownerDash = Volt::actingAs($owner)->test('admin.dashboard.owner');
    $financeDash = Volt::actingAs($owner)->test('admin.dashboard.finance');

    // Both default to the current calendar month, so the shared figures
    // must match exactly -- a drift here means one of the two copied the
    // billable definition wrong.
    expect($ownerDash->viewData('openInvoicing'))->toBe($financeDash->viewData('openCount'));
    expect($ownerDash->viewData('unbilledValue'))->toBe($financeDash->viewData('unbilledValue'));
    expect($ownerDash->viewData('variance'))->toBe($financeDash->viewData('variance'));
    expect($ownerDash->viewData('openInvoicing'))->toBe(2);
    expect($ownerDash->viewData('unbilledValue'))->toBe(2000.0);
});

test('owner roll-up reports the licence as off when metering is disabled', function () {
    SystemSetting::set(ProselverLicenceBilling::SETTING_ENABLED, false);

    $c = Volt::actingAs(dashUser('owner'))->test('admin.dashboard.owner');

    expect($c->viewData('licence'))->toBeNull();
    $c->assertSee('Licence metering is currently disabled');
});

// -----------------------------------------------------------------
// 5. Navigation
// -----------------------------------------------------------------

test('the dashboard tab strip only offers dashboards the viewer can open', function () {
    // Owner sees all three.
    $this->actingAs(dashUser('owner'))
        ->get(route('admin.dashboard.owner'))
        ->assertSee(route('admin.dashboard.ops'))
        ->assertSee(route('admin.dashboard.finance'))
        ->assertSee(route('admin.dashboard.owner'));

    // Accounts sees Operations + Finance but never the owner roll-up.
    $this->actingAs(dashUser('accounts'))
        ->get(route('admin.dashboard.finance'))
        ->assertSee(route('admin.dashboard.finance'))
        ->assertDontSee(route('admin.dashboard.owner'));
});

test('the sidebar surfaces the finance pages that used to be buried as tabs', function () {
    // The cash pages and Driver Pay previously had no sidebar entry at all --
    // they were only reachable from inside Petty Cash. The overview entry is
    // labelled "Cash Overview" rather than the original "Cash Reconciliation",
    // which now belongs to the Reconciliation Queries report next to it.
    $this->actingAs(dashUser('accounts'))
        ->get(route('admin.dashboard.finance'))
        ->assertSee(route('admin.overview'))
        ->assertSee(route('admin.petty-cash.reconciliation'))
        ->assertSee(route('admin.drivers.pay'))
        ->assertSee(route('admin.invoices.index'))
        ->assertSee('Cash Overview')
        ->assertSee('Reconciliation Queries')
        ->assertSee('Driver Pay');
});

test('the sidebar hides the finance dashboard from a dispatcher', function () {
    // Asserted from the Finance page's own gate rather than by rendering a
    // dispatcher's sidebar, because the only page a dispatcher can render
    // here is the Operations dashboard and that needs Postgres.
    $this->actingAs(dashUser('dispatcher'))
        ->get(route('admin.dashboard.finance'))
        ->assertForbidden();

    // And the reverse: accounts, who can see it, gets the link.
    $this->actingAs(dashUser('accounts'))
        ->get(route('admin.dashboard.finance'))
        ->assertSee(route('admin.dashboard.finance'));
});
