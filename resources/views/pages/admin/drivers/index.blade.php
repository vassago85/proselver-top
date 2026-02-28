<?php
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);
    }

    public function with(): array
    {
        $query = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->with('driverProfile')
            ->withCount(['assignedJobs as active_jobs_count' => fn($q) => $q->whereIn('status', ['assigned', 'in_progress'])]);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('phone', 'ilike', "%{$this->search}%")
                  ->orWhere('email', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('is_active', $this->statusFilter === 'active');
        }

        return [
            'drivers' => $query->orderBy('name')->paginate(20),
        ];
    }
};
?>
<div>
    <x-slot:header>Drivers</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex flex-1 gap-3">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, phone or email..."
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <a href="{{ route('admin.drivers.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + Add Driver
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">License</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">License Expiry</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active Jobs</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($drivers as $driver)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $driver->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $driver->phone ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $driver->driverProfile?->license_code ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm">
                        @if($driver->driverProfile?->license_expiry)
                            <span class="{{ $driver->driverProfile->license_expiry->isPast() ? 'text-red-600 font-medium' : ($driver->driverProfile->license_expiry->diffInDays(now()) < 60 ? 'text-yellow-600' : 'text-gray-500') }}">
                                {{ $driver->driverProfile->license_expiry->format('d M Y') }}
                            </span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $driver->active_jobs_count }}</td>
                    <td class="px-6 py-4"><x-badge :color="$driver->is_active ? 'green' : 'red'">{{ $driver->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                    <td class="px-6 py-4 text-right text-sm">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.drivers.edit', $driver) }}" class="text-blue-600 hover:text-blue-800 font-medium">Edit</a>
                            <button wire:click="toggleActive({{ $driver->id }})" wire:confirm="Are you sure?"
                                class="{{ $driver->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                {{ $driver->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">No drivers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $drivers->links() }}</div>
</div>
