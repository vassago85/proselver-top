<?php

/**
 * Covers the Admin > Audit Log page (resources/views/pages/admin/audit-log.blade.php),
 * which replaced a "this page is under development" placeholder.
 *
 * The interesting behaviour, and what these tests pin down:
 *
 *   - It is gated. The placeholder had no gate, so every internal role could
 *     read the whole trail; it is now management + ops controller.
 *   - entity_type arrives in three different shapes from three writers
 *     ("App\Models\Job" from the Auditable trait, "job" / "transport_job"
 *     from AuditService call sites). The page has to treat them as one thing.
 *   - before_json / after_json are raw model attributes, so a User row carries
 *     a password hash. That must never render.
 *   - Deep links must only point at records that still exist.
 *
 * Rows are inserted with an explicit created_at throughout, which is only
 * possible because AuditLog::creating() now stamps with `??=` instead of
 * overwriting. Without that the date-window behaviour is untestable.
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
        'operations_controller' => 'Ops Controller',
        'accounts' => 'Accounts',
        'dispatcher' => 'Dispatcher',
    ] as $slug => $name) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => 'internal']);
    }
});

function auditUser(string $slug, array $attributes = []): User
{
    $u = User::factory()->create(array_merge(['is_active' => true], $attributes));
    $u->assignRole($slug);

    return $u;
}

/**
 * One audit entry. created_at defaults to "now" but is always passed
 * explicitly so ordering and window tests are deterministic.
 */
function auditEntry(array $attributes = []): AuditLog
{
    return AuditLog::create(array_merge([
        'actor_user_id' => null,
        'actor_roles_snapshot' => 'Owner',
        'action_type' => 'updated',
        'entity_type' => 'App\Models\Job',
        'entity_id' => 1,
        'before_json' => null,
        'after_json' => null,
        'reason' => null,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'PestTest',
        'created_at' => now(),
    ], $attributes));
}

/** A minimal persisted Job, so entity deep-links have something real to hit. */
function auditJob(): Job
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
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => $pickup->id,
        'delivery_location_id' => $delivery->id,
        'scheduled_date' => now()->toDateString(),
        'delivered_at' => now(),
    ]);
}

// -----------------------------------------------------------------
// 1. Access control
// -----------------------------------------------------------------

test('management and the ops controller can open the audit log', function (string $slug) {
    $this->actingAs(auditUser($slug))
        ->get(route('admin.audit-log'))
        ->assertOk()
        ->assertSee('Audit Log');
})->with(['owner', 'developer', 'super_admin', 'operations_controller']);

test('internal roles outside management cannot open the audit log', function (string $slug) {
    $this->actingAs(auditUser($slug))
        ->get(route('admin.audit-log'))
        ->assertForbidden();
})->with(['accounts', 'dispatcher']);

test('the page no longer advertises itself as under development', function () {
    $this->actingAs(auditUser('owner'))
        ->get(route('admin.audit-log'))
        ->assertOk()
        ->assertDontSee('under development');
});

// -----------------------------------------------------------------
// 2. Listing and ordering
// -----------------------------------------------------------------

test('entries render newest first and flip to oldest first on request', function () {
    $actor = auditUser('owner');

    auditEntry(['action_type' => 'first_thing', 'created_at' => now()->subHours(3)]);
    auditEntry(['action_type' => 'second_thing', 'created_at' => now()->subHour()]);

    $this->actingAs($actor);

    $ids = Volt::test('admin.audit-log')
        ->viewData('rows')
        ->pluck('action_type')
        ->all();

    expect($ids)->toBe(['second_thing', 'first_thing']);

    $flipped = Volt::test('admin.audit-log')
        ->set('sort', 'asc')
        ->viewData('rows')
        ->pluck('action_type')
        ->all();

    expect($flipped)->toBe(['first_thing', 'second_thing']);
});

test('the actor name is shown, and system entries are labelled System', function () {
    $viewer = auditUser('owner');
    $actor = auditUser('developer', ['name' => 'Grace Mokoena']);

    auditEntry(['actor_user_id' => $actor->id]);
    auditEntry(['actor_user_id' => null, 'action_type' => 'nightly_sweep']);

    $this->actingAs($viewer)
        ->get(route('admin.audit-log'))
        ->assertOk()
        ->assertSee('Grace Mokoena')
        ->assertSee('System');
});

// -----------------------------------------------------------------
// 3. Filters
// -----------------------------------------------------------------

test('search matches the actor name', function () {
    $viewer = auditUser('owner');
    $thabo = auditUser('developer', ['name' => 'Thabo Ndlovu']);
    $sarah = auditUser('dispatcher', ['name' => 'Sarah Petersen']);

    auditEntry(['actor_user_id' => $thabo->id, 'action_type' => 'thabo_action']);
    auditEntry(['actor_user_id' => $sarah->id, 'action_type' => 'sarah_action']);

    $this->actingAs($viewer);

    $rows = Volt::test('admin.audit-log')
        ->set('search', 'Thabo')
        ->viewData('rows');

    expect($rows->pluck('action_type')->all())->toBe(['thabo_action']);
});

test('search matches the reason text', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['reason' => 'Customer cancelled the collection', 'action_type' => 'has_reason']);
    auditEntry(['reason' => null, 'action_type' => 'no_reason']);

    $rows = Volt::test('admin.audit-log')
        ->set('search', 'cancelled the collection')
        ->viewData('rows');

    expect($rows->pluck('action_type')->all())->toBe(['has_reason']);
});

test('a numeric search term is matched against the record id exactly', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['entity_id' => 4210, 'action_type' => 'wanted']);
    auditEntry(['entity_id' => 999, 'action_type' => 'unwanted']);

    $rows = Volt::test('admin.audit-log')
        ->set('search', '4210')
        ->viewData('rows');

    expect($rows->pluck('action_type')->all())->toBe(['wanted']);
});

test('search is case insensitive', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['reason' => 'Vehicle Was Damaged', 'action_type' => 'wanted']);
    auditEntry(['reason' => 'nothing relevant', 'action_type' => 'unwanted']);

    $rows = Volt::test('admin.audit-log')
        ->set('search', 'vehicle was damaged')
        ->viewData('rows');

    expect($rows->pluck('action_type')->all())->toBe(['wanted']);
});

test('the action, entity and actor filters each narrow the list', function () {
    $viewer = auditUser('owner');
    $actor = auditUser('developer');

    auditEntry(['action_type' => 'petty_cash_approved', 'entity_type' => 'petty_cash_entry', 'actor_user_id' => $actor->id]);
    auditEntry(['action_type' => 'deleted', 'entity_type' => 'App\Models\Job', 'actor_user_id' => null]);

    $this->actingAs($viewer);

    expect(Volt::test('admin.audit-log')->set('actionType', 'deleted')->viewData('rows')->count())->toBe(1);
    expect(Volt::test('admin.audit-log')->set('entityType', 'petty_cash_entry')->viewData('rows')->count())->toBe(1);
    expect(Volt::test('admin.audit-log')->set('actorId', $actor->id)->viewData('rows')->count())->toBe(1);
});

test('the entity filter matches every spelling of the same entity at once', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['entity_type' => 'App\Models\Job', 'action_type' => 'from_the_trait']);
    auditEntry(['entity_type' => 'job', 'action_type' => 'from_a_service']);
    auditEntry(['entity_type' => 'transport_job', 'action_type' => 'from_invoicing']);
    auditEntry(['entity_type' => 'petty_cash_entry', 'action_type' => 'unrelated']);

    // One "Job" choice, not three, and it finds all three spellings.
    $rows = Volt::test('admin.audit-log')->set('entityType', 'job')->viewData('rows');

    expect($rows->count())->toBe(3);
    expect($rows->pluck('action_type')->sort()->values()->all())
        ->toBe(['from_a_service', 'from_invoicing', 'from_the_trait']);
});

test('the entity dropdown offers each entity once, not once per spelling', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['entity_type' => 'App\Models\Job']);
    auditEntry(['entity_type' => 'job']);
    auditEntry(['entity_type' => 'transport_job']);
    auditEntry(['entity_type' => 'petty_cash_entry']);

    $options = Volt::test('admin.audit-log')->viewData('entityTypeOptions');

    expect($options)->toBe([
        'job' => 'Job',
        'petty_cash_entry' => 'Petty cash entry',
    ]);
});

test('the date window excludes entries outside it', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['action_type' => 'recent', 'created_at' => now()->subDays(2)]);
    auditEntry(['action_type' => 'ancient', 'created_at' => now()->subMonths(8)]);

    // Default window is the last 30 days, so the old entry is out of scope.
    $rows = Volt::test('admin.audit-log')->viewData('rows');
    expect($rows->pluck('action_type')->all())->toBe(['recent']);

    // "All time" clears the window and brings it back.
    $all = Volt::test('admin.audit-log')
        ->call('applyRange', 'all')
        ->viewData('rows');

    expect($all->pluck('action_type')->all())->toContain('ancient');
});

test('resetting filters restores the default 30 day window and clears the rest', function () {
    $this->actingAs(auditUser('owner'));

    $component = Volt::test('admin.audit-log')
        ->set('search', 'anything')
        ->set('actionType', 'deleted')
        ->set('sort', 'asc')
        ->call('applyRange', 'all')
        ->call('resetFilters');

    $component
        ->assertSet('search', '')
        ->assertSet('actionType', '')
        ->assertSet('sort', 'desc')
        ->assertSet('dateFrom', now()->subDays(29)->toDateString())
        ->assertSet('dateTo', now()->toDateString());
});

test('a hand-edited perPage in the URL falls back to the default', function () {
    $this->actingAs(auditUser('owner'));

    Volt::test('admin.audit-log', ['perPage' => 999999])
        ->assertSet('perPage', 50);
});

// -----------------------------------------------------------------
// 4. Headline counts
// -----------------------------------------------------------------

test('the counts describe the filtered window', function () {
    $viewer = auditUser('owner');
    $a = auditUser('developer');
    $b = auditUser('super_admin');

    auditEntry(['actor_user_id' => $a->id, 'action_type' => 'updated']);
    auditEntry(['actor_user_id' => $a->id, 'action_type' => 'deleted']);
    auditEntry(['actor_user_id' => $b->id, 'action_type' => 'petty_cash_rejected']);
    // Outside the default 30-day window, so none of the counts should see it.
    auditEntry(['actor_user_id' => $b->id, 'action_type' => 'deleted', 'created_at' => now()->subMonths(6)]);

    $this->actingAs($viewer);

    $stats = Volt::test('admin.audit-log')->viewData('stats');

    expect($stats['events'])->toBe(3);
    expect($stats['actors'])->toBe(2);
    // "deleted" plus the rejection, but not the out-of-window deletion.
    expect($stats['destructive'])->toBe(2);
    expect($stats['latest'])->not->toBeNull();
});

test('reversal counting catches rejections and cancellations, not just deletes', function () {
    $this->actingAs(auditUser('owner'));

    auditEntry(['action_type' => 'deleted']);
    auditEntry(['action_type' => 'petty_cash_rejected']);
    auditEntry(['action_type' => 'order_cancelled']);
    auditEntry(['action_type' => 'petty_cash_approved']);

    $stats = Volt::test('admin.audit-log')->viewData('stats');

    expect($stats['destructive'])->toBe(3);
});

// -----------------------------------------------------------------
// 5. The before/after diff
// -----------------------------------------------------------------

test('credentials are redacted out of the diff', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry([
        'entity_type' => 'App\Models\User',
        'action_type' => 'updated',
        'before_json' => [
            'name' => 'Old Name',
            'password' => '$2y$10$oldhashvaluehere',
            'remember_token' => 'oldtokenvalue',
            'two_factor_secret' => 'OLDSECRET',
        ],
        'after_json' => [
            'name' => 'New Name',
            'password' => '$2y$10$newhashvaluehere',
            'remember_token' => 'newtokenvalue',
            'two_factor_secret' => 'NEWSECRET',
        ],
    ]);

    $diff = collect(Volt::test('admin.audit-log')->instance()->diffRows($log))->keyBy('key');

    expect($diff['name']['before'])->toBe('Old Name');
    expect($diff['name']['after'])->toBe('New Name');
    expect($diff['name']['changed'])->toBeTrue();

    foreach (['password', 'remember_token', 'two_factor_secret'] as $secret) {
        expect($diff[$secret]['redacted'])->toBeTrue();
        expect($diff[$secret]['before'])->toBe('••••••••');
        expect($diff[$secret]['after'])->toBe('••••••••');
    }

    // And nothing leaks into the rendered page either.
    $this->get(route('admin.audit-log'))
        ->assertOk()
        ->assertDontSee('oldhashvaluehere')
        ->assertDontSee('oldtokenvalue')
        ->assertDontSee('OLDSECRET');
});

test('the diff drops bookkeeping columns and flags only real changes', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry([
        'before_json' => ['id' => 7, 'status' => 'planned', 'vin' => 'ABC123', 'updated_at' => '2026-01-01 00:00:00'],
        'after_json' => ['id' => 7, 'status' => 'delivered', 'vin' => 'ABC123', 'updated_at' => '2026-02-01 00:00:00'],
    ]);

    $diff = collect(Volt::test('admin.audit-log')->instance()->diffRows($log));

    expect($diff->pluck('key')->all())->toBe(['status', 'vin']);
    expect($diff->firstWhere('key', 'status')['changed'])->toBeTrue();
    expect($diff->firstWhere('key', 'vin')['changed'])->toBeFalse();
});

test('a creation has no before side and is not reported as changed', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry([
        'action_type' => 'created',
        'before_json' => null,
        'after_json' => ['status' => 'received', 'archived_at' => null],
    ]);

    $diff = collect(Volt::test('admin.audit-log')->instance()->diffRows($log))->keyBy('key');

    expect($diff['status']['before'])->toBe('—');
    expect($diff['status']['after'])->toBe('received');
    expect($diff['status']['changed'])->toBeFalse();
    // Null on the after side still renders as a dash rather than blank.
    expect($diff['archived_at']['after'])->toBe('—');
});

test('nested and boolean values are rendered readably', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry([
        'before_json' => ['flagged' => false, 'meta' => ['a' => 1]],
        'after_json' => ['flagged' => true, 'meta' => ['a' => 2]],
    ]);

    $diff = collect(Volt::test('admin.audit-log')->instance()->diffRows($log))->keyBy('key');

    expect($diff['flagged']['before'])->toBe('false');
    expect($diff['flagged']['after'])->toBe('true');
    expect($diff['meta']['after'])->toBe('{"a":2}');
});

// -----------------------------------------------------------------
// 6. Entity labelling and deep links
// -----------------------------------------------------------------

test('the three entity_type conventions collapse onto one canonical key', function () {
    $this->actingAs(auditUser('owner'));
    $page = Volt::test('admin.audit-log')->instance();

    expect($page->canonicalEntity('App\Models\Job'))->toBe('job');
    expect($page->canonicalEntity('job'))->toBe('job');
    expect($page->canonicalEntity('transport_job'))->toBe('job');
    expect($page->canonicalEntity('petty_cash_entry'))->toBe('petty_cash_entry');
    expect($page->canonicalEntity(null))->toBeNull();
});

test('entity types are labelled for humans regardless of which writer produced them', function () {
    $this->actingAs(auditUser('owner'));
    $page = Volt::test('admin.audit-log')->instance();

    expect($page->entityLabel('App\Models\Job'))->toBe('Job');
    expect($page->entityLabel('petty_cash_entry'))->toBe('Petty cash entry');
    expect($page->entityLabel('system_setting'))->toBe('System setting');
    expect($page->entityLabel(null))->toBe('—');
});

test('a job entry deep-links to the order regardless of the entity_type spelling', function (string $spelling) {
    $this->actingAs(auditUser('owner'));

    $job = auditJob();
    $log = auditEntry(['entity_type' => $spelling, 'entity_id' => $job->id]);

    $links = Volt::test('admin.audit-log')->viewData('entityLinks');

    expect($links)->toHaveKey($log->id);
    expect($links[$log->id])->toBe(route('admin.orders.show', $job->id));
})->with(['App\Models\Job', 'job', 'transport_job']);

test('no link is offered for a record that no longer exists', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry(['entity_type' => 'App\Models\Job', 'entity_id' => 987654]);

    $links = Volt::test('admin.audit-log')->viewData('entityLinks');

    expect($links)->not->toHaveKey($log->id);
});

test('a soft-deleted job gets no link, because the order page could not resolve it', function () {
    $this->actingAs(auditUser('owner'));

    $job = auditJob();
    $log = auditEntry(['entity_type' => 'job', 'entity_id' => $job->id]);
    $job->delete();

    $links = Volt::test('admin.audit-log')->viewData('entityLinks');

    expect($links)->not->toHaveKey($log->id);
});

test('entries with no entity id are never linked', function () {
    $this->actingAs(auditUser('owner'));

    $log = auditEntry(['entity_type' => 'customer_invoicing', 'entity_id' => null]);

    expect(Volt::test('admin.audit-log')->viewData('entityLinks'))->not->toHaveKey($log->id);
});

// -----------------------------------------------------------------
// 7. CSV export
// -----------------------------------------------------------------

test('the export streams a UTF-8 CSV of the filtered entries', function () {
    $viewer = auditUser('owner', ['name' => 'Ayanda Dube']);

    auditEntry([
        'actor_user_id' => $viewer->id,
        'action_type' => 'petty_cash_approved',
        'entity_type' => 'petty_cash_entry',
        'entity_id' => 55,
        'reason' => 'Slip verified against receipt',
        'before_json' => ['status' => 'pending'],
        'after_json' => ['status' => 'approved'],
    ]);

    $this->actingAs($viewer);

    $component = Volt::test('admin.audit-log');
    /** @var StreamedResponse $response */
    $response = $component->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->headers->get('Content-Disposition'))->toContain('audit-log_');
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");

    expect($body)->toContain('Ayanda Dube');
    expect($body)->toContain('Petty cash approved');
    expect($body)->toContain('Petty cash entry');
    expect($body)->toContain('Slip verified against receipt');
    // The change summary is flattened into a single readable cell.
    expect($body)->toContain('status: pending -> approved');
});

test('the export redacts credentials just like the screen does', function () {
    $viewer = auditUser('owner');

    auditEntry([
        'actor_user_id' => $viewer->id,
        'entity_type' => 'App\Models\User',
        'before_json' => ['password' => 'supersecrethash'],
        'after_json' => ['password' => 'anothersecrethash'],
    ]);

    $this->actingAs($viewer);

    $response = Volt::test('admin.audit-log')->instance()->exportCsv();
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->not->toContain('supersecrethash');
    expect($body)->not->toContain('anothersecrethash');
});

test('exporting the audit log is itself audited, with the filters used', function () {
    $viewer = auditUser('owner');
    $this->actingAs($viewer);

    Volt::test('admin.audit-log')
        ->set('actionType', 'deleted')
        ->set('search', 'chasing something')
        ->instance()
        ->exportCsv();

    $entry = AuditLog::where('action_type', 'audit_log_exported')->firstOrFail();

    expect($entry->actor_user_id)->toBe($viewer->id);
    expect($entry->entity_type)->toBe('audit_log');
    expect($entry->after_json['action_type'])->toBe('deleted');
    expect($entry->after_json['search'])->toBe('chasing something');
});

test('a role without access gets nothing out of the export path', function () {
    $this->actingAs(auditUser('accounts'));

    // The page gate stops them at mount(), and because exportCsv() also
    // writes an audit entry, "no entry was written" is direct evidence the
    // export body never ran for them.
    $this->get(route('admin.audit-log'))->assertForbidden();

    expect(AuditLog::where('action_type', 'audit_log_exported')->count())->toBe(0);
});

// -----------------------------------------------------------------
// 8. Immutability of the trail itself
// -----------------------------------------------------------------

test('an explicitly supplied created_at is kept, so history can be backfilled', function () {
    $when = now()->subMonths(3)->startOfMinute();

    $log = auditEntry(['created_at' => $when]);

    expect($log->fresh()->created_at->equalTo($when))->toBeTrue();
});

test('created_at still defaults to now when the caller omits it', function () {
    $log = AuditLog::create([
        'action_type' => 'updated',
        'entity_type' => 'App\Models\Job',
        'entity_id' => 1,
    ]);

    expect($log->created_at)->not->toBeNull();
    expect($log->created_at->diffInSeconds(now()))->toBeLessThan(5);
});

test('audit entries remain immutable', function () {
    $log = auditEntry(['reason' => 'original reason']);

    $log->reason = 'tampered';
    $log->save();

    expect($log->fresh()->reason)->toBe('original reason');

    $log->delete();

    expect(AuditLog::find($log->id))->not->toBeNull();
});

// -----------------------------------------------------------------
// 9. Pagination
// -----------------------------------------------------------------

test('the list paginates at the chosen page size', function () {
    $this->actingAs(auditUser('owner'));

    foreach (range(1, 30) as $i) {
        auditEntry(['action_type' => 'event_' . $i, 'created_at' => now()->subMinutes($i)]);
    }

    $rows = Volt::test('admin.audit-log')->set('perPage', 25)->viewData('rows');

    expect($rows->count())->toBe(25);
    expect($rows->total())->toBe(30);
    expect($rows->hasPages())->toBeTrue();
});

test('an empty result set shows the empty state rather than a bare table', function () {
    $this->actingAs(auditUser('owner'));

    $this->get(route('admin.audit-log'))
        ->assertOk()
        ->assertSee('No matching activity');
});
