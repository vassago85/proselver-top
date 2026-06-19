<?php

use App\Models\Company;
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

    public function mount(): void
    {
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

    public function commit(DealerStockImporter $importer): void
    {
        if (empty($this->preview)) {
            $this->resultMessage = 'Nothing to import yet -- upload a file first.';
            return;
        }

        $dealer = auth()->user()->company();
        $summary = $importer->commit($this->preview, $dealer);

        $this->resultMessage = sprintf(
            'Import complete -- %d new, %d updated, %d skipped.',
            $summary['created'],
            $summary['updated'],
            $summary['skipped'],
        );

        // Reset for another file
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

        return [
            'fields' => DealerStockImporter::FIELDS,
            'normalisedHeaders' => $normalisedHeaders,
        ];
    }
};
?>

<div>
    <x-slot:header>Import stock</x-slot:header>

    @if($resultMessage)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $resultMessage }}</div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-6 mb-6">
        <h2 class="text-base font-semibold text-slate-900">1. Upload spreadsheet</h2>
        <p class="mt-1 text-sm text-slate-500">Drop an .xlsx, .xls or .csv export from your DMS. We'll match VIN, suffix, variant, engine number, colour and registration.</p>

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
        <div class="rounded-xl border border-slate-200 bg-white p-6 mb-6">
            <h2 class="text-base font-semibold text-slate-900">2. Confirm column mapping</h2>
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
                <h2 class="text-base font-semibold text-slate-900">3. Preview ({{ count($preview) }} rows)</h2>
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
