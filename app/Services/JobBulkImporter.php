<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use App\Models\VehicleClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk-load OEM movement spreadsheets (FAW / Isuzu / etc.) into transport
 * jobs.
 *
 * Pipeline:
 *
 *   parse(path)        → flatten every sheet in the workbook into a list of
 *                        rows keyed by their normalised header strings.
 *
 *   detectMapping()    → guess the field-to-column mapping (vin, model,
 *                        pickup, delivery, movement_date, comments). The
 *                        first time ops uploads for an OEM they confirm
 *                        the mapping; subsequent uploads short-circuit
 *                        through Company::movement_csv_mapping.
 *
 *   preview()          → for every parsed row, look up locations against
 *                        the OEM's address book, parse dates (Excel serial
 *                        OR dd-mm-yyyy / dd/mm/yyyy strings), drop "ON HOLD"
 *                        rows unless explicitly opted in. Returns a flat
 *                        list of preview rows with status / warnings /
 *                        errors so the UI can render the green/yellow/red
 *                        table without re-running the parser.
 *
 *   commit()           → wraps creation in a single DB transaction. Calls
 *                        BookingService for every committable row so jobs
 *                        come out the other side identical to ones booked
 *                        through the dealer / OEM portals (pending
 *                        verification, in the Phase-1 funnel).
 *
 * Things this importer DOES NOT do (kept deliberately small):
 *   - No price/PO inference. The user said "this is just for loading" —
 *     ops will price the job once it lands. Files don't carry POs anyway.
 *   - No driver assignment. Even when the source file has driver names
 *     and cell numbers (Isuzu format) we ignore them; dispatch picks the
 *     driver afterwards.
 *   - No vehicle-model catalogue lookups. We persist the model name
 *     verbatim; matching to vehicle_models is a separate cleanup task.
 */
class JobBulkImporter
{
    /**
     * Logical fields the importer understands. Everything else on the
     * sheet is informational and is dropped on parse. Order matters only
     * for the UI mapping form (we render in this order).
     */
    public const FIELDS = [
        'vin'             => 'VIN / chassis number',
        'registration'    => 'Registration / number plate',
        'model'           => 'Model description',
        'pickup'          => 'Pickup location (origin)',
        'delivery'        => 'Delivery location (destination)',
        'movement_date'   => 'Movement / scheduled date',
        'comments'        => 'Comments / notes',
        // Optional executor-aware columns. Customers using their own
        // drivers / 3rd-party couriers / self-collect can express that
        // intent inline in the spreadsheet instead of editing each
        // booking individually after import. All blank for a row =
        // falls back to the page-level default executor.
        'executor_type'   => 'Executor (proselver / internal / 3rd_party / self_collect)',
        'driver_name'     => 'Internal driver name (for executor=internal)',
        'courier_name'    => '3rd-party courier name (for executor=3rd_party)',
        'waybill'         => '3rd-party waybill / tracking number',
        'collector_name'  => 'Self-collect collector name',
        'collector_phone' => 'Self-collect collector phone',
    ];

    /**
     * Fields that MUST resolve to a value for a row to be committable.
     * Note: VIN OR registration is enforced separately in preview()
     * because "one of two columns" isn't expressible as a per-field
     * flag -- either identifier is a valid vehicle reference.
     */
    public const REQUIRED_FIELDS = ['pickup', 'delivery'];

    /**
     * Heuristic header → field guesses. We lower-case + collapse whitespace
     * before matching so a header like "Movement \nOrder Date" still
     * lines up. First match wins.
     */
    private const HEADER_HINTS = [
        'vin'             => ['vin', 'chassis', 'chassis no', 'chassis number'],
        // Aliases mirror DealerStockImporter so a single template
        // works across both flows.
        'registration'    => ['registration', 'regno', 'reg no', 'reg', 'regnumber', 'reg number', 'licenseplate', 'license plate', 'licenseno', 'numberplate', 'number plate', 'plate'],
        'model'           => ['model', 'model description', 'vehicle model'],
        'pickup'          => ['from', 'departure', 'origin', 'pickup', 'collection from'],
        'delivery'        => ['to', 'destination', 'delivery', 'deliver to', 'collection to'],
        'movement_date'   => ['movement date', 'movement order date', 'scheduled date', 'collection date', 'date'],
        'comments'        => ['comments', 'comment', 'notes', 'remarks'],
        'executor_type'   => ['executor', 'executor type', 'movement type', 'transporter'],
        'driver_name'     => ['driver', 'driver name', 'internal driver'],
        'courier_name'    => ['courier', 'courier name', '3rd party', 'third party courier'],
        'waybill'         => ['waybill', 'waybill no', 'tracking', 'tracking number', 'consignment'],
        'collector_name'  => ['collector', 'collector name', 'self collect', 'collected by'],
        'collector_phone' => ['collector phone', 'collector mobile', 'collector cell'],
    ];

    /**
     * Map free-form executor strings the operator might type in a
     * spreadsheet cell onto Job::EXECUTOR_* values.  We lower-case +
     * trim before matching.  Anything unrecognised falls through to the
     * page-level default, so a typo never silently flips an entire
     * customer's executor pool.
     */
    private const EXECUTOR_ALIASES = [
        'proselver'          => Job::EXECUTOR_PROSELVER,
        'proseltech'         => Job::EXECUTOR_PROSELVER,
        'us'                 => Job::EXECUTOR_PROSELVER,
        'internal'           => Job::EXECUTOR_INTERNAL,
        'own'                => Job::EXECUTOR_INTERNAL,
        'in-house'           => Job::EXECUTOR_INTERNAL,
        'inhouse'            => Job::EXECUTOR_INTERNAL,
        'dealer driver'      => Job::EXECUTOR_INTERNAL,
        '3rd party'          => Job::EXECUTOR_THIRD_PARTY,
        '3rd-party'          => Job::EXECUTOR_THIRD_PARTY,
        'third party'        => Job::EXECUTOR_THIRD_PARTY,
        'third-party'        => Job::EXECUTOR_THIRD_PARTY,
        'courier'            => Job::EXECUTOR_THIRD_PARTY,
        'self collect'       => Job::EXECUTOR_SELF_COLLECT,
        'self-collect'       => Job::EXECUTOR_SELF_COLLECT,
        'collection'         => Job::EXECUTOR_SELF_COLLECT,
        'customer collect'   => Job::EXECUTOR_SELF_COLLECT,
        'customer'           => Job::EXECUTOR_SELF_COLLECT,
    ];

    /**
     * Phrases in date columns that mean "we don't have a date yet, treat
     * the row as on hold". Kept lower-case because we lower-case the
     * incoming cell first.
     */
    private const ON_HOLD_TOKENS = ['on hold', 'hold', 'tbc', 'tba', 'pending'];

    public function __construct(
        protected BookingService $bookingService,
    ) {}

    // ---------------------------------------------------------------------
    // PARSE
    // ---------------------------------------------------------------------

    /**
     * Read a CSV / XLSX / XLS file and return:
     *
     *   [
     *     'headers' => ['Model', 'Chassis No.', ...],   // normalised, deduped union across sheets
     *     'rows'    => [
     *       [
     *         '_sheet' => 'February 2026',
     *         '_row'   => 2,
     *         'Model'        => 'J5N 28.290FL',
     *         'Chassis No.'  => 'AAK2829FLSB121485',
     *         ...
     *       ],
     *       ...
     *     ],
     *   ]
     *
     * - First non-empty row of each sheet is treated as the header row.
     * - Subsequent rows are merged into the unified rows[] list.
     * - Empty rows (every cell blank) are dropped.
     * - Header strings are normalised (whitespace collapsed, trimmed) so
     *   the mapping form doesn't have to deal with embedded newlines.
     */
    public function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $headersUnion = [];
        $rows = [];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (!$sheet) {
                continue;
            }

            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();

            // Pull the entire sheet as a 2-D array. PhpSpreadsheet returns
            // raw cell values (numbers stay numeric — important for date
            // serials), and missing cells come through as null.
            $matrix = $sheet->rangeToArray('A1:' . $highestColumn . $highestRow, null, false, false);
            if (empty($matrix)) {
                continue;
            }

            $headerRow = array_shift($matrix);
            $headers = [];
            foreach ($headerRow as $i => $h) {
                $clean = $this->normaliseHeader($h);
                // Tolerate trailing empty header columns (Excel often saves
                // a phantom column past the last named one). Skip them
                // entirely instead of forcing a "Column F"-style label.
                if ($clean === '') {
                    $headers[$i] = null;
                    continue;
                }
                $headers[$i] = $clean;
                if (!in_array($clean, $headersUnion, true)) {
                    $headersUnion[] = $clean;
                }
            }

            foreach ($matrix as $rowIndex => $cells) {
                if ($this->isBlankRow($cells)) {
                    continue;
                }

                $assoc = [
                    '_sheet' => $sheetName,
                    '_row' => $rowIndex + 2,
                ];
                foreach ($cells as $i => $val) {
                    $h = $headers[$i] ?? null;
                    if ($h === null) {
                        continue;
                    }
                    $assoc[$h] = $val;
                }
                $rows[] = $assoc;
            }
        }

        return [
            'headers' => $headersUnion,
            'rows' => $rows,
        ];
    }

    // ---------------------------------------------------------------------
    // MAPPING
    // ---------------------------------------------------------------------

    /**
     * Guess a field → header mapping for the given headers, optionally
     * seeded by a previously-saved mapping on the company. Saved values
     * always win (ops hand-curated them); guesses fill the gaps.
     */
    public function detectMapping(array $headers, ?Company $company = null): array
    {
        $saved = $company?->movement_csv_mapping['columns'] ?? [];
        $mapping = [];

        foreach (array_keys(self::FIELDS) as $field) {
            // 1. honour a previously-saved choice if the same header still
            //    exists in the file. If the OEM renamed a column we fall
            //    through to the heuristic guess.
            if (!empty($saved[$field]) && in_array($saved[$field], $headers, true)) {
                $mapping[$field] = $saved[$field];
                continue;
            }

            // 2. heuristic match against the hint list.
            $mapping[$field] = $this->guessHeader($field, $headers);
        }

        return $mapping;
    }

    /**
     * Persist the mapping back onto the company so the next monthly
     * upload jumps straight to preview without re-mapping. Defaults
     * (brand / vehicle class / auto-create flag) are stored alongside.
     */
    public function rememberMapping(
        Company $company,
        array $columns,
        ?int $defaultBrandId = null,
        ?int $defaultVehicleClassId = null,
        bool $autoCreateLocations = true,
    ): void {
        $company->forceFill([
            'movement_csv_mapping' => [
                'columns' => array_filter($columns, fn ($v) => is_string($v) && $v !== ''),
                'default_brand_id' => $defaultBrandId,
                'default_vehicle_class_id' => $defaultVehicleClassId,
                'auto_create_locations' => $autoCreateLocations,
                'remembered_at' => now()->toIso8601String(),
            ],
        ])->save();
    }

    // ---------------------------------------------------------------------
    // PREVIEW
    // ---------------------------------------------------------------------

    /**
     * Walk every parsed row, apply the mapping, resolve locations against
     * the company's address book and return a uniform preview structure.
     * The Volt page renders this verbatim; nothing in the UI re-derives
     * status from the raw data.
     *
     * options:
     *   include_on_hold (bool, default false) — keep rows whose date cell
     *     contains "ON HOLD"/"HOLD"/blank. Their date defaults to today
     *     and they get flagged with a warning.
     *
     *   auto_create_locations (bool, default true) — flag rows whose
     *     pickup/delivery doesn't match an existing location with
     *     "will create"; commit() will then materialise them. With the
     *     flag off, those rows go to the "needs attention" bucket.
     */
    public function preview(
        Company $company,
        array $rows,
        array $mapping,
        array $options = [],
    ): array {
        $includeOnHold = (bool) ($options['include_on_hold'] ?? false);
        $autoCreate = (bool) ($options['auto_create_locations'] ?? true);
        $defaultClassId = $options['default_vehicle_class_id'] ?? null;
        // Page-level executor default — applied when a row leaves the
        // executor column blank.  Defaults to ProSelver so any existing
        // import that doesn't know about executors keeps working.
        $defaultExecutor = $this->normaliseExecutor($options['default_executor_type'] ?? null) ?: Job::EXECUTOR_PROSELVER;

        // Internal-driver lookup keyed by lower-cased name. Built once
        // per preview so we don't hit the DB per row when a customer's
        // sheet has dozens of internal-driver rows.
        $driverIndex = User::driversForCompany($company->id)
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(fn ($u) => [Str::lower(trim($u->name)) => (int) $u->id]);

        // The vehicle-class collection is optional. When provided we can
        // run a model-name heuristic per row (e.g. "28.290FL" → 28-tonne
        // class). Without it we just rely on the default class id.
        $vehicleClasses = $options['vehicle_classes'] ?? collect();

        // Remembered model → vehicle_class_id hints from previous imports
        // for this customer. The customer gets a smarter default each
        // time they upload because we capture every per-row class choice
        // on commit and replay it here as a guess. Lookup is keyed by
        // a normalised model string so "NPS300SWA" and " nps300swa "
        // both hit the same hint.
        $savedHints = (array) ($company->movement_csv_mapping['model_class_hints'] ?? []);

        // The importing company's own address book takes precedence, but
        // we ALSO let a row resolve to a shared operational location owned
        // by another company. Body builders, yards and plants are platform
        // infrastructure that turn up as origins / destinations on many
        // customers' files (e.g. "Anchor Body Builders" on an OEM's import).
        // Matching to the real record — which carries a proper street
        // address / city — stops the importer spawning a blank, city-less
        // duplicate under the importing company for every such row. We key
        // off the LOCATION type (not the owner's company type) so an OEM's
        // plant location, a body-builder's yard, and a standalone yard
        // company are all reachable.
        $ownLocations = $company->locations()->get();
        $sharedLocations = Location::query()
            ->where('is_active', true)
            ->where('company_id', '!=', $company->id)
            ->whereIn('type', [
                Location::TYPE_BODY_BUILDER,
                Location::TYPE_YARD,
                Location::TYPE_PLANT,
            ])
            ->get();
        // Own first so an exact / slug name clash resolves to the
        // customer's own record rather than a shared one.
        $locations = $ownLocations->concat($sharedLocations)->unique('id')->values();

        // Dedup: pull every in-flight job for this customer keyed by
        // BOTH VIN and registration so we can warn operators about
        // re-imports without round-tripping per row.  "In-flight"
        // means anything that hasn't finished its lifecycle -- a
        // delivered/completed/cancelled vehicle is fair game to
        // re-book (it's the next movement for the same physical
        // vehicle, e.g. coming back from storage or a body builder).
        // We index on either identifier because a plate-only booking
        // must still trip the "already on order" guard when re-imported.
        $existingJobs = Job::query()
            ->where('company_id', $company->id)
            ->where(function ($q) {
                $q->whereNotNull('vin')->orWhereNotNull('registration');
            })
            ->whereNotIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED, Job::STATUS_DELIVERED])
            ->orderByDesc('id') // newest match wins if an identifier somehow has multiple in-flight rows
            ->get(['vin', 'registration', 'job_number', 'status']);
        $existingByVin = $existingJobs
            ->filter(fn ($j) => $j->vin)
            ->keyBy(fn ($j) => strtoupper(trim((string) $j->vin)));
        $existingByReg = $existingJobs
            ->filter(fn ($j) => $j->registration)
            ->keyBy(fn ($j) => strtoupper(trim((string) $j->registration)));

        $today = now()->startOfDay();

        $preview = [];
        $seenVinsInFile = []; // VIN → first row index that used it
        $seenRegsInFile = []; // Registration → first row index that used it

        foreach ($rows as $row) {
            $entry = $this->buildPreviewRow($row, $mapping, $locations, $autoCreate, $defaultExecutor, $driverIndex);

            // Per-row vehicle class: prefer the operator-set default;
            // otherwise honour a remembered hint for this exact model;
            // otherwise try to infer from the model description so the
            // preview screen lights up green for the rows we recognise
            // and only flags the unfamiliar ones for manual review.
            $hintKey = $this->normaliseModelKey($entry['parsed']['model'] ?? null);
            $hintClassId = $hintKey ? ($savedHints[$hintKey] ?? null) : null;
            $entry['parsed']['vehicle_class_id'] = $defaultClassId
                ?: $hintClassId
                ?: $this->guessVehicleClassId($entry['parsed']['model'] ?? null, $vehicleClasses);

            if (!$entry['parsed']['vehicle_class_id']) {
                $entry['errors'][] = 'Vehicle class needed — set per row in the preview, or pick a default on the previous step';
            }

            // Past-date guard. FAW-style sheets list "movements required
            // for that day" with no explicit delivery date, so a date
            // that's already in the past must be a stale carry-over from
            // last month's file — refuse it rather than silently book
            // backdated jobs.
            if ($entry['parsed']['scheduled_date']) {
                $sd = Carbon::parse($entry['parsed']['scheduled_date'])->startOfDay();
                if ($sd->lt($today)) {
                    $entry['errors'][] = 'Movement date ' . $sd->toDateString() . ' is in the past — historical movements are not imported';
                }
            }

            // Vehicle-identifier dedup.  Three different shapes here:
            //   - Same identifier listed twice in this upload → hard
            //     ERROR (an operator typo, importing both would create
            //     a guaranteed duplicate booking).
            //   - Identifier matches an ACTIVE job on the customer's
            //     account → BLOCKED until the operator ticks the
            //     override checkbox.  A vehicle that's genuinely on
            //     its next leg (returning from storage, body builder
            //     to dealer, etc.) needs to be importable but must
            //     be a deliberate choice, never an accident.
            //   - Identifier matches a delivered/completed/cancelled
            //     job → no flag at all; that's a clean re-book.
            // We check VIN and registration independently so a
            // reg-only re-import still trips the guard.
            $vin = $entry['parsed']['vin'] ?? null;
            $registration = $entry['parsed']['registration'] ?? null;
            $entry['requires_override'] = false;
            $entry['override_acknowledged'] = false;
            $entry['duplicate_of'] = null;

            $matchedExisting = null;
            $matchedByLabel = null;

            if ($vin) {
                $vinKey = strtoupper(trim($vin));
                if (isset($seenVinsInFile[$vinKey])) {
                    $entry['errors'][] = 'Duplicate VIN in this file — already listed on row ' . $seenVinsInFile[$vinKey];
                } else {
                    $seenVinsInFile[$vinKey] = $entry['source_row'];
                }
                if ($existingByVin->has($vinKey)) {
                    $matchedExisting = $existingByVin->get($vinKey);
                    $matchedByLabel = 'VIN';
                }
            }

            if ($registration) {
                $regKey = strtoupper(trim($registration));
                if (isset($seenRegsInFile[$regKey])) {
                    $entry['errors'][] = 'Duplicate registration in this file — already listed on row ' . $seenRegsInFile[$regKey];
                } else {
                    $seenRegsInFile[$regKey] = $entry['source_row'];
                }
                if (!$matchedExisting && $existingByReg->has($regKey)) {
                    $matchedExisting = $existingByReg->get($regKey);
                    $matchedByLabel = 'Registration';
                }
            }

            if ($matchedExisting) {
                $statusLabel = ucfirst(str_replace('_', ' ', $matchedExisting->status));
                $jobNo = $matchedExisting->job_number ?: '#?';

                $entry['requires_override'] = true;
                $entry['duplicate_of'] = [
                    'job_number'   => $jobNo,
                    'status'       => $matchedExisting->status,
                    'status_label' => $statusLabel,
                ];
                // Single, loud warning line that doubles as the
                // tooltip / inline explanation next to the override
                // checkbox.  We deliberately emphasise ACTIVE so an
                // operator skimming the preview can't mistake it
                // for a past order.
                $entry['warnings'][] = 'DUPLICATE OF ACTIVE ORDER ' . $jobNo
                    . ' (' . $statusLabel . ') — this ' . strtolower($matchedByLabel) . ' is already on an open job for this account.'
                    . ' Tick the override box if you really want to create a second movement.';
            }

            // "Urgent" flag from the comments column. We carry this onto
            // the booking as is_emergency so dispatch sees the priority
            // straight away in the planning queue.
            $comments = $entry['parsed']['comments'] ?? null;
            if ($comments && preg_match('/\burgent\b/i', $comments)) {
                $entry['parsed']['is_urgent'] = true;
                $entry['warnings'][] = 'Marked URGENT — will create as emergency booking';
            } else {
                $entry['parsed']['is_urgent'] = false;
            }

            $preview[] = $this->finaliseRowStatus($entry, $includeOnHold);
        }

        return [
            'rows' => $preview,
            'stats' => $this->aggregateStats($preview),
        ];
    }

    /**
     * Re-evaluate a single preview row's status — used by the Volt component
     * after the operator changes a per-row vehicle class. Wipes any stale
     * "vehicle class needed" error so the row can flip green when fixed.
     */
    public function recalculateRow(array $row, bool $includeOnHold = false): array
    {
        $row['errors'] = array_values(array_filter(
            $row['errors'] ?? [],
            fn ($err) => !str_contains($err, 'Vehicle class needed'),
        ));

        if (empty($row['parsed']['vehicle_class_id'])) {
            $row['errors'][] = 'Vehicle class needed — set per row in the preview, or pick a default on the previous step';
        }

        // Make sure the new override fields exist on legacy preview
        // arrays (e.g. rows in flight when this code shipped) so the
        // status finaliser doesn't trip on missing keys.
        $row['requires_override']     = $row['requires_override']     ?? false;
        $row['override_acknowledged'] = $row['override_acknowledged'] ?? false;
        $row['duplicate_of']          = $row['duplicate_of']          ?? null;

        return $this->finaliseRowStatus($row, $includeOnHold);
    }

    /**
     * Flip the operator's "yes I really want to import this duplicate"
     * decision on a preview row.  No-op for rows that aren't flagged as
     * duplicates (so the UI can call this blindly without first asking
     * the importer whether the toggle is meaningful).
     */
    public function setDuplicateOverride(array $row, bool $acknowledged, bool $includeOnHold = false): array
    {
        if (empty($row['requires_override'])) {
            return $row;
        }
        $row['override_acknowledged'] = $acknowledged;
        return $this->finaliseRowStatus($row, $includeOnHold);
    }

    /**
     * Recompute the summary stats for a list of preview rows. Public so
     * the Volt component can refresh the row-status counters after a
     * per-row vehicle class change without re-running the full preview
     * pipeline against the spreadsheet.
     */
    public function aggregateStats(array $rows): array
    {
        $stats = [
            'total' => count($rows),
            'ready' => 0,
            'warnings' => 0,
            'errors' => 0,
            'on_hold' => 0,
            // Rows blocked because the VIN is already on an active
            // order and the operator hasn't ticked the override.  They
            // don't count toward "ready" — the Import button refuses
            // to create them until the override fires.
            'duplicates_blocked' => 0,
            // Rows the operator explicitly chose to override (they'll
            // import as duplicate movements).  Surfaced separately so
            // the confirm dialog can highlight how many "I really mean
            // this" rows are about to land.
            'duplicates_override' => 0,
        ];

        foreach ($rows as $row) {
            switch ($row['status'] ?? null) {
                case 'ready':
                    $stats['ready']++;
                    break;
                case 'warning':
                    $stats['warnings']++;
                    $stats['ready']++;
                    if (!empty($row['override_acknowledged'])) {
                        $stats['duplicates_override']++;
                    }
                    break;
                case 'duplicate':
                    $stats['duplicates_blocked']++;
                    break;
                case 'error':
                    $stats['errors']++;
                    break;
                case 'skipped':
                    if (!empty($row['on_hold'])) {
                        $stats['on_hold']++;
                    } else {
                        $stats['errors']++;
                    }
                    break;
            }
        }

        return $stats;
    }

    /**
     * Given a model description ("J5N 28.290FL", "FTR 850 RIGID"), try to
     * find a matching vehicle class by tonnage. We only commit a guess
     * when there's exactly one candidate so ambiguous rows route to the
     * operator instead of silently picking the wrong class.
     */
    public function guessVehicleClassId(?string $model, \Illuminate\Support\Collection $vehicleClasses): ?int
    {
        if (!$model || $vehicleClasses->isEmpty()) {
            return null;
        }

        $tonnage = $this->extractTonnage($model);
        if ($tonnage === null) {
            return null;
        }

        $matches = $vehicleClasses->filter(function ($vc) use ($tonnage) {
            $name = strtolower((string) $vc->name);
            // Match "8t", "8 t", "8 ton", "8-ton", "8tonne" — guarded on
            // the left so "18t" doesn't bleed into a search for tonne 8,
            // and `\b` on the right because the unit (t/ton/tonne) is
            // always followed by a non-word boundary.
            return preg_match('/(?<!\d)' . $tonnage . '\s*(?:t|ton|tonne)\b/i', $name) === 1;
        });

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    /**
     * Pull a tonnage hint out of an OEM model code.
     *
     * - FAW: "J5N 28.290FL" → 28, "8.140FL" → 8, "13.180FL" → 13.
     *   The pattern is "<tonnage>.<engine kw·10>" so the leading number
     *   IS the tonnage. We use a `(?!\d)` lookahead instead of `\b`
     *   because the engine kW is followed directly by letters ("FL"),
     *   so a word boundary never fires.
     * - Isuzu: "FTR850AMT" → 8 (tonnage = hundreds digit of a 3-digit
     *   number embedded in the model code). The string typically butts
     *   the digits up against letters with no whitespace, so again no
     *   `\b`-based regex would catch it.
     */
    private function extractTonnage(string $model): ?int
    {
        $m = [];

        if (preg_match('/(?<![\d.])(\d{1,2})\.\d{2,3}(?!\d)/', $model, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/(?<!\d)(\d)(\d{2})(?!\d)/', $model, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Compute the final status for a preview row from its on_hold flag,
     * accumulated errors and warnings. Single source of truth so initial
     * preview build and per-row mutations stay consistent.
     */
    private function finaliseRowStatus(array $entry, bool $includeOnHold): array
    {
        if ($entry['on_hold'] && !$includeOnHold) {
            $entry['status'] = 'skipped';
            // Avoid stacking duplicate hold messages on every recompute.
            $hasHoldErr = false;
            foreach ($entry['errors'] as $e) {
                if (str_contains($e, 'ON HOLD')) {
                    $hasHoldErr = true;
                    break;
                }
            }
            if (!$hasHoldErr) {
                $entry['errors'][] = 'Row marked ON HOLD — toggle "Include on-hold rows" to import';
            }
            return $entry;
        }

        // Hard errors always win over the duplicate gate — if a row can
        // never import anyway (missing VIN, past date, etc.) there's no
        // value in showing the override toggle.
        if (!empty($entry['errors'])) {
            $entry['status'] = 'error';
            return $entry;
        }

        // Active-duplicate row that the operator hasn't explicitly
        // overridden → block import.  Once the override checkbox is
        // ticked the row falls through to the warning/ready branch and
        // commit() lets it through.
        if (!empty($entry['requires_override']) && empty($entry['override_acknowledged'])) {
            $entry['status'] = 'duplicate';
            return $entry;
        }

        if (!empty($entry['warnings'])) {
            $entry['status'] = 'warning';
        } else {
            $entry['status'] = 'ready';
        }

        return $entry;
    }

    // ---------------------------------------------------------------------
    // COMMIT
    // ---------------------------------------------------------------------

    /**
     * Persist every committable row in the preview as a transport job. The
     * whole thing is wrapped in a DB transaction — partial imports are
     * confusing, and the volume here (a single OEM's monthly file) is
     * comfortably small enough to roll back if anything explodes.
     *
     * Returns:
     *   [
     *     'created' => int,           // new transport_jobs rows
     *     'created_locations' => int, // new locations row in company's book
     *     'skipped' => int,           // rows the importer chose not to commit
     *     'errors'  => [ ['row' => 12, 'message' => '...'], ... ],
     *   ]
     */
    public function commit(
        Company $company,
        int $createdByUserId,
        array $previewRows,
        ?int $defaultBrandId = null,
        ?int $defaultVehicleClassId = null,
        array $options = [],
    ): array {
        $autoCreate = (bool) ($options['auto_create_locations'] ?? true);

        $created = 0;
        $createdLocations = 0;
        $skipped = 0;
        $errors = [];

        // Captures model → vehicle_class_id pairs from rows the operator
        // actually approved & imported, so the next upload for this
        // customer can pre-fill the same class automatically.
        $modelHints = [];

        DB::transaction(function () use (
            $company,
            $createdByUserId,
            $previewRows,
            $defaultBrandId,
            $defaultVehicleClassId,
            $autoCreate,
            &$created,
            &$createdLocations,
            &$skipped,
            &$errors,
            &$modelHints,
        ) {
            // In-run address-book cache, keyed by a normalised location
            // name. Seeded with the company's existing locations and then
            // populated with every location created during THIS import, so
            // the same address appearing on many rows reuses one record
            // instead of spawning a duplicate per row (the cause of the
            // "55 Main Street …" × N mess in the address book).
            $locationCache = [];
            foreach ($company->locations()->get() as $existingLocation) {
                foreach ([$existingLocation->company_name, $existingLocation->address] as $candidate) {
                    $key = $this->locationKey($candidate);
                    if ($key !== '' && !isset($locationCache[$key])) {
                        $locationCache[$key] = $existingLocation->id;
                    }
                }
            }

            foreach ($previewRows as $row) {
                // 'duplicate' is a soft block — the row only flips out
                // of this status when the operator ticks the override
                // on the preview screen.  Treat it exactly like
                // 'skipped' here so an un-acknowledged active-duplicate
                // VIN never sneaks into the database.
                if (in_array($row['status'] ?? null, ['error', 'skipped', 'duplicate'], true)) {
                    $skipped++;
                    continue;
                }

                try {
                    [$pickupId, $createdPickup] = $this->resolveLocation(
                        $company,
                        $row['parsed']['pickup_match'],
                        $row['parsed']['pickup_raw'],
                        $autoCreate,
                        $locationCache,
                    );
                    [$deliveryId, $createdDelivery] = $this->resolveLocation(
                        $company,
                        $row['parsed']['delivery_match'],
                        $row['parsed']['delivery_raw'],
                        $autoCreate,
                        $locationCache,
                    );

                    if (!$pickupId || !$deliveryId) {
                        $skipped++;
                        $errors[] = ['row' => $row['source_row'], 'message' => 'Pickup or delivery location could not be resolved'];
                        continue;
                    }

                    // Per-row vehicle class wins over the default. The
                    // BookingService payload requires this to be set
                    // (NOT NULL on transport_jobs); if neither source has
                    // one we skip rather than crash the transaction.
                    $vehicleClassId = $row['parsed']['vehicle_class_id'] ?? $defaultVehicleClassId;
                    if (!$vehicleClassId) {
                        $skipped++;
                        $errors[] = ['row' => $row['source_row'], 'message' => 'Vehicle class is required'];
                        continue;
                    }

                    $createdLocations += (int) $createdPickup + (int) $createdDelivery;

                    $isUrgent = (bool) ($row['parsed']['is_urgent'] ?? false);

                    $executorType = $row['parsed']['executor_type'] ?? Job::EXECUTOR_PROSELVER;

                    $this->bookingService->createTransportBooking([
                        'company_id' => $company->id,
                        'created_by_user_id' => $createdByUserId,
                        'pickup_location_id' => $pickupId,
                        'delivery_location_id' => $deliveryId,
                        'vehicle_class_id' => $vehicleClassId,
                        'brand_id' => $defaultBrandId,
                        'model_name' => $row['parsed']['model'] ?? null,
                        'vin' => $row['parsed']['vin'] ?? null,
                        'registration' => $row['parsed']['registration'] ?? null,
                        'scheduled_date' => $row['parsed']['scheduled_date'],
                        'customer_notes' => $this->buildNotes($row['parsed']),
                        'is_emergency' => $isUrgent,
                        'emergency_reason' => $isUrgent
                            ? trim((string) ($row['parsed']['comments'] ?? 'Urgent'))
                            : null,
                        'executor_type' => $executorType,
                        'driver_user_id' => $row['parsed']['driver_user_id'] ?? null,
                        'third_party_courier_name' => $row['parsed']['third_party_courier_name'] ?? null,
                        'third_party_waybill' => $row['parsed']['third_party_waybill'] ?? null,
                        'self_collect_name' => $row['parsed']['self_collect_name'] ?? null,
                        'self_collect_phone' => $row['parsed']['self_collect_phone'] ?? null,
                    ]);

                    // Capture the operator-approved model → class pair so
                    // the next upload can pre-fill it. Keyed by the
                    // normalised model name (trimmed & uppercased).
                    $modelKey = $this->normaliseModelKey($row['parsed']['model'] ?? null);
                    if ($modelKey) {
                        $modelHints[$modelKey] = (int) $vehicleClassId;
                    }

                    $created++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = [
                        'row' => $row['source_row'],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        // Merge the newly-learned hints into whatever was already saved
        // for this customer and persist back. We merge (not replace) so
        // models that didn't appear in this upload keep their previous
        // hint. A single upload that overrides a model's class wins —
        // we always trust the most recent operator choice.
        if (!empty($modelHints)) {
            $this->rememberModelClassHints($company, $modelHints);
        }

        return [
            'created' => $created,
            'created_locations' => $createdLocations,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Merge the supplied model → class_id hints into the company's saved
     * mapping so future imports auto-fill the same vehicle class.
     * The merge is biased toward $hints, so if the operator switched a
     * model's class in this upload the new value replaces the old one.
     */
    public function rememberModelClassHints(Company $company, array $hints): void
    {
        $mapping = (array) ($company->movement_csv_mapping ?? []);
        $existing = (array) ($mapping['model_class_hints'] ?? []);

        $mapping['model_class_hints'] = array_merge($existing, $hints);

        $company->forceFill(['movement_csv_mapping' => $mapping])->save();
    }

    // ---------------------------------------------------------------------
    // INTERNALS
    // ---------------------------------------------------------------------

    /*
     * Normalise a model string into a stable lookup key. Trim + upper-case
     * but DO NOT collapse internal whitespace — "NPS300 CREW CAB SWA" is
     * a different vehicle from "NPS300SWA" and we want the hints to
     * distinguish them.
     */
    private function normaliseModelKey(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }
        $key = strtoupper(trim($model));
        return $key === '' ? null : $key;
    }

    private function buildPreviewRow(
        array $row,
        array $mapping,
        \Illuminate\Support\Collection $locations,
        bool $autoCreate,
        string $defaultExecutor = Job::EXECUTOR_PROSELVER,
        ?\Illuminate\Support\Collection $driverIndex = null,
    ): array {
        $vin = $this->stringValue($row, $mapping['vin'] ?? null);
        $registration = $this->stringValue($row, $mapping['registration'] ?? null);
        $model = $this->stringValue($row, $mapping['model'] ?? null);
        $pickup = $this->stringValue($row, $mapping['pickup'] ?? null);
        $delivery = $this->stringValue($row, $mapping['delivery'] ?? null);
        $rawDate = $row[$mapping['movement_date'] ?? '_unset_'] ?? null;
        $comments = $this->stringValue($row, $mapping['comments'] ?? null);

        $rawExecutor    = $this->stringValue($row, $mapping['executor_type'] ?? null);
        $driverName     = $this->stringValue($row, $mapping['driver_name'] ?? null);
        $courierName    = $this->stringValue($row, $mapping['courier_name'] ?? null);
        $waybill        = $this->stringValue($row, $mapping['waybill'] ?? null);
        $collectorName  = $this->stringValue($row, $mapping['collector_name'] ?? null);
        $collectorPhone = $this->stringValue($row, $mapping['collector_phone'] ?? null);

        $errors = [];
        $warnings = [];

        // Resolve executor: explicit cell wins, otherwise fall back to
        // the page-level default.  Unknown values get flagged with a
        // warning and silently fall back to the default — the import
        // still proceeds rather than erroring out the whole row.
        $executorType = $defaultExecutor;
        if ($rawExecutor !== null && $rawExecutor !== '') {
            $resolved = $this->normaliseExecutor($rawExecutor);
            if ($resolved) {
                $executorType = $resolved;
            } else {
                $warnings[] = "Unrecognised executor “{$rawExecutor}” — defaulted to " . (Job::EXECUTOR_LABELS[$defaultExecutor] ?? $defaultExecutor);
            }
        }

        // Internal driver resolution — only attempted when the row
        // actually wants an internal executor and a name was supplied.
        $driverUserId = null;
        if ($executorType === Job::EXECUTOR_INTERNAL && $driverName) {
            $key = Str::lower(trim($driverName));
            $driverUserId = $driverIndex?->get($key);
            if (!$driverUserId) {
                $warnings[] = "Driver “{$driverName}” isn't on this customer's roster — row will import unassigned";
            }
        }

        if ($executorType === Job::EXECUTOR_THIRD_PARTY && !$courierName) {
            $warnings[] = '3rd-party row has no courier name — set it on the booking after import';
        }
        if ($executorType === Job::EXECUTOR_SELF_COLLECT && !$collectorName) {
            $warnings[] = 'Self-collect row has no collector name — set it on the booking after import';
        }

        // Auto-reclassify: if the mapped VIN cell actually looks like
        // a registration and the reg column was empty, move the value
        // across.  This is the whole point of the smart classifier --
        // operators regularly enter a plate in the "VIN" column and
        // then everything downstream keyed on VIN silently breaks.
        // We flag it as a warning so it's visible on the preview.
        if ($vin && !$registration
            && \App\Support\VehicleIdentifier::classify($vin) === \App\Support\VehicleIdentifier::TYPE_REGISTRATION
        ) {
            $registration = $vin;
            $vin = null;
            $warnings[] = "Value in VIN column (“{$registration}”) looks like a registration — imported as registration instead.";
        }

        // Required fields (pickup + delivery).  VIN OR registration
        // is enforced separately below because it's a "one of two
        // columns" constraint.
        foreach (self::REQUIRED_FIELDS as $required) {
            $value = match ($required) {
                'pickup' => $pickup,
                'delivery' => $delivery,
            };
            if ($value === null || $value === '') {
                $errors[] = ucfirst($required) . ' is missing';
            }
        }
        if (($vin === null || $vin === '') && ($registration === null || $registration === '')) {
            $errors[] = 'VIN / chassis or Registration is required';
        }

        // Date parsing
        $onHold = false;
        [$scheduledDate, $dateWarning, $onHold] = $this->parseScheduledDate($rawDate);
        if ($dateWarning) {
            $warnings[] = $dateWarning;
        }

        // Location matching
        $pickupMatch = $this->matchLocation($pickup, $locations);
        $deliveryMatch = $this->matchLocation($delivery, $locations);

        if ($pickup !== null && $pickup !== '' && !$pickupMatch) {
            if ($autoCreate) {
                // A brand-new book entry is seeded with the raw name as its
                // address, which Google usually can't geocode — so it lands
                // without coordinates and the route/toll estimate can't run.
                // Flag it up front so ops knows to set a real street address.
                $warnings[] = "Pickup “{$pickup}” will be added to the address book — set a street address on it so route & tolls calculate";
            } else {
                $errors[] = "Pickup “{$pickup}” isn't in the address book";
            }
        } elseif ($pickupMatch && !$this->locationHasCoordinates($pickupMatch)) {
            $warnings[] = "Pickup “{$pickupMatch->company_name}” has no map coordinates — route & tolls won't calculate until its address is fixed";
        }
        if ($delivery !== null && $delivery !== '' && !$deliveryMatch) {
            if ($autoCreate) {
                $warnings[] = "Delivery “{$delivery}” will be added to the address book — set a street address on it so route & tolls calculate";
            } else {
                $errors[] = "Delivery “{$delivery}” isn't in the address book";
            }
        } elseif ($deliveryMatch && !$this->locationHasCoordinates($deliveryMatch)) {
            $warnings[] = "Delivery “{$deliveryMatch->company_name}” has no map coordinates — route & tolls won't calculate until its address is fixed";
        }

        return [
            'source_row' => $row['_row'] ?? null,
            'source_sheet' => $row['_sheet'] ?? null,
            'on_hold' => $onHold,
            'errors' => $errors,
            'warnings' => $warnings,
            'parsed' => [
                'vin' => $vin ? strtoupper(trim($vin)) : null,
                'registration' => $registration ? strtoupper(trim($registration)) : null,
                'model' => $model,
                'pickup_raw' => $pickup,
                'delivery_raw' => $delivery,
                'pickup_match' => $pickupMatch,
                'delivery_match' => $deliveryMatch,
                'scheduled_date' => $scheduledDate?->toDateString(),
                'comments' => $comments,
                'executor_type' => $executorType,
                'driver_user_id' => $driverUserId,
                'driver_name_raw' => $driverName,
                'third_party_courier_name' => $courierName,
                'third_party_waybill' => $waybill,
                'self_collect_name' => $collectorName,
                'self_collect_phone' => $collectorPhone,
            ],
        ];
    }

    /**
     * Map a free-form executor cell value onto Job::EXECUTOR_*. Returns
     * null when the value isn't recognised so callers can decide
     * whether to warn-and-default or hard-error.
     */
    public function normaliseExecutor(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = Str::lower(trim($raw));
        if ($key === '') {
            return null;
        }
        if (in_array($key, Job::EXECUTOR_TYPES, true)) {
            return $key;
        }
        return self::EXECUTOR_ALIASES[$key] ?? null;
    }

    /**
     * Try to coerce whatever Excel handed us (DateTime, integer serial,
     * or string) into a Carbon. Returns [date|null, warning|null, on_hold].
     */
    private function parseScheduledDate(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [now()->startOfDay(), 'No movement date — defaulted to today', true];
        }

        // PhpSpreadsheet's read_data_only path can hand us a DateTime
        // directly when the cell is formatted as date.
        if ($raw instanceof \DateTimeInterface) {
            return [Carbon::instance($raw)->startOfDay(), null, false];
        }

        // Numeric → Excel serial date.
        if (is_numeric($raw)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $raw);
                return [Carbon::instance($dt)->startOfDay(), null, false];
            } catch (\Throwable) {
                return [now()->startOfDay(), 'Unrecognised date — defaulted to today', false];
            }
        }

        $clean = strtolower(trim((string) $raw));
        foreach (self::ON_HOLD_TOKENS as $token) {
            if (str_contains($clean, $token)) {
                return [now()->startOfDay(), 'Row marked ' . strtoupper($token) . ' — defaulted to today', true];
            }
        }

        // Common SA spreadsheet formats.
        $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'd-m-y', 'd/m/y'];
        foreach ($formats as $fmt) {
            try {
                $parsed = Carbon::createFromFormat($fmt, trim((string) $raw));
                if ($parsed) {
                    return [$parsed->startOfDay(), null, false];
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        // Fall back to Carbon's loose parser as a last resort.
        try {
            return [Carbon::parse($raw)->startOfDay(), null, false];
        } catch (\Throwable) {
            return [now()->startOfDay(), 'Unrecognised date — defaulted to today', false];
        }
    }

    /**
     * Match a free-text location name against the company's address book.
     * Strategy:
     *   1. exact match (case-insensitive) on company_name
     *   2. exact match on slugified company_name (strips punctuation/spaces)
     *   3. substring match (incoming string contained in book entry, or
     *      vice versa) — only if exactly one location matches
     *
     * Returns the matching Location or null.
     */
    /**
     * A location can only produce a route (and therefore a toll estimate)
     * when it has both coordinates. Mirrors the falsy guard in
     * RouteCalculationService so a 0/blank coord counts as "missing".
     */
    private function locationHasCoordinates(Location $location): bool
    {
        return !empty($location->latitude) && !empty($location->longitude);
    }

    private function matchLocation(?string $name, \Illuminate\Support\Collection $locations): ?Location
    {
        if ($name === null || $name === '') {
            return null;
        }
        $needle = Str::lower(trim($name));

        // Exact (case-insensitive) on either the book entry's NAME or its
        // ADDRESS. Bulk files often carry a full street address in the
        // pickup/delivery cell while the matching book row stores that
        // string in `address` (name being e.g. "Demo Motors"); matching on
        // name alone missed those and created a duplicate every time.
        $exact = $locations->first(fn (Location $l) =>
            Str::lower((string) $l->company_name) === $needle
            || Str::lower((string) $l->address) === $needle
        );
        if ($exact) {
            return $exact;
        }

        $needleSlug = Str::slug($name, '');
        $slugMatch = $locations->first(fn (Location $l) =>
            Str::slug((string) $l->company_name, '') === $needleSlug
            || Str::slug((string) $l->address, '') === $needleSlug
        );
        if ($slugMatch) {
            return $slugMatch;
        }

        $contains = $locations->filter(function (Location $l) use ($needle) {
            $candidate = Str::lower($l->company_name);
            return str_contains($candidate, $needle) || str_contains($needle, $candidate);
        });
        if ($contains->count() === 1) {
            return $contains->first();
        }

        return null;
    }

    /**
     * Return an existing location id (when the match resolved) or
     * lazily create a new one in the company's address book.
     *
     * The created flag in the second tuple position drives the
     * "created_locations" stat in commit().
     *
     * @return array{0: ?int, 1: bool}
     */
    private function resolveLocation(
        Company $company,
        ?Location $matched,
        ?string $rawName,
        bool $autoCreate,
        array &$locationCache,
    ): array {
        if ($matched) {
            // Remember the preview-resolved match so a later row with the
            // same raw text reuses it instead of creating a near-duplicate.
            $this->cacheLocation($locationCache, $matched->company_name, $matched->id);
            $this->cacheLocation($locationCache, $rawName, $matched->id);
            return [$matched->id, false];
        }
        if (!$autoCreate || !$rawName) {
            return [null, false];
        }

        // Already created (or pre-existing) under this name during this run?
        // Reuse it — this is what stops the same address spawning a new row
        // for every line of the spreadsheet.
        $key = $this->locationKey($rawName);
        if ($key !== '' && isset($locationCache[$key])) {
            return [$locationCache[$key], false];
        }

        // Default new locations to dealer/body_builder territory rather
        // than plant — most "to" rows are bodybuilders/dealers, and a yard
        // would imply transport-controlled space. Type isn't load-bearing
        // here (just metadata for later filtering); ops can fix it from
        // the address-book UI without re-importing.
        $location = Location::create([
            'company_id' => $company->id,
            'company_name' => trim($rawName),
            // Address is NOT NULL on the locations table; we don't know
            // the street yet so we seed it with the same name and let ops
            // fix it from the address-book UI later. The Location::saving
            // hook will then re-geocode it on next save.
            'address' => trim($rawName),
            'type' => Location::TYPE_DEALER,
            'is_active' => true,
        ]);

        $this->cacheLocation($locationCache, $rawName, $location->id);

        return [$location->id, true];
    }

    /**
     * Normalised key for address-book de-duplication. Slug strips
     * punctuation, spacing and case so "55 Main Street, Bordeaux" and
     * "55 main street bordeaux" collapse to the same key. Falls back to a
     * lower/trim of the raw value when the slug is empty (all punctuation).
     */
    private function locationKey(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '';
        }
        $slug = Str::slug($name, '');
        return $slug !== '' ? $slug : Str::lower(trim($name));
    }

    private function cacheLocation(array &$locationCache, ?string $name, int $id): void
    {
        $key = $this->locationKey($name);
        if ($key !== '' && !isset($locationCache[$key])) {
            $locationCache[$key] = $id;
        }
    }

    private function buildNotes(array $parsed): ?string
    {
        $bits = [];
        if (!empty($parsed['comments'])) {
            $bits[] = trim((string) $parsed['comments']);
        }
        if (!empty($parsed['model']) && empty($parsed['comments'])) {
            return null;
        }
        return $bits ? implode(' | ', $bits) : null;
    }

    private function guessHeader(string $field, array $headers): ?string
    {
        $hints = self::HEADER_HINTS[$field] ?? [];
        $haystack = array_map(fn ($h) => Str::lower($h), $headers);

        // 1. exact (lowered) match against any hint
        foreach ($hints as $hint) {
            $idx = array_search($hint, $haystack, true);
            if ($idx !== false) {
                return $headers[$idx];
            }
        }

        // 2. contains match — pick the shortest header that contains a hint
        $best = null;
        foreach ($haystack as $i => $h) {
            foreach ($hints as $hint) {
                if (str_contains($h, $hint)) {
                    if ($best === null || strlen($headers[$i]) < strlen($best)) {
                        $best = $headers[$i];
                    }
                }
            }
        }
        return $best;
    }

    private function stringValue(array $row, ?string $header): ?string
    {
        if ($header === null || !array_key_exists($header, $row)) {
            return null;
        }
        $v = $row[$header];
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $v = trim($v);
            return $v === '' ? null : $v;
        }
        return (string) $v;
    }

    private function normaliseHeader(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        // Collapse runs of whitespace (incl. embedded \r\n inside merged
        // header cells) into a single space.
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $value));
        return $clean;
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && $c !== '') {
                return false;
            }
        }
        return true;
    }
}
