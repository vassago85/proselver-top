<?php

namespace App\Console\Commands;

use App\Models\TollPlaza;
use Illuminate\Console\Command;

/**
 * Import the SANRAL 2026 toll fee schedule (effective 1 March 2026)
 * sourced from foresightpublications.co.za.
 *
 * Strategy:
 *   - Match plazas in our toll_plazas table by name (case-insensitive,
 *     ramp suffixes stripped).  Update class_1..class_4 fees and the
 *     effective_from date.
 *   - Plazas in the 2026 data that don't exist in our table are
 *     reported so ops can decide whether to add coords + insert.  We
 *     don't auto-insert because route detection needs lat/long.
 *   - --dry-run shows what would change without writing.
 *
 * Run:
 *   php artisan tolls:import-fees-2026 --dry-run
 *   php artisan tolls:import-fees-2026
 */
class TollFeesImport2026 extends Command
{
    protected $signature = 'tolls:import-fees-2026 {--dry-run : Preview without saving}';
    protected $description = 'Import the SANRAL 2026 toll fee schedule and update existing toll_plazas rows';

    /**
     * Canonical 2026 fees per plaza, keyed by OUR DB plaza_name.
     * Only main plazas listed -- ramp variants aren't in our seeded
     * data and ops would need coordinates to add them.  Sourced
     * 2026-05-26 from foresightpublications.co.za/TollroadsN*.html
     * with effective date 1 March 2026.
     *
     * Format: [plaza_name => [c1, c2, c3, c4]]
     */
    private const FEES_2026 = [
        // N1: Johannesburg - Cape Town
        'Grasmere'      => [ 27.50,  82.00,  96.00, 126.00],
        'Vaal'          => [ 91.50, 172.00, 207.00, 275.00],
        'Verkeerdevlei' => [ 78.50, 157.00, 236.00, 331.00],
        'Huguenot'      => [ 54.50, 151.00, 236.00, 383.00],

        // N1: Pretoria - Beit Bridge
        'Pumulani'  => [ 16.50,  41.00,  47.00,  57.00],
        'Carousel'  => [ 75.00, 202.00, 224.00, 258.00],
        'Kranskop'  => [ 61.50, 157.00, 210.00, 257.00],
        'Nyl'       => [ 79.50, 149.00, 180.00, 241.00],
        'Capricorn' => [ 63.50, 175.00, 205.00, 256.00],
        'Baobab'    => [ 61.50, 168.00, 231.00, 278.00],

        // N2: Durban - Empangeni (North Coast)
        'Othongathi' => [ 15.50,  32.00,  42.00,  62.00],
        'Mvoti'      => [ 18.50,  52.00,  70.00, 104.00],
        'Mtunzini'   => [ 63.50, 122.00, 146.00, 217.00],

        // N2: Durban - Port Shepstone (South Coast)
        'Oribi' => [ 41.00,  73.00, 100.00, 162.00],

        // N2: Garden Route
        'Tsitsikamma' => [ 73.00, 183.00, 438.00, 619.00],

        // N3: Heidelberg - Pietermaritzburg
        'De Hoek'     => [ 67.00, 105.00, 160.00, 230.00],
        'Wilge'       => [ 94.00, 161.00, 215.00, 304.00],
        'Tugela'      => [100.00, 165.00, 260.00, 359.00],
        'Mooi'        => [ 70.00, 171.00, 240.00, 324.00],
        'Mariannhill' => [ 16.50,  30.00,  37.00,  57.00],

        // N4: Pretoria - Lobatse
        'Pelindaba'    => [  8.00,  15.00,  21.00,  27.00],
        'Quagga'       => [  6.50,  11.00,  16.00,  21.00],
        'Doornpoort'   => [ 20.00,  50.00,  58.00,  70.00],
        'Brits'        => [ 20.00,  70.00,  77.00,  90.00],
        'Marikana'     => [ 30.00,  72.00,  81.00,  96.00],
        'Swartruggens' => [103.00, 258.00, 313.00, 368.00],

        // N4: Pretoria - Maputo
        'Diamond Hill' => [ 51.00,  70.00, 133.00, 220.00],
        'Middelburg'   => [ 84.00, 182.00, 277.00, 365.00],
        'Machado'      => [126.00, 350.00, 510.00, 729.00],
        'Nkomazi'      => [ 95.00, 193.00, 281.00, 405.00],

        // N17: Springs - Ermelo
        'Gosforth'  => [ 17.00,  46.00,  50.00,  69.00],
        'Dalpark'   => [ 15.50,  32.00,  42.00,  58.00],
        'Leandra'   => [ 50.50, 127.00, 190.00, 253.00],
        'Trichardt' => [ 25.00,  63.00,  96.00, 127.00],
        'Ermelo'    => [ 45.00, 114.00, 170.00, 226.00],

        // R30: Kroonstad - Bloemfontein
        'Brandfort' => [ 62.50, 125.00, 188.00, 265.00],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $effectiveFrom = '2026-03-01';

        $this->info('SANRAL 2026 toll fee import' . ($dryRun ? ' (dry-run)' : ''));
        $this->info('Source: foresightpublications.co.za · Effective ' . $effectiveFrom);
        $this->newLine();

        $updated = [];
        $unchanged = [];
        $notFound = [];

        foreach (self::FEES_2026 as $name => $fees) {
            [$c1, $c2, $c3, $c4] = $fees;

            $plaza = TollPlaza::where('plaza_name', $name)->first();
            if (!$plaza) {
                $notFound[] = $name;
                continue;
            }

            $changed = (
                (float) $plaza->class_1_fee !== $c1
                || (float) $plaza->class_2_fee !== $c2
                || (float) $plaza->class_3_fee !== $c3
                || (float) $plaza->class_4_fee !== $c4
                || $plaza->effective_from?->toDateString() !== $effectiveFrom
            );

            if (!$changed) {
                $unchanged[] = $name;
                continue;
            }

            $row = [
                'name' => $name,
                'before' => sprintf('R%.2f / R%.2f / R%.2f / R%.2f', $plaza->class_1_fee, $plaza->class_2_fee, $plaza->class_3_fee, $plaza->class_4_fee),
                'after'  => sprintf('R%.2f / R%.2f / R%.2f / R%.2f', $c1, $c2, $c3, $c4),
            ];
            $updated[] = $row;

            if (!$dryRun) {
                $plaza->forceFill([
                    'class_1_fee' => $c1,
                    'class_2_fee' => $c2,
                    'class_3_fee' => $c3,
                    'class_4_fee' => $c4,
                    'effective_from' => $effectiveFrom,
                ])->save();
            }
        }

        // Plazas in our DB that are NOT in the 2026 data -- worth
        // flagging so ops knows which rates may be stale or which
        // plazas the import didn't touch.
        $ourPlazaNames = TollPlaza::pluck('plaza_name')->all();
        $missingFromImport = array_diff($ourPlazaNames, array_keys(self::FEES_2026));

        $this->line('Updated:   ' . count($updated));
        $this->line('Unchanged: ' . count($unchanged) . ' (already on 2026 rates)');
        $this->line('Missing:   ' . count($notFound) . ' (in 2026 data but not in our DB)');
        $this->line('Untouched: ' . count($missingFromImport) . ' (in our DB but not in 2026 data)');
        $this->newLine();

        if (!empty($updated)) {
            $this->info('Changes' . ($dryRun ? ' (would apply)' : ' applied') . ':');
            $this->table(['Plaza', 'Before', 'After'], $updated);
        }

        if (!empty($notFound)) {
            $this->newLine();
            $this->warn('In 2026 data but not in your toll_plazas (would need coords to add):');
            foreach ($notFound as $n) $this->line('  · ' . $n);
        }

        if (!empty($missingFromImport)) {
            $this->newLine();
            $this->comment('In your toll_plazas but not in 2026 data (left untouched):');
            foreach ($missingFromImport as $n) $this->line('  · ' . $n);
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run -- no changes saved.  Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
