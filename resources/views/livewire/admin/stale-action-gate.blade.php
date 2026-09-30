{{--
    Stale-action modal (extracted 2026-09-30).

    Rendered by App\Livewire\Admin\StaleActionGate, mounted once by the
    app layout for every internal-tier user.  Previously lived inside
    the Operations dashboard's Livewire view; hiding the ops dash from
    ops / dispatch / super_admin broke the enforcement flow for the
    exact roles it was designed for, so the modal + its state moved to
    a standalone component that renders on every internal admin page
    (except admin.orders.show, where the deep-links out of the modal
    land).

    Fixed-position overlay sits at z-[80] so it clocks over the sidebar
    (z-40) and the sticky header (z-30) but leaves the impersonation
    banner (z-[100]) reachable.
--}}
<div>
    @if($showStaleGate && $stale['total'] > 0)
        @php
            $ownedCount  = $stale['owned']->count();
            $othersCount = $stale['others']->count();
            $worstDays   = $stale['worst_days'];
            $canClose    = $ownedCount === 0;
        @endphp
        <div class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 sm:p-8"
             role="dialog"
             aria-modal="true"
             aria-labelledby="stale-gate-title">
            <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5">
                <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        <p class="text-[10.5px] font-semibold uppercase tracking-[.14em] text-rose-600">Attention needed</p>
                        <h2 id="stale-gate-title" class="mt-1 text-[17px] font-semibold text-slate-900">
                            {{ $stale['total'] }} {{ Str::plural('job', $stale['total']) }} with no update for a week
                        </h2>
                        <p class="mt-1 text-[12.5px] text-slate-500">
                            Worst is <span class="font-semibold text-slate-700 tabular-nums">{{ $worstDays }}d</span> in the same stage.
                            @if($ownedCount > 0)
                                You created {{ $ownedCount }} of these &mdash; action or wait longer with a note before continuing.
                            @else
                                Nothing you created is stuck; you can close this and get on with the day.
                            @endif
                        </p>
                    </div>
                    @if($canClose)
                        <button type="button"
                                wire:click="dismissStaleGate"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 hover:border-slate-300">
                            Close
                        </button>
                    @else
                        <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-[11px] font-medium text-slate-400"
                              title="Action your own rows below to close.">
                            Close
                        </span>
                    @endif
                </header>

                <div class="max-h-[70vh] overflow-y-auto divide-y divide-slate-100">
                    @if($ownedCount > 0)
                        <section class="px-5 py-4">
                            <h3 class="text-[11px] font-semibold uppercase tracking-[.14em] text-slate-500">
                                You created ({{ $ownedCount }}) &mdash; must action
                            </h3>
                            <ul class="mt-3 space-y-3">
                                @foreach($stale['owned'] as $job)
                                    <li class="rounded-xl border border-rose-100 bg-rose-50/40 px-3 py-3">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="text-[13px] font-semibold text-slate-900">
                                                    {{ $job->job_number ?? '#'.$job->id }}
                                                    <span class="ml-1 font-normal text-slate-500">
                                                        {{ $job->company?->name }}
                                                        @if($job->brand?->name)
                                                            &middot; {{ $job->brand->name }}
                                                            @if($job->model_name) {{ $job->model_name }} @endif
                                                        @endif
                                                    </span>
                                                </p>
                                                <p class="mt-0.5 text-[11px] text-slate-500">
                                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                        {{ $job->stage_label }}
                                                    </span>
                                                    <span class="ml-1">&middot; {{ $job->days_in_stage }}d in stage</span>
                                                </p>
                                            </div>
                                            <a href="{{ route('admin.orders.show', $job) }}"
                                               class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[11.5px] font-semibold text-slate-800 hover:border-slate-400">
                                                Open to cancel / deliver &rarr;
                                            </a>
                                        </div>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto] sm:items-start">
                                            <div>
                                                <label class="block text-[10.5px] font-semibold uppercase tracking-[.12em] text-slate-500">
                                                    Reason to wait longer
                                                </label>
                                                <textarea wire:model.defer="staleComments.{{ $job->id }}"
                                                          rows="2"
                                                          placeholder="e.g. Customer confirmed collection Monday"
                                                          class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[12px] text-slate-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"></textarea>
                                                @error('staleComment.' . $job->id)
                                                    <p class="mt-1 text-[11px] font-medium text-rose-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <button type="button"
                                                    wire:click="snoozeStale({{ $job->id }})"
                                                    class="self-end rounded-md bg-slate-900 px-3 py-1.5 text-[11.5px] font-semibold text-white hover:bg-slate-800">
                                                Wait longer (7 days)
                                            </button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if($othersCount > 0)
                        <section class="px-5 py-4">
                            <h3 class="text-[11px] font-semibold uppercase tracking-[.14em] text-slate-500">
                                Others ({{ $othersCount }}) &mdash; for visibility
                            </h3>
                            <ul class="mt-3 space-y-2">
                                @foreach($stale['others'] as $job)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 bg-white px-3 py-2">
                                        <div class="min-w-0">
                                            <p class="text-[12.5px] font-semibold text-slate-900">
                                                {{ $job->job_number ?? '#'.$job->id }}
                                                <span class="ml-1 font-normal text-slate-500">
                                                    {{ $job->company?->name }}
                                                    @if($job->brand?->name)
                                                        &middot; {{ $job->brand->name }}
                                                    @endif
                                                </span>
                                            </p>
                                            <p class="mt-0.5 text-[10.5px] text-slate-500">
                                                <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {{ $job->stage_label }}
                                                </span>
                                                <span class="ml-1">&middot; {{ $job->days_in_stage }}d in stage</span>
                                                @if($job->createdBy?->name)
                                                    &middot; created by {{ $job->createdBy->name }}
                                                @endif
                                            </p>
                                        </div>
                                        <a href="{{ route('admin.orders.show', $job) }}"
                                           class="shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:border-slate-300">
                                            Open &rarr;
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>

                <footer class="border-t border-slate-100 bg-slate-50/50 px-5 py-3">
                    <p class="text-[11px] text-slate-500">
                        A real status move automatically clears any Wait-longer snooze &mdash; this list only shows rows genuinely stalled in the current stage.
                    </p>
                </footer>
            </div>
        </div>
    @endif
</div>
