<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;
    public function with(): array
    {
        return ['jobs' => Job::where('status', Job::STATUS_READY_FOR_INVOICING)->with(['company:id,name'])->orderByDesc('completed_at')->paginate(25)];
    }
};
?>
<div>
    <x-slot:header>Invoices</x-slot:header>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
        <div class="text-sm text-emerald-900">
            <strong>Customer invoicing sheet</strong> — capture invoice numbers, amounts, extras and fuel per movement and export the OEM-shaped Excel.
        </div>
        <a href="{{ route('admin.reports.invoicing') }}"
           class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
            Open customer invoicing
        </a>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('admin.bookings.show', $job) }}'">
                    <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ $job->job_number }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $job->company?->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">R{{ number_format($job->total_sell_price, 2) }}</td>
                    <td class="px-6 py-4"><x-status-badge :status="$job->status" /></td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">No jobs ready for invoicing.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>
</div>
