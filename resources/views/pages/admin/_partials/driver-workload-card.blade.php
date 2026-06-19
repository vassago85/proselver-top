{{--
    Driver workload card.
    Props:
      $driver  – User with ->primary_job (Job|null), ->bucket_status, ->driverProfile
      $bucket  – 'on_road' | 'collecting' | 'idle'

    On-road + collecting cards link to the currently assigned order so a
    dispatcher can go straight to the detail view. Idle cards link to
    the driver's edit page so ops can tweak base location / trade plate
    while they have the tab open.
--}}
@php
    $palette = match($bucket) {
        'on_road'    => ['border' => 'border-blue-200',    'accent' => 'bg-blue-500',    'tint' => 'bg-blue-50/60',    'label' => 'On the road',  'labelCls' => 'bg-blue-100 text-blue-800'],
        'collecting' => ['border' => 'border-indigo-200',  'accent' => 'bg-indigo-500',  'tint' => 'bg-indigo-50/60',  'label' => 'Collecting',   'labelCls' => 'bg-indigo-100 text-indigo-800'],
        default      => ['border' => 'border-slate-200',   'accent' => 'bg-slate-400',   'tint' => 'bg-white',         'label' => 'Idle',         'labelCls' => 'bg-slate-100 text-slate-700'],
    };

    $profile = $driver->driverProfile;
    $job     = $driver->primary_job ?? null;
    $extraJobs = ($driver->assignedJobs ?? collect())->filter(fn($j) => $job && $j->id !== $job->id);

    $href = $job
        ? route('admin.orders.show', $job)
        : route('admin.drivers.edit', $driver);
@endphp

<a href="{{ $href }}"
   wire:key="driver-{{ $driver->id }}-{{ $bucket }}"
   class="group block rounded-xl border {{ $palette['border'] }} bg-white hover:shadow-md hover:-translate-y-0.5 transition-all duration-150 overflow-hidden">

    {{-- Top stripe --}}
    <div class="h-1 {{ $palette['accent'] }}"></div>

    {{-- Header: name + bucket chip --}}
    <div class="px-4 pt-3.5 pb-2 flex items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-900 truncate">{{ $driver->name }}</p>
            @if($profile?->cellphone || $driver->phone)
                <p class="text-[11px] text-slate-500 truncate">{{ $profile?->cellphone ?: $driver->phone }}</p>
            @endif
        </div>
        <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide {{ $palette['labelCls'] }}">
            {{ $palette['label'] }}
        </span>
    </div>

    {{-- Primary job block OR idle state --}}
    @if($job)
        <div class="px-4 py-2.5 {{ $palette['tint'] }} border-t border-b {{ $palette['border'] }}">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Job</span>
                        <span class="text-xs font-semibold text-blue-700">{{ $job->job_number ?? '—' }}</span>
                    </div>
                    <p class="text-xs font-medium text-slate-800 truncate">
                        {{ $job->brand?->name }} {{ $job->model_name ?: '' }}
                    </p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px]">
                        @if($job->vin)
                            <span class="font-mono text-slate-500">{{ $job->vin }}</span>
                        @endif
                        @if($job->registration)
                            <span class="inline-flex rounded bg-yellow-100 border border-yellow-300 px-1 py-0 font-mono font-semibold text-yellow-900">{{ $job->registration }}</span>
                        @endif
                    </div>
                </div>

                @if($bucket === 'on_road' && $profile?->tracker_id)
                    <div class="shrink-0 inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-1 font-mono text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200"
                         title="Live tracker">
                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>{{ $profile->tracker_id }}</span>
                    </div>
                @endif
            </div>

            {{-- Leg info --}}
            <div class="mt-2 flex items-center gap-1.5 text-[11px] text-slate-700">
                @if($bucket === 'collecting')
                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 shrink-0 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 1 8 8c0 4.5-6 12-8 12S4 14.5 4 10a8 8 0 0 1 8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span class="text-[10px] uppercase tracking-wide text-indigo-600 font-semibold">En route to</span>
                    <span class="truncate">{{ $job->pickupLocation?->company_name ?: ($job->pickup_address ?: '—') }}</span>
                    @if($job->pickupLocation?->city)
                        <span class="text-slate-400">·&nbsp;{{ $job->pickupLocation->city }}</span>
                    @endif
                @else
                    {{-- On-road: show full route --}}
                    <span class="truncate">{{ $job->pickupLocation?->company_name ?: '—' }}</span>
                    <svg viewBox="0 0 24 24" class="h-3 w-3 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    <span class="truncate">{{ $job->deliveryLocation?->company_name ?: '—' }}</span>
                @endif
            </div>

            @if($job->scheduled_date)
                <p class="mt-1 text-[10px] text-slate-500">
                    Scheduled {{ $job->scheduled_date->format('d M') }}
                </p>
            @endif
        </div>
    @else
        {{-- Idle card body --}}
        <div class="px-4 py-3 bg-slate-50/60 border-t border-b border-slate-100">
            <div class="flex items-center gap-2 text-xs">
                <svg viewBox="0 0 24 24" class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 7-8 12-8 12s-8-5-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span class="text-slate-500">
                    @if($profile?->base_location)
                        Available at <span class="font-medium text-slate-800">{{ $profile->base_location }}</span>
                    @else
                        Available &mdash; no base set
                    @endif
                </span>
            </div>
        </div>
    @endif

    {{-- Footer: plate, extra jobs --}}
    <div class="px-4 py-2 flex items-center justify-between gap-2 text-[10px]">
        <div class="flex items-center gap-2 text-slate-500">
            @if($profile?->trade_plate)
                <span class="inline-flex items-center gap-1" title="Trade plate">
                    <svg viewBox="0 0 24 24" class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="12" x="3" y="6" rx="2"/></svg>
                    <span class="font-mono font-semibold text-slate-700">{{ $profile->trade_plate }}</span>
                </span>
            @else
                <span class="italic text-slate-400">no trade plate</span>
            @endif
        </div>
        @if($extraJobs->isNotEmpty())
            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-slate-600 font-semibold"
                  title="{{ $extraJobs->count() }} other active {{ Str::plural('assignment', $extraJobs->count()) }}">
                +{{ $extraJobs->count() }} more
            </span>
        @endif
    </div>
</a>
