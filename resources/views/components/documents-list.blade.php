@props([
    // Illuminate\\Support\\Collection<JobDocument>
    'documents',
    // Show the retention notice strip under the list. Defaults true —
    // dealers/customers need to know; internal pages can set to false.
    'showRetention' => true,
    // Hide petty-cash / other internal-only categories when rendering for
    // an external user. Defaults true; set false for /admin/* pages.
    'hideInternalOnly' => true,
    // Heading shown on the collapsible summary row.
    'title' => 'Documents',
    // Default "recent" window (days). Anything captured inside this window
    // shows as soon as the user opens the panel; older items are hidden
    // behind a "Show older" toggle to keep the default view tidy.
    'recentDays' => 3,
    // Open the panel by default. Off by default so the page doesn't get
    // clobbered by a stack of thumbnails; the user clicks to reveal.
    'open' => false,
])

@php
    use App\Models\JobDocument;
    use App\Support\DocumentRetention;

    $internalOnly = [
        JobDocument::CATEGORY_FUEL_SLIP,
        JobDocument::CATEGORY_FOOD_SLIP,
        JobDocument::CATEGORY_TOLL_SLIP,
        JobDocument::CATEGORY_PARKING_SLIP,
        JobDocument::CATEGORY_OTHER,
    ];

    $visible = collect($documents)
        ->when($hideInternalOnly, fn ($c) => $c->reject(fn ($d) => in_array($d->category, $internalOnly, true)))
        ->values();

    // Recent vs older split — based on captured_at if present (the moment
    // the driver took the photo on the phone), falling back to created_at
    // for server-generated paperwork like collection notes.
    $cutoff = now()->subDays((int) $recentDays);
    $whenFor = fn ($d) => $d->captured_at ?? $d->created_at;

    $recentDocs = $visible->filter(fn ($d) => $whenFor($d) && $whenFor($d)->gte($cutoff))->values();
    $olderDocs  = $visible->filter(fn ($d) => !$whenFor($d) || $whenFor($d)->lt($cutoff))->values();

    // Priority order for readability — paperwork on top, photos after.
    $categoryOrder = [
        JobDocument::CATEGORY_COLLECTION_NOTE => 1,
        JobDocument::CATEGORY_POD             => 2,
        JobDocument::CATEGORY_PO              => 3,
        JobDocument::CATEGORY_INVOICE         => 4,
        JobDocument::CATEGORY_DATA_PLATE      => 5,
        JobDocument::CATEGORY_DASHBOARD       => 6,
        JobDocument::CATEGORY_DAMAGE_PHOTO    => 7,
        JobDocument::CATEGORY_PHOTO           => 8,
    ];

    $groupByCategory = fn ($collection) => $collection
        ->sortBy(fn ($d) => $categoryOrder[$d->category] ?? 99)
        ->groupBy('category');

    $kb = fn ($bytes) => $bytes ? number_format(max(1, round(((int) $bytes) / 1024)), 0) . ' KB' : '—';
    $isImage = fn ($d) => str_starts_with((string) $d->mime_type, 'image/');

    $categoryHeading = fn (string $cat): string => match ($cat) {
        JobDocument::CATEGORY_COLLECTION_NOTE => 'Collection note',
        JobDocument::CATEGORY_POD             => 'Proof of delivery',
        JobDocument::CATEGORY_PO              => 'Purchase order',
        JobDocument::CATEGORY_INVOICE         => 'Invoice',
        JobDocument::CATEGORY_DATA_PLATE      => 'Data plate photos',
        JobDocument::CATEGORY_DASHBOARD       => 'Dashboard photos',
        JobDocument::CATEGORY_DAMAGE_PHOTO    => 'Damage photos',
        JobDocument::CATEGORY_PHOTO           => 'Vehicle photos',
        JobDocument::CATEGORY_FUEL_SLIP       => 'Fuel slips',
        JobDocument::CATEGORY_FOOD_SLIP       => 'Food slips',
        JobDocument::CATEGORY_TOLL_SLIP       => 'Toll slips',
        JobDocument::CATEGORY_PARKING_SLIP    => 'Parking slips',
        default                               => ucfirst(str_replace('_', ' ', $cat)),
    };

    $latest = $visible->map($whenFor)->filter()->sort()->last();
@endphp

<div x-data="{ showOlder: false }">
    <style>
        .documents-summary::-webkit-details-marker { display: none; }
        .documents-summary { list-style: none; }
    </style>
    <details
        @class(['group'])
        @if($open) open @endif>
        <summary class="documents-summary flex cursor-pointer items-center justify-between gap-3 select-none py-1 -mx-1 px-1 rounded hover:bg-gray-50">
            <div class="min-w-0">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    {{ $title }}
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                        {{ $visible->count() }}
                    </span>
                </h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    @if($visible->isEmpty())
                        No documents uploaded yet.
                    @else
                        {{ $recentDocs->count() }} in the last {{ $recentDays }} {{ $recentDays === 1 ? 'day' : 'days' }}
                        @if($olderDocs->isNotEmpty())
                            &middot; {{ $olderDocs->count() }} older
                        @endif
                        @if($latest)
                            &middot; latest {{ $latest->diffForHumans() }}
                        @endif
                    @endif
                </p>
            </div>
            <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180"
                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </summary>

        @if($visible->isNotEmpty())
            <div class="mt-4">
                {{-- Recent section --}}
                @if($recentDocs->isNotEmpty())
                    <div class="space-y-5">
                        @foreach($groupByCategory($recentDocs) as $cat => $docs)
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">
                                    {{ $categoryHeading($cat) }}
                                    <span class="ml-1 text-gray-400">({{ $docs->count() }})</span>
                                </h4>
                                <ul class="space-y-2">
                                    @foreach($docs as $doc)
                                        <x-documents-list-row :doc="$doc" :isImage="$isImage($doc)" :kb="$kb($doc->size_bytes)" />
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500">
                        Nothing uploaded in the last {{ $recentDays }} {{ $recentDays === 1 ? 'day' : 'days' }}.
                        @if($olderDocs->isNotEmpty())
                            Use "Show older" below to see the archive.
                        @endif
                    </p>
                @endif

                {{-- Older toggle --}}
                @if($olderDocs->isNotEmpty())
                    <div class="mt-5 pt-4 border-t border-dashed border-gray-200">
                        <button type="button"
                                @click="showOlder = !showOlder"
                                class="inline-flex items-center gap-2 text-xs font-medium text-gray-600 hover:text-gray-900">
                            <svg class="h-3.5 w-3.5 transition-transform"
                                 :class="showOlder ? 'rotate-180' : ''"
                                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                 stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            <span x-show="!showOlder">Show older documents ({{ $olderDocs->count() }})</span>
                            <span x-show="showOlder" x-cloak>Hide older documents</span>
                        </button>

                        <div x-show="showOlder" x-cloak
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="mt-4 space-y-5">
                            @foreach($groupByCategory($olderDocs) as $cat => $docs)
                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">
                                        {{ $categoryHeading($cat) }}
                                        <span class="ml-1 text-gray-400">({{ $docs->count() }})</span>
                                    </h4>
                                    <ul class="space-y-2">
                                        @foreach($docs as $doc)
                                            <x-documents-list-row :doc="$doc" :isImage="$isImage($doc)" :kb="$kb($doc->size_bytes)" />
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($showRetention)
                    <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
                        <p class="flex gap-2 text-xs leading-relaxed text-amber-800">
                            <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                            <span><strong>Retention:</strong> {{ DocumentRetention::noticeText() }}</span>
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </details>
</div>
