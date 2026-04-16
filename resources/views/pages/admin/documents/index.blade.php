<?php

use App\Models\JobDocument;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $categoryFilter = '';

    public function with(): array
    {
        $query = JobDocument::with(['job:id,job_number', 'uploadedBy:id,name'])
            ->orderByDesc('created_at');

        if ($this->categoryFilter) {
            $query->where('category', $this->categoryFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('original_filename', 'ilike', "%{$this->search}%")
                    ->orWhereHas('job', fn($q) => $q->where('job_number', 'ilike', "%{$this->search}%"));
            });
        }

        return [
            'documents' => $query->paginate(20),
            'categories' => [
                JobDocument::CATEGORY_PO => 'Purchase Order',
                JobDocument::CATEGORY_POD => 'Proof of Delivery',
                JobDocument::CATEGORY_COLLECTION_NOTE => 'Collection Note',
                JobDocument::CATEGORY_INVOICE => 'Invoice',
                JobDocument::CATEGORY_FUEL_SLIP => 'Fuel Slip',
                JobDocument::CATEGORY_PHOTO => 'Photo',
                JobDocument::CATEGORY_OTHER => 'Other',
            ],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }
};

?>

<div>
    <x-slot:header>Documents</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order # or filename..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <select wire:model.live="categoryFilter" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">All Categories</option>
            @foreach($categories as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Filename</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uploaded By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($documents as $doc)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        @if($doc->job)
                            <a href="{{ route('admin.orders.show', $doc->job_id) }}" class="text-sm font-medium text-blue-600 hover:text-blue-800">{{ $doc->job->job_number }}</a>
                        @else
                            <span class="text-sm text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                            {{ ucfirst(str_replace('_', ' ', $doc->category)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $doc->original_filename }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600">{{ $doc->uploadedBy?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $doc->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-center">
                        @if($doc->path)
                            <a href="{{ Storage::disk($doc->disk)->url($doc->path) }}" target="_blank"
                                class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                Download
                            </a>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">No documents found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $documents->links() }}
    </div>
</div>
