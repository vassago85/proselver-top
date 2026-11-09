<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\GeocodingService;
use Illuminate\Console\Command;

/**
 * Backfill missing latitude/longitude on locations.
 *
 * Why we need this: when the bulk importer creates a new location it
 * defaults `address` to the dealer's business name (it has nothing else
 * to put there).  The Location saving hook tries to geocode but Google
 * can't resolve "EVERSTER INDUSTRIES PMB" to coordinates, so the hook
 * leaves lat/lng null.  Later somebody updates the address to a proper
 * street address.  The hook only re-geocodes when both lat AND lng are
 * null, so in theory the update fixes it -- but in practice many of
 * these address updates happened before the Geocoding API was enabled
 * (or via a direct DB write that didn't fire the hook), leaving live
 * locations with proper addresses but no coordinates.  Result: the
 * trip-cost estimator can't run a route on those orders, no toll
 * detection happens, and the modal shows the unhelpful "Could not
 * calculate a route" message.
 *
 * It also fills a blank province (and city) from the same hit. The ops
 * queue's "Lane not set" bucket is missing province, not missing
 * coordinates — a pin without Gauteng/KZN still cannot be grouped.
 * Dealer-name stubs such as "ISUZU TRUCK CENTRE CITY DEEP" are included:
 * one corrected location row fixes every job that points at it.
 *
 * This command finds those locations and runs the geocode for them.
 *
 * Usage:
 *   php artisan locations:geocode --dry-run
 *   php artisan locations:geocode               # actually save
 *   php artisan locations:geocode --limit=50    # rate-limit if many
 */
class LocationsGeocode extends Command
{
    protected $signature = 'locations:geocode
        {--dry-run : List the locations that would be geocoded without saving}
        {--limit=0 : Maximum number to process (0 = no limit)}
        {--sleep=200 : Milliseconds to sleep between API calls (default 200, Google free tier handles 50/sec)}';

    protected $description = 'Backfill coordinates and province for locations Google can resolve';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $sleepMs = max(0, (int) $this->option('sleep'));

        // Candidates: a query string exists, and either the pin or the
        // province is missing. Province is what the lane calculator
        // reads; coordinates are what the route calculator reads.
        // Inactive rows stay in scope — an old order still needs them.
        $query = Location::query()
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('address')->where('address', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('company_name')->where('company_name', '!=', '');
                });
            })
            ->where(function ($q) {
                $q->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhereNull('province')
                    ->orWhere('province', '');
            });

        $totalCandidates = (clone $query)->count();
        $this->info("Found {$totalCandidates} locations missing coordinates or a province.");

        if ($limit > 0) {
            $query->limit($limit);
            $this->line("Limiting to {$limit} this run.");
        }

        $locations = $query->get();
        if ($locations->isEmpty()) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $succeeded = 0;
        $provinceOnly = 0;
        $failed = 0;

        $progress = $this->output->createProgressBar($locations->count());
        $progress->start();

        foreach ($locations as $location) {
            $address = trim((string) $location->address);
            if ($address === '') {
                $address = trim((string) $location->company_name);
            }

            if ($address === '') {
                $failed++;
                $progress->advance();
                continue;
            }

            if ($dryRun) {
                $progress->advance();
                continue;
            }

            try {
                $hit = GeocodingService::resolve($address);
                if ($hit && $location->applyGeocodeHit($hit)) {
                    // saveQuietly so the saving hook does not geocode again.
                    $location->saveQuietly();
                    if ($location->latitude !== null && $location->longitude !== null && trim((string) $location->province) !== '') {
                        $succeeded++;
                    } else {
                        $provinceOnly++;
                    }
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  Location #{$location->id}: " . $e->getMessage());
            }

            $progress->advance();

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $progress->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry run complete. {$locations->count()} would have been processed.");
            return self::SUCCESS;
        }

        $this->info("Done. Fully resolved: {$succeeded}. Province only (no street pin): {$provinceOnly}. No usable match: {$failed}.");
        if ($failed > 0 || $provinceOnly > 0) {
            $this->warn('Rows without a street pin still need a Google suggestion picked on /admin/settings/locations. A province-only hit clears "Lane not set", but it is not enough to price the route.');
        }
        return self::SUCCESS;
    }
}
