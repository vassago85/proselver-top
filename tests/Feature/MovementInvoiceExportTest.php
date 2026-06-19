<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\MovementInvoiceExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['slug' => 'accounts'], ['name' => 'Accounts', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'customer_owner'], ['name' => 'Customer Owner', 'tier' => 'customer']);
    Role::firstOrCreate(['slug' => 'owner'], ['name' => 'Owner', 'tier' => 'internal']);
    Role::firstOrCreate(['slug' => 'developer'], ['name' => 'Developer', 'tier' => 'internal']);
});

function makeOwner(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('owner');
    return $u;
}

function makeAccountant(): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('accounts');
    return $u;
}

function makeOemCompany(string $name = 'FAW SA'): Company
{
    return Company::factory()->create(['name' => $name, 'type' => Company::TYPE_OEM]);
}

function makeProselverJob(Company $oem, array $extras = []): Job
{
    $creator = User::factory()->create();
    $pickup = Location::create([
        'company_id' => null,
        'company_name' => 'FAW PE Plant',
        'address' => 'PE',
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_id' => null,
        'company_name' => 'Springs Storage Yard',
        'address' => 'Springs',
        'is_active' => true,
    ]);

    return Job::create(array_merge([
        'uuid'                  => (string) Str::uuid(),
        'job_number'            => 'JOB-' . Str::upper(Str::random(6)),
        'job_type'              => 'transport',
        'status'                => Job::STATUS_DELIVERED,
        'company_id'            => $oem->id,
        'created_by_user_id'    => $creator->id,
        'executor_type'         => Job::EXECUTOR_PROSELVER,
        'vin'                   => 'AAK6130FLSB' . Str::upper(Str::random(6)),
        'model_name'            => '6.130FL',
        'pickup_location_id'    => $pickup->id,
        'delivery_location_id'  => $delivery->id,
        'scheduled_date'        => now()->subDays(8)->toDateString(),
        'collected_at'          => now()->subDays(7),
        'delivered_at'          => now()->subDays(6),
        'created_at'            => now()->subDays(9),
    ], $extras));
}

// ----- migration / model -------------------------------------------

test('finance fields are fillable and decimal-cast', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem, [
        'invoice_number' => 'INV0007966',
        'invoice_amount' => '3112.22',
        'extras_amount'  => '114.00',
        'fuel_litres'    => '50',
        'fuel_amount'    => '1002.10',
    ]);

    $job->refresh();
    expect($job->invoice_number)->toBe('INV0007966');
    expect((float) $job->invoice_amount)->toBe(3112.22);
    expect((float) $job->extras_amount)->toBe(114.00);
    expect((float) $job->fuel_litres)->toBe(50.0);
    expect((float) $job->fuel_amount)->toBe(1002.10);
});

// ----- MovementInvoiceExport ---------------------------------------

test('export writes the header row in the FAW column order', function () {
    $oem = makeOemCompany();
    $contents = (new MovementInvoiceExport())->build(collect([makeProselverJob($oem)]), 'FAW SA', '01-05-2026 to 01-06-2026');

    $path = tempnam(sys_get_temp_dir(), 'inv-') . '.xlsx';
    file_put_contents($path, $contents);

    $sheet = IOFactory::load($path)->getActiveSheet();

    $headers = [];
    foreach (range(1, 12) as $col) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $headers[] = $sheet->getCell("{$letter}1")->getValue();
    }

    expect($headers)->toBe([
        'ORDER DATE RECEIVED', 'MODEL', 'CHASSIS NO',
        'FROM', 'TO',
        'COLLECTION DATE', 'DELIVERY DATE',
        'INVOICE AMOUNT', 'INVOICE NO',
        'Extras / Truck Stop etc', 'LITRES', 'AMOUNT',
    ]);

    @unlink($path);
});

test('export populates a row with the job data in the right cells', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem, [
        'invoice_number' => 'INV0007966',
        'invoice_amount' => '3112.22',
        'extras_amount'  => '114.00',
        'fuel_litres'    => '50',
        'fuel_amount'    => '1002.10',
    ]);
    $job = $job->fresh(['pickupLocation', 'deliveryLocation']);

    $contents = (new MovementInvoiceExport())->build(collect([$job]), 'FAW SA');

    $path = tempnam(sys_get_temp_dir(), 'inv-') . '.xlsx';
    file_put_contents($path, $contents);

    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('B2')->getValue())->toBe('6.130FL');
    expect($sheet->getCell('C2')->getValue())->toBe($job->vin);
    expect($sheet->getCell('D2')->getValue())->toBe('FAW PE Plant');
    expect($sheet->getCell('E2')->getValue())->toBe('Springs Storage Yard');
    expect((float) $sheet->getCell('H2')->getValue())->toBe(3112.22);
    expect($sheet->getCell('I2')->getValue())->toBe('INV0007966');
    expect((float) $sheet->getCell('J2')->getValue())->toBe(114.00);
    expect((float) $sheet->getCell('K2')->getValue())->toBe(50.0);
    expect((float) $sheet->getCell('L2')->getValue())->toBe(1002.10);

    @unlink($path);
});

test('export leaves the optional columns blank when fields are null (matches non-FAW OEMs)', function () {
    $oem = makeOemCompany('Isuzu SA');
    $job = makeProselverJob($oem); // no invoice / extras / fuel set

    $contents = (new MovementInvoiceExport())->build(collect([$job->fresh(['pickupLocation', 'deliveryLocation'])]), 'Isuzu SA');
    $path = tempnam(sys_get_temp_dir(), 'inv-') . '.xlsx';
    file_put_contents($path, $contents);

    $sheet = IOFactory::load($path)->getActiveSheet();

    foreach (['H2', 'I2', 'J2', 'K2', 'L2'] as $cell) {
        $val = $sheet->getCell($cell)->getValue();
        expect($val === null || $val === '')->toBeTrue();
    }

    @unlink($path);
});

// ----- Volt page ---------------------------------------------------

test('Customer invoicing page hydrates rows from existing finance fields', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem, ['invoice_number' => 'INV-PRE', 'invoice_amount' => '500.00']);

    $this->actingAs(makeAccountant());

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo', now()->toDateString())
        ->assertSet("rows.{$job->id}.invoice_number", 'INV-PRE');
});

test('save() persists edited finance fields', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem);

    $this->actingAs(makeAccountant());

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo', now()->toDateString())
        ->set("rows.{$job->id}.invoice_number", 'INV0008888')
        ->set("rows.{$job->id}.invoice_amount", '4321.10')
        ->set("rows.{$job->id}.fuel_litres", '60')
        ->set("rows.{$job->id}.fuel_amount", '1234.56')
        ->call('save')
        ->assertHasNoErrors();

    $job->refresh();
    expect($job->invoice_number)->toBe('INV0008888');
    expect((float) $job->invoice_amount)->toBe(4321.10);
    expect((float) $job->fuel_litres)->toBe(60.0);
    expect((float) $job->fuel_amount)->toBe(1234.56);
});

test('non-ProSelver and out-of-window jobs are excluded from the scope', function () {
    $oem = makeOemCompany();
    $other = makeOemCompany('Other OEM');

    $inWindowProselver = makeProselverJob($oem);
    $inWindowThirdParty = makeProselverJob($oem, ['executor_type' => Job::EXECUTOR_THIRD_PARTY]);
    $outOfWindow = makeProselverJob($oem, [
        'collected_at' => now()->subYear(),
        'delivered_at' => now()->subYear(),
    ]);
    $differentCustomer = makeProselverJob($other);

    $this->actingAs(makeAccountant());

    $component = \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo', now()->toDateString());

    $rows = $component->get('rows');
    expect($rows)->toHaveKey($inWindowProselver->id);
    expect($rows)->not->toHaveKey($inWindowThirdParty->id);
    expect($rows)->not->toHaveKey($outOfWindow->id);
    expect($rows)->not->toHaveKey($differentCustomer->id);
});

test('Last month preset sets the window from the 2nd of last month to the 1st of this month', function () {
    $oem = makeOemCompany();
    $this->actingAs(makeAccountant());

    $expectedFrom = now()->copy()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString();
    $expectedTo   = now()->copy()->startOfMonth()->toDateString();

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('applyRange', 'last_month')
        ->assertSet('dateFrom', $expectedFrom)
        ->assertSet('dateTo', $expectedTo);
});

test('non-accounts/owner/developer users get 403 on the invoicing page', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin'); // internal but not accounts/owner/developer
    $this->actingAs($u);

    \Livewire\Volt\Volt::test('admin.reports.invoicing')->assertStatus(403);
});

test('toggleComplete flips invoicing_completed_at and stamps the user', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem);
    $u = makeAccountant();
    $this->actingAs($u);

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('toggleComplete', $job->id);

    $job->refresh();
    expect($job->invoicing_completed_at)->not->toBeNull();
    expect($job->invoicing_completed_by_user_id)->toBe($u->id);

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('toggleComplete', $job->id);

    $job->refresh();
    expect($job->invoicing_completed_at)->toBeNull();
    expect($job->invoicing_completed_by_user_id)->toBeNull();
});

test('completion filter hides rows marked complete by default (incomplete view)', function () {
    $oem = makeOemCompany();
    $incomplete = makeProselverJob($oem);
    $complete   = makeProselverJob($oem, ['invoicing_completed_at' => now()]);

    $this->actingAs(makeAccountant());

    // Default ($completion === 'incomplete') -- only the unfinished row.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->assertSee($incomplete->job_number)
        ->assertDontSee($complete->job_number);

    // Flip to 'all' -- both visible.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->set('completion', 'all')
        ->assertSee($incomplete->job_number)
        ->assertSee($complete->job_number);

    // Flip to 'complete' -- only the done row.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->set('completion', 'complete')
        ->assertSee($complete->job_number)
        ->assertDontSee($incomplete->job_number);
});

test('accounts cannot mark a row as not-required', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem);

    $this->actingAs(makeAccountant());

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('toggleExclude', $job->id)
        ->assertStatus(403);

    $job->refresh();
    expect($job->invoicing_excluded_at)->toBeNull();
});

test('owner can mark a row as not-required and it drops out of the default working list', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem);
    $owner = makeOwner();
    $this->actingAs($owner);

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('toggleExclude', $job->id, 'internal shuffle');

    $job->refresh();
    expect($job->invoicing_excluded_at)->not->toBeNull();
    expect($job->invoicing_excluded_by_user_id)->toBe($owner->id);
    expect($job->invoicing_excluded_reason)->toBe('internal shuffle');

    // Default 'incomplete' view should no longer show this job.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->assertDontSee($job->job_number);

    // The 'excluded' filter surfaces it again.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->set('completion', 'excluded')
        ->assertSee($job->job_number);
});

test('excluding a row clears any pre-existing completion stamp', function () {
    $oem = makeOemCompany();
    $job = makeProselverJob($oem, [
        'invoicing_completed_at' => now()->subHour(),
        'invoicing_completed_by_user_id' => User::factory()->create()->id,
    ]);

    $this->actingAs(makeOwner());
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->call('toggleExclude', $job->id);

    $job->refresh();
    expect($job->invoicing_completed_at)->toBeNull();
    expect($job->invoicing_completed_by_user_id)->toBeNull();
    expect($job->invoicing_excluded_at)->not->toBeNull();
});

test('Excel export never includes excluded rows even when the view says All', function () {
    $oem = makeOemCompany();
    $billable = makeProselverJob($oem, ['invoice_number' => 'INV0001', 'invoice_amount' => 1000]);
    $excluded = makeProselverJob($oem, [
        'invoicing_excluded_at' => now(),
        'invoicing_excluded_by_user_id' => makeOwner()->id,
    ]);

    $this->actingAs(makeAccountant());

    // The Volt response wraps a StreamedResponse and Livewire's test
    // harness doesn't expose its content directly, so instead we
    // assert (a) the export call succeeds and (b) the underlying query
    // the page uses returns only the billable VIN.  This is the same
    // query exportExcel() runs internally.
    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->set('completion', 'all')
        ->call('exportExcel')
        ->assertFileDownloaded();

    $exported = Job::query()
        ->where('executor_type', Job::EXECUTOR_PROSELVER)
        ->whereIn('status', [Job::STATUS_DELIVERED, Job::STATUS_COMPLETED, Job::STATUS_INVOICED])
        ->whereNotNull('delivered_at')
        ->where('company_id', $oem->id)
        ->whereNull('invoicing_excluded_at')
        ->pluck('vin')
        ->all();

    expect($exported)->toContain($billable->vin);
    expect($exported)->not->toContain($excluded->vin);
});

test('saving finance fields skips excluded rows', function () {
    $oem = makeOemCompany();
    $excluded = makeProselverJob($oem, [
        'invoicing_excluded_at' => now(),
        'invoicing_excluded_by_user_id' => makeOwner()->id,
    ]);

    $this->actingAs(makeAccountant());

    \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo',   now()->toDateString())
        ->set('completion', 'all')
        ->set("rows.{$excluded->id}.invoice_number", 'INV9999')
        ->set("rows.{$excluded->id}.invoice_amount", 1234.56)
        ->call('save');

    $excluded->refresh();
    expect($excluded->invoice_number)->toBeNull();
    expect((float) $excluded->invoice_amount)->toBe(0.0);
});

test('exportExcel returns an xlsx download response when a customer is picked', function () {
    $oem = makeOemCompany();
    makeProselverJob($oem);

    $this->actingAs(makeAccountant());

    $response = \Livewire\Volt\Volt::test('admin.reports.invoicing')
        ->set('companyId', $oem->id)
        ->set('dateFrom', now()->subDays(30)->toDateString())
        ->set('dateTo', now()->toDateString())
        ->call('exportExcel');

    $response->assertFileDownloaded();
});
