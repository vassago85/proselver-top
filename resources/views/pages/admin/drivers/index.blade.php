<?php
use App\Models\User;
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Carbon\Carbon;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public bool $showExpiring = false;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedShowExpiring(): void { $this->resetPage(); }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);
    }

    protected function expiryBadge(?string $date, int $warnMonths): string
    {
        if (!$date) return 'gray';
        $expiry = Carbon::parse($date);
        if ($expiry->isPast()) return 'red';
        if ($expiry->diffInMonths(now()) <= $warnMonths) return 'amber';
        return 'green';
    }

    public function with(): array
    {
        $licenseWarnMonths = (int) SystemSetting::get('driver_license_expiry_warn_months', 3);
        $pdpWarnMonths = (int) SystemSetting::get('driver_pdp_expiry_warn_months', 3);

        $query = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->with('driverProfile')
            ->withCount(['assignedJobs as active_jobs_count' => fn($q) => $q->whereIn('status', ['assigned', 'in_progress'])]);

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%")
                  ->orWhereHas('driverProfile', fn($dp) =>
                      $dp->where('id_number', 'ilike', "%{$search}%")
                         ->orWhere('cellphone', 'ilike', "%{$search}%")
                  );
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('is_active', $this->statusFilter === 'active');
        }

        if ($this->showExpiring) {
            $licenseThreshold = now()->addMonths($licenseWarnMonths);
            $pdpThreshold = now()->addMonths($pdpWarnMonths);
            $query->whereHas('driverProfile', fn($q) =>
                $q->where(function ($sub) use ($licenseThreshold, $pdpThreshold) {
                    $sub->where('license_expiry', '<=', $licenseThreshold)
                        ->orWhere('prdp_expiry', '<=', $pdpThreshold);
                })
            );
        }

        return [
            'drivers' => $query->orderBy('name')->paginate(20),
            'licenseWarnMonths' => $licenseWarnMonths,
            'pdpWarnMonths' => $pdpWarnMonths,
        ];
    }
};
?>
<div>
    <x-slot:header>Drivers</x-slot:header>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex flex-1 flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, phone, ID number..."
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                <input wire:model.live="showExpiring" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Show expiring soon
            </label>
        </div>
        <a href="{{ route('admin.drivers.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
            + Add Driver
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID Number</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">License Code</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">License Expiry</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PDP Expiry</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Base Location</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($drivers as $driver)
                @php
                    $profile = $driver->driverProfile;
                    $licenseBadge = $this->expiryBadge($profile?->license_expiry?->toDateString(), $licenseWarnMonths);
                    $pdpBadge = $this->expiryBadge($profile?->prdp_expiry?->toDateString(), $pdpWarnMonths);
                    $badgeClasses = [
                        'green' => 'bg-green-100 text-green-800',
                        'amber' => 'bg-amber-100 text-amber-800',
                        'red'   => 'bg-red-100 text-red-800',
                        'gray'  => 'bg-gray-100 text-gray-500',
                    ];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $driver->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $profile?->cellphone ?? $driver->phone ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $profile?->id_number ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $profile?->license_code ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm">
                        @if($profile?->license_expiry)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badgeClasses[$licenseBadge] }}">
                                {{ $profile->license_expiry->format('d M Y') }}
                            </span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if($profile?->prdp_expiry)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badgeClasses[$pdpBadge] }}">
                                {{ $profile->prdp_expiry->format('d M Y') }}
                            </span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $profile?->base_location ?? '—' }}</td>
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
                <tr><td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500">No drivers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $drivers->links() }}</div>
</div>
