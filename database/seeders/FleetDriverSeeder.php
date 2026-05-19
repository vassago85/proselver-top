<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DriverProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed the current ProselverTech fleet driver roster (April 2026 master
 * sheet). Safe to re-run:
 *   - Matches existing drivers by SA ID number (primary key) and merges
 *     sheet data onto their profile.
 *   - Creates brand-new users for drivers not yet on file, with role
 *     "driver" and the default reset password.
 *   - Always resets the password to the value below so ops can hand a
 *     clean credential to each driver on first login.
 *
 *   php artisan db:seed --class=FleetDriverSeeder
 *
 * After seeding, audit with:
 *
 *   php artisan drivers:list --missing
 */
class FleetDriverSeeder extends Seeder
{
    /**
     * Default password applied to every fleet driver (new AND existing).
     * Override via env FLEET_DRIVER_DEFAULT_PASSWORD if you need to rotate.
     */
    protected function defaultPassword(): string
    {
        return (string) env('FLEET_DRIVER_DEFAULT_PASSWORD', 'Trident@2026');
    }

    public function run(): void
    {
        $password = Hash::make($this->defaultPassword());
        $rows = $this->masterSheet();

        // Every fleet driver must be linked to the platform-owner company
        // (ProSelver) via the company_users pivot, otherwise they won't
        // appear in the order-show "Assign Driver" picker which scopes by
        // User::scopePlatformDrivers().
        $platform = Company::where('is_platform_owner', true)->first();
        if (! $platform) {
            throw new ModelNotFoundException(
                'No company has is_platform_owner=true. Seed the ProSelver company before running FleetDriverSeeder.'
            );
        }

        $created = 0;
        $updated = 0;
        $touched = [];

        DB::transaction(function () use ($rows, $password, $platform, &$created, &$updated, &$touched) {
            foreach ($rows as $row) {
                $profile = DriverProfile::where('id_number', $row['id_number'])->first();

                if ($profile) {
                    $user = $profile->user;
                    // Force every (re-)seeded driver to rotate their password
                    // on next PWA login. The ForceChangePassword middleware
                    // routes them to /profile until the rotation happens.
                    $user->update([
                        'name'                  => $row['name'],
                        'phone'                 => $row['cellphone'],
                        'password'              => $password,
                        'must_change_password'  => true,
                        'password_changed_at'   => null,
                        'is_active'             => true,
                    ]);
                    $profile->update([
                        'id_type'            => $row['id_type'],
                        'cellphone'          => $row['cellphone'],
                        'trade_plate'        => $row['trade_plate'],
                        'trade_plate_expiry' => $row['trade_plate_expiry'],
                        'tracker_id'         => $row['tracker_id'],
                        'camera_id'          => $row['camera_id'],
                        'toll_card_number'   => $row['toll_card_number'],
                        'license_code'       => $row['license_code'],
                        'prdp_expiry'        => $row['prdp_expiry'],
                    ]);
                    $user->companies()->syncWithoutDetaching([$platform->id]);
                    $updated++;
                    $touched[] = ['action' => 'updated', 'name' => $row['name'], 'username' => $user->username];
                    continue;
                }

                $username = $this->pickUsername($row['name']);
                $user = User::create([
                    'uuid'                 => (string) Str::uuid(),
                    'username'             => $username,
                    'name'                 => $row['name'],
                    'phone'                => $row['cellphone'],
                    'password'             => $password,
                    'must_change_password' => true,
                    'is_active'            => true,
                ]);
                $user->assignRole('driver');
                $user->companies()->syncWithoutDetaching([$platform->id]);

                DriverProfile::create([
                    'user_id'            => $user->id,
                    'id_number'          => $row['id_number'],
                    'id_type'            => $row['id_type'],
                    'cellphone'          => $row['cellphone'],
                    'trade_plate'        => $row['trade_plate'],
                    'trade_plate_expiry' => $row['trade_plate_expiry'],
                    'tracker_id'         => $row['tracker_id'],
                    'camera_id'          => $row['camera_id'],
                    'toll_card_number'   => $row['toll_card_number'],
                    'license_code'       => $row['license_code'],
                    'prdp_expiry'        => $row['prdp_expiry'],
                ]);

                $created++;
                $touched[] = ['action' => 'created', 'name' => $row['name'], 'username' => $username];
            }
        });

        $this->command->info(sprintf('Fleet driver seed complete: %d created, %d updated.', $created, $updated));
        $this->command->line(sprintf('Default password set to: <fg=yellow>%s</> — all drivers will be forced to change it on their next login.', $this->defaultPassword()));
        $this->command->newLine();

        $this->command->table(['Action', 'Name', 'Username'], array_map(
            fn($t) => [$t['action'], $t['name'], $t['username']],
            $touched
        ));
    }

    /**
     * Pick a unique username in the form `first.last`, falling back to a
     * numeric suffix if taken. Strips diacritics and anything non-alphanum.
     */
    protected function pickUsername(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        $first = $parts[0] ?? 'driver';
        $last  = $parts[count($parts) - 1] ?? '';

        $base = Str::lower(Str::ascii(trim($first . '.' . $last, '.')));
        $base = preg_replace('/[^a-z0-9.]/', '', $base) ?: 'driver';

        $username = $base;
        $i = 2;
        while (User::where('username', $username)->exists()) {
            $username = $base . $i;
            $i++;
        }

        return $username;
    }

    /**
     * Parse a DD-MM-YYYY or DD/MM/YYYY string to a Carbon date, or null.
     * Defensive against the two different formats used on the sheet.
     */
    protected function parseDate(?string $raw): ?Carbon
    {
        if (!$raw) {
            return null;
        }
        $raw = trim($raw);
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d'] as $fmt) {
            $d = Carbon::createFromFormat($fmt, $raw);
            if ($d && $d->format($fmt) === $raw) {
                return $d->startOfDay();
            }
        }
        return null;
    }

    /**
     * Transcribed verbatim from the ops master driver sheet (April 2026).
     * Numbering matches the physical sheet to make audits easy. The spare
     * T-plate row at the bottom of the sheet is intentionally excluded —
     * it is not a driver, it is a reserve trade plate.
     */
    protected function masterSheet(): array
    {
        // id_type defaults to 'sa_id'. Row 23 (Clifford Chinyana) carries a
        // Zimbabwean passport number and is flagged as 'passport' so UI
        // labels render correctly ("Passport" instead of "ID Number").
        $P = DriverProfile::ID_TYPE_PASSPORT;
        $I = DriverProfile::ID_TYPE_SA_ID;
        $raw = [
            // [no,  name,                    id_number,       id_type, cell,              t_plate,     t_exp,       camera,      tracker,    toll,      license,  pdp_exp]
            [1,  'Bongani Hlatshwayo',    '6311085627086',  $I, '073 603 9253',    'AGC 166 GP', '31-08-2026', 'MOBILE 8A', 'AT4-84700', '1183636', 'CODE 14', '24-11-2026'],
            [2,  'Francis Mpheteng',      '6501045391084',  $I, '072 614 1617',    'AGD 955 GP', '31-08-2026', 'MOBILE 14A','AT4-78405', '1183638', 'CODE 14', '16-08-2027'],
            [3,  'Makatlego Ntsabeleng',  '9004280207088',  $I, '072 999 1652',    'AGF 456 GP', '31-08-2026', 'MOBILE 12A','AT4-84445', '1183642', 'CODE 14', '17-08-2027'],
            [4,  'Mduduzi Sithole',       '8201225508080',  $I, '072 780 2629',    'AGF 452 GP', '30-09-2026', 'MOBILE 3A', 'AT4-84965', '1183636', 'CODE 14', '08-09-2027'],
            [5,  'Mlondolozi Dube',       '8909236431080',  $I, '071 109 1830',    'AGF 454 GP', '30-09-2026', 'MOBILE 10A','AT4-85350', '1183640', 'CODE 14', '04-07-2026'],
            [6,  'Peter Molala',          '6409125474087',  $I, '083 510 6435',    'AGD 943 GP', '31-05-2026', 'MOBILE 4A', 'AT4-82852', '1183637', 'CODE 14', '18-08-2027'],
            [7,  'Xolani Tshabalala',     '7803036329084',  $I, '066 570 5111',    'AGF 455 GP', '30-09-2025', 'MOBILE 9A', 'AT4-87323', '1183633', 'CODE 14', '15-10-2025'],
            [8,  'Xolisile Shipana',      '8501290781086',  $I, '073 081 0347',    'AGF 457 GP', '30-09-2026', null,        'AT4-85087', '1183643', 'CODE 14', '08-09-2026'],
            [9,  'Khulekani Dlalisa',     '6906166078086',  $I, '072 249 5719',    'AGF 450 GP', '31-08-2026', 'MOBILE 7A', 'AT4-85038', '1184158', 'CODE 14', '30-01-2026'],
            [10, 'Patrick Mathe',         '81121456910180', $I, '+27 82 057 6388', 'AGF 451 GP', '31-08-2026', null,        'AT4-84288', '1183634', 'CODE 14', '08-09-2026'],
            [11, 'Mandla Nkabinde',       '7808145558085',  $I, '078 483 9181',    'AGC 165 GP', '31-08-2026', 'MOBILE 4A', 'AT4-85079', '1183635', 'CODE 10', '24-03-2026'],
            [12, 'James Masipa',          '8202205524089',  $I, '079 910 1985',    'AGB 573 GP', '31-08-2026', 'MOBILE 2A', 'AT4-83785', '1184157', 'CODE 14', '10-10-2025'],
            [13, 'Themba Malepe',         '407065383082',   $I, '0719250903',      'AGF 459 GP', '30-09-2026', null,        'AT4-79296', null,      'CODE 14', '24-11-2027'],
            [14, 'Innocent Lebea',        '8612035848088',  $I, '0733547642',      'AFY 688 GP', '28-02-2026', null,        'AT4-82886', null,      'CODE 14', '15-07-2027'],
            [15, 'Sithembiso Xulu',       '8309125171089',  $I, '073 986 8983',    'AGB 571 GP', '31-08-2026', null,        'AT4-84296', null,      'CODE 14', '12-12-2026'],
            [16, 'Samuel Seotlo',         '9301135655085',  $I, '076 366 4073',    'AGD 940 GP', '31-05-2026', null,        'AT4-84353', null,      'CODE 14', '01-09-2026'],
            [17, 'Bhengu Msawenkosi',     '9209285634083',  $I, '0639930983',      'AGB 055 GP', '31-08-2026', 'MOBILE 5A', 'AT4-13792', null,      'CODE 14', '06-04-2027'],
            [18, 'Mavis Masango',         '9009100673088',  $I, '0828435853',      'AGC 159 GP', '31-08-2026', null,        'AT4-10590', null,      'CODE 10', '19-09-2026'],
            [19, 'Ntombizonke Msakatya',  '8212220529088',  $I, '0678606093',      'AGC 161 GP', '31-08-2026', null,        'AT4-10574', null,      'CODE 14', '29-11-2025'],
            [20, 'Hullets Kowa',          '6305205778084',  $I, '0718087575',      'AGC 160 GP', '31-08-2026', '103389',    'AT4-83777', null,      'CODE 14', '21-04-2026'],
            [21, 'Vukani Ziqubu',         '7406125487084',  $I, '0834283652',      'AGC 162 GP', '31-08-2026', 'MOBILE 18A','AT4-83751', null,      'CODE 14', '16-04-2027'],
            [22, 'Sxolile Nkosi',         '8909121732089',  $I, '0732878861',      'AGC 167 GP', '31-07-2026', null,        'AT4-79163', null,      'CODE 14', '01-09-2026'],
            [23, 'Clifford Chinyana',     '86-044411H-86',  $P, '+27 84 298 2465', 'AGF 458 GP', '31-08-2026', null,        'AT4-79205', null,      'CODE 14', '21-04-2026'],
            [24, 'Sizwe Madlala',         '8308185786083',  $I, '0729853384',      'AGH 780 GP', '31-10-2026', null,        'AT4-00351', null,      'CODE 14', '29-09-2027'],
            [25, 'Neziswa Sonti',         '9509281270083',  $I, '0827370137',      'AGH 840 GP', '31-10-2026', null,        'AT4-00351', null,      'CODE 14', '04-03-2026'],
            [26, 'Nkanyiso Nene',         '8112165851085',  $I, '0734092552',      'AGH 779 GP', '31-10-2026', null,        'AT4-00435', null,      'CODE 14', '09-07-2027'],
            [27, 'Simphiwe Dlomo',        '9609116192088',  $I, '0827374750',      'AGH 778 GP', '31-10-2026', null,        'AT4-08834', null,      'CODE 14', '17-03-2027'],
            [28, 'Mazibuko Kenny',        '5909235678080',  $I, '0732744442',      'AGH 831 GP', '31-10-2026', null,        'AT4-14303', null,      'CODE 14', '04-03-2026'],
            [29, 'Johannes Futhana',      '8003145678088',  $I, '+27 79 789 4823', 'AGH 832 GP', '31-10-2026', null,        'AT4-09527', null,      'CODE 14', '16-10-2027'],
            [30, 'Philani Buthelezi',     '9111165655081',  $I, '0738192824',      'AGC 164 GP', '31-08-2026', null,        'AT4-15656', null,      'CODE 14', '30-08-2026'],
            [31, 'Goodboy Ngonyolo',      '7708106035083',  $I, '0639793901',      'AGH 839 GP', '31-10-2026', null,        'AT4-00419', null,      'CODE 14', '16-05-2026'],
            [32, 'Benneth Hlophe',        '8810275414089',  $I, '+27 72 034 9194', 'AGH 793 GP', '31-10-2026', null,        'AT4-15649', null,      'CODE 14', '13-06-2026'],
            [33, 'Khulekani Ntombela',    '8510085447084',  $I, '+27 82 812 3382', 'AGH 830 GP', '31-10-2026', null,        'AT4-02761', null,      'CODE 14', '06-06-2027'],
            [34, 'Austin Ntseki',         '5612015761080',  $I, '0730844906',      'AGH 818 GP', '31-10-2026', null,        'AT4-14261', null,      'CODE 14', '10-04-2027'],
            [35, 'Thandanani Dlamini',    '9106085928080',  $I, '0788592206',      'AGH 829 GP', '31-10-2026', null,        'AT4-15615', null,      'CODE 14', '03-08-2027'],
            [36, 'Sizwe Mabotshwa',       '7701026691085',  $I, '0604798364',      'AGH 777 GP', '31-10-2026', null,        'AT4-00393', null,      'CODE 14', '16-05-2026'],
            [37, 'Victor Ntambula',       '7108125316087',  $I, '0731000183',      'AGH 808 GP', '31-10-2026', null,        'AT4-09535', null,      'CODE 14', '23-07-2026'],
            [38, 'Russell Gumede',        '6612085555084',  $I, '0763355918',      'AGH 810 GP', '31-10-2026', null,        'AT4-00252', null,      'CODE 14', '18-03-2027'],
        ];

        return array_map(fn($r) => [
            'no'                 => $r[0],
            'name'               => $r[1],
            'id_number'          => $r[2],
            'id_type'            => $r[3],
            'cellphone'          => $r[4],
            'trade_plate'        => $r[5],
            'trade_plate_expiry' => $this->parseDate($r[6]),
            'camera_id'          => $r[7],
            'tracker_id'         => $r[8],
            'toll_card_number'   => $r[9],
            'license_code'       => $r[10],
            'prdp_expiry'        => $this->parseDate($r[11]),
        ], $raw);
    }
}
