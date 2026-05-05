<?php

namespace App\Services\TrackSolid;

use App\Models\DriverProfile;
use App\Models\TrackerPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pull the latest GPS position for every driver-bound tracker and upsert
 * the result into `tracker_positions`. The wallboard reads from that
 * table — never from the upstream API directly — so a poller outage
 * just makes pins go stale instead of breaking the page.
 *
 * Idempotency: the upsert key is (tracker_id, reported_at). Polling
 * twice between device samples is a no-op.
 *
 * Failure mode: any exception from the client is logged and the run
 * returns a stats array with `errors` populated. The scheduler doesn't
 * back off on its own — this is a fire-and-forget command, the next
 * tick will try again.
 */
class PositionPoller
{
    public function __construct(
        protected TrackSolidClientInterface $client,
    ) {}

    /**
     * Run a single poll cycle. Returns a stats array suitable for the
     * scheduler log:
     *   [
     *     'configured'       => bool,   // false → integration is off / unset
     *     'devices_polled'   => int,    // tracker_ids we asked the API about
     *     'positions_received' => int,  // rows the API returned
     *     'positions_written' => int,   // rows we actually upserted
     *     'errors'           => array,  // string messages, never throws
     *   ]
     */
    public function poll(): array
    {
        $stats = [
            'configured' => false,
            'devices_polled' => 0,
            'positions_received' => 0,
            'positions_written' => 0,
            'errors' => [],
        ];

        if (!$this->client->isConfigured()) {
            return $stats;
        }
        $stats['configured'] = true;

        // Only poll trackers that are actually bound to a driver. Devices
        // tied to vehicles in the maintenance yard, demo units, etc. live
        // on the TrackSolid account but don't need to clutter the poll
        // budget — and won't render on the wallboard anyway.
        $imeis = DriverProfile::query()
            ->whereNotNull('tracker_id')
            ->where('tracker_id', '!=', '')
            ->pluck('tracker_id')
            ->unique()
            ->values()
            ->all();

        if (empty($imeis)) {
            return $stats;
        }

        $stats['devices_polled'] = count($imeis);

        try {
            $positions = $this->client->getLatestPositions($imeis);
        } catch (\Throwable $e) {
            Log::warning('TrackSolid poll failed', [
                'message' => $e->getMessage(),
                'imei_count' => count($imeis),
            ]);
            $stats['errors'][] = $e->getMessage();
            return $stats;
        }

        $stats['positions_received'] = count($positions);

        foreach ($positions as $position) {
            try {
                $this->upsert($position);
                $stats['positions_written']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = sprintf(
                    'tracker %s: %s',
                    $position['tracker_id'] ?? 'unknown',
                    $e->getMessage()
                );
            }
        }

        return $stats;
    }

    /**
     * Persist a single normalised position row. Uses Eloquent's
     * `updateOrCreate` against the (tracker_id, reported_at) natural
     * key so concurrent polls collapse into a single row.
     */
    protected function upsert(array $position): TrackerPosition
    {
        $reportedAt = $position['reported_at'] ?? now();
        if ($reportedAt instanceof \DateTimeInterface) {
            $reportedAt = \Carbon\Carbon::instance(\DateTime::createFromInterface($reportedAt))->utc();
        }

        return TrackerPosition::updateOrCreate(
            [
                'tracker_id' => $position['tracker_id'],
                'reported_at' => $reportedAt,
            ],
            [
                'latitude' => $position['latitude'],
                'longitude' => $position['longitude'],
                'speed_kmh' => $position['speed_kmh'] ?? null,
                'heading_deg' => $position['heading_deg'] ?? null,
                'received_at' => now(),
                'raw' => $position['raw'] ?? null,
            ]
        );
    }
}
