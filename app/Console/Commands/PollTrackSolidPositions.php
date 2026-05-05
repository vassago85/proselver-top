<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\TrackSolid\Client as TrackSolidClient;
use App\Services\TrackSolid\PositionPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled poll of the TrackSolid API for fresh device positions.
 *
 * The Laravel scheduler ticks every minute, so this command is wired up
 * with `everyThirtySeconds()` and the per-poll interval (configurable
 * in the integrations settings page) is enforced INSIDE the command via
 * a "last ran" cache marker. That way ops can dial the interval down to
 * 10 seconds without us editing the scheduler, and dial it up to 5
 * minutes for accounts on a tighter API quota.
 */
class PollTrackSolidPositions extends Command
{
    protected $signature = 'tracksolid:poll
                            {--force : Ignore the last-ran cache marker and poll immediately}';

    protected $description = 'Poll the TrackSolid API for the latest position of every driver-bound tracker';

    private const LAST_RAN_CACHE_KEY = 'tracksolid.last_polled_at';

    public function handle(PositionPoller $poller): int
    {
        $intervalSeconds = max(10, (int) SystemSetting::get(TrackSolidClient::SETTING_POLL_INTERVAL, 30));
        $now = time();

        if (!$this->option('force')) {
            // If the cache backend is down (e.g. Redis offline) we'd rather
            // poll than crash — losing a throttle marker means at worst one
            // extra API call. Same for Cache::put below.
            try {
                $lastRan = (int) Cache::get(self::LAST_RAN_CACHE_KEY, 0);
            } catch (\Throwable $e) {
                $lastRan = 0;
            }
            if ($lastRan > 0 && ($now - $lastRan) < $intervalSeconds) {
                $this->info('Skipped — last polled ' . ($now - $lastRan) . 's ago, interval is ' . $intervalSeconds . 's.');
                return self::SUCCESS;
            }
        }

        try {
            Cache::put(self::LAST_RAN_CACHE_KEY, $now, $intervalSeconds * 4);
        } catch (\Throwable $e) {
            // ignored — see comment above
        }

        $stats = $poller->poll();

        if (!$stats['configured']) {
            $this->warn('TrackSolid is not configured / not enabled. Skipping.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Polled %d device(s); %d position(s) received; %d written.',
            $stats['devices_polled'],
            $stats['positions_received'],
            $stats['positions_written']
        ));

        if (!empty($stats['errors'])) {
            $this->warn('Errors during poll:');
            foreach ($stats['errors'] as $err) {
                $this->line('  · ' . $err);
            }
        }

        return self::SUCCESS;
    }
}
