<?php

use App\Models\Company;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Operations Controller', 'slug' => 'operations_controller', 'tier' => 'internal']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);
    Storage::fake('pods');
    Storage::fake('local');
});

function podOps(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('operations_controller');

    return $user;
}

function podJob(array $overrides = []): Job
{
    $company = Company::factory()->create();
    $creator = User::factory()->create();

    return Job::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_number' => 'JOB-100',
        'vin' => 'VIN12345',
        'job_type' => 'transport',
        'status' => Job::STATUS_DELIVERED,
        'company_id' => $company->id,
        'created_by_user_id' => $creator->id,
        'scheduled_date' => now()->toDateString(),
    ], $overrides));
}

test('office upload stores the POD on the pods disk under job number and VIN', function () {
    $job = podJob();

    Volt::actingAs(podOps())
        ->test('admin.documents.index')
        ->set('podLookup', 'job-100')
        ->set('podFiles', [UploadedFile::fake()->image('signed.jpg')])
        ->call('uploadPod')
        ->assertHasNoErrors();

    $doc = JobDocument::first();
    expect($doc)->not->toBeNull()
        ->and($doc->job_id)->toBe($job->id)
        ->and($doc->category)->toBe(JobDocument::CATEGORY_POD)
        ->and($doc->disk)->toBe('pods')
        ->and($doc->path)->toStartWith('JOB-100/VIN12345/');

    Storage::disk('pods')->assertExists($doc->path);
});

test('office upload finds the order by VIN when the job number is not used', function () {
    $job = podJob(['job_number' => 'JOB-200', 'vin' => 'AbC999']);

    Volt::actingAs(podOps())
        ->test('admin.documents.index')
        ->set('podLookup', 'abc999')
        ->set('podFiles', [UploadedFile::fake()->image('pod.jpg')])
        ->call('uploadPod')
        ->assertHasNoErrors();

    expect(JobDocument::first()->job_id)->toBe($job->id)
        ->and(JobDocument::first()->path)->toStartWith('JOB-200/ABC999/');
});

test('a VIN shared by more than one order is not guessed', function () {
    podJob(['job_number' => 'JOB-1', 'vin' => 'SAMEVIN']);
    podJob(['job_number' => 'JOB-2', 'vin' => 'SAMEVIN']);

    Volt::actingAs(podOps())
        ->test('admin.documents.index')
        ->set('podLookup', 'SAMEVIN')
        ->set('podFiles', [UploadedFile::fake()->image('pod.jpg')])
        ->call('uploadPod')
        ->assertHasErrors(['podLookup']);

    expect(JobDocument::count())->toBe(0);
});

test('a two-page POD can be uploaded as one submission', function () {
    $job = podJob(['job_number' => 'JOB-300', 'vin' => 'TWOPAGE']);

    Volt::actingAs(podOps())
        ->test('admin.documents.index')
        ->set('podLookup', 'JOB-300')
        ->set('podFiles', [
            UploadedFile::fake()->image('pod-page-1.jpg'),
            UploadedFile::fake()->image('pod-page-2.jpg'),
        ])
        ->call('uploadPod')
        ->assertHasNoErrors();

    $docs = JobDocument::where('job_id', $job->id)->get();
    expect($docs)->toHaveCount(2);
    foreach ($docs as $doc) {
        expect($doc->category)->toBe(JobDocument::CATEGORY_POD)
            ->and($doc->disk)->toBe('pods')
            ->and($doc->path)->toStartWith('JOB-300/TWOPAGE/');
        Storage::disk('pods')->assertExists($doc->path);
    }
});

test('a driver POD is filed on the pods disk and other photos stay on the upload disk', function () {
    $driver = User::factory()->create();
    $driver->assignRole('driver');
    $job = podJob([
        'created_by_user_id' => $driver->id,
        'driver_user_id' => $driver->id,
        'status' => Job::STATUS_READY_FOR_COLLECTION,
    ]);

    $this->actingAs($driver)->post("/driver/api/jobs/{$job->id}/documents", [
        'file' => UploadedFile::fake()->image('pod.jpg'),
        'category' => JobDocument::CATEGORY_POD,
        'client_uuid' => (string) Str::uuid(),
    ])->assertStatus(201);

    $pod = JobDocument::where('category', JobDocument::CATEGORY_POD)->first();
    expect($pod->disk)->toBe('pods')
        ->and($pod->path)->toStartWith('JOB-100/VIN12345/');

    $this->actingAs($driver)->post("/driver/api/jobs/{$job->id}/documents", [
        'file' => UploadedFile::fake()->image('front.jpg'),
        'category' => JobDocument::CATEGORY_PHOTO,
        'client_uuid' => (string) Str::uuid(),
    ])->assertStatus(201);

    $photo = JobDocument::where('category', JobDocument::CATEGORY_PHOTO)->first();
    expect($photo->disk)->toBe('local')
        ->and($photo->path)->toStartWith('jobs/');
});
