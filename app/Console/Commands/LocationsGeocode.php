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

    protected $description = 'Backfill latitude/longitude for locations that have an address but no coordinates';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $sleepMs = max(0, (int) $this->option('sleep'));

        // Candidates: locations with an address but null lat OR null lng.
        // Both must be null to retry (the saving hook treats either-set
        // as "already geocoded", and the same rule lives in the geocode
        // logic below).  We deliberately don't filter by is_active --
        // an inactive location used by an old order still needs coords
        // for a historical PDF / report regeneration.
        $query = Location::query()
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            });

        $totalCandidates = (clone $query)->count();
        $this->info("Found {$totalCandidates} locations missing coordinates.");

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
        $failed = 0;
        $skipped = 0;

        $progress = $this->output->createProgressBar($locations->count());
        $progress->start();

        foreach ($locations as $location) {
            $address = trim((string) $location->address);

            // Skip useless stubs -- a single-word address like "Pretoria"
            // is more likely to misgeocode than to help.  We require a
            // numeric token (street number) OR a comma (which most
            // formatted addresses have) before attempting the call.
            if (!preg_match('/\d/', $address) && !str_contains($address, ',')) {
                $skipped++;
                $progress->advance();
                continue;
            }

            if ($dryRun) {
                $progress->advance();
                continue;
            }

            try {
                $coords = GeocodingService::geocode($address);
                if ($coords && isset($coords['lat'], $coords['lng'])) {
                    // forceFill + save bypasses the saving hook's
                    // geocode-on-create branch, which would otherwise
                    // re-run the API call for nothing.
                    $location->forceFill([
                        'latitude' => $coords['lat'],
                        'longitude' => $coords['lng'],
                    ])->saveQuietly();
                    $succeeded++;
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
            $this->info("Dry run complete. {$locations->count()} would have been processed, {$skipped} stub addresses skipped.");
            return self::SUCCESS;
        }

        $this->info("Done. Geocoded: {$succeeded}. Failed (no match): {$failed}. Skipped (stub address): {$skipped}.");
        if ($failed > 0) {
            $this->warn("The 'failed' rows have an address Google couldn't match. Edit them via /admin/settings/locations or check the spelling.");
        }
        return self::SUCCESS;
    }
}
