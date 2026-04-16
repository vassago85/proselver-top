<?php

use App\Models\Job;
use App\Models\Company;
use App\Models\JobDocument;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?Company $company = null;

    #[Url]
    public string $categoryFilter = '';

    public function mount(): void
    {
        $this->company = auth()->user()->company();
        abort_unless($this->company, 403, 'No company associated with your account.');
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = JobDocument::whereHas('job', fn ($q) => $q->where('company_id', $this->company->id))
            ->with(['job:id,job_number'])
            ->latest('created_at');

        if ($this->categoryFilter) {
            $query->where('category', $this->categoryFilter);
        }

        return [
            'documents' => $query->paginate(15),
        ];
    }
};

?>

<div>
    <x-slot:header>Documents</x-slot:header>

    {{-- Filters --}}
    <div class="mb-6">
        <select wire:model.live="categoryFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Categories</option>
            <option value="purchase_order">Purchase Order</option>
            <option value="collection_note">Collection Note</option>
            <option value="proof_of_delivery">Proof of Delivery</option>
            <option value="photo">Photo</option>
            <option value="other">Other</option>
        </select>
    </div>

    {{-- Documents Table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Filename</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uploaded</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($documents as $doc)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('customer.orders.show', $doc->job_id) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ $doc->job?->job_number ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $catColors = [
                                'purchase_order' => 'bg-blue-100 text-blue-700',
                                'collection_note' => 'bg-purple-100 text-purple-700',
                                'proof_of_delivery' => 'bg-green-100 text-green-700',
                                'photo' => 'bg-yellow-100 text-yellow-700',
                                'invoice' => 'bg-gray-100 text-gray-700',
                                'fuel_slip' => 'bg-orange-100 text-orange-700',
                                'other' => 'bg-gray-100 text-gray-600',
                            ];
                            $catColor = $catColors[$doc->category] ?? 'bg-gray-100 text-gray-600';
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $catColor }}">
                            {{ ucfirst(str_replace('_', ' ', $doc->category)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $doc->original_filename }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $doc->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($doc->path)
                            <a href="{{ Storage::url($doc->path) }}" target="_blank" class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                Download
                            </a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No documents found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $documents->links() }}
    </div>
</div>
