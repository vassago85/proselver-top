<?php

/**
 * Covers the reconciliation-query work that came out of the owner seeing 37
 * open queries worth R 26 340 sitting on the Petty Cash Overview:
 *
 *   1. Operations can now clear a query, not just accounts and the owner.
 *      Ops is usually the only party that knows the advance was moved to
 *      another vehicle, so routing every one through accounts left them open.
 *   2. A dedicated report (admin/petty-cash/reconciliation) listing what is
 *      outstanding and how the settled ones were explained.
 *   3. A "what changed yesterday" digest on the Owner dashboard, plus range
 *      shortcuts into the audit log for the week / month / previous month.
 *
 * The load-bearing detail in the report is that the two lists are scoped
 * differently on purpose: open queries ignore the range (cash out of the till
 * is outstanding whichever month you're looking at) while settled ones are
 * filtered on the date they were cleared. Both directions are asserted.
 */

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'owner' => 'Owner',
        'developer' => 'Developer',
        'super_admin' => 'Super Admin',
        'accounts' => 'Accounts',
        'operations_controller' => 'Ops Controller',
        'ops_manager' => 'Ops Manager',
        'dispatcher' => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function reconUser(string $slug, array $attributes = []): User
{
    $u = User::factory()->create(array_merge(['is_active' => true], $attributes));
    $u->assignRole($slug);

    return $u;
}

/**
 * A cancelled trip where the advance had already been issued — i.e. exactly
 * the state that raises a reconciliation query. Pass cleared_* to build one
 * that has already been settled.
 */
function reconJob(array $extras = [], ?Company $company = null): Job
{
    $company ??= Company::factory()->create(['type' => Company::TYPE_OEM]);

    $pickup = Location::create(['company_id' => null, 'company_name' => 'Plant', 'address' => 'Plant', 'is_active' => true]);
    $delivery = Location::create(['company_id' => null, 'company_name' => 'Dealer', 'address' => 'Dealer', 'is_active' => true]);

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(8)),
        'job_type' => 'transport',
        'status' => Job::STATUS_CANCELLED,
        'company_id' => $company->id,
        'created_by_user_id' => User::factory()->create()->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
        'advance_total' => 610.00,
        'advance_issued_at' => now()->subDays(10),
        'cancelled_at' => now()->subDays(9),
        'cancellation_reason' => 'NOT READY',
    ], $extras));
}

// -----------------------------------------------------------------
// 1. Who may clear a reconciliation query
// -----------------------------------------------------------------

test('accounts, operations and management may clear a reconciliation query', function (string $slug) {
    expect(reconUser($slug)->canClearReconciliationQuery())->toBeTrue();
})->with(['accounts', 'operations_controller', 'owner', 'developer', 'super_admin']);

test('roles with no financial standing may not clear a reconciliation query', function (string $slug) {
    expect(reconUser($slug)->canClearReconciliationQuery())->toBeFalse();
})->with(['dispatcher', 'ops_manager']);

test('the operations controller can clear a query from the overview page', function () {
    $ops = reconUser('operations_controller');
    $job = reconJob();

    $this->actingAs($ops);

    Volt::test('admin.overview')
        ->call('openClearQuery', $job->id)
        ->assertSet('showClearQueryModal', true)
        ->set('clearQueryNote', 'Advance moved to the replacement vehicle on JOB-26070999.')
        ->call('submitClearQuery')
        ->assertHasNoErrors();

    $job->refresh();

    expect($job->issued_cancellation_cleared_at)->not->toBeNull();
    expect($job->issued_cancellation_cleared_by_user_id)->toBe($ops->id);
    expect($job->issued_cancellation_cleared_note)->toBe('Advance moved to the replacement vehicle on JOB-26070999.');
    expect($job->hasOpenIssuedCancellationQuery())->toBeFalse();
});

test('a dispatcher on the order page cannot clear by calling the action directly', function () {
    $job = reconJob();
    $dispatcher = reconUser('dispatcher');

    // A dispatcher can open the order and will see the query banner, but only
    // as a notice. The action itself must be guarded, not just the button.
    Volt::actingAs($dispatcher)
        ->test('admin.orders.show', ['job' => $job])
        ->call('openClearIssuedCancellation')
        ->assertStatus(403);

    Volt::actingAs($dispatcher)
        ->test('admin.orders.show', ['job' => $job])
        ->call('clearIssuedCancellationQuery')
        ->assertStatus(403);

    expect($job->refresh()->issued_cancellation_cleared_at)->toBeNull();
});

test('a dispatcher is told who to wait for instead of being shown a dead button', function () {
    $job = reconJob();

    Volt::actingAs(reconUser('dispatcher'))
        ->test('admin.orders.show', ['job' => $job])
        ->assertSee('Waiting on accounts or ops')
        ->assertDontSee('Clear with explanation');
});

test('clearing requires an explanation of at least a few characters', function () {
    $this->actingAs(reconUser('operations_controller'));
    $job = reconJob();

    Volt::test('admin.petty-cash.reconciliation')
        ->call('openClearQuery', $job->id)
        ->set('clearQueryNote', 'ok')
        ->call('submitClearQuery')
        ->assertHasErrors('clearQueryNote');

    expect($job->refresh()->issued_cancellation_cleared_at)->toBeNull();
});

// -----------------------------------------------------------------
// 2. Reconciliation report — access
// -----------------------------------------------------------------

test('accounts, operations and management can open the reconciliation report', function (string $slug) {
    $this->actingAs(reconUser($slug))
        ->get(route('admin.petty-cash.reconciliation'))
        ->assertOk()
        ->assertSee('Reconciliation');
})->with(['accounts', 'operations_controller', 'owner', 'developer', 'super_admin']);

test('a dispatcher cannot open the reconciliation report', function () {
    $this->actingAs(reconUser('dispatcher'))
        ->get(route('admin.petty-cash.reconciliation'))
        ->assertForbidden();
});

test('super admin can now open the petty cash overview, which it previously could not', function () {
    $this->actingAs(reconUser('super_admin'))
        ->get(route('admin.overview'))
        ->assertOk();
});

// -----------------------------------------------------------------
// 3. Reconciliation report — the two lists are scoped differently
// -----------------------------------------------------------------

test('open queries ignore the selected range so old cash cannot hide', function () {
    $this->actingAs(reconUser('owner'));

    $old = reconJob(['cancelled_at' => now()->subMonths(8), 'advance_issued_at' => now()->subMonths(8)]);

    // "This week" is the narrowest range on offer; the eight-month-old open
    // query must still be listed.
    $open = Volt::test('admin.petty-cash.reconciliation')
        ->call('setRange', 'this_week')
        ->viewData('open');

    expect($open->pluck('id')->all())->toContain($old->id);
});

test('settled queries are filtered on the date they were cleared', function () {
    $this->actingAs(reconUser('owner'));

    $thisMonth = reconJob([
        'issued_cancellation_cleared_at' => now()->startOfMonth()->addDays(2),
        'issued_cancellation_cleared_note' => 'Returned to the float this month.',
    ]);

    $lastMonth = reconJob([
        'issued_cancellation_cleared_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        'issued_cancellation_cleared_note' => 'Applied to a swap trip last month.',
    ]);

    $current = Volt::test('admin.petty-cash.reconciliation')->viewData('settled');
    expect($current->pluck('id')->all())->toBe([$thisMonth->id]);

    $previous = Volt::test('admin.petty-cash.reconciliation')
        ->call('setRange', 'last_month')
        ->viewData('settled');
    expect($previous->pluck('id')->all())->toBe([$lastMonth->id]);

    $all = Volt::test('admin.petty-cash.reconciliation')
        ->call('setRange', 'all')
        ->viewData('settled');
    expect($all->pluck('id')->sort()->values()->all())
        ->toBe(collect([$thisMonth->id, $lastMonth->id])->sort()->values()->all());
});

test('a settled query is no longer listed as open', function () {
    $this->actingAs(reconUser('accounts'));

    $job = reconJob();

    $component = Volt::test('admin.petty-cash.reconciliation');
    expect($component->viewData('open')->pluck('id')->all())->toBe([$job->id]);

    $component
        ->call('openClearQuery', $job->id)
        ->set('clearQueryNote', 'Driver returned the cash at the depot.')
        ->call('submitClearQuery');

    $after = Volt::test('admin.petty-cash.reconciliation');
    expect($after->viewData('open')->pluck('id')->all())->toBe([]);
    expect($after->viewData('settled')->pluck('id')->all())->toBe([$job->id]);
});

test('the headline figures total the right money and ages', function () {
    $this->actingAs(reconUser('owner'));

    reconJob(['advance_total' => 610.00, 'cancelled_at' => now()->subDays(5)]);
    reconJob(['advance_total' => 740.00, 'cancelled_at' => now()->subDays(20)]);
    reconJob([
        'advance_total' => 850.00,
        'cancelled_at' => now()->startOfMonth(),
        'issued_cancellation_cleared_at' => now()->startOfMonth()->addDays(4),
        'issued_cancellation_cleared_note' => 'Moved to another vehicle.',
    ]);

    $component = Volt::test('admin.petty-cash.reconciliation');

    expect($component->viewData('openTotal'))->toBe(1350.00);
    expect($component->viewData('settledTotal'))->toBe(850.00);
    // Oldest open is the 20-day-old one, and the list is ordered oldest first.
    expect($component->viewData('oldestOpenDays'))->toBe(20);
    expect($component->viewData('avgDaysToSettle'))->toBe(4);
});

test('days open counts to today while open and to the clearance once settled', function () {
    $this->actingAs(reconUser('owner'));

    $open = reconJob(['cancelled_at' => now()->subDays(6)]);
    $settled = reconJob([
        'cancelled_at' => now()->subDays(6),
        'issued_cancellation_cleared_at' => now()->subDays(4),
    ]);

    $page = Volt::test('admin.petty-cash.reconciliation')->instance();

    expect($page->daysOpen($open))->toBe(6);
    expect($page->daysOpen($settled))->toBe(2);
});

test('the explanation is rendered in full on the report', function () {
    $this->actingAs(reconUser('owner'));

    reconJob([
        'issued_cancellation_cleared_at' => now(),
        'issued_cancellation_cleared_note' => 'Advance reassigned to VIN AAVZZZ3CZME012345 under JOB-26070999.',
    ]);

    $this->get(route('admin.petty-cash.reconciliation'))
        ->assertOk()
        ->assertSee('Advance reassigned to VIN AAVZZZ3CZME012345 under JOB-26070999.');
});

test('an empty report explains itself rather than showing a bare table', function () {
    $this->actingAs(reconUser('owner'));

    $this->get(route('admin.petty-cash.reconciliation'))
        ->assertOk()
        ->assertSee('Nothing outstanding')
        ->assertSee('Nothing settled in this period');
});

// -----------------------------------------------------------------
// 4. Reconciliation report — export
// -----------------------------------------------------------------

test('the export carries both lists, the explanation, and is itself audited', function () {
    $owner = reconUser('owner', ['name' => 'Paul Charsley']);
    $this->actingAs($owner);

    reconJob(['advance_total' => 610.00, 'job_number' => 'JOB-OPEN01']);
    reconJob([
        'advance_total' => 850.00,
        'job_number' => 'JOB-DONE01',
        'issued_cancellation_cleared_at' => now(),
        'issued_cancellation_cleared_note' => 'Moved to the replacement vehicle.',
    ]);

    /** @var StreamedResponse $response */
    $response = Volt::test('admin.petty-cash.reconciliation')->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");

    expect($body)->toContain('JOB-OPEN01');
    expect($body)->toContain('JOB-DONE01');
    expect($body)->toContain('Moved to the replacement vehicle.');
    expect($body)->toContain('Open');
    expect($body)->toContain('Settled');

    $entry = AuditLog::where('action_type', 'reconciliation_report_exported')->firstOrFail();
    expect($entry->actor_user_id)->toBe($owner->id);
    expect($entry->after_json['range'])->toBe('this_month');
});

test('clearing from the report is audited and says where it came from', function () {
    $ops = reconUser('operations_controller');
    $this->actingAs($ops);

    $job = reconJob();

    Volt::test('admin.petty-cash.reconciliation')
        ->call('openClearQuery', $job->id)
        ->set('clearQueryNote', 'Advance moved to another vehicle on the same run.')
        ->call('submitClearQuery');

    $entry = AuditLog::where('action_type', 'issued_cancellation_query_cleared')->firstOrFail();

    expect($entry->actor_user_id)->toBe($ops->id);
    expect($entry->entity_id)->toBe($job->id);
    expect($entry->after_json['source'])->toBe('reconciliation_report');
    expect($entry->after_json['note'])->toBe('Advance moved to another vehicle on the same run.');
    expect($entry->after_json['cleared_by_roles'])->toContain('operations_controller');
});

// -----------------------------------------------------------------
// 5. Owner dashboard — what changed yesterday
// -----------------------------------------------------------------

/** An audit entry at an explicit time, for the digest window assertions. */
function reconAudit(array $attributes = []): AuditLog
{
    return AuditLog::create(array_merge([
        'actor_user_id' => null,
        'action_type' => 'updated',
        'entity_type' => 'App\Models\Job',
        'entity_id' => 1,
        'created_at' => now()->subDay(),
    ], $attributes));
}

test('the digest counts yesterday only', function () {
    $owner = reconUser('owner');

    reconAudit(['created_at' => now()->subDay()->setTime(9, 0)]);
    reconAudit(['created_at' => now()->subDay()->setTime(16, 30)]);
    reconAudit(['created_at' => now()->setTime(8, 0)]);            // today
    reconAudit(['created_at' => now()->subDays(2)->setTime(8, 0)]); // day before

    $this->actingAs($owner);

    $digest = Volt::test('admin.dashboard.owner')->viewData('digest');

    expect($digest['total'])->toBe(2);
    expect($digest['date']->toDateString())->toBe(now()->subDay()->toDateString());
});

test('the digest ranks yesterdays actions and names the busiest people', function () {
    $owner = reconUser('owner');
    $thabo = reconUser('operations_controller', ['name' => 'Thabo Ndlovu']);
    $ayanda = reconUser('accounts', ['name' => 'Ayanda Dube']);

    foreach (range(1, 3) as $i) {
        reconAudit(['action_type' => 'driver_assigned', 'actor_user_id' => $thabo->id]);
    }
    reconAudit(['action_type' => 'petty_cash_approved', 'actor_user_id' => $ayanda->id]);

    $this->actingAs($owner);

    $digest = Volt::test('admin.dashboard.owner')->viewData('digest');

    expect($digest['total'])->toBe(4);
    expect($digest['people'])->toBe(2);

    // Humanised label, biggest first.
    expect($digest['actions']->first()['label'])->toBe('Driver assigned');
    expect($digest['actions']->first()['count'])->toBe(3);

    expect($digest['actors']->first()['name'])->toBe('Thabo Ndlovu');
    expect($digest['actors']->first()['count'])->toBe(3);
});

test('digest rows link into the audit log with the filter already applied', function () {
    $owner = reconUser('owner');
    $thabo = reconUser('operations_controller', ['name' => 'Thabo Ndlovu']);

    reconAudit(['action_type' => 'driver_assigned', 'actor_user_id' => $thabo->id]);

    $this->actingAs($owner);

    $digest = Volt::test('admin.dashboard.owner')->viewData('digest');
    $yesterday = now()->subDay()->toDateString();

    expect($digest['actions']->first()['href'])
        ->toContain('actionType=driver_assigned')
        ->toContain('dateFrom=' . $yesterday)
        ->toContain('dateTo=' . $yesterday);

    expect($digest['actors']->first()['href'])
        ->toContain('actorId=' . $thabo->id)
        ->toContain('dateFrom=' . $yesterday);
});

test('a quiet day reads as nothing recorded rather than an empty panel', function () {
    $this->actingAs(reconUser('owner'));

    $this->get(route('admin.dashboard.owner'))
        ->assertOk()
        ->assertSee('What changed yesterday')
        ->assertSee('Nothing was recorded yesterday.');
});

test('the owner gets one-click ranges for the week, month and previous month', function () {
    $this->actingAs(reconUser('owner'));

    $ranges = collect(Volt::test('admin.dashboard.owner')->viewData('changeRanges'))->keyBy('label');

    expect($ranges->keys()->all())->toBe(['Yesterday', 'This week', 'This month', 'Last month']);

    expect($ranges['This month']['href'])
        ->toContain('dateFrom=' . now()->startOfMonth()->toDateString())
        ->toContain('dateTo=' . now()->endOfMonth()->toDateString());

    expect($ranges['Last month']['href'])
        ->toContain('dateFrom=' . now()->subMonthNoOverflow()->startOfMonth()->toDateString())
        ->toContain('dateTo=' . now()->subMonthNoOverflow()->endOfMonth()->toDateString());

    expect($ranges['This week']['href'])
        ->toContain('dateFrom=' . now()->startOfWeek()->toDateString());
});

test('the owner dashboard points at the reconciliation report, not the overview', function () {
    $this->actingAs(reconUser('owner'));

    reconJob();

    $this->get(route('admin.dashboard.owner'))
        ->assertOk()
        ->assertSee('Open reconciliation queries')
        ->assertSee(route('admin.petty-cash.reconciliation'), false);
});

// -----------------------------------------------------------------
// 6. Audit log gained the ranges the owner asked for
// -----------------------------------------------------------------

test('the audit log can jump to yesterday and to this week', function () {
    $this->actingAs(reconUser('owner'));

    Volt::test('admin.audit-log')
        ->call('applyRange', 'yesterday')
        ->assertSet('dateFrom', now()->subDay()->toDateString())
        ->assertSet('dateTo', now()->subDay()->toDateString());

    Volt::test('admin.audit-log')
        ->call('applyRange', 'this_week')
        ->assertSet('dateFrom', now()->startOfWeek()->toDateString())
        ->assertSet('dateTo', now()->endOfWeek()->toDateString());
});

test('the reconciliation tab appears in the petty cash strip for those who may use it', function () {
    $this->actingAs(reconUser('operations_controller'))
        ->get(route('admin.overview'))
        ->assertOk()
        ->assertSee(route('admin.petty-cash.reconciliation'), false);
});
