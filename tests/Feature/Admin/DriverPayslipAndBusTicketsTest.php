<?php

use App\Models\Company;
use App\Models\DriverBusTicket;
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\Location;
use App\Models\PettyCashEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------
// Shared setup -- deliberately duplicated with TridentFieldRequestsTest
// so this file reads standalone and failures point here directly.
// -----------------------------------------------------------------

beforeEach(function () {
    foreach ([
        ['owner', 'Owner', 'internal'],
        ['developer', 'Developer', 'internal'],
        ['accounts', 'Accounts', 'internal'],
        ['super_admin', 'Super Admin', 'internal'],
        ['operations_controller', 'Ops Controller', 'internal'],
        ['dispatcher', 'Dispatcher', 'internal'],
        ['ops_manager', 'Ops Manager', 'internal'],
        ['driver', 'Driver', 'driver'],
    ] as [$slug, $name, $tier]) {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'tier' => $tier]);
    }
});

function dpUser(string $slug): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($slug);
    return $u;
}

function dpPlatformCompany(): Company
{
    return Company::query()->where('is_platform_owner', true)->first()
        ?: Company::factory()->create([
            'name' => 'ProSelver',
            'type' => Company::TYPE_OEM,
            'is_platform_owner' => true,
        ]);
}

function dpDriver(?int $rateCents = null): User
{
    $platform = dpPlatformCompany();
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('driver');
    $user->companies()->syncWithoutDetaching([$platform->id]);
    DriverProfile::create([
        'user_id' => $user->id,
        'rate_per_movement_cents' => $rateCents,
    ]);
    return $user;
}

function dpLocation(string $city): Location
{
    return Location::create([
        'company_id'   => null,
        'company_name' => $city . ' Depot',
        'address'      => $city,
        'city'         => $city,
        'is_active'    => true,
    ]);
}

function dpJob(User $driver, Carbon $deliveredAt, array $extras = []): Job
{
    $oem = Company::factory()->create(['type' => Company::TYPE_OEM]);
    $creator = User::factory()->create();
    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $oem->id,
        'created_by_user_id' => $creator->id,
        'executor_type' => Job::EXECUTOR_PROSELVER,
        'driver_user_id' => $driver->id,
        'vin' => 'VIN' . Str::upper(Str::random(8)),
        'pickup_location_id' => dpLocation('Johannesburg')->id,
        'delivery_location_id' => dpLocation('Port Elizabeth')->id,
        'scheduled_date' => $deliveredAt->toDateString(),
        'collected_at' => $deliveredAt->copy()->subDay(),
        'delivered_at' => $deliveredAt,
    ], $extras));
}

// -----------------------------------------------------------------
// 1. Payslip page gate
// -----------------------------------------------------------------

test('payslip page 403s dispatchers and ops; 200s for accounts / owner / developer', function () {
    $driver = dpDriver(30000);
    $url = route('admin.drivers.payslip', ['user' => $driver->id]);

    $this->actingAs(dpUser('dispatcher'))->get($url)->assertForbidden();
    $this->actingAs(dpUser('operations_controller'))->get($url)->assertForbidden();

    $this->actingAs(dpUser('accounts'))->get($url)->assertOk();
    $this->actingAs(dpUser('owner'))->get($url)->assertOk();
    $this->actingAs(dpUser('developer'))->get($url)->assertOk();
});

test('payslip page 404s if the bound user is not a driver', function () {
    $notDriver = dpUser('operations_controller');
    $this->actingAs(dpUser('accounts'))
        ->get(route('admin.drivers.payslip', ['user' => $notDriver->id]))
        ->assertNotFound();
});

// -----------------------------------------------------------------
// 2. savePay() writes override + sets provenance; empty clears it
// -----------------------------------------------------------------

test('savePay writes driver_pay_amount + note + set_by + set_at; empty amount clears the override', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000);
    $inMonth = now()->startOfMonth()->addDays(5);
    $job = dpJob($driver, $inMonth);

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', $inMonth->format('Y-m'))
        ->call('savePay', $job->id, '550.00', 'double-haul');

    $job->refresh();
    expect((float) $job->driver_pay_amount)->toBe(550.0)
        ->and($job->driver_pay_note)->toBe('double-haul')
        ->and($job->driver_pay_set_by_user_id)->toBe($accounts->id)
        ->and($job->driver_pay_set_at)->not->toBeNull();

    // Clearing restores NULL -> falls back to profile rate.
    $c->call('savePay', $job->id, '', '');
    $job->refresh();
    expect($job->driver_pay_amount)->toBeNull()
        ->and($job->driver_pay_note)->toBeNull();
});

test('savePay rejects a non-numeric amount and does not persist', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000);
    $job = dpJob($driver, now()->startOfMonth()->addDays(5));

    $this->actingAs($accounts);

    Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', now()->format('Y-m'))
        ->call('savePay', $job->id, 'nonsense', null);

    expect($job->fresh()->driver_pay_amount)->toBeNull();
});

// -----------------------------------------------------------------
// 3. Totals: gross from override OR rate, net = gross - bus charged,
//    petty cash is reference only
// -----------------------------------------------------------------

test('payslip totals combine per-trip overrides with the profile rate for the rest', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000); // R300 / move
    $inMonth = now()->startOfMonth()->addDays(5);

    $j1 = dpJob($driver, $inMonth, ['driver_pay_amount' => 500.0]); // override
    $j2 = dpJob($driver, $inMonth->copy()->addDay());               // uses rate R300
    $j3 = dpJob($driver, $inMonth->copy()->addDays(2));             // uses rate R300

    // One cancelled trip in-month -- should be excluded from gross.
    dpJob($driver, $inMonth, [
        'status'       => Job::STATUS_CANCELLED,
        'cancelled_at' => $inMonth,
    ]);

    // Bus ticket charged to driver -- deducts R180 from net.
    $t = DriverBusTicket::create([
        'driver_user_id'    => $driver->id,
        'travel_date'       => $inMonth,
        'amount_cents'      => 18000,
        'created_by_user_id'=> $accounts->id,
        'destination_label' => 'Port Elizabeth',
    ]);
    $t->markChargedToDriver($accounts, 'driver no-show');

    // Bus ticket voided -- NO payslip impact.
    $v = DriverBusTicket::create([
        'driver_user_id'    => $driver->id,
        'travel_date'       => $inMonth,
        'amount_cents'      => 25000,
        'created_by_user_id'=> $accounts->id,
        'destination_label' => 'Durban',
    ]);
    $v->markVoided($accounts, 'bus company issued refund');

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', $inMonth->format('Y-m'));

    expect($c->viewData('grossEarnings'))->toBe(500.0 + 300.0 + 300.0)   // override + 2 x rate
        ->and($c->viewData('busDeductions'))->toBe(180.0)                 // only "charged" counts
        ->and($c->viewData('netPay'))->toBe(500.0 + 300.0 + 300.0 - 180.0)
        ->and($c->viewData('cancelled')->count())->toBe(1);               // cancellation listed, not paid

    unset($j1, $j2, $j3);
});

test('payslip gross is 0 and movements is empty for a driver with no in-window jobs', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(20000);
    $prevMonth = now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(3);
    dpJob($driver, $prevMonth);

    $this->actingAs($accounts);
    $c = Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', now()->format('Y-m'));

    expect($c->viewData('movements')->count())->toBe(0)
        ->and($c->viewData('grossEarnings'))->toBe(0.0);
});

test('payslip surfaces petty-cash on cancelled trips split by open vs cleared', function () {
    // The audit question is "where did the money go?" -- a cancelled
    // trip that had an advance must be reconciled another way
    // (refunded, transferred to a replacement vehicle, or absorbed
    // with a written explanation).  The payslip must show the
    // reconciliation state per cancelled row so cash can't vanish.
    $accounts = dpUser('accounts');
    $driver   = dpDriver(30000);
    $inMonth  = now()->startOfMonth()->addDays(5);

    // Cancelled trip #1: advance issued, cleared with a note.
    $clearedJob = dpJob($driver, $inMonth, [
        'status'                               => Job::STATUS_CANCELLED,
        'cancelled_at'                         => $inMonth,
        'advance_total'                        => 500.0,
        'advance_issued_at'                    => $inMonth->copy()->subDay(),
        'issued_cancellation_cleared_at'       => $inMonth->copy()->addDay(),
        'issued_cancellation_cleared_by_user_id' => $accounts->id,
        'issued_cancellation_cleared_note'     => 'Driver returned the cash at the depot.',
    ]);

    // Cancelled trip #2: advance issued, STILL open.
    $openJob = dpJob($driver, $inMonth->copy()->addDay(), [
        'status'            => Job::STATUS_CANCELLED,
        'cancelled_at'      => $inMonth->copy()->addDay(),
        'advance_total'     => 710.0,
        'advance_issued_at' => $inMonth,
    ]);

    // Cancelled trip #3: no advance -- shouldn't appear in totals.
    dpJob($driver, $inMonth->copy()->addDays(2), [
        'status'       => Job::STATUS_CANCELLED,
        'cancelled_at' => $inMonth->copy()->addDays(2),
    ]);

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', $inMonth->format('Y-m'));

    // Totals: R500 + R710 = R1210 out across cancelled trips; only
    // R710 is still open.
    expect($c->viewData('cancelledAdvanceTotal'))->toBe(1210.0)
        ->and($c->viewData('cancelledAdvanceOpen'))->toBe(710.0)
        ->and($c->viewData('cancelled')->count())->toBe(3);

    // The HTTP render must show:
    //   - R500 + R710 amounts against the two cancelled rows
    //   - the "Open query" indicator for the unresolved R710
    //   - the written explanation from the cleared row
    $this->get(route('admin.drivers.payslip', [
        'user'  => $driver->id,
        'month' => $inMonth->format('Y-m'),
    ]))
        ->assertOk()
        ->assertSee($openJob->job_number)
        ->assertSee($clearedJob->job_number)
        ->assertSee('R 710.00')
        ->assertSee('R 500.00')
        ->assertSee('Open query')
        ->assertSee('Driver returned the cash at the depot.')
        ->assertSee('Where did it go?');
});

test('payslip surfaces petty-cash advances issued per trip and sums them in the totals strip', function () {
    // Mirror of the Austin Ntseki scenario from the trip report:
    // ops issued R710 against a specific delivery (tolls + food) but
    // the driver never submitted slips.  The payslip must still show
    // the R710 against that trip and in the "petty cash advanced"
    // total; otherwise cash goes untracked.
    $accounts = dpUser('accounts');
    $driver   = dpDriver(30000);
    $inMonth  = now()->startOfMonth()->addDays(5);

    // Trip with an advance issued.
    $withAdvance = dpJob($driver, $inMonth, [
        'advance_tolls'        => 410.0,
        'advance_food'         => 300.0,
        'advance_total'        => 710.0,
        'advance_assigned_at'  => $inMonth->copy()->subDay(),
        'advance_assigned_by_user_id' => $accounts->id,
    ]);

    // Trip with no advance -- confirms null doesn't poison the sum.
    $withoutAdvance = dpJob($driver, $inMonth->copy()->addDay());

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.payslip', ['user' => $driver])
        ->set('month', $inMonth->format('Y-m'));

    expect($c->viewData('advancesIssued'))->toBe(710.0)
        ->and($c->viewData('slipsSubmitted'))->toBe(0.0); // no PettyCashEntry rows -> 0

    // The HTTP render must also show the rand amount against the job
    // -- the user's complaint was that the trip report said R710 but
    // the payslip said nothing.
    $this->get(route('admin.drivers.payslip', [
        'user'  => $driver->id,
        'month' => $inMonth->format('Y-m'),
    ]))
        ->assertOk()
        ->assertSee($withAdvance->job_number)
        ->assertSee('R 710.00')
        ->assertSee('Petty cash advanced');

    unset($withoutAdvance);
});

// -----------------------------------------------------------------
// 4. PDF download
// -----------------------------------------------------------------

test('payslip PDF download returns 200 + application/pdf', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000);
    $inMonth = now()->startOfMonth()->addDays(3);
    dpJob($driver, $inMonth);

    $this->actingAs($accounts)
        ->get(route('admin.drivers.payslip.pdf', [
            'user'  => $driver->id,
            'month' => $inMonth->format('Y-m'),
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('payslip PDF download 403s a dispatcher', function () {
    $driver = dpDriver(30000);
    $this->actingAs(dpUser('dispatcher'))
        ->get(route('admin.drivers.payslip.pdf', ['user' => $driver->id]))
        ->assertForbidden();
});

// -----------------------------------------------------------------
// 5. DriverBusTicket lifecycle
// -----------------------------------------------------------------

test('bus-ticket lifecycle allows issued -> used; blocks changes once resolved', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver();

    $t = DriverBusTicket::create([
        'driver_user_id'    => $driver->id,
        'travel_date'       => now(),
        'amount_cents'      => 15000,
        'created_by_user_id'=> $accounts->id,
        'destination_label' => 'Cape Town',
    ]);

    expect($t->status)->toBe(DriverBusTicket::STATUS_ISSUED)
        ->and($t->uuid)->not->toBeEmpty();

    expect($t->markUsed($accounts))->toBeTrue();
    expect($t->fresh()->status)->toBe(DriverBusTicket::STATUS_USED);

    // Already-resolved tickets must not transition into another outcome.
    expect($t->fresh()->markVoided($accounts, 'try again'))->toBeFalse();
    expect($t->fresh()->markChargedToDriver($accounts, 'try again'))->toBeFalse();
});

test('bus-ticket void requires a non-empty reason; charge persists reason + resolver', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver();

    $t = DriverBusTicket::create([
        'driver_user_id'    => $driver->id,
        'travel_date'       => now(),
        'amount_cents'      => 22000,
        'created_by_user_id'=> $accounts->id,
        'destination_label' => 'East London',
    ]);

    expect($t->markVoided($accounts, '   '))->toBeFalse();
    expect($t->fresh()->status)->toBe(DriverBusTicket::STATUS_ISSUED);

    expect($t->markChargedToDriver($accounts, 'driver was a no-show'))->toBeTrue();
    $t->refresh();
    expect($t->status)->toBe(DriverBusTicket::STATUS_NOT_USED)
        ->and($t->not_used_outcome)->toBe(DriverBusTicket::OUTCOME_CHARGED_TO_DRIVER)
        ->and($t->not_used_reason)->toBe('driver was a no-show')
        ->and($t->resolved_by_user_id)->toBe($accounts->id)
        ->and($t->resolved_at)->not->toBeNull()
        ->and($t->isChargedToDriver())->toBeTrue();
});

test('inMonth + chargedToDriver + forDriver scopes filter correctly', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver();
    $other  = dpDriver();
    $month  = Carbon::now()->startOfMonth();

    // In month, charged.
    $a = DriverBusTicket::create([
        'driver_user_id' => $driver->id, 'travel_date' => $month,
        'amount_cents' => 10000, 'created_by_user_id' => $accounts->id,
        'destination_label' => 'A',
    ]);
    $a->markChargedToDriver($accounts, 'r');

    // In month, voided -- should be excluded by chargedToDriver scope.
    $b = DriverBusTicket::create([
        'driver_user_id' => $driver->id, 'travel_date' => $month,
        'amount_cents' => 5000, 'created_by_user_id' => $accounts->id,
        'destination_label' => 'B',
    ]);
    $b->markVoided($accounts, 'r');

    // Previous month, charged -- should be excluded by inMonth scope.
    $c = DriverBusTicket::create([
        'driver_user_id' => $driver->id, 'travel_date' => $month->copy()->subMonth(),
        'amount_cents' => 7000, 'created_by_user_id' => $accounts->id,
        'destination_label' => 'C',
    ]);
    $c->markChargedToDriver($accounts, 'r');

    // Different driver -- should be excluded by forDriver scope.
    $d = DriverBusTicket::create([
        'driver_user_id' => $other->id, 'travel_date' => $month,
        'amount_cents' => 9000, 'created_by_user_id' => $accounts->id,
        'destination_label' => 'D',
    ]);
    $d->markChargedToDriver($accounts, 'r');

    $sum = DriverBusTicket::query()
        ->forDriver($driver)
        ->inMonth($month)
        ->chargedToDriver()
        ->sum('amount_cents');

    expect($sum)->toBe(10000);
});

// -----------------------------------------------------------------
// 6. Bus-tickets management page: gate + issue + resolve
// -----------------------------------------------------------------

test('bus-tickets admin page is reachable by ops / accounts / owner, 403 for driver', function () {
    foreach (['operations_controller', 'dispatcher', 'ops_manager', 'accounts', 'owner', 'developer'] as $slug) {
        $this->actingAs(dpUser($slug))
            ->get(route('admin.drivers.bus-tickets'))
            ->assertOk();
    }

    $this->actingAs(dpUser('driver'))
        ->get(route('admin.drivers.bus-tickets'))
        ->assertForbidden();
});

test('issuing a ticket from the admin page creates a row and firing an outcome resolves it', function () {
    $ops = dpUser('operations_controller');
    $driver = dpDriver();

    $this->actingAs($ops);

    $c = Volt::test('admin.drivers.bus-tickets')
        ->call('openIssue')
        ->set('formDriver', $driver->id)
        ->set('formTravelDate', now()->toDateString())
        ->set('formAmount', '250.00')
        ->set('formBusCompany', 'Intercape')
        ->set('formDestinationLabel', 'Port Elizabeth')
        ->call('save');

    $t = DriverBusTicket::query()->where('driver_user_id', $driver->id)->firstOrFail();
    expect($t->amount_cents)->toBe(25000)
        ->and($t->bus_company)->toBe('Intercape')
        ->and($t->status)->toBe(DriverBusTicket::STATUS_ISSUED);

    // Mark used from the page.
    $c->call('markUsed', $t->id);
    expect($t->fresh()->status)->toBe(DriverBusTicket::STATUS_USED);
});

// -----------------------------------------------------------------
// 7. drivers.pay summary surfaces bus deductions + payslip link
// -----------------------------------------------------------------

test('drivers.pay summary includes bus deductions and net pay per driver', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000);
    $inMonth = now()->startOfMonth()->addDays(5);

    // Two moves in-month -> R600 earnings.
    dpJob($driver, $inMonth);
    dpJob($driver, $inMonth->copy()->addDay());

    // Bus ticket charged to driver -> R150 deduction.
    $t = DriverBusTicket::create([
        'driver_user_id'    => $driver->id,
        'travel_date'       => $inMonth,
        'amount_cents'      => 15000,
        'created_by_user_id'=> $accounts->id,
        'destination_label' => 'Cape Town',
    ]);
    $t->markChargedToDriver($accounts, 'no-show');

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.pay')->set('month', $inMonth->format('Y-m'));
    $rows = $c->viewData('rows');
    $row = $rows->firstWhere('id', $driver->id);

    expect($row['moves'])->toBe(2)
        ->and($row['earnings'])->toBe(600.0)
        ->and($row['bus_charged'])->toBe(150.0)
        ->and($row['net_pay'])->toBe(450.0);
});

test('drivers.pay summary earnings honour per-trip overrides', function () {
    $accounts = dpUser('accounts');
    $driver = dpDriver(30000);
    $inMonth = now()->startOfMonth()->addDays(5);

    // 1 override at R700 + 1 at rate R300 = R1000.
    dpJob($driver, $inMonth, ['driver_pay_amount' => 700.0]);
    dpJob($driver, $inMonth->copy()->addDay());

    $this->actingAs($accounts);

    $c = Volt::test('admin.drivers.pay')->set('month', $inMonth->format('Y-m'));
    $row = $c->viewData('rows')->firstWhere('id', $driver->id);

    expect($row['moves'])->toBe(2)
        ->and($row['earnings'])->toBe(1000.0);
});

// -----------------------------------------------------------------
// 8. Petty cash tab strip includes the Bus Tickets tab for ops
// -----------------------------------------------------------------

test('petty-cash section tabs expose Bus tickets to ops / accounts; the per-driver payslip lights up Driver pay', function () {
    $this->actingAs(dpUser('accounts'))
        ->get(route('admin.petty-cash.index'))
        ->assertOk()
        ->assertSee(route('admin.drivers.bus-tickets'))
        ->assertSee('Bus tickets');

    $this->actingAs(dpUser('dispatcher'))
        ->get(route('admin.petty-cash.index'))
        ->assertOk()
        ->assertSee(route('admin.drivers.bus-tickets'));
});
