<?php
use App\Models\Job;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        $company = auth()->user()->company();
        if (!$company || $job->company_id !== $company->id) {
            abort(403);
        }
        $this->job = $job->load(['driver:id,name,phone', 'pickupLocation:id,company_name', 'deliveryLocation:id,company_name', 'events', 'documents']);
    }
};
?>
<div>
    <x-slot:header>Job {{ $job->job_number ?? $job->uuid }}</x-slot:header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Job Details</h3>
                    <x-status-badge :status="$job->status" />
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-gray-500">Type</dt><dd class="font-medium">{{ $job->isTransport() ? 'Transport' : 'Yard Work' }}</dd></div>
                    <div><dt class="text-gray-500">Date</dt><dd class="font-medium">{{ $job->scheduled_date?->format('d M Y') }}</dd></div>
                    @if($job->isTransport())
                    <div><dt class="text-gray-500">Pickup</dt><dd class="font-medium">{{ $job->pickupLocation?->company_name }}</dd></div>
                    <div><dt class="text-gray-500">Delivery</dt><dd class="font-medium">{{ $job->deliveryLocation?->company_name }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">PO</dt><dd class="font-medium">{{ $job->po_number }}</dd></div>
                    <div><dt class="text-gray-500">Amount</dt><dd class="font-medium">R{{ number_format($job->po_amount ?? 0, 2) }}</dd></div>
                </dl>
            </div>

            @if($job->driver)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Driver</h3>
                <p class="text-sm"><strong>{{ $job->driver->name }}</strong></p>
                @if($job->driver->phone)<p class="text-sm text-gray-500">{{ $job->driver->phone }}</p>@endif
            </div>
            @endif

            @if($job->events->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
                <ol class="relative border-l border-gray-200 ml-3 space-y-4">
                    @foreach($job->events as $event)
                    <li class="ml-6">
                        <span class="absolute -left-2 flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 ring-4 ring-white"><span class="h-2 w-2 rounded-full bg-blue-600"></span></span>
                        <h4 class="text-sm font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</h4>
                        <time class="text-xs text-gray-500">{{ $event->event_at->format('d M Y H:i') }}</time>
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif
        </div>

        <div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Documents</h3>
                @if($job->documents->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach($job->documents as $doc)
                        <li class="text-sm">
                            <span class="font-medium">{{ $doc->original_filename }}</span>
                            <br><span class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $doc->category)) }}</span>
                        </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">No documents uploaded yet.</p>
                @endif
            </div>
        </div>
    </div>
</div>
