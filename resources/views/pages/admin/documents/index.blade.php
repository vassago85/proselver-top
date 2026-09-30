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

    public string $podLookup = '';

    public $podFile = null;

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

        $jobs = Job::query()
            ->whereHas('documents', $docFilter)
            ->with([
                'company:id,name',
                'documents' => function ($q) use ($docFilter) {
                    $docFilter($q);
                    $q->with('uploadedBy:id,name')->orderBy('captured_at')->orderBy('created_at');
                },
            ]);

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

    /**
     * Office upload of a POD. The order is found by an exact job number,
     * or by an exact VIN when that VIN belongs to one order.
     */
    public function uploadPod(PodStore $pods): void
    {
        $this->validate([
            'podLookup' => 'required|string|max:64',
            'podFile' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        $job = $this->findJobForPod(trim($this->podLookup));
        if (!$job) {
            return;
        }

        try {
            $pods->store($job, $this->podFile, auth()->user());
        } catch (PodDiskUnavailableException $e) {
            $this->addError('podFile', $e->getMessage());
            return;
        }

        $label = $job->job_number ?: ('#'.$job->id);
        $this->reset('podLookup', 'podFile');
        session()->flash('success', "POD saved for {$label}.");
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
        <p class="mt-0.5 text-xs text-gray-500">Saved on the POD disk under the job number and VIN.</p>
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
                <label for="pod-file" class="mb-1 block text-xs font-medium text-gray-700">PDF or photo</label>
                <input id="pod-file" wire:model="podFile" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                       class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700">
                @error('podFile')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-60"
                    wire:loading.attr="disabled" wire:target="uploadPod,podFile">
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

    @forelse($jobs as $job)
        @php
            $paperwork = $job->documents->reject(fn($d) => in_array($d->category, \App\Models\JobDocument::pettyCashCategories(), true));
            $pettyCash = $job->documents->filter(fn($d) => in_array($d->category, \App\Models\JobDocument::pettyCashCategories(), true));
            $isImage = fn($d) => str_starts_with((string) $d->mime_type, 'image/');
        @endphp

        <div class="mb-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            {{-- Card header: job summary + counts --}}
            <div class="flex items-start justify-between gap-3 px-5 py-3 border-b border-gray-100 bg-gradient-to-b from-white to-gray-50">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.orders.show', $job->id) }}"
                           class="text-sm font-semibold text-blue-700 hover:text-blue-800">
                            {{ $job->job_number }}
                        </a>
                        <x-status-badge :status="$job->status" />
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500 truncate">
                        {{ $job->company?->name ?? '—' }}
                        @if($job->registration)
                            &middot; <span class="font-mono">{{ $job->registration }}</span>
                        @endif
                        @if($job->vin)
                            &middot; <span class="font-mono">VIN …{{ substr($job->vin, -8) }}</span>
                        @endif
                    </p>
                </div>
                <div class="shrink-0 text-right text-xs text-gray-500">
                    <p><span class="font-semibold text-gray-700">{{ $job->documents->count() }}</span> document{{ $job->documents->count() === 1 ? '' : 's' }}</p>
                    <p class="mt-0.5">Updated {{ $job->updated_at->diffForHumans() }}</p>
                </div>
            </div>

            {{-- Paperwork + photos thumbnail strip. Each card gets a position
                 tag badge in the top-left corner so front/rear/left/right/
                 dashboard/data plate are immediately obvious. --}}
            @if($paperwork->isNotEmpty())
                <div class="px-5 py-4">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                        @foreach($paperwork as $doc)
                            @can('view', $doc)
                                <a href="{{ route('documents.view', $doc) }}" target="_blank" rel="noopener"
                                   class="group relative block rounded-lg border border-gray-200 bg-gray-50 overflow-hidden hover:border-blue-400 hover:shadow-sm transition">
                                    {{-- Thumbnail --}}
                                    @if($isImage($doc))
                                        <div class="aspect-square overflow-hidden bg-gray-100">
                                            <img src="{{ route('documents.view', $doc) }}"
                                                 alt="{{ $doc->positionLabel() }}"
                                                 class="h-full w-full object-cover group-hover:scale-105 transition-transform"
                                                 loading="lazy">
                                        </div>
                                    @else
                                        <div class="aspect-square flex items-center justify-center bg-gray-100 text-gray-400">
                                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                        </div>
                                    @endif

                                    {{-- Position tag badge (top-left) --}}
                                    <span class="absolute top-1.5 left-1.5 inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold leading-none {{ $doc->positionBadgeClasses() }}">
                                        {{ $doc->positionLabel() }}
                                    </span>

                                    {{-- Meta footer --}}
                                    <div class="px-2 py-1.5 text-[10px] bg-white border-t border-gray-100">
                                        <p class="text-gray-500 truncate">
                                            @if($doc->captured_at)
                                                {{ $doc->captured_at->format('d M H:i') }}
                                            @else
                                                {{ $doc->created_at->format('d M H:i') }}
                                            @endif
                                        </p>
                                        @if($doc->uploadedBy)
                                            <p class="text-gray-400 truncate" title="{{ $doc->uploadedBy->name }}">{{ $doc->uploadedBy->name }}</p>
                                        @endif
                                    </div>
                                </a>
                            @endcan
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Petty cash footer — ops-only. Hidden entirely from non-internal
                 users by the policy gate, and additionally suppressed here so
                 the dealer/OEM view doesn't render an empty container. --}}
            @if($pettyCash->isNotEmpty() && (auth()->user()->isInternal() || auth()->user()->belongsToPlatformOwner()))
                <div class="px-5 py-3 border-t border-gray-100 bg-amber-50/30">
                    <div class="flex items-center gap-2 mb-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-800">Driver expenses</h4>
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 border border-amber-200">
                            Ops only
                        </span>
                    </div>
                    <ul class="flex flex-wrap gap-2">
                        @foreach($pettyCash as $doc)
                            @can('view', $doc)
                                <li>
                                    <a href="{{ route('documents.view', $doc) }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-1.5 rounded-md border bg-white px-2 py-1 text-xs hover:border-amber-400 {{ $doc->positionBadgeClasses() }}">
                                        <span class="font-semibold">{{ $doc->positionLabel() }}</span>
                                        <span class="text-gray-500">&middot;</span>
                                        <span class="text-gray-500">{{ $doc->captured_at?->format('d M') ?? $doc->created_at->format('d M') }}</span>
                                    </a>
                                </li>
                            @endcan
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-200 bg-white px-6 py-16 text-center">
            <p class="text-sm text-gray-500">No documents match these filters.</p>
        </div>
    @endforelse

    <div class="mt-4">
        {{ $jobs->links() }}
    </div>
</div>
