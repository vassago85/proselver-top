@props([
    // Illuminate\\Support\\Collection<JobDocument>
    'documents',
    // Show the retention notice strip under the list. Defaults true —
    // dealers/customers need to know; internal pages can set to false.
    'showRetention' => true,
    // Hide petty-cash / other internal-only categories when rendering for
    // an external user. Defaults true; set false for /admin/* pages.
    'hideInternalOnly' => true,
    // Optional override copy above the list (e.g. "Vehicle documents").
    'title' => null,
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

    $grouped = $visible
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
@endphp

<div>
    @if($title)
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ $title }}</h3>
    @endif

    @if($visible->isEmpty())
        <p class="text-sm text-gray-500">No documents uploaded yet.</p>
    @else
        <div class="space-y-5">
            @foreach($grouped as $cat => $docs)
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">
                        {{ $categoryHeading($cat) }}
                        <span class="ml-1 text-gray-400">({{ $docs->count() }})</span>
                    </h4>

                    <ul class="space-y-2">
                        @foreach($docs as $doc)
                            <li class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 hover:bg-white hover:border-gray-300 px-3 py-2.5 transition-colors">
                                {{-- Thumbnail / icon --}}
                                <div class="shrink-0">
                                    @if($isImage($doc))
                                        <a href="{{ route('documents.view', $doc) }}" target="_blank" title="Preview {{ $doc->positionLabel() }}">
                                            <img
                                                src="{{ route('documents.view', $doc) }}"
                                                alt="{{ $doc->positionLabel() }}"
                                                class="h-11 w-11 rounded-md object-cover border border-gray-200"
                                                loading="lazy">
                                        </a>
                                    @else
                                        <div class="flex h-11 w-11 items-center justify-center rounded-md bg-white border border-gray-200 text-gray-500">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                <polyline points="14 2 14 8 20 8"/>
                                            </svg>
                                        </div>
                                    @endif
                                </div>

                                {{-- Label / meta --}}
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-medium text-gray-900 truncate">
                                            {{ $doc->positionLabel() }}
                                        </p>
                                        @if($doc->category === JobDocument::CATEGORY_DAMAGE_PHOTO)
                                            <span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">
                                                Damage
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ $kb($doc->size_bytes) }}
                                        @if($doc->captured_at ?? $doc->created_at)
                                            &middot; {{ ($doc->captured_at ?? $doc->created_at)->format('d M Y · H:i') }}
                                        @endif
                                        @if($doc->uploadedBy?->name ?? null)
                                            &middot; {{ $doc->uploadedBy->name }}
                                        @endif
                                    </p>
                                </div>

                                {{-- Actions --}}
                                <div class="flex items-center gap-1 shrink-0">
                                    <a href="{{ route('documents.view', $doc) }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="inline-flex items-center gap-1 rounded-md bg-white border border-gray-200 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                       title="Open in new tab">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View
                                    </a>
                                    <a href="{{ route('documents.view', $doc) }}?download=1"
                                       class="inline-flex items-center gap-1 rounded-md bg-blue-50 border border-blue-200 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                       title="Save to your device">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                        Download
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    @if($showRetention && $visible->isNotEmpty())
        <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
            <p class="flex gap-2 text-xs leading-relaxed text-amber-800">
                <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <span><strong>Retention:</strong> {{ DocumentRetention::noticeText() }}</span>
            </p>
        </div>
    @endif
</div>
