<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Print the current driver roster as a table. Use this to compare the DB
 * against the ops master sheet before and after running the fleet seeder.
 *
 *   php artisan drivers:list              # human-readable table
 *   php artisan drivers:list --inactive   # include deactivated drivers
 *   php artisan drivers:list --json       # machine-readable JSON
 *   php artisan drivers:list --csv        # CSV (stdout)
 *   php artisan drivers:list --missing    # show fields that are blank/null
 */
class ListDrivers extends Command
{
    protected $signature = 'drivers:list
                            {--inactive : include deactivated drivers}
                            {--json : output as JSON}
                            {--csv : output as CSV}
                            {--missing : highlight missing fields}';

    protected $description = 'List all drivers with profile data for auditing against the ops master sheet';

    public function handle(): int
    {
        $query = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->with('driverProfile')
            ->orderBy('name');

        if (!$this->option('inactive')) {
            $query->where('is_active', true);
        }

        $drivers = $query->get();

        if ($drivers->isEmpty()) {
            $this->warn('No drivers found.');
            return self::SUCCESS;
        }

        $rows = $drivers->map(function (User $u) {
            $p = $u->driverProfile;
            return [
                'id'            => $u->id,
                'username'      => $u->username,
                'name'          => $u->name,
                'id_number'     => $p?->id_number,
                'id_type'       => $p?->id_type ?? 'sa_id',
                'cellphone'     => $p?->cellphone ?: $u->phone,
                'trade_plate'   => $p?->trade_plate,
                'plate_expiry'  => optional($p?->trade_plate_expiry)->format('Y-m-d'),
                'tracker_id'    => $p?->tracker_id,
                'camera_id'     => $p?->camera_id,
                'toll_card'     => $p?->toll_card_number,
                'license'       => $p?->license_code,
                'pdp_expiry'    => optional($p?->prdp_expiry)->format('Y-m-d'),
                'active'        => $u->is_active ? 'yes' : 'no',
            ];
        })->values()->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($this->option('csv')) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fh, array_values($row));
            }
            fclose($fh);
            return self::SUCCESS;
        }

        // Default: pretty table. Trim to the columns that fit terminals nicely.
        $headers = ['#', 'Name', 'ID / Passport', 'Cell', 'T-Plate', 'T-Plate Exp', 'Tracker', 'License', 'PDP Exp', 'Active'];
        $display = array_map(function ($r) {
            $mark = fn($v) => $this->option('missing') && ($v === null || $v === '') ? '<fg=red>—</>' : ($v ?? '—');

            // Annotate the identity column with doc type + anomaly flag:
            //  - "PASSPORT" tag for non-SA ID holders
            //  - "!" warning tag when an id_type is 'sa_id' but the number is
            //    not 13 digits (likely data entry error — ops should verify).
            $idCol = $mark($r['id_number']);
            if ($r['id_number']) {
                if ($r['id_type'] === 'passport') {
                    $idCol = $r['id_number'] . ' <fg=cyan>[PASSPORT]</>';
                } elseif ($r['id_type'] === 'other') {
                    $idCol = $r['id_number'] . ' <fg=cyan>[OTHER]</>';
                } elseif (!preg_match('/^\d{13}$/', $r['id_number'])) {
                    $idCol = $r['id_number'] . ' <fg=yellow>[verify]</>';
                }
            }

            return [
                $r['id'],
                $r['name'],
                $idCol,
                $mark($r['cellphone']),
                $mark($r['trade_plate']),
                $mark($r['plate_expiry']),
                $mark($r['tracker_id']),
                $mark($r['license']),
                $mark($r['pdp_expiry']),
                $r['active'],
            ];
        }, $rows);

        $this->info(sprintf('Drivers on file: %d%s', count($rows), $this->option('inactive') ? ' (incl. inactive)' : ''));
        $this->table($headers, $display);

        // Quick expiry warning summary — useful for a "what needs attention" glance.
        $today = now()->startOfDay();
        $expiredPlate = collect($rows)->filter(fn($r) => $r['plate_expiry'] && $r['plate_expiry'] < $today->format('Y-m-d'))->count();
        $expiredPdp = collect($rows)->filter(fn($r) => $r['pdp_expiry'] && $r['pdp_expiry'] < $today->format('Y-m-d'))->count();
        $missingPdp = collect($rows)->filter(fn($r) => !$r['pdp_expiry'])->count();
        $anomalousIds = collect($rows)
            ->filter(fn($r) => $r['id_number']
                && ($r['id_type'] ?? 'sa_id') === 'sa_id'
                && !preg_match('/^\d{13}$/', $r['id_number']))
            ->count();
        $passportHolders = collect($rows)->filter(fn($r) => ($r['id_type'] ?? 'sa_id') === 'passport')->count();

        if ($expiredPlate || $expiredPdp || $missingPdp || $anomalousIds || $passportHolders) {
            $this->newLine();
            if ($expiredPlate) {
                $this->warn(sprintf('  %d trade plate(s) expired', $expiredPlate));
            }
            if ($expiredPdp) {
                $this->warn(sprintf('  %d PDP(s) expired', $expiredPdp));
            }
            if ($missingPdp) {
                $this->warn(sprintf('  %d driver(s) with no PDP expiry on file', $missingPdp));
            }
            if ($passportHolders) {
                $this->line(sprintf('  <fg=cyan>%d passport holder(s)</> (non-SA ID)', $passportHolders));
            }
            if ($anomalousIds) {
                $this->line(sprintf('  <fg=yellow>%d SA ID(s) not 13 digits</> — verify with ops (may be data entry errors or passports mislabelled as SA IDs)', $anomalousIds));
            }
        }

        return self::SUCCESS;
    }
}
