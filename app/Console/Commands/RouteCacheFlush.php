<?php

namespace App\Console\Commands;

use App\Models\RouteEstimate;
use Illuminate\Console\Command;

/**
 * Flush the route_estimates cache so the next estimator call re-fetches
 * each pickup→delivery pair from Google Directions.
 *
 * Useful after a routing rule change (e.g. enabling the
 * main-highway alternative scoring) so existing cached shortest-path
 * polylines don't stay sticky.  Pass --pickup= / --delivery= to flush
 * just one pair; otherwise the entire table is wiped.
 *
 * Usage:
 *   php artisan route:flush-cache
 *   php artisan route:flush-cache --pickup=12 --delivery=34
 */
class RouteCacheFlush extends Command
{
    protected $signature = 'route:flush-cache
        {--pickup= : Optional pickup location id}
        {--delivery= : Optional delivery location id}';

    protected $description = 'Flush cached route_estimates so the next request re-fetches from Google.';

    public function handle(): int
    {
        $pickup = $this->option('pickup');
        $delivery = $this->option('delivery');

        $query = RouteEstimate::query();
        if ($pickup) {
            $query->where('pickup_location_id', (int) $pickup);
        }
        if ($delivery) {
            $query->where('delivery_location_id', (int) $delivery);
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info('Nothing to flush.');
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Flushed {$count} cached route" . ($count === 1 ? '' : 's') . '.');
        $this->line('The next estimator call for these pairs will re-fetch from Google with the main-highway preference.');

        return self::SUCCESS;
    }
}
