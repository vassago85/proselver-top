<div class="min-w-0 flex-1">
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.orders.show', $job->id) }}"
           class="text-sm font-semibold text-blue-700 hover:text-blue-800"
           @if($isStale) onclick="event.stopPropagation()" @endif>
            {{ $job->job_number }}
        </a>
        <x-status-badge :status="$job->status" />
        @if($isStale)
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500">
                Older than {{ $recentDays }} days
            </span>
        @endif
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
<div class="flex shrink-0 items-start gap-3 text-right text-xs text-gray-500">
    <div>
        <p><span class="font-semibold text-gray-700">{{ $job->documents->count() }}</span> document{{ $job->documents->count() === 1 ? '' : 's' }}</p>
        <p class="mt-0.5">Updated {{ $job->updated_at->diffForHumans() }}</p>
    </div>
    @if($isStale)
        <svg class="mt-0.5 h-4 w-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6"/>
        </svg>
    @endif
</div>
