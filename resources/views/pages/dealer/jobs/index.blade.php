<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';

    public function with(): array
    {
        $company = auth()->user()->company();
        if (!$company) return ['jobs' => collect()];

        $query = Job::where('company_id', $company->id)
            ->with(['fromHub:id,name', 'toHub:id,name', 'driver:id,name'])
            ->orderByDesc('created_at');
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%");
            });
        }
        return ['jobs' => $query->paginate(25)];
    }
    public function updatedSearch(): void { $this->resetPage(); }
};
?>
<div>
    <x-slot:header>Jobs</x-slot:header>
    <div class="flex justify-between items-center mb-6">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search..." class="w-full max-w-md rounded-lg border border-gray-300 px-4 py-2.5 text-sm">
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('dealer.jobs.show', $job) }}'">
                    <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">@if($job->isTransport()){{ $job->fromHub?->name }} → {{ $job->toHub?->name }}@else Yard Work @endif</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->driver?->name ?? '—' }}</td>
                    <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $job->scheduled_date?->format('d M Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No jobs found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>
</div>
