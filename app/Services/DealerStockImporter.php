<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\VehicleModel;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-load dealer stock spreadsheets into the dealer_stock ledger.
 *
 * Mirrors the JobBulkImporter pipeline (parse → detectMapping →
 * preview → commit) but the surface area is much smaller because
 * every row maps cleanly onto a single dealer_stock unit -- no
 * location resolution, no executor inference, no per-row branching.
 *
 * Idempotency contract: an upload that's already been committed
 * (or partially committed) and is re-uploaded will UPSERT on
 * (dealer_company_id, vin), refreshing the vehicle attributes but
 * never resetting current_location_type / status / sale fields --
 * those are only mutated by the linker observer and explicit
 * dealer staff actions.  Re-running the import is therefore safe.
 */
class DealerStockImporter
{
    /**
     * Logical fields the importer understands.  The UI mapping form
     * renders columns in this order.
     */
    public const FIELDS = [
        'vin'           => 'VIN',
        'suffix'        => 'Suffix',
        'variant'       => 'Variant',
        'description'   => 'Description',
        'engine_number' => 'Engine number',
        'colour'        => 'Colour',
        'registration'  => 'Registration (blank for new)',
        'model_name'    => 'Model',
        'brand'         => 'Brand',
        'model_year'    => 'Model year',
    ];

    /** VIN is the only mandatory column -- everything else can be filled later. */
    public const REQUIRED_FIELDS = ['vin'];

    /**
     * Heuristic header-name → field guesses.  We lower-case + strip
     * non-alphanumerics before matching so "VIN", "Engine No."
     * and "Engine#" all resolve identically.  First match wins.
     */
    private const HEADER_HINTS = [
        'vin'           => ['vin', 'chassis', 'chassisnumber'],
        'suffix'        => ['suffix'],
        'variant'       => ['variant'],
        'description'   => ['description', 'desc'],
        'engine_number' => ['engineno', 'enginenumber', 'engine'],
        'colour'        => ['colour', 'color'],
        'registration'  => ['registration', 'reg', 'regno', 'regnumber', 'licenseplate'],
        'model_name'    => ['model', 'modelname'],
        'brand'         => ['brand', 'make', 'manufacturer'],
        'model_year'    => ['year', 'modelyear', 'yearmodel'],
    ];

    /**
     * Parse a spreadsheet into a flat list of associative rows keyed
     * by normalised header (lowercase + alnum-only).  Returns:
     *   ['headers' => [original header strings, in order],
     *    'rows'    => [['normalised_header' => 'cell value', ...], ...]]
     */
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = [];
        $headersOriginal = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetRows = $sheet->toArray(null, true, true, false);
            if (count($sheetRows) < 2) {
                continue;
            }

            $headerRow = array_shift($sheetRows);
            $headersNormalised = array_map(
                fn ($h) => $this->normaliseHeader((string) ($h ?? '')),
                $headerRow
            );

            if (empty($headersOriginal)) {
                $headersOriginal = array_map(fn ($h) => trim((string) ($h ?? '')), $headerRow);
            }

            foreach ($sheetRows as $row) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                $assoc = [];
                foreach ($headersNormalised as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $assoc[$key] = trim((string) ($row[$i] ?? ''));
                }
                if (!empty($assoc)) {
                    $rows[] = $assoc;
                }
            }
        }

        return ['headers' => $headersOriginal, 'rows' => $rows];
    }

    /**
     * Auto-detect which spreadsheet header maps to which logical
     * field.  Returns the same shape as the UI's per-row mapping
     * (logical_field => normalised_header).  Headers that don't
     * match any HEADER_HINTS pattern are left unmapped.
     */
    public function detectMapping(array $headers): array
    {
        $mapping = [];
        $normalisedHeaders = array_map(fn ($h) => $this->normaliseHeader($h), $headers);

        foreach (self::HEADER_HINTS as $field => $hints) {
            foreach ($normalisedHeaders as $normalisedHeader) {
                if ($normalisedHeader === '') {
                    continue;
                }
                foreach ($hints as $hint) {
                    if (str_contains($normalisedHeader, $hint)) {
                        $mapping[$field] = $normalisedHeader;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Walk every parsed row, apply the mapping, and return a list of
     * preview rows the UI can render before commit.  Each preview
     * row carries:
     *   - data:     ['vin' => ..., 'colour' => ..., ...]
     *   - warnings: human-readable strings (non-blocking)
     *   - errors:   human-readable strings (blocks commit for this row)
     *
     * No persistence happens here.
     */
    public function preview(array $rows, array $mapping, Company $dealer): array
    {
        $preview = [];
        $vinsSeen = [];

        // Loaded once so the make-inference resolver doesn't re-query
        // the catalogue per row.
        $catalogue = VehicleModel::catalogue();
        $brandNames = [];

        foreach ($rows as $i => $row) {
            $data = $this->extract($row, $mapping);
            $warnings = [];
            $errors = [];

            foreach (self::REQUIRED_FIELDS as $field) {
                if (empty($data[$field])) {
                    $errors[] = "Missing " . self::FIELDS[$field];
                }
            }

            if (!empty($data['vin'])) {
                $vin = strtoupper($data['vin']);
                if (isset($vinsSeen[$vin])) {
                    $errors[] = "Duplicate VIN within this upload (also row " . ($vinsSeen[$vin] + 1) . ").";
                }
                $vinsSeen[$vin] = $i;

                // Existing-row detection -- the importer is an upsert.
                // Surface it as a warning so the dealer knows their
                // attributes will overwrite the previous import but
                // location/sale state stays put.
                $existing = DealerStock::where('dealer_company_id', $dealer->id)
                    ->where('vin', $vin)
                    ->first();
                if ($existing) {
                    $warnings[] = "Already on file -- attributes will be refreshed, location and sale status are preserved.";
                }
            }

            // Brand lookup -- a free-text brand string is resolved at
            // commit time via firstOrCreate.  Here we just attach the
            // string so the preview shows what the dealer typed.
            if (!empty($data['brand']) && !is_numeric($data['brand'])) {
                $warnings[] = "Brand will be matched / created on commit.";
            }

            // Make inference -- if the model name is a known make in the
            // catalogue (e.g. Mokka -> Opel), the make is corrected to it
            // even when a different/blank brand was selected.  This stops
            // a whole upload being mis-tagged (e.g. every row set Isuzu).
            $inferredBrandId = VehicleModel::brandIdForModelName($data['model_name'] ?? null, $catalogue);
            if ($inferredBrandId !== null) {
                $inferredName = $brandNames[$inferredBrandId]
                    ??= (Brand::find($inferredBrandId)?->name ?? null);
                if ($inferredName && strcasecmp($inferredName, (string) ($data['brand'] ?? '')) !== 0) {
                    $was = trim((string) ($data['brand'] ?? '')) !== '' ? "'{$data['brand']}'" : '(blank)';
                    $warnings[] = "Model '{$data['model_name']}' is an {$inferredName} — make will be set to {$inferredName} (was {$was}).";
                    $data['brand'] = $inferredName;
                }
            }

            // Model year -- coerce to int, clear if not parseable.
            if (!empty($data['model_year'])) {
                $year = (int) preg_replace('/[^0-9]/', '', $data['model_year']);
                if ($year < 1980 || $year > (int) date('Y') + 2) {
                    $warnings[] = "Model year '{$data['model_year']}' looks wrong, will be left blank.";
                    $data['model_year'] = null;
                } else {
                    $data['model_year'] = $year;
                }
            }

            $preview[] = [
                'data'     => $data,
                'warnings' => $warnings,
                'errors'   => $errors,
            ];
        }

        return $preview;
    }

    /**
     * Commit every committable preview row to dealer_stock.  Returns
     * a result summary keyed by created/updated/skipped.  Idempotent
     * upsert on (dealer_company_id, vin).
     *
     * @param  array<int, array{data: array, warnings: array, errors: array}>  $previewRows
     */
    public function commit(array $previewRows, Company $dealer): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $catalogue = VehicleModel::catalogue();

        foreach ($previewRows as $row) {
            if (!empty($row['errors'])) {
                $skipped++;
                continue;
            }
            $data = $row['data'];
            $vin = strtoupper(trim((string) ($data['vin'] ?? '')));
            if ($vin === '') {
                $skipped++;
                continue;
            }

            // Brand resolved at commit -- firstOrCreate keeps the
            // catalog clean across re-runs.
            $brandId = null;
            if (!empty($data['brand'])) {
                $brand = Brand::firstOrCreate(
                    ['name' => $data['brand']],
                    ['is_active' => true]
                );
                $brandId = $brand->id;
            }

            // A known model overrides a wrong/blank make (Mokka -> Opel).
            // preview() already rewrites $data['brand'] for previewed
            // imports; this guards direct commit() callers too.
            $inferredBrandId = VehicleModel::brandIdForModelName($data['model_name'] ?? null, $catalogue);
            if ($inferredBrandId !== null && $inferredBrandId !== $brandId) {
                $brandId = $inferredBrandId;
            }

            $attrs = [
                'engine_number' => $data['engine_number'] ?: null,
                'registration'  => $data['registration'] ?: null,
                'brand_id'      => $brandId,
                'model_name'    => $data['model_name'] ?: null,
                'suffix'        => $data['suffix'] ?: null,
                'variant'       => $data['variant'] ?: null,
                'description'   => $data['description'] ?: null,
                'colour'        => $data['colour'] ?: null,
                'model_year'    => is_int($data['model_year'] ?? null) ? $data['model_year'] : null,
            ];

            $stock = DealerStock::where('dealer_company_id', $dealer->id)
                ->where('vin', $vin)
                ->first();

            if ($stock) {
                // Only refresh the import-owned columns, never the
                // commercial / location / sale state -- those are
                // owned by the linker + dealer staff actions.
                $stock->fill($attrs);
                if ($stock->isDirty()) {
                    $stock->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                DealerStock::create(array_merge($attrs, [
                    'dealer_company_id'     => $dealer->id,
                    'vin'                   => $vin,
                    'current_location_type' => DealerStock::LOCATION_PREMISES,
                    'status'                => DealerStock::STATUS_AVAILABLE,
                ]));
                $created++;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    // ----- internals ------------------------------------------------

    private function normaliseHeader(string $header): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $header));
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    private function extract(array $row, array $mapping): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $_) {
            $key = $mapping[$field] ?? null;
            $out[$field] = $key !== null ? trim((string) ($row[$key] ?? '')) : '';
        }
        return $out;
    }
}
