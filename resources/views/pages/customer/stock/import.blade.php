<?php

use App\Models\Company;
use App\Models\DealerStock;
use App\Models\Location;
use App\Services\DealerStockImporter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/*
 * Bulk stock importer for dealers.  Mirrors the OEM movement
 * importer but only handles dealer stock rows -- no destination
 * resolution, no executor inference, no per-row branching.
 *
 * Three phases, all in one page:
 *   1. Upload   -- user drops a .xlsx / .csv file.
 *   2. Preview  -- DealerStockImporter::detectMapping() does a
 *                  first pass; the user can adjust the mapping;
 *                  the table shows what will land in dealer_stock.
 *   3. Commit   -- idempotent upsert on (dealer_company_id, vin).
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public array $headers = [];
    public array $rows = [];
    /** mapping: logical_field => normalised_header */
    public array $mapping = [];
    public array $preview = [];
    public bool $hasMapping = false;
    public string $resultMessage = '';

    /*
     * Where should NEW rows land?  Mirrors the single-vehicle add
     * page so the dealer can drop a "factory-direct-to-BB" batch
     * straight into the right bucket instead of importing them at
     * premises and then moving them.
     */
    public string $default_location_type = DealerStock::LOCATION_PREMISES;
    public ?int $default_bb_company_id   = null;
    public ?int $default_bb_location_id  = null;
    public ?int $default_storage_location_id = null;

    public function mount(): void
    {
        // Dealer-tenant only.  Stock import is a dealer concept --
        // OEM tenants don't run a stock ledger.  See stock/index for
        // the same gating + rationale.
        abort_unless(auth()->user()?->company()?->isDealer(), 404);
        abort_unless(auth()->user()?->hasPermission('manage_dealer_stock'), 403);
        abort_unless(auth()->user()?->company(), 403, 'No company associated with your account.');
    }

    public function updatedUpload(): void
    {
        // Reset state when the user replaces the file mid-flow.
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->preview = [];
        $this->hasMapping = false;
        $this->resultMessage = '';
    }

    public function loadFile(DealerStockImporter $importer): void
    {
        $this->validate([
            'upload' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $parsed = $importer->parse($this->upload->getRealPath());
        $this->headers = $parsed['headers'];
        $this->rows = $parsed['rows'];
        $this->mapping = $importer->detectMapping($this->headers);
        $this->hasMapping = !empty($this->mapping);

        $this->refreshPreview($importer);
    }

    public function refreshPreview(DealerStockImporter $importer): void
    {
        if (empty($this->rows)) {
            $this->preview = [];
            return;
        }
        $dealer = auth()->user()->company();
        $this->preview = $importer->preview($this->rows, $this->mapping, $dealer);
    }

    /**
     * Reset the BB-yard picker when the BB itself changes -- stops a
     * stale yard ID belonging to the previously-picked BB sneaking
     * through validation.
     */
    public function updatedDefaultBbCompanyId(): void
    {
        $this->default_bb_location_id = null;
    }

    public function commit(DealerStockImporter $importer): void
    {
        if (empty($this->preview)) {
            $this->resultMessage = 'Nothing to import yet -- upload a file first.';
            return;
        }

        // Resolve which location id to apply to new rows based on the
        // picked starting bucket.  Premises = no location id; the
        // other two require a Location.
        $defaultLocationId = match ($this->default_location_type) {
            DealerStock::LOCATION_BODY_BUILDER => $this->default_bb_location_id,
            DealerStock::LOCATION_STORAGE      => $this->default_storage_location_id,
            default => null,
        };

        // Guard: if the dealer picked a non-premises starting bucket
        // we MUST have a location id, otherwise the new rows will be
        // floating without an address.
        if (in_array($this->default_location_type, [DealerStock::LOCATION_BODY_BUILDER, DealerStock::LOCATION_STORAGE], true)
            && $defaultLocationId === null) {
            $this->resultMessage = 'Pick the body builder yard / storage location before committing.';
            return;
        }

        $dealer = auth()->user()->company();
        $summary = $importer->commit(
            $this->preview,
            $dealer,
            $this->default_location_type,
            $defaultLocationId,
        );

        $this->resultMessage = sprintf(
            'Import complete -- %d new, %d updated, %d skipped.',
            $summary['created'],
            $summary['updated'],
            $summary['skipped'],
        );

        // Reset for another file -- but keep the starting-location
        // picker so an OEM-to-BB batch followed by a second batch
        // to the same BB doesn't have to re-pick every time.
        $this->headers = [];
        $this->rows = [];
        $this->mapping = [];
        $this->preview = [];
        $this->upload = null;
    }

    public function with(): array
    {
        $normalisedHeaders = array_map(
            fn ($h) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $h)),
            $this->headers
        );

        $dealer = auth()->user()?->company();

        // BB companies linked to this dealer; same shortlist the
        // single-vehicle add form uses.  Hidden until the dealer
        // actually picks "body builder" as the starting location.
        $bbCompanies = $dealer
            ? \App\Models\Company::query()
                ->where('type', \App\Models\Company::TYPE_BODY_BUILDER)
                ->whereHas('linkedDealers', fn ($l) => $l->where('companies.id', $dealer->id))
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        $bbLocations = $this->default_bb_company_id
            ? Location::where('company_id', $this->default_bb_company_id)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city'])
            : collect();

        $storageLocations = $dealer
            ? Location::where('company_id', $dealer->id)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'city'])
            : collect();

        return [
            'fields' => DealerStockImporter::FIELDS,
            'normalisedHeaders' => $normalisedHeaders,
            'bbCompanies' => $bbCompanies,
            'bbLocations' => $bbLocations,
            'storageLocations' => $storageLocations,
        ];
    }
};
?>

<div>
    <x-slot:header>Import stock</x-slot:header>

    @if($resultMessage)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $resultMessage }}</div>
    @endif

    <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold">How this works</p>
        <p class="mt-1 text-blue-900/80">
            Export your stock list out of your DMS (or any spreadsheet you keep it in), drop the file below,
            confirm the column mapping, and commit. Re-running the same file is safe &mdash;
            existing vehicles are matched on VIN and only their attributes refresh.
        </p>
        <p class="mt-2">
            <a href="{{ route('customer.stock.import.template') }}"
               class="font-semibold text-blue-700 underline hover:text-blue-900">
                Download sample CSV template
            </a>
            &nbsp;&middot;&nbsp;
            <span class="text-blue-900/80">Use it to see exactly which columns we read.</span>
        </p>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 mb-6">
        <h2 class="text-base font-semibold text-slate-900">1. Upload spreadsheet</h2>
        <p class="mt-1 text-sm text-slate-500">
            Drop an .xlsx, .xls or .csv export from your DMS. We read the following columns automatically &mdash;
            anything else is ignored, and you can remap any column in step 2:
        </p>
        <ul class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-medium">
            @foreach($fields as $field => $label)
                <li class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ $label }}</li>
            @endforeach
        </ul>
        <p class="mt-2 text-xs text-slate-500">
            <strong class="text-slate-700">VIN</strong> is the only required column. Common header names like
            <code class="rounded bg-slate-100 px-1">Chassis</code>,
            <code class="rounded bg-slate-100 px-1">Reg No</code>,
            <code class="rounded bg-slate-100 px-1">Make</code>,
            <code class="rounded bg-slate-100 px-1">Engine No.</code>
            are recognised automatically.
        </p>

        <form wire:submit="loadFile" class="mt-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <input type="file" wire:model="upload"
                   accept=".xlsx,.xls,.csv"
                   class="block w-full sm:w-auto text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
            <button type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                Read file
            </button>
        </form>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    @if(!empty($headers))
        {{-- Starting-location picker: only shown once a file has been
             parsed (no point asking before we know there's anything
             to import).  Defaults to "premises" so the historical
             behaviour is preserved when the dealer skips this step. --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 mb-6">
            <h2 class="text-base font-semibold text-slate-900">2. Where are these vehicles right now?</h2>
            <p class="mt-1 text-sm text-slate-500">
                Pick the bucket the rows should land in. Most of the time this is your premises &mdash; pick a body builder
                if the OEM shipped this batch factory-direct to one of your fitters.
            </p>

            <div class="mt-4 space-y-3">
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                    <input wire:model.live="default_location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_PREMISES }}" class="mt-0.5">
                    <span class="text-sm"><span class="font-medium text-slate-900">At my premises</span></span>
                </label>

                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                    <input wire:model.live="default_location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_BODY_BUILDER }}" class="mt-0.5">
                    <span class="text-sm flex-1">
                        <span class="font-medium text-slate-900">At a body builder</span>
                        <span class="block text-xs text-slate-500">Whole batch landed at the same fitter.</span>

                        @if($default_location_type === \App\Models\DealerStock::LOCATION_BODY_BUILDER)
                            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-600">Body builder</label>
                                    <select wire:model.live="default_bb_company_id"
                                            class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs">
                                        <option value="">&mdash; pick &mdash;</option>
                                        @foreach($bbCompanies as $bb)
                                            <option value="{{ $bb->id }}">{{ $bb->name }}</option>
                                        @endforeach
                                    </select>
                                    @if($bbCompanies->isEmpty())
                                        <p class="mt-1 text-[11px] text-amber-700">No body builders linked yet &mdash; link one under <em>Body Builders</em> first.</p>
                                    @endif
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-slate-600">BB yard / location</label>
                                    <select wire:model="default_bb_location_id"
                                            @if(!$default_bb_company_id) disabled @endif
                                            class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs disabled:bg-slate-50 disabled:text-slate-400">
                                        <option value="">&mdash; pick &mdash;</option>
                                        @foreach($bbLocations as $loc)
                                            <option value="{{ $loc->id }}">{{ trim(($loc->company_name ?? '') . ($loc->city ? ' — ' . $loc->city : '')) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif
                    </span>
                </label>

                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer hover:bg-slate-50">
                    <input wire:model.live="default_location_type" type="radio" value="{{ \App\Models\DealerStock::LOCATION_STORAGE }}" class="mt-0.5">
                    <span class="text-sm flex-1">
                        <span class="font-medium text-slate-900">At another storage / yard</span>
                        <span class="block text-xs text-slate-500">One of your own branches or yards (not your main premises).</span>

                        @if($default_location_type === \App\Models\DealerStock::LOCATION_STORAGE)
                            <div class="mt-3">
                                <label class="block text-[11px] font-medium text-slate-600">Storage location</label>
                                <select wire:model="default_storage_location_id"
                                        class="mt-1 block w-full rounded border border-slate-300 px-2 py-1.5 text-xs">
                                    <option value="">&mdash; pick &mdash;</option>
                                    @foreach($storageLocations as $loc)
                                        <option value="{{ $loc->id }}">{{ trim(($loc->company_name ?? '') . ($loc->city ? ' — ' . $loc->city : '')) }}</option>
                                    @endforeach
                                </select>
                                @if($storageLocations->isEmpty())
                                    <p class="mt-1 text-[11px] text-amber-700">No storage locations on file. Add one under <em>Resources &rarr; Address Book</em> first.</p>
                                @endif
                            </div>
                        @endif
                    </span>
                </label>
            </div>
            <p class="mt-3 text-[11px] text-slate-500">
                Existing rows (matched on VIN) keep their current location &mdash; this only applies to brand-new rows in the upload.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 mb-6">
            <h2 class="text-base font-semibold text-slate-900">3. Confirm column mapping</h2>
            <p class="mt-1 text-sm text-slate-500">We've guessed the columns. Override anything wrong, then preview the result below.</p>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($fields as $field => $label)
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-700 w-44 shrink-0">{{ $label }}</span>
                        <select wire:model.live="mapping.{{ $field }}" wire:change="refreshPreview"
                                class="w-full rounded border border-slate-300 px-2 py-1.5 text-xs">
                            <option value="">— not mapped —</option>
                            @foreach($headers as $i => $h)
                                <option value="{{ $normalisedHeaders[$i] ?? '' }}">{{ $h }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($preview))
        <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">4. Preview ({{ count($preview) }} rows)</h2>
                <button wire:click="commit"
                        class="inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition-colors">
                    Commit import
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">VIN</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Brand / Model</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Colour</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Reg</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach($preview as $i => $row)
                            <tr class="text-sm">
                                <td class="px-3 py-1.5 text-slate-500 tabular-nums">{{ $i + 1 }}</td>
                                <td class="px-3 py-1.5 font-mono text-slate-700">{{ $row['data']['vin'] ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ trim(($row['data']['brand'] ?? '') . ' ' . ($row['data']['model_name'] ?? '')) ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ $row['data']['colour'] ?: '—' }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ $row['data']['registration'] ?: '—' }}</td>
                                <td class="px-3 py-1.5">
                                    @if(!empty($row['errors']))
                                        @foreach($row['errors'] as $e)
                                            <span class="inline-block rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">{{ $e }}</span>
                                        @endforeach
                                    @elseif(!empty($row['warnings']))
                                        @foreach($row['warnings'] as $w)
                                            <span class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">{{ $w }}</span>
                                        @endforeach
                                    @else
                                        <span class="inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Ready</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
