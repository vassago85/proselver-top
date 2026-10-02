<?php

use App\Exceptions\PodDiskUnavailableException;
use App\Models\Job;
use App\Models\JobDocument;
use App\Services\PodStore;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Documents index — grouped by job rather than flat so ops can scan
 * "this movement has front/rear/left/right/dashboard/data plate + POD"
 * at a glance instead of hunting through 200 rows of identical
 * filenames.
 *
 * We paginate at the JOB level (not the document level) so each card
 * always shows the full set of artefacts for that movement.
 *
 * The default list is the last 7 days. Older jobs stay off the page
 * until "Show older" is opened, and then each of those cards is
 * collapsed so the photo grids don't sit open.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $categoryFilter = '';

    #[Url]
    public bool $photosOnly = false;

    /** When true, jobs with nothing in the recent window are listed too, collapsed. */
    #[Url]
    public bool $includeOlder = false;

    /** Documents captured inside this window stay expanded. Older ones collapse. */
    public int $recentDays = 7;

    public string $podLookup = '';

    /**
     * Multiple files, so a two-page POD (or a POD + a separate signature
     * sheet) can be filed as one upload. Each file becomes its own
     * JobDocument row on the pods disk.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>|null
     */
    public $podFiles = [];

    public function with(): array
    {
        // Build the JOB query first — we want to paginate jobs that have
        // at least one document matching the filter, NOT documents.
        $docFilter = function ($q) {
            if ($this->categoryFilter) {
                $q->where('category', $this->categoryFilter);
            }
            if ($this->photosOnly) {
                $q->where('mime_type', 'like', 'image/%');
            }
        };

        $cutoff = now()->subDays($this->recentDays);
        $recentDoc = function ($q) use ($cutoff) {
            $q->where(function ($inner) use ($cutoff) {
                $inner->where('captured_at', '>=', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff) {
                        $fallback->whereNull('captured_at')->where('created_at', '>=', $cutoff);
                    });
            });
        };

        // Default view is the last 7 days. A search still looks through the
        // archive so an old job number or VIN can be found. "Show older"
        // lists the archive as collapsed cards.
        $restrictToRecent = $this->search === '' && ! $this->includeOlder;

        $jobs = Job::query()
            ->whereHas('documents', $docFilter)
            ->when($restrictToRecent, function ($q) use ($docFilter, $recentDoc) {
                $q->whereHas('documents', function ($docs) use ($docFilter, $recentDoc) {
                    $docFilter($docs);
                    $recentDoc($docs);
                });
            })
            ->with([
                'company:id,name',
                'documents' => function ($q) use ($docFilter) {
                    $docFilter($q);
                    $q->with('uploadedBy:id,name')->orderBy('captured_at')->orderBy('created_at');
                },
            ]);

        $olderJobCount = 0;
        if ($restrictToRecent) {
            $olderJobCount = Job::query()
                ->whereHas('documents', $docFilter)
                ->whereDoesntHave('documents', function ($docs) use ($docFilter, $recentDoc) {
                    $docFilter($docs);
                    $recentDoc($docs);
                })
                ->count();
        }

        if ($this->search !== '') {
            $needle = '%' . $this->search . '%';
            $jobs->where(function ($q) use ($needle) {
                $q->where('job_number', 'ilike', $needle)
                    ->orWhere('vin', 'ilike', $needle)
                    ->orWhere('registration', 'ilike', $needle)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'ilike', $needle))
                    ->orWhereHas('documents', fn ($d) => $d->where('original_filename', 'ilike', $needle));
            });
        }

        $jobs->orderByDesc('updated_at');

        return [
            'jobs' => $jobs->paginate(10),
            'cutoff' => $cutoff,
            'olderJobCount' => $olderJobCount,
            'categories' => [
                JobDocument::CATEGORY_PO            => 'Purchase Order',
                JobDocument::CATEGORY_POD           => 'Proof of Delivery',
                JobDocument::CATEGORY_COLLECTION_NOTE => 'Collection Note',
                JobDocument::CATEGORY_PHOTO         => 'Vehicle photo',
                JobDocument::CATEGORY_DASHBOARD     => 'Dashboard (fuel + odo)',
                JobDocument::CATEGORY_DATA_PLATE    => 'Data plate (VIN)',
                JobDocument::CATEGORY_DAMAGE_PHOTO  => 'Damage photo',
                JobDocument::CATEGORY_FUEL_SLIP     => 'Fuel slip',
                JobDocument::CATEGORY_FOOD_SLIP     => 'Food slip',
                JobDocument::CATEGORY_TOLL_SLIP     => 'Toll slip',
                JobDocument::CATEGORY_PARKING_SLIP  => 'Parking slip',
                JobDocument::CATEGORY_OTHER         => 'Other',
            ],
        ];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCategoryFilter(): void { $this->resetPage(); }
    public function updatedPhotosOnly(): void { $this->resetPage(); }
    public function updatedIncludeOlder(): void { $this->resetPage(); }

    /**
     * Office upload of a POD. The order is found by an exact job number,
     * or by an exact VIN when that VIN belongs to one order. Accepts
     * multiple files so a two-page POD comes in as one submission.
     */
    public function uploadPod(PodStore $pods): void
    {
        $this->validate([
            'podLookup' => 'required|string|max:64',
            'podFiles' => 'required|array|min:1|max:10',
            'podFiles.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        $job = $this->findJobForPod(trim($this->podLookup));
        if (!$job) {
            return;
        }

        $saved = 0;
        foreach ($this->podFiles as $file) {
            try {
                $pods->store($job, $file, auth()->user());
                $saved++;
            } catch (PodDiskUnavailableException $e) {
                $this->addError('podFiles', $e->getMessage());
                return;
            }
        }

        $label = $job->job_number ?: ('#'.$job->id);
        $this->reset('podLookup', 'podFiles');
        $noun = $saved === 1 ? 'POD' : 'PODs';
        session()->flash('success', "{$saved} {$noun} saved for {$label}.");
    }

    private function findJobForPod(string $term): ?Job
    {
        $needle = mb_strtolower($term);

        $byNumber = Job::query()
            ->whereRaw('lower(job_number) = ?', [$needle])
            ->first();
        if ($byNumber) {
            return $byNumber;
        }

        $byVin = Job::query()
            ->whereRaw('lower(vin) = ?', [$needle])
            ->orderByDesc('id')
            ->get();

        if ($byVin->count() === 1) {
            return $byVin->first();
        }

        if ($byVin->count() > 1) {
            $this->addError('podLookup', 'More than one order uses that VIN. Enter the job number.');
            return null;
        }

        $this->addError('podLookup', 'No order matches that job number or VIN.');
        return null;
    }
};

?>

<div>
    <x-slot:header>Documents</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="uploadPod" class="mb-4 rounded-xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900">Upload a POD</p>
        <p class="mt-0.5 text-xs text-gray-500">Saved on the POD disk under the job number and VIN. Pick more than one file if the POD is two pages.</p>
        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="pod-lookup" class="mb-1 block text-xs font-medium text-gray-700">Job number or VIN</label>
                <input id="pod-lookup" wire:model="podLookup" type="text" autocomplete="off"
                       placeholder="e.g. 26050289 or the chassis number"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                @error('podLookup')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex-1">
                <label for="pod-file" class="mb-1 block text-xs font-medium text-gray-700">PDF or photos (one or more)</label>
                <input id="pod-file" wire:model="podFiles" type="file" multiple
                       accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                       class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700">
                @error('podFiles')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @error('podFiles.*')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @if(is_array($podFiles) && count($podFiles) > 0)
                    <p class="mt-1 text-xs text-gray-500">
                        {{ count($podFiles) }} {{ count($podFiles) === 1 ? 'file' : 'files' }} ready
                    </p>
                @endif
            </div>
            <button type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60"
                    wire:loading.attr="disabled" wire:target="uploadPod,podFiles">
                <span wire:loading.remove wire:target="uploadPod">Upload POD</span>
                <span wire:loading wire:target="uploadPod">Uploading…</span>
            </button>
        </div>
    </form>

    <div class="mb-6 flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Search by job number or VIN..."
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="categoryFilter"
                class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All categories</option>
            @foreach($categories as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2.5 text-sm cursor-pointer hover:bg-gray-50">
            <input type="checkbox" wire:model.live="photosOnly" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span>Photos only</span>
        </label>
    </div>

    @if($search === '' && ! $includeOlder)
        <p class="mb-4 text-xs text-gray-500">Showing documents from the last {{ $recentDays }} days.</p>
    @endif

    @forelse($jobs as $job)
        @php
            $isPetty = fn ($d) => in_array($d->category, \App\Models\JobDocument::pettyCashCategories(), true);
            $stamp = fn ($d) => $d->captured_at ?? $d->created_at;
            $isRecent = fn ($d) => $stamp($d) && $stamp($d)->gte($cutoff);
            $recent = $job->documents->filter($isRecent)->values();
            $older = $job->documents->reject($isRecent)->values();
            $isStale = $recent->isEmpty();
            $isImage = fn ($d) => str_starts_with((string) $d->mime_type, 'image/');
            $showExpenses = auth()->user()->isInternal() || auth()->user()->belongsToPlatformOwner();
            $paper = fn ($set) => $set->reject($isPetty);
            $petty = fn ($set) => $set->filter($isPetty);
        @endphp

        <div @class([
            'mb-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden',
            'opacity-90' => $isStale,
        ]) @if($isStale) data-stale-documents @endif>
            @if($isStale)
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 border-b border-transparent px-5 py-3 bg-gradient-to-b from-white to-gray-50 group-open:border-gray-100 [&::-webkit-details-marker]:hidden">
                        @include('pages.admin.documents.card-header', ['job' => $job, 'isStale' => true])
                    </summary>
                    @include('pages.admin.documents.thumbnail-grid', ['docs' => $paper($job->documents), 'isImage' => $isImage])
                    @if($showExpenses)
                        @include('pages.admin.documents.expense-list', ['docs' => $petty($job->documents)])
                    @endif
                </details>
            @else
                <div class="flex items-start justify-between gap-3 px-5 py-3 border-b border-gray-100 bg-gradient-to-b from-white to-gray-50">
                    @include('pages.admin.documents.card-header', ['job' => $job, 'isStale' => false])
                </div>

                @include('pages.admin.documents.thumbnail-grid', ['docs' => $paper($recent), 'isImage' => $isImage])

                @if($showExpenses && $petty($recent)->isNotEmpty())
                    @include('pages.admin.documents.expense-list', ['docs' => $petty($recent)])
                @endif

                @if($older->isNotEmpty())
                    <details class="group border-t border-dashed border-gray-200">
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-2.5 text-xs font-medium text-gray-600 hover:bg-gray-50 [&::-webkit-details-marker]:hidden">
                            <svg class="h-3.5 w-3.5 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            Show {{ $older->count() }} older {{ \Illuminate\Support\Str::plural('document', $older->count()) }}
                        </summary>
                        @include('pages.admin.documents.thumbnail-grid', ['docs' => $paper($older), 'isImage' => $isImage])
                        @if($showExpenses)
                            @include('pages.admin.documents.expense-list', ['docs' => $petty($older)])
                        @endif
                    </details>
                @endif
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center">
            @if($olderJobCount > 0)
                <p class="text-sm text-gray-500">No documents in the last {{ $recentDays }} days.</p>
            @else
                <p class="text-sm text-gray-500">No documents match these filters.</p>
            @endif
        </div>
    @endforelse

    @if($olderJobCount > 0)
        <div class="mb-4">
            <button type="button" wire:click="$set('includeOlder', true)"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Show documents older than {{ $recentDays }} days
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ $olderJobCount }}</span>
            </button>
        </div>
    @elseif($includeOlder && $search === '')
        <div class="mb-4">
            <button type="button" wire:click="$set('includeOlder', false)"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Hide documents older than {{ $recentDays }} days
            </button>
        </div>
    @endif

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>
