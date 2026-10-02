{{-- Photo / paperwork tiles for one job on the documents index. --}}
@if($docs->isNotEmpty())
    <div class="px-5 py-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach($docs as $doc)
                @can('view', $doc)
                    <a href="{{ route('documents.view', $doc) }}" target="_blank" rel="noopener"
                       class="group relative block rounded-lg border border-gray-200 bg-gray-50 overflow-hidden hover:border-blue-400 hover:shadow-sm transition">
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

                        <span class="absolute top-1.5 left-1.5 inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold leading-none {{ $doc->positionBadgeClasses() }}">
                            {{ $doc->positionLabel() }}
                        </span>

                        <div class="px-2 py-1.5 text-[10px] bg-white border-t border-gray-100">
                            <p class="text-gray-500 truncate">
                                {{ ($doc->captured_at ?? $doc->created_at)->format('d M H:i') }}
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
