@if($docs->isNotEmpty())
    <div class="px-5 py-3 border-t border-gray-100 bg-amber-50/30">
        <div class="flex items-center gap-2 mb-2">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-800">Driver expenses</h4>
            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 border border-amber-200">
                Ops only
            </span>
        </div>
        <ul class="flex flex-wrap gap-2">
            @foreach($docs as $doc)
                @can('view', $doc)
                    <li>
                        <a href="{{ route('documents.view', $doc) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-md border bg-white px-2 py-1 text-xs hover:border-amber-400 {{ $doc->positionBadgeClasses() }}">
                            <span class="font-semibold">{{ $doc->positionLabel() }}</span>
                            <span class="text-gray-500">&middot;</span>
                            <span class="text-gray-500">{{ ($doc->captured_at ?? $doc->created_at)->format('d M') }}</span>
                        </a>
                    </li>
                @endcan
            @endforeach
        </ul>
    </div>
@endif
