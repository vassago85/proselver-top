<?php
use App\Models\User;
use App\Models\DriverProfile;
use App\Models\SystemSetting;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Carbon\Carbon;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public bool $showExpiring = false;

    // Fleet-health filters. #[Url] makes them shareable via the dashboard's
    // deep links (?trade_plate=expired, ?missing=tracker, ?id_type=passport,
    // ?id_anomaly=1) so ops can triage with one click.
    #[Url(as: 'trade_plate', except: '')] public string $tradePlateFilter = '';
    #[Url(as: 'missing', except: '')]     public string $missingFilter = '';
    #[Url(as: 'id_type', except: '')]     public string $idTypeFilter = '';
    #[Url(as: 'id_anomaly', except: '')]  public string $idAnomalyFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedShowExpiring(): void { $this->resetPage(); }
    public function updatedTradePlateFilter(): void { $this->resetPage(); }
    public function updatedMissingFilter(): void { $this->resetPage(); }
    public function updatedIdTypeFilter(): void { $this->resetPage(); }
    public function updatedIdAnomalyFilter(): void { $this->resetPage(); }

    public function clearFleetFilters(): void
    {
        $this->tradePlateFilter = '';
        $this->missingFilter = '';
        $this->idTypeFilter = '';
        $this->idAnomalyFilter = '';
        $this->resetPage();
    }

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
        $tradePlateWarnDays = 60;

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
                         ->orWhere('trade_plate', 'ilike', "%{$search}%")
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

        // Trade-plate filter — dashboard deep-link target.
        if ($this->tradePlateFilter !== '') {
            $tpThreshold = now()->addDays($tradePlateWarnDays);
            $mode = $this->tradePlateFilter;
            $query->whereHas('driverProfile', function ($q) use ($mode, $tpThreshold) {
                match ($mode) {
                    'expired'        => $q->whereNotNull('trade_plate_expiry')->whereDate('trade_plate_expiry', '<', now()),
                    'expiring'       => $q->whereNotNull('trade_plate_expiry')->whereBetween('trade_plate_expiry', [now(), $tpThreshold]),
                    'missing_expiry' => $q->whereNotNull('trade_plate')->where('trade_plate', '!=', '')->whereNull('trade_plate_expiry'),
                    'held'           => $q->whereNotNull('trade_plate')->where('trade_plate', '!=', ''),
                    'none'           => $q->where(fn($sub) => $sub->whereNull('trade_plate')->orWhere('trade_plate', '')),
                    default          => null,
                };
            });
        }

        // Missing-equipment filter (tracker / camera / toll_card).
        if ($this->missingFilter !== '') {
            $col = match ($this->missingFilter) {
                'tracker'   => 'tracker_id',
                'camera'    => 'camera_id',
                'toll_card' => 'toll_card_number',
                default     => null,
            };
            if ($col) {
                $query->whereHas('driverProfile', fn($q) =>
                    $q->where(fn($sub) => $sub->whereNull($col)->orWhere($col, ''))
                );
            }
        }

        // Identity filter.
        if ($this->idTypeFilter !== '') {
            $query->whereHas('driverProfile', fn($q) => $q->where('id_type', $this->idTypeFilter));
        }

        if ($this->idAnomalyFilter !== '') {
            $query->whereHas('driverProfile', fn($q) =>
                $q->where('id_type', DriverProfile::ID_TYPE_SA_ID)
                  ->whereRaw("length(regexp_replace(id_number, '\\D', '', 'g')) <> 13")
            );
        }

        $fleetFiltersActive = $this->tradePlateFilter !== ''
            || $this->missingFilter !== ''
            || $this->idTypeFilter !== ''
            || $this->idAnomalyFilter !== '';

        return [
            'drivers' => $query->orderBy('name')->paginate(20),
            'licenseWarnMonths' => $licenseWarnMonths,
            'pdpWarnMonths' => $pdpWarnMonths,
            'tradePlateWarnDays' => $tradePlateWarnDays,
            'fleetFiltersActive' => $fleetFiltersActive,
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

    {{-- Active fleet filters (only shown when a dashboard deep-link filter is
         applied). Renders as a dismissable chip bar so ops can see at a glance
         *why* the roster is filtered, and one click clears it. --}}
    @if($fleetFiltersActive)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-[12px] text-amber-900">
            <span class="font-semibold uppercase tracking-wide text-[10px] text-amber-700">Filtered</span>
            @if($tradePlateFilter === 'expired')        <span class="rounded-full bg-white px-2 py-0.5 font-medium">Trade plate: expired</span>@endif
            @if($tradePlateFilter === 'expiring')       <span class="rounded-full bg-white px-2 py-0.5 font-medium">Trade plate: expiring ≤{{ $tradePlateWarnDays }}d</span>@endif
            @if($tradePlateFilter === 'missing_expiry') <span class="rounded-full bg-white px-2 py-0.5 font-medium">Trade plate: missing expiry</span>@endif
            @if($tradePlateFilter === 'held')           <span class="rounded-full bg-white px-2 py-0.5 font-medium">Trade plate: held</span>@endif
            @if($tradePlateFilter === 'none')           <span class="rounded-full bg-white px-2 py-0.5 font-medium">Trade plate: none</span>@endif
            @if($missingFilter === 'tracker')           <span class="rounded-full bg-white px-2 py-0.5 font-medium">Missing tracker</span>@endif
            @if($missingFilter === 'camera')            <span class="rounded-full bg-white px-2 py-0.5 font-medium">Missing camera</span>@endif
            @if($missingFilter === 'toll_card')         <span class="rounded-full bg-white px-2 py-0.5 font-medium">Missing toll card</span>@endif
            @if($idTypeFilter === 'passport')           <span class="rounded-full bg-white px-2 py-0.5 font-medium">Passport holders</span>@endif
            @if($idTypeFilter === 'sa_id')              <span class="rounded-full bg-white px-2 py-0.5 font-medium">SA ID holders</span>@endif
            @if($idTypeFilter === 'other')              <span class="rounded-full bg-white px-2 py-0.5 font-medium">Other ID</span>@endif
            @if($idAnomalyFilter !== '')                <span class="rounded-full bg-white px-2 py-0.5 font-medium">SA ID to verify (≠13 digits)</span>@endif
            <button wire:click="clearFleetFilters" class="ml-auto text-[11px] font-semibold text-amber-800 hover:text-amber-900 underline underline-offset-2">Clear</button>
        </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID Number</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Licence</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PDP</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trade Plate</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase" title="T = Tracker · C = Camera · $ = Toll Card">Equip</th>
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
                    // Trade plate uses a fixed 60-day warning horizon (days, not
                    // months) because plates are renewed far more aggressively
                    // than licences and the same "3 months out" heuristic is
                    // too loose.
                    $tpBadge = 'gray';
                    if ($profile?->trade_plate_expiry) {
                        if ($profile->trade_plate_expiry->isPast()) $tpBadge = 'red';
                        elseif ($profile->trade_plate_expiry->diffInDays(now()) <= $tradePlateWarnDays) $tpBadge = 'amber';
                        else $tpBadge = 'green';
                    }
                    $badgeClasses = [
                        'green' => 'bg-green-100 text-green-800',
                        'amber' => 'bg-amber-100 text-amber-800',
                        'red'   => 'bg-red-100 text-red-800',
                        'gray'  => 'bg-gray-100 text-gray-500',
                    ];
                    // ID number validity flag — only meaningful for SA IDs.
                    $rawId = $profile?->id_number ?? '';
                    $idDigits = strlen(preg_replace('/\D/', '', $rawId));
                    $idTypeRaw = $profile?->id_type ?? 'sa_id';
                    $idAnomaly = $idTypeRaw === 'sa_id' && $rawId !== '' && $idDigits !== 13;
                    $idTypeChip = match ($idTypeRaw) {
                        'passport' => ['label' => 'Passport', 'class' => 'bg-blue-50 text-blue-700 border border-blue-200'],
                        'other'    => ['label' => 'Other',    'class' => 'bg-slate-50 text-slate-700 border border-slate-200'],
                        default    => ['label' => 'SA ID',    'class' => 'bg-slate-50 text-slate-600 border border-slate-200'],
                    };
                    // Equipment coverage dots (T / C / $). Filled = present,
                    // outlined = missing. Title tooltips expose the actual id
                    // on hover without widening the column.
                    $hasTracker  = filled($profile?->tracker_id);
                    $hasCamera   = filled($profile?->camera_id);
                    $hasTollCard = filled($profile?->toll_card_number);
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $driver->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $profile?->cellphone ?? $driver->phone ?? '—' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <div class="flex items-center gap-1.5">
                            <span>{{ $profile?->id_number ?? '—' }}</span>
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $idTypeChip['class'] }}">{{ $idTypeChip['label'] }}</span>
                            @if($idAnomaly)
                                <span title="SA ID is not 13 digits — verify" class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-amber-100 text-amber-800 border border-amber-200">verify</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if($profile?->license_expiry)
                            <div class="flex flex-col gap-0.5">
                                <span class="inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badgeClasses[$licenseBadge] }}">
                                    {{ $profile->license_expiry->format('d M Y') }}
                                </span>
                                @if($profile?->license_code)
                                    <span class="text-[10px] text-slate-400 uppercase tracking-wide">Code {{ $profile->license_code }}</span>
                                @endif
                            </div>
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
                    <td class="px-6 py-4 text-sm">
                        @if($profile?->trade_plate)
                            <div class="flex flex-col gap-0.5">
                                <span class="font-mono text-[11px] font-semibold text-slate-700">{{ strtoupper($profile->trade_plate) }}</span>
                                @if($profile->trade_plate_expiry)
                                    <span class="inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $badgeClasses[$tpBadge] }}">
                                        exp {{ $profile->trade_plate_expiry->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="text-[10px] text-amber-700 font-medium">no expiry recorded</span>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <div class="flex items-center gap-1">
                            <span title="Tracker ID {{ $hasTracker ? ': ' . $profile->tracker_id : 'missing' }}"
                                class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold
                                {{ $hasTracker ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">T</span>
                            <span title="Camera ID {{ $hasCamera ? ': ' . $profile->camera_id : 'missing' }}"
                                class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold
                                {{ $hasCamera ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">C</span>
                            <span title="Toll card {{ $hasTollCard ? ': ' . $profile->toll_card_number : 'missing' }}"
                                class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold
                                {{ $hasTollCard ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">$</span>
                        </div>
                    </td>
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
