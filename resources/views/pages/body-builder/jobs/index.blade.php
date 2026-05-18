<?php

use App\Models\Job;
use App\Models\Location;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url(history: true, keep: true)]
    public string $bucket = 'inbound';

    #[Url(history: true, keep: true)]
    public string $q = '';

    #[Url(history: true, keep: true)]
    public ?int $dealerId = null;

    /**
     * Bucket → status whitelist. The BB worldview is:
     *   - Inbound  = vehicles being dispatched to us right now.
     *   - On site  = at our workshop, awaiting next move / collection.
     *   - Past     = anything else (cancelled, archived, collected).
     */
    protected function bucketStatuses(): array
    {
        return [
            'inbound' => [Job::STATUS_IN_TRANSIT, Job::STATUS_ASSIGNED, Job::STATUS_PLANNED],
            'on_site' => [Job::STATUS_DELIVERED],
            'past'    => [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED],
        ][$this->bucket] ?? [];
    }

    public function updating($name): void
    {
        if (in_array($name, ['bucket', 'q', 'dealerId'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $user = auth()->user();
        $company = $user?->company();

        if (! $company) {
            return ['company' => null, 'jobs' => collect(), 'dealerOptions' => [], 'counts' => [0,0,0]];
        }

        $myLocationIds = Location::where('company_id', $company->id)->pluck('id');

        $linkedDealerIds = $company->linkedDealers()
            ->wherePivot('is_active', true)
            ->pluck('companies.id');

        $base = Job::query()
            ->whereIn('delivery_location_id', $myLocationIds)
            ->whereIn('company_id', $linkedDealerIds);

        // Counts per bucket — drives the tab badges.  Cheap because
        // both filters are indexed (delivery_location_id +
        // company_id), and we cap the BB to a small set of dealers.
        $counts = [
            'inbound' => (clone $base)->whereIn('status', [Job::STATUS_IN_TRANSIT, Job::STATUS_ASSIGNED, Job::STATUS_PLANNED])->count(),
            'on_site' => (clone $base)->where('status', Job::STATUS_DELIVERED)->count(),
            'past'    => (clone $base)->whereIn('status', [Job::STATUS_COMPLETED, Job::STATUS_CANCELLED])->count(),
        ];

        $query = (clone $base)
            ->with(['company:id,name', 'pickupLocation:id,company_name,city', 'deliveryLocation:id,company_name,city', 'brand:id,name'])
            ->when($this->bucketStatuses(), fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->when($this->dealerId, fn ($q, $id) => $q->where('company_id', $id))
            ->when(trim($this->q) !== '', function ($q) {
                $needle = '%' . trim($this->q) . '%';
                $q->where(function ($qq) use ($needle) {
                    $qq->where('vin', 'like', $needle)
                        ->orWhere('registration', 'like', $needle)
                        ->orWhere('job_number', 'like', $needle)
                        ->orWhere('model_name', 'like', $needle);
                });
            })
            ->orderByDesc('updated_at');

        $jobs = $query->paginate(25);

        $dealerOptions = $company->linkedDealers()
            ->wherePivot('is_active', true)
            ->get(['companies.id', 'companies.name'])
            ->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])
            ->values()
            ->all();

        return compact('company', 'jobs', 'counts', 'dealerOptions');
    }
};
?>

<div>
    <x-slot:header>Vehicles</x-slot:header>

    @if(!$company)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
            No body-builder company linked to your account.
        </div>
    @else
        <div class="mb-4 flex flex-wrap gap-2 border-b border-slate-200">
            @foreach(['inbound' => 'Inbound', 'on_site' => 'On site', 'past' => 'Past'] as $bucketKey => $label)
                <button
                    type="button"
                    wire:click="$set('bucket', '{{ $bucketKey }}')"
                    class="-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition
                        {{ $bucket === $bucketKey
                            ? 'border-blue-600 text-blue-700'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
                >
                    {{ $label }}
                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-700">{{ $counts[$bucketKey] ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <input
                    type="search"
                    wire:model.live.debounce.400ms="q"
                    placeholder="Search VIN, registration, job number, model"
                    class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
            </div>
            <div>
                <x-searchable-select
                    wire:model.live="dealerId"
                    :options="$dealerOptions"
                    placeholder="All dealers"
                />
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            @if($jobs->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-slate-500">
                    Nothing in this bucket yet.
                </div>
            @else
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Job</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Vehicle</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">From dealer</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Delivery</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($jobs as $job)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-slate-900">{{ $job->job_number ?: '—' }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700">
                                    <div>{{ $job->brand?->name }} {{ $job->model_name }}</div>
                                    <div class="text-xs text-slate-500">
                                        @if($job->vin)VIN {{ $job->vin }}@endif
                                        @if($job->registration) · {{ $job->registration }}@endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-sm text-slate-700">{{ $job->company?->name }}</td>
                                <td class="px-4 py-2 text-sm text-slate-700">{{ $job->deliveryLocation?->company_name }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $job->status === Job::STATUS_DELIVERED ? 'bg-emerald-50 text-emerald-700' : ($job->status === Job::STATUS_CANCELLED ? 'bg-rose-50 text-rose-700' : 'bg-blue-50 text-blue-700') }}">
                                        {{ str_replace('_', ' ', $job->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('body-builder.jobs.show', $job) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">Open →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="mt-4">{{ $jobs->links() }}</div>
    @endif
</div>
