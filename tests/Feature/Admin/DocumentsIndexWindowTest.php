<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
});

function windowOps(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('operations_controller');

    return $user;
}

function windowJob(string $number): Job
{
    $company = Company::factory()->create([
        'name' => 'Window Co '.$number,
    ]);
    $creator = User::factory()->create();

    return Job::create([
        'uuid' => (string) Str::uuid(),
        'job_number' => $number,
        'vin' => 'VIN'.$number,
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'scheduled_date' => now()->toDateString(),
    ]);
}

function windowPhoto(Job $job, User $uploader, ?\DateTimeInterface $capturedAt = null, ?\DateTimeInterface $createdAt = null): JobDocument
{
    $doc = JobDocument::create([
        'job_id' => $job->id,
        'uploaded_by_user_id' => $uploader->id,
        'category' => JobDocument::CATEGORY_PHOTO,
        'disk' => 'local',
        'path' => 'photos/'.$job->job_number.'.jpg',
        'original_filename' => $job->job_number.'.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1200,
        'captured_at' => $capturedAt,
    ]);

    if ($createdAt) {
        $doc->created_at = $createdAt;
        $doc->updated_at = $createdAt;
        $doc->save();
    }

    return $doc;
}

test('the documents index only lists jobs with a document from the last 7 days', function () {
    $ops = windowOps();
    $recent = windowJob('JOB-NEW');
    $stale = windowJob('JOB-OLD');
    $mixed = windowJob('JOB-MIX');

    windowPhoto($recent, $ops, now()->subDay());
    windowPhoto($stale, $ops, now()->subMonths(3));
    windowPhoto($mixed, $ops, now()->subDay());
    windowPhoto($mixed, $ops, now()->subMonths(3));

    Volt::actingAs($ops)
        ->test('admin.documents.index')
        ->assertSee('JOB-NEW')
        ->assertSee('JOB-MIX')
        ->assertSee('Show 1 older document')
        ->assertDontSee('JOB-OLD')
        ->assertSee('Show documents older than 7 days')
        ->set('includeOlder', true)
        ->assertSee('JOB-OLD')
        ->assertSee('data-stale-documents')
        ->assertSee('Older than 7 days');
});

test('captured_at decides the 7 day window when it is set', function () {
    $ops = windowOps();
    $shotRecently = windowJob('JOB-SHOT');
    $filedRecently = windowJob('JOB-FILED');

    windowPhoto($shotRecently, $ops, now()->subDay(), now()->subMonths(3));
    windowPhoto($filedRecently, $ops, now()->subMonths(3), now()->subDay());

    Volt::actingAs($ops)
        ->test('admin.documents.index')
        ->assertSee('JOB-SHOT')
        ->assertDontSee('JOB-FILED');
});
