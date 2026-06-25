<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seed ProSelver's existing active customer base (companies) from the
 * 2026 Customer Listing Report export.  Idempotent on the model's
 * normalized_name (lower + ASCII-folded) so it's safe to re-run on
 * production every time the listing is refreshed -- only rows that
 * don't already exist on the platform are inserted.
 *
 *   php artisan db:seed --class=ProselverCustomersSeeder --force
 *
 * The source row layout is `[name, category, phone]`.  Categories
 * are translated to the platform's Company::TYPE_* slugs via a small
 * matcher in run() -- the spreadsheet uses a handful of free-text
 * labels (`Dealer`, `OEM`, `OEM/Dealer`, `Group/Dealer`, `Body
 * Builder`, `Rental`) that we fold into the four real types.
 *
 * `Proselver Technologies CC` (the platform owner itself) and the
 * placeholder `Cash Sales` row are intentionally NOT in the list.
 */
class ProselverCustomersSeeder extends Seeder
{
    /**
     * Source rows from the 2026 Customer Listing Report.
     *
     * @var array<int, array{0:string, 1:string, 2:?string}>
     */
    private const ROWS = [
        ['BIDVEST MCCARTHY FAW GERMISTON',                       'Dealer',       '011 437 2380'],
        ['Bidvest McCarthy Hino Midrand',                        'Dealer',       null],
        ['Bidvest Mccarthy Isuzu Pretoria East',                 'Dealer',       '012 003 3092'],
        ['BOLAND MOTOR GROUP (PTY) LTD',                         'Dealer',       null],
        ['CFAO Mobility',                                        'Group/Dealer', null],
        ['Clover South Africa Pty Ltd',                          'Rental',       null],
        ['COMBINED TRUCK AND EQUIPMENT GROUP (PTY) LTD',         'Dealer',       '+27 (0) 11 390 8160'],
        ['EQUUM PTY LTD T/A POWERSTAR POLOKWANE',                'Dealer',       null],
        ['EVERSTAR INDUSTRIES (PTY) LTD JNB',                    'OEM',          '012 940 1750'],
        ['EVERSTAR INDUSTRIES PTY LTD PMB - Plant',              'OEM',          '033 846 0500'],
        ['FAW PRETORIA WEST',                                    'Dealer',       '012 372 0309'],
        ['FAW TRUCKS SOUTH AFRICA ISANDO SHOWROOM',              'OEM/Dealer',   '087 700 2903'],
        ['FAW VEHICLE MANUFACTURES SA (PTY) LTD',                'OEM',          '0877002838'],
        ['Global Commodity Traders Pty Ltd',                     'Dealer',       null],
        ['HANDAX MACHINERY (PTY) LTD',                           'Dealer',       null],
        ['HINO ALGOA',                                           'Dealer',       '041 393 2047'],
        ['HINO EAST RAND',                                       'Dealer',       '011 914 8902'],
        ['Hino Eastrand',                                        'Dealer',       '0119148900'],
        ['Isuzu Cape Gate',                                      'Dealer',       null],
        ['ISUZU CAPE GATE DEALERSHIP',                           'Dealer',       '021 002 5421'],
        ['ISUZU CITY DEEP',                                      'Dealer',       '0832638707'],
        ['ISUZU MALMESBURY',                                     'Dealer',       '0224821158'],
        ['Isuzu Motors South Africa (Pty) Ltd',                  'OEM',          null],
        ['Isuzu Motors South Africa - Corporate Affairs',        'OEM',          null],
        ['Isuzu Motors South Africa - Customer Care',            'OEM',          '0118064656'],
        ['Isuzu Motors South Africa - CV movements',             'OEM',          '0414039130'],
        ['Isuzu Motors South Africa - Marketing Fleet',          'OEM',          '011 8064815'],
        ['Isuzu Motors South Africa - Press Fleet',              'OEM',          '011 8064815'],
        ['Isuzu Motors South Africa - Sales Fleet',              'OEM',          '011 8064815'],
        ['Isuzu Truck Centre Cape Town',                         'Dealer',       null],
        ['ISUZU TRUCK CENTRE CITY DEEP',                         'Dealer',       null],
        ['Isuzu Truck Centre Midrand',                           'Dealer',       '011 207 0900'],
        ['ISUZU TRUCK CENTRE PORT ELIZABETH',                    'Dealer',       '041 001 0812'],
        ['Isuzu Truck Centre Pretoria',                          'Dealer',       null],
        ['ISUZU TRUCKS BLACKHEATH',                              'Dealer',       '021 002 5490'],
        ['Isuzu Trucks Midrand - Workshop',                      'Dealer',       null],
        ['Isuzu Woodmead',                                       'Dealer',       null],
        ['Ithemba Truck Bodies',                                 'Body Builder', '011 420 0160 / 70'],
        ['KEY DURBAN',                                           'Dealer',       '0314625215'],
        ['KEY PIETERMARITZBURG',                                 'Dealer',       null],
        ['KIA EAST LONDON',                                      'Dealer',       '0438805010'],
        ['Motus Group LTD t/a Zambezi Multifranchise',           'Dealer',       '012 492 5150'],
        ['MOTUS ISUZU ISANDO',                                   'Dealer',       '0119743001'],
        ['Phantom Power',                                        'Dealer',       null],
        ['REEDE N1 CITY',                                        'Dealer',       '021 596 2611'],
        ['Reeds Belville',                                       'Dealer',       '0214435100'],
        ['Reeds N1 City',                                        'Dealer',       null],
        ['TRUCKS 2 GO (PTY) LTD',                                'Dealer',       null],
        ['UNI-SPEC BODIES (PTY) LTD',                            'Body Builder', '011 892 8732'],
        ['William Hunt Blackheath',                              'Dealer',       null],
        ['William Hunt Hatfield',                                'Dealer',       null],
        ['WILLIAM HUNT PORT ELIZABETH',                          'Dealer',       '041 396 4621'],
        ['WILLIAM HUNT THE GLEN',                                'Dealer',       '(011) 210 6000'],
        ['WILLIAM HUNT TRUCK PARTS PE',                          'Dealer',       '0414058676'],
        ['Williams Hunt Centurion',                              'Dealer',       null],
        ['Williams Hunt Fourways',                               'Dealer',       null],
        ['Williams Hunt Kroonstad',                              'Dealer',       null],
        ['Williams Hunt Midrand',                                'Dealer',       null],
        ['Williams Hunt Port Elizabeth',                         'Dealer',       null],
        ['Williams Hunt Pretoria',                               'Dealer',       '012 328 6582'],
        ['Williams Hunt Roodepoort',                             'Dealer',       null],
        ['Williams Hunt The Glen',                               'Dealer',       null],
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;
        $skippedSourceDupes = 0;
        $seenNormalized = [];

        foreach (self::ROWS as [$name, $category, $phone]) {
            $name = trim($name);
            $normalized = Str::lower(Str::ascii($name));

            // De-dupe within the source spreadsheet itself -- the
            // 2026 export has a handful of near-duplicates from
            // different upstream systems that resolve to the same
            // normalised key (e.g. "Reeds N1 City" appearing twice).
            if (isset($seenNormalized[$normalized])) {
                $skippedSourceDupes++;
                continue;
            }
            $seenNormalized[$normalized] = true;

            // Skip if already on the platform.  withTrashed() so a
            // soft-deleted row isn't shadowed by an attempted re-
            // insert and tripping the unique constraint on
            // normalized_name (the model has SoftDeletes).
            $existing = Company::withTrashed()
                ->where('normalized_name', $normalized)
                ->first();
            if ($existing) {
                $skipped++;
                continue;
            }

            $type = $this->mapCategory($category);

            Company::create([
                'name'          => $name,
                'type'          => $type,
                'workflow_type' => $this->workflowTypeFor($name, $category),
                'phone'         => $phone ? $this->cleanPhone($phone) : null,
                'is_active'     => true,
            ]);

            $created++;
        }

        $msg = "ProselverCustomersSeeder: created {$created}, skipped {$skipped} already on platform";
        if ($skippedSourceDupes > 0) {
            $msg .= ", {$skippedSourceDupes} duplicate(s) in source";
        }
        $this->command?->info($msg);
    }

    /**
     * Map the free-text spreadsheet category to a Company::TYPE_*
     * slug.  Unknown / blank categories fall back to dealer because
     * 90 %+ of the customer base are dealers and that's the safest
     * default for ProSelver staff to land on when reviewing the new
     * row in admin.
     */
    private function mapCategory(?string $category): string
    {
        return match (Str::lower(trim((string) $category))) {
            'oem', 'oem/dealer'              => Company::TYPE_OEM,
            'body builder'                   => Company::TYPE_BODY_BUILDER,
            'dealer', 'group/dealer', 'rental', '' => Company::TYPE_DEALER,
            default                          => Company::TYPE_DEALER,
        };
    }

    /**
     * FAW dealerships and the FAW factory itself need the FAW
     * customer-confirmation workflow (the same flag the admin sets
     * by hand today via workflow_type='faw' on the company edit
     * page).  Everything else stays on the standard auto-confirm
     * workflow.
     */
    private function workflowTypeFor(string $name, ?string $category): string
    {
        return Str::contains(Str::lower($name), 'faw') ? 'faw' : 'standard';
    }

    /**
     * Light-touch phone-number cleanup -- collapse runs of spaces
     * and strip trailing slashes / common artefacts.  We do NOT
     * try to normalise to E.164 because the column is also shown
     * verbatim on the admin company list (the spreadsheet's
     * formatting is the dealer's preferred contact form).
     */
    private function cleanPhone(string $phone): string
    {
        $phone = trim(preg_replace('/\s+/', ' ', $phone) ?? $phone);
        return rtrim($phone, " /\\-");
    }
}
