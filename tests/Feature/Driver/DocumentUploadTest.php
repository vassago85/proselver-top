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

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Super Admin', 'slug' => 'super_admin', 'tier' => 'internal']);
    Role::create(['name' => 'Driver', 'slug' => 'driver', 'tier' => 'driver']);

    Storage::fake('local');
});

function makeDriverWithJob(): array
{
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    $company = Company::factory()->create();

    $job = Job::create([
        'uuid' => Str::uuid(),
        'job_type' => 'transport',
        'status' => Job::STATUS_READY_FOR_COLLECTION,
        'company_id' => $company->id,
        'created_by_user_id' => $driver->id,
        'driver_user_id' => $driver->id,
        'scheduled_date' => now()->toDateString(),
    ]);

    return [$driver, $job];
}

test('driver can upload a document with a client_uuid', function () {
    [$driver, $job] = makeDriverWithJob();

    $response = $this->actingAs($driver)
        ->post("/driver/api/jobs/{$job->id}/documents", [
            'file' => UploadedFile::fake()->image('front.jpg'),
            'category' => JobDocument::CATEGORY_PHOTO,
            'client_uuid' => (string) Str::uuid(),
        ]);

    $response->assertStatus(201);
    expect(JobDocument::where('job_id', $job->id)->count())->toBe(1);
});

test('repeat upload with same client_uuid is idempotent', function () {
    [$driver, $job] = makeDriverWithJob();
    $uuid = (string) Str::uuid();

    $first = $this->actingAs($driver)->post("/driver/api/jobs/{$job->id}/documents", [
        'file' => UploadedFile::fake()->image('a.jpg'),
        'category' => JobDocument::CATEGORY_PHOTO,
        'client_uuid' => $uuid,
    ])->assertStatus(201);

    $second = $this->actingAs($driver)->post("/driver/api/jobs/{$job->id}/documents", [
        'file' => UploadedFile::fake()->image('a.jpg'),
        'category' => JobDocument::CATEGORY_PHOTO,
        'client_uuid' => $uuid,
    ])->assertStatus(200);

    $second->assertJsonPath('idempotent', true);
    expect(JobDocument::where('job_id', $job->id)->count())->toBe(1);
});

test('unknown category is rejected', function () {
    [$driver, $job] = makeDriverWithJob();

    $this->actingAs($driver)
        ->postJson("/driver/api/jobs/{$job->id}/documents", [
            'file' => UploadedFile::fake()->image('a.jpg'),
            'category' => 'not_a_real_category',
            'client_uuid' => (string) Str::uuid(),
        ])
        ->assertStatus(422);
});

test('a driver cannot upload to another drivers job', function () {
    [$driverA, $job] = makeDriverWithJob();

    $driverB = User::factory()->create();
    $driverB->assignRole('driver');

    $this->actingAs($driverB)
        ->post("/driver/api/jobs/{$job->id}/documents", [
            'file' => UploadedFile::fake()->image('a.jpg'),
            'category' => JobDocument::CATEGORY_PHOTO,
            'client_uuid' => (string) Str::uuid(),
        ])
        ->assertStatus(403);
});

test('missing client_uuid is rejected', function () {
    [$driver, $job] = makeDriverWithJob();

    $this->actingAs($driver)
        ->postJson("/driver/api/jobs/{$job->id}/documents", [
            'file' => UploadedFile::fake()->image('a.jpg'),
            'category' => JobDocument::CATEGORY_PHOTO,
        ])
        ->assertStatus(422);
});

test('petty cash categories are accepted', function () {
    [$driver, $job] = makeDriverWithJob();

    foreach ([
        JobDocument::CATEGORY_FUEL_SLIP,
        JobDocument::CATEGORY_FOOD_SLIP,
        JobDocument::CATEGORY_TOLL_SLIP,
        JobDocument::CATEGORY_PARKING_SLIP,
        JobDocument::CATEGORY_ACCOMMODATION_SLIP,
    ] as $category) {
        $this->actingAs($driver)
            ->post("/driver/api/jobs/{$job->id}/documents", [
                'file' => UploadedFile::fake()->image('slip.jpg'),
                'category' => $category,
                'client_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(201);
    }

    expect(JobDocument::where('job_id', $job->id)->count())->toBe(5);
});
