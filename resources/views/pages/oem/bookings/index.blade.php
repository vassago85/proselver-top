<?php
use App\Models\Job;
use App\Models\PurchaseOrder;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination, WithFileUploads;

    public string $search = '';
    public string $viewMode = 'all';

    // Quick PO upload
    public ?int $poJobId = null;
    public string $poNumber = '';
    public string $poAmount = '';
    public string $poLabel = '';
    public $poFile;

    public function with(): array
    {
        $company = auth()->user()->company();
        if (!$company) return ['jobs' => collect()];

        $query = Job::where('company_id', $company->id)
            ->with([
                'pickupLocation:id,company_name',
                'deliveryLocation:id,company_name',
                'driver:id,name',
                'createdBy:id,name',
                'brand:id,name',
                'purchaseOrders:id,job_id,po_number,po_amount,is_verified',
            ])
            ->orderByDesc('created_at');

        if ($this->viewMode === 'mine') {
            $query->where('created_by_user_id', auth()->id());
        }
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('job_number', 'ilike', "%{$this->search}%")
                    ->orWhere('vin', 'ilike', "%{$this->search}%");
            });
        }
        return ['jobs' => $query->paginate(25)];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedViewMode(): void { $this->resetPage(); }

    public function openPoUpload(int $jobId): void
    {
        $this->poJobId = $jobId;
        $this->poNumber = '';
        $this->poAmount = '';
        $this->poLabel = '';
        $this->poFile = null;
    }

    public function closePoUpload(): void
    {
        $this->poJobId = null;
        $this->poNumber = '';
        $this->poAmount = '';
        $this->poLabel = '';
        $this->poFile = null;
    }

    public function uploadPo(): void
    {
        $this->validate([
            'poNumber' => 'required|string|max:50',
            'poAmount' => 'required|numeric|min:0.01',
            'poFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $job = Job::where('company_id', auth()->user()->company()?->id)->findOrFail($this->poJobId);

        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $this->poFile->store('jobs/' . $job->uuid . '/po', $disk);

        PurchaseOrder::create([
            'job_id' => $job->id,
            'po_number' => $this->poNumber,
            'po_amount' => $this->poAmount,
            'label' => $this->poLabel ?: null,
            'document_disk' => $disk,
            'document_path' => $path,
            'original_filename' => $this->poFile->getClientOriginalName(),
            'uploaded_by_user_id' => auth()->id(),
        ]);

        $this->closePoUpload();
        session()->flash('success', 'Purchase order uploaded.');
    }
};
?>
<div>
    <x-slot:header>Bookings</x-slot:header>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="flex justify-between items-center mb-6">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by job # or VIN..." class="w-full max-w-md rounded-lg border border-gray-300 px-4 py-2.5 text-sm">
        @if(auth()->user()->hasPermission('submit_booking'))
        <a href="{{ route('oem.bookings.create') }}" class="ml-4 inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 whitespace-nowrap">+ New Booking</a>
        @endif
    </div>
    <div class="flex gap-2 mb-4">
        <button wire:click="$set('viewMode', 'all')" class="{{ $viewMode === 'all' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 border border-gray-300' }} rounded-lg px-4 py-2 text-sm font-medium">All Deliveries</button>
        <button wire:click="$set('viewMode', 'mine')" class="{{ $viewMode === 'mine' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 border border-gray-300' }} rounded-lg px-4 py-2 text-sm font-medium">My Deliveries</button>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date / Time</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($jobs as $job)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('oem.bookings.show', $job) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ $job->job_number ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        @if($job->brand || $job->model_name)
                            <span class="text-gray-900">{{ $job->brand?->name }} {{ $job->model_name }}</span><br>
                        @endif
                        @if($job->vin)
                            <span class="font-mono text-xs text-gray-500 uppercase">{{ strtoupper($job->vin) }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                        @if($job->isTransport())
                            {{ $job->pickupLocation?->company_name }} &rarr; {{ $job->deliveryLocation?->company_name }}
                        @else
                            Yard Work
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm">
                        @if($job->purchaseOrders->isNotEmpty())
                            <span class="text-gray-900">{{ $job->purchaseOrders->first()->po_number }}</span>
                            @if($job->purchaseOrders->count() > 1)
                                <span class="text-xs text-gray-400">(+{{ $job->purchaseOrders->count() - 1 }})</span>
                            @endif
                            <br><span class="text-xs text-gray-400">R{{ number_format($job->purchaseOrders->sum('po_amount'), 2) }}</span>
                        @else
                            <span class="text-xs text-amber-600">No PO</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-status-badge :status="$job->status" /></td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $job->createdBy?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                        {{ $job->scheduled_date?->format('d M Y') ?? '—' }}
                        @if($job->scheduled_ready_time)
                            <br><span class="text-xs text-gray-400">{{ $job->scheduled_ready_time->format('H:i') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('oem.bookings.show', $job) }}" class="rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100" title="View Details">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            </a>
                            <button wire:click="openPoUpload({{ $job->id }})" class="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100" title="Upload PO">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">No bookings yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>

    {{-- Quick PO Upload Modal --}}
    @if($poJobId)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closePoUpload">
        <div class="w-full max-w-md mx-4 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900">Upload Purchase Order</h3>
                <button wire:click="closePoUpload" class="rounded-full p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Number *</label>
                    <input wire:model="poNumber" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('poNumber')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">PO Amount (ZAR) *</label>
                    <input wire:model="poAmount" type="number" step="0.01" min="0.01" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('poAmount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Label</label>
                    <input wire:model="poLabel" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g. Transport, Fuel">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Document *</label>
                    <input wire:model="poFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    @error('poFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex gap-3 justify-end pt-2">
                    <button wire:click="closePoUpload" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button wire:click="uploadPo" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="uploadPo">Upload PO</span>
                        <span wire:loading wire:target="uploadPo">Uploading...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
