@php
    use App\Models\JobDocument;
    // $doc, $isImage, $kb provided by the caller
@endphp
<li class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 hover:bg-white hover:border-gray-300 px-3 py-2.5 transition-colors">
    <div class="shrink-0">
        @if($isImage)
            <a href="{{ route('documents.view', $doc) }}" target="_blank" rel="noopener" title="Preview {{ $doc->positionLabel() }}">
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
            {{ $kb }}
            @if($doc->captured_at ?? $doc->created_at)
                &middot; {{ ($doc->captured_at ?? $doc->created_at)->format('d M Y · H:i') }}
            @endif
            @if($doc->uploadedBy?->name ?? null)
                &middot; {{ $doc->uploadedBy->name }}
            @endif
        </p>
    </div>

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
