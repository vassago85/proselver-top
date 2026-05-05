<?php

use App\Models\DriverProfile;
use App\Models\TrackerPosition;
use App\Models\User;
use App\Services\TrackSolid\PositionPoller;
use App\Services\TrackSolid\TrackSolidClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Stub client that returns whatever positions the test hands it. Lets
 * the poller be exercised without an HTTP fake or a Mockery double.
 */
class FakeTrackSolidClient implements TrackSolidClientInterface
{
    public bool $configured = true;
    public array $positions = [];
    public bool $shouldThrow = false;
    public array $askedForImeis = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function authenticate(): string
    {
        return 'fake-token';
    }

    public function listDevices(): array
    {
        return collect($this->positions)->map(fn ($p) => [
            'imei' => $p['tracker_id'],
            'name' => null,
            'status' => null,
        ])->all();
    }

    public function getLatestPositions(array $imeis): array
    {
        $this->askedForImeis = $imeis;
        if ($this->shouldThrow) {
            throw new \RuntimeException('upstream unreachable');
        }
        return collect($this->positions)
            ->filter(fn ($p) => in_array($p['tracker_id'], $imeis, true))
            ->values()
            ->all();
    }

    public function getDevicePosition(string $imei): ?array
    {
        return collect($this->positions)->first(fn ($p) => $p['tracker_id'] === $imei);
    }
}

beforeEach(function () {
    $this->client = new FakeTrackSolidClient();
    $this->poller = new PositionPoller($this->client);
});

function makeDriverWithTracker(string $imei): DriverProfile
{
    $user = User::factory()->create();
    return DriverProfile::create([
        'user_id' => $user->id,
        'tracker_id' => $imei,
    ]);
}

it('no-ops when the integration is not configured', function () {
    $this->client->configured = false;
    makeDriverWithTracker('IMEI-1');

    $stats = $this->poller->poll();

    expect($stats['configured'])->toBeFalse();
    expect($stats['devices_polled'])->toBe(0);
    expect(TrackerPosition::count())->toBe(0);
});

it('only polls trackers actually bound to a driver', function () {
    makeDriverWithTracker('IMEI-DRIVER-A');
    makeDriverWithTracker('IMEI-DRIVER-B');
    // Driver row with no tracker — should be filtered out.
    DriverProfile::create(['user_id' => User::factory()->create()->id, 'tracker_id' => null]);

    $this->client->positions = [
        [
            'tracker_id' => 'IMEI-DRIVER-A',
            'latitude' => -26.2041,
            'longitude' => 28.0473,
            'speed_kmh' => 60.0,
            'heading_deg' => 180.0,
            'reported_at' => now()->subSeconds(20)->toImmutable(),
            'raw' => ['fake' => true],
        ],
    ];

    $stats = $this->poller->poll();

    expect($stats['devices_polled'])->toBe(2);
    expect(sort($this->client->askedForImeis))->toBeTrue();
    expect($this->client->askedForImeis)->toContain('IMEI-DRIVER-A', 'IMEI-DRIVER-B');
    expect($stats['positions_received'])->toBe(1);
    expect($stats['positions_written'])->toBe(1);
    expect(TrackerPosition::count())->toBe(1);
});

it('upserts on (tracker_id, reported_at) so back-to-back polls are idempotent', function () {
    makeDriverWithTracker('IMEI-1');

    $sample = [
        'tracker_id' => 'IMEI-1',
        'latitude' => -26.2041,
        'longitude' => 28.0473,
        'speed_kmh' => 0.0,
        'heading_deg' => 0.0,
        'reported_at' => now()->subSeconds(45)->toImmutable(),
        'raw' => ['snapshot' => 1],
    ];

    $this->client->positions = [$sample];

    $this->poller->poll();
    $this->poller->poll(); // same sample, should NOT create a duplicate row
    $this->poller->poll();

    expect(TrackerPosition::count())->toBe(1);

    $row = TrackerPosition::first();
    expect((float) $row->latitude)->toBe(-26.2041);
    expect($row->raw)->toBe(['snapshot' => 1]);
});

it('writes a new row when reported_at advances', function () {
    makeDriverWithTracker('IMEI-1');

    $base = [
        'tracker_id' => 'IMEI-1',
        'latitude' => -26.2041,
        'longitude' => 28.0473,
        'speed_kmh' => 60.0,
        'heading_deg' => 90.0,
        'raw' => [],
    ];

    $this->client->positions = [array_merge($base, ['reported_at' => now()->subSeconds(60)->toImmutable()])];
    $this->poller->poll();

    $this->client->positions = [array_merge($base, [
        'reported_at' => now()->subSeconds(30)->toImmutable(),
        'latitude' => -26.2050,
    ])];
    $this->poller->poll();

    expect(TrackerPosition::count())->toBe(2);

    // latestPerTracker() should give us the more recent fix.
    $latest = TrackerPosition::latestPerTracker()->first();
    expect((float) $latest->latitude)->toBe(-26.2050);
});

it('records errors instead of crashing when the upstream call throws', function () {
    makeDriverWithTracker('IMEI-1');
    $this->client->shouldThrow = true;

    $stats = $this->poller->poll();

    expect($stats['configured'])->toBeTrue();
    expect($stats['devices_polled'])->toBe(1);
    expect($stats['positions_received'])->toBe(0);
    expect($stats['errors'])->toContain('upstream unreachable');
    expect(TrackerPosition::count())->toBe(0);
});

it('scopeFresh limits to recent positions', function () {
    makeDriverWithTracker('IMEI-A');
    makeDriverWithTracker('IMEI-B');

    TrackerPosition::create([
        'tracker_id' => 'IMEI-A',
        'latitude' => -26.0,
        'longitude' => 28.0,
        'reported_at' => now()->subMinutes(2),
        'received_at' => now()->subMinutes(2),
    ]);
    TrackerPosition::create([
        'tracker_id' => 'IMEI-B',
        'latitude' => -26.5,
        'longitude' => 28.5,
        'reported_at' => now()->subHour(),
        'received_at' => now()->subHour(),
    ]);

    expect(TrackerPosition::query()->fresh(5)->count())->toBe(1);
    expect(TrackerPosition::query()->recent(120)->count())->toBe(2);
});
