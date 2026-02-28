<?php
use App\Models\BookingChangeRequest;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $filterStatus = 'pending';
    public string $rejectNotes = '';
    public ?int $rejectingId = null;

    public function with(): array
    {
        $query = BookingChangeRequest::with(['job', 'requestedBy', 'reviewedBy'])
            ->latest();

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        return ['requests' => $query->paginate(25)];
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
        $this->rejectingId = null;
        $this->rejectNotes = '';
    }

    public function approve(int $id): void
    {
        $cr = BookingChangeRequest::findOrFail($id);

        if (!$cr->isPending()) {
            session()->flash('error', 'This request has already been processed.');
            return;
        }

        $job = $cr->job;
        $requested = $cr->requested_value;

        if (isset($requested['scheduled_date'])) {
            $job->scheduled_date = $requested['scheduled_date'];
        }
        if (isset($requested['scheduled_ready_time'])) {
            $job->scheduled_ready_time = $requested['scheduled_ready_time'];
        }
        $job->save();

        $cr->update([
            'status' => 'approved',
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        session()->flash('success', "Change request #{$cr->id} approved and job updated.");
    }

    public function confirmReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectNotes = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectNotes = '';
    }

    public function doReject(): void
    {
        $this->validate([
            'rejectNotes' => 'required|string|min:3|max:1000',
        ]);

        $cr = BookingChangeRequest::findOrFail($this->rejectingId);

        if (!$cr->isPending()) {
            session()->flash('error', 'This request has already been processed.');
            $this->rejectingId = null;
            return;
        }

        $cr->update([
            'status' => 'rejected',
            'review_notes' => $this->rejectNotes,
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->rejectingId = null;
        $this->rejectNotes = '';

        session()->flash('success', "Change request #{$cr->id} rejected.");
    }
};
?>
<div>
    <x-slot:header>Change Requests</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Status filter tabs --}}
    <div class="mb-6 flex flex-wrap gap-2">
        @foreach(['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
            <button wire:click="$set('filterStatus', '{{ $value }}')"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors
                    {{ $filterStatus === $value
                        ? 'bg-blue-600 text-white'
                        : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Current Date / Time</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested Date / Time</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submitted</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($requests as $cr)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $cr->job?->job_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $cr->job?->company?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $cr->requestedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $cr->current_value['scheduled_date'] ?? '—' }}
                            @if(!empty($cr->current_value['scheduled_ready_time']))
                                <span class="text-gray-400">{{ \Carbon\Carbon::parse($cr->current_value['scheduled_ready_time'])->format('H:i') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 font-medium">
                            {{ $cr->requested_value['scheduled_date'] ?? '—' }}
                            @if(!empty($cr->requested_value['scheduled_ready_time']))
                                <span class="text-gray-500">{{ \Carbon\Carbon::parse($cr->requested_value['scheduled_ready_time'])->format('H:i') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="{{ $cr->reason }}">{{ $cr->reason ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($cr->status === 'pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Pending</span>
                            @elseif($cr->status === 'approved')
                                <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700">Approved</span>
                            @elseif($cr->status === 'rejected')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700">Rejected</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $cr->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($cr->isPending())
                                <button wire:click="approve({{ $cr->id }})"
                                    wire:confirm="Approve this change request and update the job?"
                                    class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-500">
                                    Approve
                                </button>
                                <button wire:click="confirmReject({{ $cr->id }})"
                                    class="ml-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">
                                    Reject
                                </button>
                            @else
                                <span class="text-xs text-gray-400">
                                    {{ $cr->reviewedBy?->name }}
                                    @if($cr->reviewed_at)
                                        · {{ $cr->reviewed_at->format('d M H:i') }}
                                    @endif
                                </span>
                            @endif
                        </td>
                    </tr>

                    {{-- Inline reject form --}}
                    @if($rejectingId === $cr->id)
                        <tr class="bg-red-50/50">
                            <td colspan="9" class="px-4 py-3">
                                <div class="flex items-start gap-3 max-w-2xl">
                                    <div class="flex-1">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Rejection reason *</label>
                                        <textarea wire:model="rejectNotes" rows="2"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
                                            placeholder="Explain why this request is being rejected..."></textarea>
                                        @error('rejectNotes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    </div>
                                    <div class="flex gap-2 pt-6">
                                        <button wire:click="doReject"
                                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">
                                            Confirm Reject
                                        </button>
                                        <button wire:click="cancelReject"
                                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No change requests found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $requests->links() }}
    </div>
</div>
