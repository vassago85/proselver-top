<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\Location;
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
        'model'           => 'Model description',
        'pickup'          => 'Pickup location (origin)',
        'delivery'        => 'Delivery location (destination)',
        'movement_date'   => 'Movement / scheduled date',
        'comments'        => 'Comments / notes',
    ];

    /**
     * Fields that MUST resolve to a value for a row to be committable.
     */
    public const REQUIRED_FIELDS = ['vin', 'pickup', 'delivery'];

    /**
     * Heuristic header → field guesses. We lower-case + collapse whitespace
     * before matching so a header like "Movement \nOrder Date" still
     * lines up. First match wins.
     */
    private const HEADER_HINTS = [
        'vin'           => ['vin', 'chassis', 'chassis no', 'chassis number'],
        'model'         => ['model', 'model description', 'vehicle model'],
        'pickup'        => ['from', 'departure', 'origin', 'pickup', 'collection from'],
        'delivery'      => ['to', 'destination', 'delivery', 'deliver to', 'collection to'],
        'movement_date' => ['movement date', 'movement order date', 'scheduled date', 'collection date', 'date'],
        'comments'      => ['comments', 'comment', 'notes', 'remarks'],
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

        $locations = $company->locations()->get();

        $preview = [];
        $stats = [
            'total' => count($rows),
            'ready' => 0,
            'warnings' => 0,
            'errors' => 0,
            'on_hold' => 0,
        ];

        foreach ($rows as $row) {
            $entry = $this->buildPreviewRow($row, $mapping, $locations, $autoCreate);

            if ($entry['on_hold'] && !$includeOnHold) {
                $entry['status'] = 'skipped';
                $entry['errors'][] = 'Row marked ON HOLD — toggle "Include on-hold rows" to import';
                $stats['on_hold']++;
            }

            if (!empty($entry['errors'])) {
                $entry['status'] = $entry['status'] ?? 'error';
                $stats['errors']++;
            } elseif (!empty($entry['warnings'])) {
                $entry['status'] = 'warning';
                $stats['warnings']++;
                $stats['ready']++;
            } else {
                $entry['status'] = 'ready';
                $stats['ready']++;
            }

            $preview[] = $entry;
        }

        return [
            'rows' => $preview,
            'stats' => $stats,
        ];
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
        ) {
            foreach ($previewRows as $row) {
                if ($row['status'] === 'error' || $row['status'] === 'skipped') {
                    $skipped++;
                    continue;
                }

                try {
                    [$pickupId, $createdPickup] = $this->resolveLocation(
                        $company,
                        $row['parsed']['pickup_match'],
                        $row['parsed']['pickup_raw'],
                        $autoCreate,
                    );
                    [$deliveryId, $createdDelivery] = $this->resolveLocation(
                        $company,
                        $row['parsed']['delivery_match'],
                        $row['parsed']['delivery_raw'],
                        $autoCreate,
                    );

                    if (!$pickupId || !$deliveryId) {
                        $skipped++;
                        $errors[] = ['row' => $row['source_row'], 'message' => 'Pickup or delivery location could not be resolved'];
                        continue;
                    }

                    $createdLocations += (int) $createdPickup + (int) $createdDelivery;

                    $this->bookingService->createTransportBooking([
                        'company_id' => $company->id,
                        'created_by_user_id' => $createdByUserId,
                        'pickup_location_id' => $pickupId,
                        'delivery_location_id' => $deliveryId,
                        'vehicle_class_id' => $defaultVehicleClassId,
                        'brand_id' => $defaultBrandId,
                        'model_name' => $row['parsed']['model'] ?? null,
                        'vin' => $row['parsed']['vin'],
                        'scheduled_date' => $row['parsed']['scheduled_date'],
                        'customer_notes' => $this->buildNotes($row['parsed']),
                    ]);

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

        return [
            'created' => $created,
            'created_locations' => $createdLocations,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    // ---------------------------------------------------------------------
    // INTERNALS
    // ---------------------------------------------------------------------

    private function buildPreviewRow(
        array $row,
        array $mapping,
        \Illuminate\Support\Collection $locations,
        bool $autoCreate,
    ): array {
        $vin = $this->stringValue($row, $mapping['vin'] ?? null);
        $model = $this->stringValue($row, $mapping['model'] ?? null);
        $pickup = $this->stringValue($row, $mapping['pickup'] ?? null);
        $delivery = $this->stringValue($row, $mapping['delivery'] ?? null);
        $rawDate = $row[$mapping['movement_date'] ?? '_unset_'] ?? null;
        $comments = $this->stringValue($row, $mapping['comments'] ?? null);

        $errors = [];
        $warnings = [];

        // Required fields
        foreach (self::REQUIRED_FIELDS as $required) {
            $value = match ($required) {
                'vin' => $vin,
                'pickup' => $pickup,
                'delivery' => $delivery,
            };
            if ($value === null || $value === '') {
                $errors[] = ucfirst($required) . ' is missing';
            }
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
                $warnings[] = "Pickup “{$pickup}” will be added to the address book";
            } else {
                $errors[] = "Pickup “{$pickup}” isn't in the address book";
            }
        }
        if ($delivery !== null && $delivery !== '' && !$deliveryMatch) {
            if ($autoCreate) {
                $warnings[] = "Delivery “{$delivery}” will be added to the address book";
            } else {
                $errors[] = "Delivery “{$delivery}” isn't in the address book";
            }
        }

        return [
            'source_row' => $row['_row'] ?? null,
            'source_sheet' => $row['_sheet'] ?? null,
            'on_hold' => $onHold,
            'errors' => $errors,
            'warnings' => $warnings,
            'parsed' => [
                'vin' => $vin ? strtoupper(trim($vin)) : null,
                'model' => $model,
                'pickup_raw' => $pickup,
                'delivery_raw' => $delivery,
                'pickup_match' => $pickupMatch,
                'delivery_match' => $deliveryMatch,
                'scheduled_date' => $scheduledDate?->toDateString(),
                'comments' => $comments,
            ],
        ];
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
    private function matchLocation(?string $name, \Illuminate\Support\Collection $locations): ?Location
    {
        if ($name === null || $name === '') {
            return null;
        }
        $needle = Str::lower(trim($name));

        $exact = $locations->first(fn (Location $l) => Str::lower($l->company_name) === $needle);
        if ($exact) {
            return $exact;
        }

        $needleSlug = Str::slug($name, '');
        $slugMatch = $locations->first(
            fn (Location $l) => Str::slug($l->company_name, '') === $needleSlug
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
    ): array {
        if ($matched) {
            return [$matched->id, false];
        }
        if (!$autoCreate || !$rawName) {
            return [null, false];
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

        return [$location->id, true];
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
