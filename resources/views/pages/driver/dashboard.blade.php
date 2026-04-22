<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.driver')] class extends Component {
    public function with(): array
    {
        $active = Job::where('driver_user_id', auth()->id())
            ->whereIn('status', [
                Job::STATUS_ASSIGNED,
                Job::STATUS_IN_PROGRESS,
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
                Job::STATUS_COLLECTED,
                Job::STATUS_IN_TRANSIT,
                Job::STATUS_DELIVERED,
            ])
            ->with([
                'company:id,name',
                'pickupLocation:id,company_name,address',
                'deliveryLocation:id,company_name,address',
                'yardLocation:id,company_name',
            ])
            ->orderBy('scheduled_date')
            ->get();

        return [
            'jobs' => $active,
            'activeJob' => $active->first(),
        ];
    }
}; ?>

<div x-data="driverDashboard()" x-init="init()">
    <x-slot:header>My Jobs</x-slot:header>

    {{-- Install-to-home-screen prompt --}}
    <div x-show="installAvailable" x-cloak
         class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-3 flex items-center gap-3">
        <div class="h-9 w-9 rounded-lg bg-blue-600/10 flex items-center justify-center text-blue-700">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-blue-900">Install Trident Driver</p>
            <p class="text-[11px] text-blue-700">Adds an icon to your home screen. Works offline.</p>
        </div>
        <button type="button" @click="promptInstall()"
                class="rounded-lg bg-blue-600 text-white text-xs font-semibold px-3 py-2">
            Install
        </button>
    </div>

    {{-- Queue status bar --}}
    <div class="mt-3 rounded-xl bg-white border border-slate-200 p-3 flex items-center gap-3" x-data>
        <div class="h-9 w-9 rounded-lg flex items-center justify-center"
             :class="$store.driverQueue.pending > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
            <svg x-show="$store.driverQueue.pending > 0" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <svg x-show="$store.driverQueue.pending === 0" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-slate-900">
                <span x-show="$store.driverQueue.pending > 0" x-text="$store.driverQueue.pending + ' pending upload(s)'"></span>
                <span x-show="$store.driverQueue.pending === 0">All synced</span>
            </p>
            <p class="text-[11px] text-slate-500">
                <span x-show="$store.driverQueue.lastSyncAt">
                    Last sync <span x-text="new Date($store.driverQueue.lastSyncAt).toLocaleTimeString('en-ZA', { hour12: false })"></span>
                </span>
                <span x-show="!$store.driverQueue.lastSyncAt">Waiting for first sync</span>
            </p>
        </div>
        <button type="button" @click="$dispatch('open-queue')"
                class="rounded-lg border border-slate-200 text-slate-700 text-xs font-semibold px-3 py-2">
            View
        </button>
    </div>

    {{-- Job list --}}
    <div class="mt-4">
        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide mb-2">Active jobs</h2>

        <div class="space-y-2">
            @forelse($jobs as $job)
            <a href="{{ route('driver.job', $job) }}"
               class="block rounded-xl bg-white border border-slate-200 p-4 active:bg-slate-50">
                <div class="flex items-center justify-between gap-3 mb-1.5">
                    <span class="text-sm font-bold text-slate-900">{{ $job->job_number }}</span>
                    <x-status-badge :status="$job->status" size="xs" />
                </div>
                <p class="text-xs text-slate-500 truncate">{{ $job->company?->name }}</p>
                @if($job->isTransport())
                <p class="mt-1.5 text-sm font-semibold text-slate-900 truncate">
                    {{ $job->pickupLocation?->company_name }} → {{ $job->deliveryLocation?->company_name }}
                </p>
                @else
                <p class="mt-1.5 text-sm font-semibold text-slate-900 truncate">
                    Yard — {{ $job->yardLocation?->company_name }}
                </p>
                @endif
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                    <span>{{ $job->scheduled_date?->format('D, d M Y') }}{{ $job->scheduled_ready_time ? ' · ' . $job->scheduled_ready_time->format('H:i') : '' }}</span>
                    @if($job->vin)
                    <span class="font-mono">VIN {{ substr($job->vin, -8) }}</span>
                    @endif
                </div>
                @if(trim($job->customer_notes ?? '') !== '')
                <div class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-100 px-2 py-1 text-[11px] font-semibold text-amber-800">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                    Special instructions
                </div>
                @endif
            </a>
            @empty
            <div class="rounded-xl bg-white border border-slate-200 p-8 text-center text-sm text-slate-500">
                No assigned jobs right now.
            </div>
            @endforelse
        </div>
    </div>

    {{-- Quick petty cash capture (not tied to a specific step) --}}
    @if($activeJob)
    <section class="mt-5 rounded-xl bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wide">Quick slip</h3>
            <span class="text-[11px] text-slate-500">Attached to {{ $activeJob->job_number }}</span>
        </div>
        <div class="grid grid-cols-5 gap-2">
            @php
                $quick = [
                    'fuel_slip' => 'Fuel',
                    'food_slip' => 'Food',
                    'toll_slip' => 'Toll',
                    'parking_slip' => 'Parking',
                    'other' => 'Other',
                ];
            @endphp
            @foreach($quick as $category => $label)
            <label class="block rounded-lg border border-slate-200 cursor-pointer px-2 py-3 flex flex-col items-center gap-1 text-slate-600 active:bg-slate-50">
                <input type="file" accept="image/*" capture="environment"
                       class="sr-only"
                       @change="quickCapture($event, '{{ $category }}', {{ $activeJob->id }})">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <span class="text-[10px] font-semibold">{{ $label }}</span>
            </label>
            @endforeach
        </div>
    </section>
    @endif
</div>

<script>
    function driverDashboard() {
        return {
            installAvailable: false,

            init() {
                if (window.driverInstall && window.driverInstall.available()) {
                    this.installAvailable = true;
                }
                window.addEventListener('driver-install-available', () => (this.installAvailable = true));
                window.addEventListener('driver-install-dismissed', () => (this.installAvailable = false));
            },

            async promptInstall() {
                if (window.driverInstall) {
                    await window.driverInstall.prompt();
                }
            },

            async quickCapture(event, category, jobId) {
                const input = event.target;
                await window.driverCapture.fromInput({ input, jobId, category });
            },
        };
    }
</script>
