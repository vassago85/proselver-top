<?php

namespace App\Console\Commands;

use App\Models\TollPlaza;
use Illuminate\Console\Command;

/**
 * Print every toll plaza with its current lat/lng and a Google Maps
 * link that drops a pin on those coordinates.  Use to visually verify
 * each plaza is at the actual SANRAL booth, not at the town centre or
 * a 50 km drift like the original Tugela seed.
 *
 * Workflow:
 *   1. `php artisan tolls:verify-coords` -- prints the table.
 *   2. For each plaza, ctrl-click the Maps URL.  If the pin sits on
 *      the toll booth (or right next to the highway) it's fine.
 *      If it's miles off the road, jot down the correct lat/lng
 *      from the satellite view.
 *   3. Feed the corrections back -- I'll ship a single update
 *      migration for the batch.
 */
class TollPlazasVerifyCoords extends Command
{
    protected $signature = 'tolls:verify-coords {--road= : Optional filter, e.g. --road=N3}';
    protected $description = 'List every toll plaza with current coords + a Google Maps verification URL';

    public function handle(): int
    {
        $q = TollPlaza::query()->orderBy('road_name')->orderBy('plaza_name');
        if ($road = $this->option('road')) {
            $q->where('road_name', 'ilike', '%' . $road . '%');
        }

        $rows = [];
        foreach ($q->get() as $p) {
            $lat = number_format((float) $p->latitude, 6);
            $lng = number_format((float) $p->longitude, 6);
            $rows[] = [
                'id' => $p->id,
                'plaza' => $p->plaza_name,
                'road' => $p->road_name,
                'coords' => "$lat, $lng",
                // Google Maps URL with the pin parameter -- drops a
                // marker on the exact coord rather than just centring
                // the map there.  Open in browser, satellite view,
                // visually confirm the pin is on the toll booth.
                'maps' => "https://www.google.com/maps?q={$lat},{$lng}",
            ];
        }

        if (empty($rows)) {
            $this->info('No plazas found' . ($this->option('road') ? ' for ' . $this->option('road') : '') . '.');
            return self::SUCCESS;
        }

        // Two-line layout per plaza so the URL is easy to click in
        // most terminals (table truncation often breaks the link).
        $this->info(sprintf('Verifying %d plaza%s:', count($rows), count($rows) === 1 ? '' : 's'));
        $this->newLine();

        foreach ($rows as $r) {
            $this->line(sprintf(
                '#%-4d %-20s %-40s %s',
                $r['id'],
                $r['plaza'],
                substr($r['road'], 0, 40),
                $r['coords'],
            ));
            $this->line('       ' . $r['maps']);
            $this->newLine();
        }

        $this->info('Open each URL in a browser.  If the pin is on or beside the actual toll booth, the coord is fine.  Note the ones that are off and report them back.');
        return self::SUCCESS;
    }
}
