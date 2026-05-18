<?php

use App\Models\MovementRequest;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url(history: true, keep: true)]
    public string $status = '';

    public function updating($name): void
    {
        if ($name === 'status') {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $company = auth()->user()?->company();
        if (! $company) {
            return ['requests' => collect(), 'counts' => []];
        }

        $base = MovementRequest::query()
            ->where('requesting_company_id', $company->id);

        $counts = [
            ''          => (clone $base)->count(),
            'pending'   => (clone $base)->where('status', MovementRequest::STATUS_PENDING)->count(),
            'approved'  => (clone $base)->where('status', MovementRequest::STATUS_APPROVED)->count(),
            'rejected'  => (clone $base)->where('status', MovementRequest::STATUS_REJECTED)->count(),
            'cancelled' => (clone $base)->where('status', MovementRequest::STATUS_CANCELLED)->count(),
        ];

        $requests = (clone $base)
            ->with([
                'targetCompany:id,name',
                'sourceJob:id,job_number,vin,registration',
                'createdJob:id,job_number',
                'pickupLocation:id,company_name,city',
                'deliveryLocation:id,company_name,city',
            ])
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(25);

        return compact('requests', 'counts');
    }
};
?>

<div>
    <x-slot:header>My Requests</x-slot:header>

    <div class="mb-4 flex flex-wrap gap-2 border-b border-slate-200">
        @foreach(['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $key => $label)
            <button
                type="button"
                wire:click="$set('status', '{{ $key }}')"
                class="-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition
                    {{ $status === $key
                        ? 'border-blue-600 text-blue-700'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
            >
                {{ $label }}
                <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-700">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        @if($requests->isEmpty())
            <div class="px-6 py-10 text-center text-sm text-slate-500">
                No requests yet. Open a vehicle and click "Request next fitment" or "Request collection" to raise one.
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Request</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Vehicle</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Dealer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Raised</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($requests as $req)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-2 text-sm">
                                <div class="font-medium text-slate-900">{{ $req->typeLabel() }}</div>
                                <div class="text-xs text-slate-500">#{{ substr($req->uuid, 0, 8) }}</div>
                            </td>
                            <td class="px-4 py-2 text-sm text-slate-700">
                                @if($req->sourceJob)
                                    {{ $req->sourceJob->job_number }}
                                    <div class="text-xs text-slate-500">{{ $req->sourceJob->vin ?: $req->vin ?: '—' }}</div>
                                @else
                                    {{ $req->vin ?: '—' }}
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-slate-700">{{ $req->targetCompany?->name ?: '—' }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $req->status === 'approved' ? 'bg-emerald-50 text-emerald-700'
                                      : ($req->status === 'rejected' ? 'bg-rose-50 text-rose-700'
                                      : ($req->status === 'cancelled' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700')) }}">
                                    {{ $req->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-slate-700">{{ optional($req->created_at)->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('body-builder.requests.show', $req->uuid) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">Open →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-4">{{ $requests->links() }}</div>
</div>
