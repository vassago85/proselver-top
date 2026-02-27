<?php
use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $drivers = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->withCount(['assignedJobs as active_jobs_count' => fn($q) => $q->whereIn('status', ['assigned', 'in_progress'])])
            ->orderBy('name')
            ->get();
        return ['drivers' => $drivers];
    }
};
?>
<div>
    <x-slot:header>Drivers</x-slot:header>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active Jobs</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($drivers as $driver)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $driver->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $driver->phone ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $driver->active_jobs_count }}</td>
                    <td class="px-6 py-4"><x-badge :color="$driver->is_active ? 'green' : 'red'">{{ $driver->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">No drivers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
