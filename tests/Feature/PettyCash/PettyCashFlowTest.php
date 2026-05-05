<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\PettyCashEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);
    Role::create(['name' => 'Customer Owner', 'slug' => 'customer_owner', 'tier' => 'customer']);

    Storage::fake('local');
});

function pettyDriverAndJob(): array
{
    $driver = User::factory()->create(['name' => 'Test Driver']);
    $driver->assignRole('driver');

    $company = Company::factory()->create();

    $job = Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-' . Str::upper(Str::random(6)),
        'job_type' => 'transport',
        'status' => Job::STATUS_DRIVER_ASSIGNED,
        'company_id' => $company->id,
        'created_by_user_id' => $driver->id,
        'driver_user_id' => $driver->id,
        'scheduled_date' => now()->toDateString(),
    ]);

    return [$driver, $job, $company];
}

function pettyOps(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('operations_controller');
    return $u;
}

it('driver can submit a petty cash entry with photo and amount', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('expenseCategory', JobDocument::CATEGORY_FUEL_SLIP)
        ->set('expenseAmount', '152.50')
        ->set('expenseMerchant', 'Engen Bedfordview')
        ->set('expensePhoto', UploadedFile::fake()->image('slip.jpg'))
        ->call('submitExpense')
        ->assertHasNoErrors();

    $entry = PettyCashEntry::first();
    expect($entry)->not->toBeNull();
    expect($entry->driver_user_id)->toBe($driver->id);
    expect($entry->job_id)->toBe($job->id);
    expect($entry->amount_cents)->toBe(15250);
    expect($entry->status)->toBe(PettyCashEntry::STATUS_SUBMITTED);
    expect($entry->merchant_name)->toBe('Engen Bedfordview');
    expect($entry->document_id)->not->toBeNull();
    expect(JobDocument::where('id', $entry->document_id)->where('category', JobDocument::CATEGORY_FUEL_SLIP)->exists())->toBeTrue();
});

it('rejects a petty cash submission with no photo', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('expenseAmount', '100.00')
        ->call('submitExpense')
        ->assertHasErrors(['expensePhoto']);

    expect(PettyCashEntry::count())->toBe(0);
});

it('rejects a petty cash submission with zero or negative amount', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('expenseAmount', '0')
        ->set('expensePhoto', UploadedFile::fake()->image('a.jpg'))
        ->call('submitExpense')
        ->assertHasErrors(['expenseAmount']);
});

it('accepts the new accommodation_slip category', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('expenseCategory', JobDocument::CATEGORY_ACCOMMODATION_SLIP)
        ->set('expenseAmount', '850.00')
        ->set('expensePhoto', UploadedFile::fake()->image('hotel.jpg'))
        ->call('submitExpense')
        ->assertHasNoErrors();

    expect(PettyCashEntry::where('category', JobDocument::CATEGORY_ACCOMMODATION_SLIP)->exists())->toBeTrue();
});

it('approves a submitted entry through the model and sets the approver + approved_at', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 10000,
    ]);

    expect($entry->approve($ops))->toBeTrue();
    $entry->refresh();
    expect($entry->status)->toBe(PettyCashEntry::STATUS_APPROVED);
    expect($entry->approved_by_user_id)->toBe($ops->id);
    expect($entry->approved_at)->not->toBeNull();
});

it('refuses to re-approve an already-approved entry', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 10000,
        'status' => PettyCashEntry::STATUS_APPROVED,
    ]);

    expect($entry->approve($ops))->toBeFalse();
});

it('rejects an entry with a reason', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 10000,
    ]);

    expect($entry->reject($ops, 'Slip illegible'))->toBeTrue();
    $entry->refresh();
    expect($entry->status)->toBe(PettyCashEntry::STATUS_REJECTED);
    expect($entry->rejection_reason)->toBe('Slip illegible');
});

it('marks an approved entry as reimbursed with EFT reference', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 10000,
        'status' => PettyCashEntry::STATUS_APPROVED,
    ]);

    expect($entry->reimburse($ops, 'EFT-2026-05-001'))->toBeTrue();
    $entry->refresh();
    expect($entry->status)->toBe(PettyCashEntry::STATUS_REIMBURSED);
    expect($entry->reimbursement_reference)->toBe('EFT-2026-05-001');
});

it('admin can approve an entry through the review queue and writes an audit log', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 12500,
    ]);

    Volt::actingAs($ops)->test('admin.petty-cash.index')
        ->call('approveEntry', $entry->id)
        ->assertHasNoErrors();

    expect($entry->fresh()->status)->toBe(PettyCashEntry::STATUS_APPROVED);
    expect(AuditLog::where('action_type', 'petty_cash_approved')->where('entity_id', $entry->id)->exists())->toBeTrue();
});

it('admin reject requires a reason', function () {
    [$driver, $job] = pettyDriverAndJob();
    $ops = pettyOps();

    $entry = PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 5000,
    ]);

    Volt::actingAs($ops)->test('admin.petty-cash.index')
        ->call('rejectEntry', $entry->id);
    expect($entry->fresh()->status)->toBe(PettyCashEntry::STATUS_SUBMITTED);

    Volt::actingAs($ops)->test('admin.petty-cash.index')
        ->set('reasonDrafts.' . $entry->id, 'Receipt missing')
        ->call('rejectEntry', $entry->id);

    expect($entry->fresh()->status)->toBe(PettyCashEntry::STATUS_REJECTED);
    expect($entry->fresh()->rejection_reason)->toBe('Receipt missing');
});

it('customer is forbidden from /admin/petty-cash', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer_owner');

    $this->actingAs($customer)->get('/admin/petty-cash')->assertForbidden();
});

it('driver-level consolidated view shows only the drivers own entries', function () {
    [$driver, $job] = pettyDriverAndJob();
    [$otherDriver, $otherJob] = pettyDriverAndJob();

    PettyCashEntry::create([
        'job_id' => $job->id,
        'driver_user_id' => $driver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 10000,
    ]);
    PettyCashEntry::create([
        'job_id' => $otherJob->id,
        'driver_user_id' => $otherDriver->id,
        'category' => JobDocument::CATEGORY_FUEL_SLIP,
        'amount_cents' => 25000,
    ]);

    $entries = collect(Volt::actingAs($driver)
        ->test('driver.expenses')
        ->set('range', 'all')
        ->viewData('entries'));

    expect($entries)->toHaveCount(1);
    expect($entries->first()->driver_user_id)->toBe($driver->id);
});

it('driver-level totals split by status', function () {
    [$driver, $job] = pettyDriverAndJob();

    PettyCashEntry::create(['job_id' => $job->id, 'driver_user_id' => $driver->id, 'category' => JobDocument::CATEGORY_FUEL_SLIP, 'amount_cents' => 10000, 'status' => PettyCashEntry::STATUS_SUBMITTED]);
    PettyCashEntry::create(['job_id' => $job->id, 'driver_user_id' => $driver->id, 'category' => JobDocument::CATEGORY_FUEL_SLIP, 'amount_cents' => 25000, 'status' => PettyCashEntry::STATUS_APPROVED]);
    PettyCashEntry::create(['job_id' => $job->id, 'driver_user_id' => $driver->id, 'category' => JobDocument::CATEGORY_FUEL_SLIP, 'amount_cents' => 5000,  'status' => PettyCashEntry::STATUS_REJECTED]);
    PettyCashEntry::create(['job_id' => $job->id, 'driver_user_id' => $driver->id, 'category' => JobDocument::CATEGORY_FUEL_SLIP, 'amount_cents' => 15000, 'status' => PettyCashEntry::STATUS_REIMBURSED]);

    $totals = Volt::actingAs($driver)
        ->test('driver.expenses')
        ->set('range', 'all')
        ->viewData('totals');

    expect($totals['submitted'])->toBe(10000);
    expect($totals['approved'])->toBe(25000);
    expect($totals['rejected'])->toBe(5000);
    expect($totals['reimbursed'])->toBe(15000);
});

it('damage tab accepts severity, location and notes and writes them as JSON', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('damageSeverity', 'high')
        ->set('damageLocation', 'front bumper, driver side')
        ->set('damageNotes', 'Hairline crack')
        ->set('damagePhoto', UploadedFile::fake()->image('damage.jpg'))
        ->call('submitDamage')
        ->assertHasNoErrors();

    $doc = JobDocument::where('category', JobDocument::CATEGORY_DAMAGE_PHOTO)->first();
    expect($doc)->not->toBeNull();
    $payload = json_decode($doc->notes, true);
    expect($payload['severity'])->toBe('high');
    expect($payload['location'])->toBe('front bumper, driver side');
    expect($payload['notes'])->toBe('Hairline crack');
});

it('damage submission requires a location and a photo', function () {
    [$driver, $job] = pettyDriverAndJob();

    Volt::actingAs($driver)->test('driver.job', ['job' => $job])
        ->set('damageSeverity', 'high')
        ->call('submitDamage')
        ->assertHasErrors(['damageLocation', 'damagePhoto']);
});
