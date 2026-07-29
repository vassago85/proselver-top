<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use App\Models\VehicleClass;
use App\Services\JobBulkImporter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Ops-side bulk upload page for OEM monthly movement spreadsheets.
 *
 * UX is a 4-step wizard, all on one URL — no router redirects so a tab
 * reload mid-import still feels safe (you start over instead of
 * landing on a half-finished preview).
 *
 *   1. upload  — pick the customer + spreadsheet file, click Parse
 *   2. map     — confirm field-to-column mapping + defaults (brand,
 *                vehicle class, auto-create locations)
 *   3. preview — see which rows are ready / warning / error
 *   4. done    — final tally + link back to the orders list
 */

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public string $step = 'upload';

    public ?int $companyId = null;
    public $spreadsheet = null;

    /** Cached parse() output between steps so we don't re-read the file. */
    public array $parsedHeaders = [];
    public array $parsedRows = [];

    /** field => header column from the file (editable on the map step). */
    public array $mapping = [];

    public ?int $defaultBrandId = null;
    public ?int $defaultVehicleClassId = null;
    public string $defaultExecutorType = Job::EXECUTOR_PROSELVER;
    public bool $autoCreateLocations = true;
    public bool $includeOnHold = false;
    public bool $rememberMapping = true;

    public array $previewRows = [];
    public array $previewStats = [];

    /** Bulk-set helper: dropdown value for "apply this class to ..." actions on the preview screen. */
    public ?int $bulkVehicleClassId = null;

    public array $commitResult = [];

    public function with(): array
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->whereIn('type', [Company::TYPE_OEM, Company::TYPE_DEALER, Company::TYPE_CUSTOMER])
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'movement_csv_mapping']);

        $brands = Brand::query()->where('is_active', true)->orderBy('name')->get();
        $vehicleClasses = VehicleClass::query()->where('is_active', true)->ordered()->get();

        $companyOptions = $companies->map(fn ($c) => [
            'value' => (string) $c->id,
            'label' => $c->name . ' (' . $c->type . ')',
        ])->values()->all();

        $brandOptions = $brands->map(fn ($b) => [
            'value' => (string) $b->id,
            'label' => $b->name,
        ])->values()->all();

        $vehicleClassOptions = $vehicleClasses->map(fn ($vc) => [
            'value' => (string) $vc->id,
            'label' => $vc->name,
        ])->values()->all();

        return [
            'companies' => $companies,
            'brands' => $brands,
            'vehicleClasses' => $vehicleClasses,
            'fields' => JobBulkImporter::FIELDS,
            'companyOptions' => $companyOptions,
            'brandOptions' => $brandOptions,
            'vehicleClassOptions' => $vehicleClassOptions,
            'executorOptions' => collect(Job::EXECUTOR_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
        ];
    }

    /**
     * Step 1 → 2. Parse the file, drop the user on the mapping screen
     * with sensible guesses pre-filled. If the company has previously
     * imported a file we honour their saved mapping verbatim.
     */
    public function parseFile(JobBulkImporter $importer): void
    {
        $this->validate([
            'companyId' => 'required|integer|exists:companies,id',
            'spreadsheet' => 'required|file|mimes:csv,txt,xls,xlsx|max:20480',
        ]);

        $company = Company::findOrFail($this->companyId);
        $path = $this->spreadsheet->getRealPath();

        try {
            $parsed = $importer->parse($path);
        } catch (\Throwable $e) {
            $this->addError('spreadsheet', 'Could not read this file: ' . $e->getMessage());
            return;
        }

        if (empty($parsed['rows'])) {
            $this->addError('spreadsheet', 'The file looks empty — no data rows were found.');
            return;
        }

        $this->parsedHeaders = $parsed['headers'];
        $this->parsedRows = $parsed['rows'];

        $this->mapping = $importer->detectMapping($parsed['headers'], $company);

        // Seed defaults from any saved mapping on the company so the
        // operator confirms rather than re-picks every month.
        $saved = $company->movement_csv_mapping ?? [];
        $this->defaultBrandId = $saved['default_brand_id'] ?? null;
        $this->defaultVehicleClassId = $saved['default_vehicle_class_id'] ?? null;
        $this->autoCreateLocations = (bool) ($saved['auto_create_locations'] ?? true);

        $this->step = 'map';
    }

    /**
     * Step 2 → 3. Build the preview off the cached rows. We don't touch
     * the database here — every row is virtual until the operator
     * commits.
     */
    public function buildPreview(JobBulkImporter $importer): void
    {
        // Vehicle class is no longer required on this step — ops can pick
        // a default OR allocate per-row on the preview screen, OR rely on
        // the model-name heuristic. Whichever path they take, every
        // committed row still ends up with a class (commit() refuses
        // unclassed rows).
        $this->validate([
            'companyId' => 'required|integer|exists:companies,id',
            // VIN OR registration -- either can identify the vehicle.
            'mapping.vin' => 'required_without:mapping.registration|nullable|string',
            'mapping.registration' => 'required_without:mapping.vin|nullable|string',
            'mapping.pickup' => 'required|string',
            'mapping.delivery' => 'required|string',
            'defaultVehicleClassId' => 'nullable|integer|exists:vehicle_classes,id',
            'defaultBrandId' => 'nullable|integer|exists:brands,id',
        ], [
            'mapping.vin.required_without' => 'Map the chassis / VIN column, or map the Registration column instead.',
            'mapping.registration.required_without' => 'Map the Registration column, or map the chassis / VIN column instead.',
            'mapping.pickup.required' => 'You must map the pickup / origin column.',
            'mapping.delivery.required' => 'You must map the delivery / destination column.',
        ]);

        $company = Company::findOrFail($this->companyId);

        $preview = $importer->preview($company, $this->parsedRows, $this->mapping, [
            'include_on_hold' => $this->includeOnHold,
            'auto_create_locations' => $this->autoCreateLocations,
            'default_vehicle_class_id' => $this->defaultVehicleClassId,
            'default_executor_type' => $this->defaultExecutorType,
            'vehicle_classes' => VehicleClass::query()->where('is_active', true)->get(['id', 'name']),
        ]);

        $this->previewRows = $preview['rows'];
        $this->previewStats = $preview['stats'];
        $this->bulkVehicleClassId = $this->defaultVehicleClassId;

        $this->step = 'preview';
    }

    /**
     * Update one preview row's vehicle class and refresh its status —
     * driven by the per-row dropdown on the preview table.
     */
    public function setRowVehicleClass(int $index, ?int $classId, JobBulkImporter $importer): void
    {
        if (!isset($this->previewRows[$index])) {
            return;
        }
        $this->previewRows[$index]['parsed']['vehicle_class_id'] = $classId ?: null;
        $this->previewRows[$index] = $importer->recalculateRow($this->previewRows[$index], $this->includeOnHold);
        $this->previewStats = $importer->aggregateStats($this->previewRows);
    }

    /**
     * Flip an "active duplicate" row between blocked and overridden.
     * Driven by the per-row checkbox on the preview table — see
     * JobBulkImporter::setDuplicateOverride for the guard logic.
     */
    public function toggleDuplicateOverride(int $index, bool $ack, JobBulkImporter $importer): void
    {
        if (!isset($this->previewRows[$index])) {
            return;
        }
        $this->previewRows[$index] = $importer->setDuplicateOverride(
            $this->previewRows[$index],
            $ack,
            $this->includeOnHold,
        );
        $this->previewStats = $importer->aggregateStats($this->previewRows);
    }

    /**
     * Apply a vehicle class to every row that doesn't already have one.
     * The "set 1117 trucks to '8t Rigid'" button — kept conservative
     * (only fills blanks, doesn't overwrite) so a heuristic guess that
     * was correct on row 12 isn't blown away by an over-eager click.
     */
    public function applyVehicleClassToBlanks(JobBulkImporter $importer): void
    {
        if (!$this->bulkVehicleClassId) {
            return;
        }
        foreach ($this->previewRows as $i => $row) {
            if (empty($row['parsed']['vehicle_class_id'])) {
                $this->previewRows[$i]['parsed']['vehicle_class_id'] = $this->bulkVehicleClassId;
                $this->previewRows[$i] = $importer->recalculateRow($this->previewRows[$i], $this->includeOnHold);
            }
        }
        $this->previewStats = $importer->aggregateStats($this->previewRows);
    }

    /**
     * Force a class onto every row regardless of what was guessed —
     * intentionally separate from "fill blanks" so the destructive
     * variant requires an explicit second button press.
     */
    public function applyVehicleClassToAll(JobBulkImporter $importer): void
    {
        if (!$this->bulkVehicleClassId) {
            return;
        }
        foreach ($this->previewRows as $i => $row) {
            $this->previewRows[$i]['parsed']['vehicle_class_id'] = $this->bulkVehicleClassId;
            $this->previewRows[$i] = $importer->recalculateRow($this->previewRows[$i], $this->includeOnHold);
        }
        $this->previewStats = $importer->aggregateStats($this->previewRows);
    }

    /**
     * Step 3 → 4. Commit the import. The mapping is saved back onto the
     * company on success so the next monthly run skips the map step
     * entirely.
     */
    /*
     * NB: do NOT name this method `commit()` — Livewire 3 reserves
     * `$commit` as an internal magic action (state-sync). A method
     * literally called `commit` is silently shadowed by it and never
     * runs, so the Import button looks dead.
     */
    public function commitImport(JobBulkImporter $importer): void
    {
        $company = Company::findOrFail($this->companyId);

        $result = $importer->commit(
            company: $company,
            createdByUserId: auth()->id(),
            previewRows: $this->previewRows,
            defaultBrandId: $this->defaultBrandId,
            defaultVehicleClassId: $this->defaultVehicleClassId,
            options: [
                'auto_create_locations' => $this->autoCreateLocations,
            ],
        );

        if ($this->rememberMapping) {
            $importer->rememberMapping(
                $company,
                $this->mapping,
                $this->defaultBrandId,
                $this->defaultVehicleClassId,
                $this->autoCreateLocations,
            );
        }

        $this->commitResult = $result;
        $this->step = 'done';
    }

    public function startOver(): void
    {
        $this->reset([
            'step', 'companyId', 'spreadsheet', 'parsedHeaders', 'parsedRows',
            'mapping', 'defaultBrandId', 'defaultVehicleClassId', 'defaultExecutorType',
            'autoCreateLocations', 'includeOnHold', 'rememberMapping',
            'previewRows', 'previewStats', 'commitResult', 'bulkVehicleClassId',
        ]);
    }

    public function backToUpload(): void
    {
        $this->step = 'upload';
    }

    public function backToMap(): void
    {
        $this->step = 'map';
    }
};

?>
<div>
    <x-slot:header>Bulk Order Upload</x-slot:header>

    {{-- Step indicator --}}
    <ol class="mb-8 flex flex-wrap items-center gap-2 text-xs font-medium tracking-wider uppercase text-slate-500">
        @foreach([
            'upload'  => '1 · Upload',
            'map'     => '2 · Confirm columns',
            'preview' => '3 · Preview',
            'done'    => '4 · Done',
        ] as $key => $label)
            @php
                $isCurrent = $step === $key;
                $isPast = array_search($step, ['upload','map','preview','done']) > array_search($key, ['upload','map','preview','done']);
            @endphp
            <li class="flex items-center gap-2">
                <span @class([
                    'inline-flex items-center gap-2 rounded-full border px-3 py-1',
                    'border-blue-200 bg-blue-50 text-blue-700' => $isCurrent,
                    'border-emerald-200 bg-emerald-50 text-emerald-700' => $isPast,
                    'border-slate-200 bg-white text-slate-500' => !$isCurrent && !$isPast,
                ])>
                    {{ $label }}
                </span>
                @if(!$loop->last)
                    <svg class="h-3 w-3 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                @endif
            </li>
        @endforeach
    </ol>

    {{-- ========================================================== --}}
    {{-- STEP 1 · UPLOAD                                            --}}
    {{-- ========================================================== --}}
    @if($step === 'upload')
        <form wire:submit="parseFile" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 max-w-2xl">
            <h2 class="text-base font-semibold text-slate-900">Choose customer &amp; file</h2>
            <p class="mt-1 text-sm text-slate-500">
                Drop in an OEM movement workbook (CSV / XLS / XLSX, up to 20 MB).
                We'll read every sheet, you'll confirm the columns, then we preview before anything is saved.
            </p>

            <div class="mt-6 space-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Customer</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="companyId"
                            :options="$companyOptions"
                            placeholder="— pick a customer —"
                            search-placeholder="Search customers…"
                        />
                    </div>
                    @error('companyId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">Spreadsheet</label>
                    <input wire:model="spreadsheet" type="file" accept=".csv,.txt,.xls,.xlsx"
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                    @error('spreadsheet')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

                    <div wire:loading wire:target="spreadsheet" class="mt-2 text-xs text-slate-500">Uploading...</div>
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="parseFile">Read the file</span>
                    <span wire:loading wire:target="parseFile">Reading...</span>
                </button>
                <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</a>
            </div>
        </form>
    @endif

    {{-- ========================================================== --}}
    {{-- STEP 2 · MAP COLUMNS                                       --}}
    {{-- ========================================================== --}}
    @if($step === 'map')
        <form wire:submit="buildPreview" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Confirm column mapping</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        We found <span class="font-semibold text-slate-700">{{ count($parsedRows) }}</span>
                        row(s) across <span class="font-semibold text-slate-700">{{ count($parsedHeaders) }}</span>
                        column(s). Match each field below to a column in your file.
                    </p>
                </div>
                <button type="button" wire:click="backToUpload" class="text-sm font-medium text-slate-500 hover:text-slate-800">
                    ← Re-upload
                </button>
            </div>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                @foreach($fields as $key => $label)
                    <div>
                        <label class="block text-sm font-medium text-slate-700">
                            {{ $label }}
                            @if(in_array($key, ['pickup', 'delivery']))
                                <span class="text-red-500">*</span>
                            @elseif(in_array($key, ['vin', 'registration']))
                                <span class="text-slate-400 text-xs font-normal">(VIN or Registration required)</span>
                            @endif
                        </label>
                        <select wire:model="mapping.{{ $key }}" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">— skip —</option>
                            @foreach($parsedHeaders as $h)
                                <option value="{{ $h }}">{{ $h }}</option>
                            @endforeach
                        </select>
                        @error('mapping.' . $key)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>

            <hr class="my-6 border-slate-200">

            <h3 class="text-sm font-semibold text-slate-900">Defaults applied to every row</h3>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Brand</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="defaultBrandId"
                            :options="$brandOptions"
                            placeholder="— none —"
                            search-placeholder="Search brands…"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">Default vehicle class</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="defaultVehicleClassId"
                            :options="$vehicleClassOptions"
                            placeholder="— let the importer guess from the model —"
                            search-placeholder="Search classes…"
                        />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Optional. Leave blank to let us infer the class from the model description (e.g. "28.290FL" → 28-tonne).
                        You can also override the class per row on the next screen.
                    </p>
                    @error('defaultVehicleClassId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Default executor</label>
                    <div class="mt-1">
                        <x-searchable-select
                            wire:model="defaultExecutorType"
                            :options="$executorOptions"
                            placeholder="— pick an executor —"
                        />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Applied to every row that doesn't carry its own executor column. You can also map an
                        <strong>Executor</strong> column above and the per-row value will win.
                    </p>
                </div>
            </div>

            <div class="mt-5 space-y-2">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="autoCreateLocations" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>
                        <span class="font-medium text-slate-700">Auto-create unknown locations.</span>
                        <span class="text-slate-500 block text-xs">When a pickup or delivery name doesn't match this customer's address book, add it as a new dealer location.</span>
                    </span>
                </label>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="includeOnHold" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>
                        <span class="font-medium text-slate-700">Include rows marked ON HOLD / HOLD.</span>
                        <span class="text-slate-500 block text-xs">By default these rows are skipped. Tick this to import them with today's date and a flag for ops to follow up.</span>
                    </span>
                </label>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="rememberMapping" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>
                        <span class="font-medium text-slate-700">Remember this mapping for next time.</span>
                        <span class="text-slate-500 block text-xs">Save these choices on the customer so next month's upload skips this screen.</span>
                    </span>
                </label>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="buildPreview">Preview</span>
                    <span wire:loading wire:target="buildPreview">Building preview...</span>
                </button>
                <button type="button" wire:click="backToUpload" class="text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</button>
            </div>
        </form>
    @endif

    {{-- ========================================================== --}}
    {{-- STEP 3 · PREVIEW                                           --}}
    {{-- ========================================================== --}}
    @if($step === 'preview')
        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="text-base font-semibold text-slate-900">Preview</h2>
                    <button type="button" wire:click="backToMap" class="text-sm font-medium text-slate-500 hover:text-slate-800">← Edit columns</button>
                </div>

                @php
                    $blocked  = (int) ($previewStats['duplicates_blocked']  ?? 0);
                    $override = (int) ($previewStats['duplicates_override'] ?? 0);
                    $importCount = (int) ($previewStats['ready'] ?? 0);
                    $confirmMsg = $override > 0
                        ? "You are about to import {$importCount} job(s) for this customer, and {$override} of them are DUPLICATES of an active order. Continue?"
                        : "Import {$importCount} job(s) for this customer?";
                @endphp

                @if($blocked > 0)
                    <div class="mt-5 rounded-xl border-2 border-rose-300 bg-rose-50 p-4">
                        <div class="flex items-start gap-3">
                            <svg class="h-6 w-6 flex-none text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                            <div>
                                <h3 class="text-sm font-bold uppercase tracking-wider text-rose-800">
                                    {{ $blocked }} row{{ $blocked === 1 ? '' : 's' }} blocked — duplicate of an ACTIVE order
                                </h3>
                                <p class="mt-1 text-sm text-rose-700">
                                    {{ $blocked === 1 ? 'This row' : 'These rows' }} would create a second movement for {{ $blocked === 1 ? 'a vehicle' : 'vehicles' }} that {{ $blocked === 1 ? 'is' : 'are' }} already on an open job for this customer.
                                    They will <strong>not</strong> import as-is.
                                </p>
                                <p class="mt-2 text-xs text-rose-700">
                                    If a vehicle genuinely needs another movement (returning from storage, body builder collection, etc.), tick
                                    <span class="font-semibold">"Override — create duplicate"</span> on each row you want to import.
                                    Otherwise leave them un-ticked and they'll be skipped.
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                @if($override > 0)
                    <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                        <strong>{{ $override }}</strong> duplicate row{{ $override === 1 ? '' : 's' }} marked for override — they'll import as a second movement on top of the existing active order.
                    </div>
                @endif

                <div class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Total</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $previewStats['total'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-emerald-700">Ready</p>
                        <p class="mt-1 text-2xl font-semibold text-emerald-900 tabular-nums">{{ $importCount }}</p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-amber-700">Warnings</p>
                        <p class="mt-1 text-2xl font-semibold text-amber-900 tabular-nums">{{ $previewStats['warnings'] ?? 0 }}</p>
                    </div>
                    <div @class([
                        'rounded-lg border p-3',
                        'border-rose-300 bg-rose-50' => $blocked > 0,
                        'border-slate-200 bg-slate-50' => $blocked === 0,
                    ])>
                        <p @class([
                            'text-[11px] font-medium uppercase tracking-wider',
                            'text-rose-700' => $blocked > 0,
                            'text-slate-500' => $blocked === 0,
                        ])>Duplicates blocked</p>
                        <p @class([
                            'mt-1 text-2xl font-semibold tabular-nums',
                            'text-rose-900' => $blocked > 0,
                            'text-slate-700' => $blocked === 0,
                        ])>{{ $blocked }}</p>
                    </div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-rose-700">Skipped</p>
                        <p class="mt-1 text-2xl font-semibold text-rose-900 tabular-nums">
                            {{ ($previewStats['errors'] ?? 0) + ($previewStats['on_hold'] ?? 0) }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button wire:click="commitImport" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-60" wire:loading.attr="disabled" wire:confirm="{{ $confirmMsg }}">
                        <span wire:loading.remove wire:target="commitImport">Import {{ $importCount }} job(s)</span>
                        <span wire:loading wire:target="commitImport">Importing...</span>
                    </button>
                    <button type="button" wire:click="backToMap" class="text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</button>
                </div>

                {{-- Bulk vehicle-class allocator --}}
                <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Bulk allocate vehicle class</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <div class="w-56">
                            <x-searchable-select
                                wire:model="bulkVehicleClassId"
                                :options="$vehicleClassOptions"
                                placeholder="— pick a class —"
                                search-placeholder="Search classes…"
                            />
                        </div>
                        <button type="button" wire:click="applyVehicleClassToBlanks" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-500 disabled:opacity-50" {{ $bulkVehicleClassId ? '' : 'disabled' }}>
                            Apply to rows missing a class
                        </button>
                        <button type="button" wire:click="applyVehicleClassToAll" wire:confirm="Overwrite the vehicle class on every row, including ones that already have one?" class="rounded-lg bg-white border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" {{ $bulkVehicleClassId ? '' : 'disabled' }}>
                            Apply to all rows
                        </button>
                        <p class="text-xs text-slate-500">Or change the class one row at a time in the table below.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Source</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">VIN</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Model</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Class</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Executor</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Pickup</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Delivery</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Date</th>
                                <th class="px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-gray-500">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach(array_slice($previewRows, 0, 200) as $index => $row)
                                @php
                                    $status = $row['status'];
                                    $isDuplicate = !empty($row['requires_override']);
                                    $isOverridden = !empty($row['override_acknowledged']);
                                    $pillClass = match($status) {
                                        'ready'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'warning'   => 'bg-amber-50 text-amber-800 border-amber-200',
                                        'error'     => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'duplicate' => 'bg-rose-100 text-rose-800 border-rose-400 ring-2 ring-rose-200',
                                        'skipped'   => 'bg-slate-100 text-slate-600 border-slate-200',
                                        default     => 'bg-slate-50 text-slate-600 border-slate-200',
                                    };
                                    $statusLabel = $status === 'duplicate' ? 'duplicate · blocked' : $status;
                                @endphp
                                <tr @class([
                                    'hover:bg-slate-50',
                                    'bg-rose-50/40' => $status === 'duplicate',
                                ])>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium uppercase tracking-wider {{ $pillClass }}">
                                            {{ $statusLabel }}
                                        </span>
                                        @if($isDuplicate && $isOverridden)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800">override</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-500 whitespace-nowrap">
                                        {{ $row['source_sheet'] ?? '—' }} · row {{ $row['source_row'] ?? '?' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs font-mono text-slate-700">
                                        @if(!empty($row['parsed']['vin']))
                                            {{ $row['parsed']['vin'] }}
                                            @if(!empty($row['parsed']['registration']))
                                                <span class="block text-[10px] text-slate-500">Reg {{ $row['parsed']['registration'] }}</span>
                                            @endif
                                        @elseif(!empty($row['parsed']['registration']))
                                            <span class="text-slate-500 uppercase text-[10px] mr-1">Reg</span>{{ $row['parsed']['registration'] }}
                                        @else
                                            —
                                        @endif
                                        @if(!empty($row['parsed']['is_urgent']))
                                            <span class="ml-1 inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-rose-700">URGENT</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700">{{ $row['parsed']['model'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs">
                                        <select wire:change="setRowVehicleClass({{ $index }}, $event.target.value)"
                                                class="block w-36 rounded border border-gray-300 px-2 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                            <option value="">— pick —</option>
                                            @foreach($vehicleClasses as $vc)
                                                <option value="{{ $vc->id }}" @selected(($row['parsed']['vehicle_class_id'] ?? null) == $vc->id)>{{ $vc->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700">
                                        @php
                                            $execType = $row['parsed']['executor_type'] ?? null;
                                            $execLabel = $execType ? (\App\Models\Job::EXECUTOR_LABELS[$execType] ?? $execType) : '—';
                                            $execPill = match ($execType) {
                                                \App\Models\Job::EXECUTOR_INTERNAL    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                                \App\Models\Job::EXECUTOR_THIRD_PARTY => 'bg-purple-50 text-purple-700 border-purple-200',
                                                \App\Models\Job::EXECUTOR_SELF_COLLECT => 'bg-amber-50 text-amber-700 border-amber-200',
                                                default                                => 'bg-blue-50 text-blue-700 border-blue-200',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider {{ $execPill }}">
                                            {{ $execLabel }}
                                        </span>
                                        @if($execType === \App\Models\Job::EXECUTOR_INTERNAL && !empty($row['parsed']['driver_name_raw']))
                                            <div class="mt-1 text-[10px] text-slate-500">drv: {{ $row['parsed']['driver_name_raw'] }}
                                                @if(empty($row['parsed']['driver_user_id']))<span class="text-amber-600">·unmatched</span>@endif
                                            </div>
                                        @elseif($execType === \App\Models\Job::EXECUTOR_THIRD_PARTY && !empty($row['parsed']['third_party_courier_name']))
                                            <div class="mt-1 text-[10px] text-slate-500">{{ $row['parsed']['third_party_courier_name'] }}</div>
                                        @elseif($execType === \App\Models\Job::EXECUTOR_SELF_COLLECT && !empty($row['parsed']['self_collect_name']))
                                            <div class="mt-1 text-[10px] text-slate-500">{{ $row['parsed']['self_collect_name'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700">
                                        {{ $row['parsed']['pickup_match']?->company_name ?? $row['parsed']['pickup_raw'] ?? '—' }}
                                        @if(!$row['parsed']['pickup_match'] && $row['parsed']['pickup_raw'])
                                            <span class="ml-1 text-[10px] uppercase tracking-wider text-amber-600">new</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700">
                                        {{ $row['parsed']['delivery_match']?->company_name ?? $row['parsed']['delivery_raw'] ?? '—' }}
                                        @if(!$row['parsed']['delivery_match'] && $row['parsed']['delivery_raw'])
                                            <span class="ml-1 text-[10px] uppercase tracking-wider text-amber-600">new</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-700 whitespace-nowrap">{{ $row['parsed']['scheduled_date'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-slate-500 max-w-xs">
                                        @if($isDuplicate)
                                            <div class="rounded-lg border-2 border-rose-300 bg-rose-50 p-2">
                                                <p class="text-[11px] font-bold uppercase tracking-wider text-rose-800">
                                                    Duplicate of active order
                                                    @if(!empty($row['duplicate_of']['job_number']))
                                                        — {{ $row['duplicate_of']['job_number'] }}
                                                    @endif
                                                </p>
                                                @if(!empty($row['duplicate_of']['status_label']))
                                                    <p class="mt-0.5 text-[11px] text-rose-700">Existing status: {{ $row['duplicate_of']['status_label'] }}</p>
                                                @endif
                                                <label class="mt-2 flex items-start gap-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           class="mt-0.5 rounded border-rose-400 text-rose-600 focus:ring-rose-500"
                                                           {{ $isOverridden ? 'checked' : '' }}
                                                           wire:change="toggleDuplicateOverride({{ $index }}, $event.target.checked)">
                                                    <span class="text-[11px] font-semibold text-rose-800">
                                                        Override — create duplicate movement anyway
                                                    </span>
                                                </label>
                                            </div>
                                        @elseif(!empty($row['errors']))
                                            <ul class="list-disc list-inside text-rose-600">
                                                @foreach($row['errors'] as $err)<li>{{ $err }}</li>@endforeach
                                            </ul>
                                        @elseif(!empty($row['warnings']))
                                            <ul class="list-disc list-inside text-amber-700">
                                                @foreach($row['warnings'] as $w)<li>{{ $w }}</li>@endforeach
                                            </ul>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($previewRows) > 200)
                    <div class="px-3 py-2 border-t border-gray-100 text-[11px] text-slate-500 text-center bg-slate-50">
                        Showing first 200 of {{ count($previewRows) }} rows. Larger files import in full — you'll see the full count on the next screen.
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ========================================================== --}}
    {{-- STEP 4 · DONE                                              --}}
    {{-- ========================================================== --}}
    @if($step === 'done')
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 max-w-2xl">
            <div class="flex items-start gap-3">
                <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center flex-none">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Import complete</h2>
                    <p class="mt-1 text-sm text-slate-500">Mapping was {{ $rememberMapping ? 'saved' : 'not saved' }} on the customer.</p>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 text-sm">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                    <dt class="text-[11px] font-medium uppercase tracking-wider text-emerald-700">Jobs created</dt>
                    <dd class="mt-1 text-2xl font-semibold text-emerald-900 tabular-nums">{{ $commitResult['created'] ?? 0 }}</dd>
                </div>
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
                    <dt class="text-[11px] font-medium uppercase tracking-wider text-blue-700">New locations</dt>
                    <dd class="mt-1 text-2xl font-semibold text-blue-900 tabular-nums">{{ $commitResult['created_locations'] ?? 0 }}</dd>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-[11px] font-medium uppercase tracking-wider text-slate-500">Skipped</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-700 tabular-nums">{{ $commitResult['skipped'] ?? 0 }}</dd>
                </div>
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                    <dt class="text-[11px] font-medium uppercase tracking-wider text-rose-700">Errors</dt>
                    <dd class="mt-1 text-2xl font-semibold text-rose-900 tabular-nums">{{ count($commitResult['errors'] ?? []) }}</dd>
                </div>
            </dl>

            @if(!empty($commitResult['errors']))
                <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-3">
                    <p class="text-xs font-semibold text-rose-700">Rows that failed to commit:</p>
                    <ul class="mt-2 list-disc list-inside text-xs text-rose-700 space-y-1 max-h-48 overflow-y-auto">
                        @foreach(array_slice($commitResult['errors'], 0, 50) as $err)
                            <li>row {{ $err['row'] ?? '?' }} — {{ $err['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 flex items-center gap-3">
                <a href="{{ route('admin.orders.index') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                    Open Orders
                </a>
                <button wire:click="startOver" type="button" class="text-sm font-medium text-slate-500 hover:text-slate-800">
                    Upload another file
                </button>
            </div>
        </div>
    @endif
</div>
