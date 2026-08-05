<?php
use App\Models\DriverProfile;
use App\Models\Job;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public bool $showExpiring = false;

    // Presentation toggle — 'table' (dense triage grid, default) or 'cards'
    // (photo-friendly briefing view ops tend to prefer when walking the
    // floor). #[Url] so the choice survives a refresh and shared links.
    #[Url(as: 'view', except: 'table')] public string $view = 'table';

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

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['table', 'cards'], true) ? $view : 'table';
    }

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

    /**
     * Soft-delete a driver. Used when ops needs to remove a duplicate, a
     * mis-typed entry, or somebody who has actually left — the row stays
     * in the database (deleted_at) so historical jobs that reference
     * driver_user_id still resolve, but the driver disappears from every
     * picker / board / dropdown thanks to the default global scope on
     * the SoftDeletes trait.
     *
     * Refuses to delete drivers who currently sit on a live movement —
     * we'd be hiding the assigned driver mid-trip otherwise. Ops must
     * reassign the job(s) first, then come back and delete.
     */
    public function deleteDriver(int $userId): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canManageInternalUsers(), 403, 'You may not delete drivers.');

        if ($actor->id === $userId) {
            session()->flash('error', 'You cannot delete your own account from here.');
            return;
        }

        $driver = User::with('roles')->findOrFail($userId);

        // Rank guard mirrors the user-edit form: you cannot remove a user
        // whose highest role outranks (or equals) yours, except as a
        // developer. In practice drivers sit far below admin tiers so
        // this is mostly belt-and-braces against role-config drift.
        if (!$actor->isDeveloper() && $driver->highestRoleLevel() >= $actor->highestRoleLevel()) {
            session()->flash('error', 'You may not delete a user at or above your own role level.');
            return;
        }

        // Don't soft-delete a driver mid-trip. We cover the full
        // "actively dispatched" range: from driver_assigned through
        // in_transit. Once a movement is COMPLETED the driver_user_id
        // is just history and a soft-delete leaves it intact.
        $liveJobCount = Job::where('driver_user_id', $userId)
            ->whereIn('status', [
                Job::STATUS_DRIVER_ASSIGNED,
                Job::STATUS_READY_FOR_COLLECTION,
                Job::STATUS_COLLECTED,
                Job::STATUS_IN_TRANSIT,
            ])
            ->count();

        if ($liveJobCount > 0) {
            session()->flash(
                'error',
                "Cannot delete {$driver->name}: they are currently assigned to {$liveJobCount} live "
                . \Illuminate\Support\Str::plural('job', $liveJobCount)
                . '. Reassign the job(s) first, then delete the driver.'
            );
            return;
        }

        // Detach company memberships first so /admin/companies/{id} stops
        // listing them in the Users table. Then soft-delete the user
        // itself — driverProfile is left in place because it's tied to
        // user_id with cascade-on-delete only at hard-delete time.
        $driver->companies()->detach();
        $driver->delete();

        session()->flash('success', "Deleted {$driver->name}. Their historical jobs still reference them.");
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
        // Shared "how soon is soon" threshold. Same key the Driver
        // Operations dashboard reads so the two surfaces can't drift
        // apart and produce contradictory-looking counts. Trade-plate
        // rows still fall inside this window; per-row pill colouring
        // (below) still uses the per-credential warn-months settings.
        $tradePlateWarnDays = (int) SystemSetting::get('drivers.expiry_soon_days', 30);

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

        // ─── Compliance dashboard (the bit the admin dashboard used to
        // show). Lives here because this is *the* drivers page and it's
        // where ops actually come to act on expiring credentials.
        $today = now()->startOfDay();
        // The Action-Required window uses the same setting as the ops
        // dashboard so a driver "expiring soon" here is the same driver
        // "expiring soon" on the compliance-risks table -- no more
        // "roster says 5, ops says 2" confusion.
        $expiryWarnDays = (int) SystemSetting::get('drivers.expiry_soon_days', 30);
        $windowEnd = $today->copy()->addDays($expiryWarnDays)->endOfDay();

        $totalActiveDrivers = User::whereHas('roles', fn($q) => $q->where('slug', 'driver'))
            ->where('is_active', true)
            ->count();

        $profiles = DriverProfile::with('user:id,name,is_active')
            ->whereHas('user', fn($q) => $q->where('is_active', true))
            ->where(function ($q) use ($windowEnd) {
                $q->where('license_expiry', '<=', $windowEnd)
                  ->orWhere('prdp_expiry', '<=', $windowEnd)
                  ->orWhere('trade_plate_expiry', '<=', $windowEnd);
            })
            ->get();

        $attentionItems = [];
        foreach ($profiles as $p) {
            $fields = [
                ['date' => $p->license_expiry,     'label' => 'Licence',     'filter' => 'license'],
                ['date' => $p->prdp_expiry,        'label' => 'PrDP',        'filter' => 'prdp'],
                ['date' => $p->trade_plate_expiry, 'label' => 'Trade plate', 'filter' => 'trade_plate'],
            ];
            foreach ($fields as $f) {
                $d = $f['date'];
                if (!$d || $d->gt($windowEnd)) continue;
                $daysLeft = (int) floor($today->diffInDays($d, false));
                $attentionItems[] = [
                    'user_id'     => $p->user_id,
                    'driver_name' => $p->user?->name ?? 'Unknown',
                    'label'       => $f['label'],
                    'date'        => $d,
                    'days_left'   => $daysLeft,
                    'expired'     => $daysLeft < 0,
                ];
            }
        }
        usort($attentionItems, fn($a, $b) => $a['date'] <=> $b['date']);

        $attentionExpiredCount  = count(array_filter($attentionItems, fn($i) => $i['expired']));
        $attentionExpiringCount = count($attentionItems) - $attentionExpiredCount;
        $attentionVisible  = array_slice($attentionItems, 0, 8);
        $attentionOverflow = max(0, count($attentionItems) - count($attentionVisible));

        return [
            'drivers' => $query->orderBy('name')->paginate(20),
            'licenseWarnMonths' => $licenseWarnMonths,
            'pdpWarnMonths' => $pdpWarnMonths,
            'tradePlateWarnDays' => $tradePlateWarnDays,
            'fleetFiltersActive' => $fleetFiltersActive,
            'totalActiveDrivers' => $totalActiveDrivers,
            'attentionVisible' => $attentionVisible,
            'attentionExpiredCount' => $attentionExpiredCount,
            'attentionExpiringCount' => $attentionExpiringCount,
            'attentionOverflow' => $attentionOverflow,
            'expiryWarnDays' => $expiryWarnDays,
        ];
    }
};
?>
<div>
    <x-slot:header>Drivers</x-slot:header>

    {{-- Compliance dashboard
         Moved off the admin dashboard and lives here because this is
         where ops come to fix expiring credentials. Shows the
         headline counts and a clickable "Action required" list of
         the drivers with the most urgent expiries. Hidden when
         there's literally nothing expiring — no point taking up
         screen space on a clean fleet. --}}
    @if(count($attentionVisible) > 0 || $attentionExpiredCount > 0 || $attentionExpiringCount > 0)
        @php
            $attnHeadColor = $attentionExpiredCount > 0 ? 'amber' : 'slate';
            $attnSummary = trim(
                ($attentionExpiredCount > 0 ? $attentionExpiredCount . ' expired' : '')
                . ($attentionExpiredCount > 0 && $attentionExpiringCount > 0 ? ' · ' : '')
                . ($attentionExpiringCount > 0 ? $attentionExpiringCount . ' expiring within ' . $expiryWarnDays . 'd' : '')
            );
        @endphp
        {{-- Dismissible on the client only. The user asked for this banner
             to always be present when they open /admin/drivers (it's the
             reason compliance lives here), but to be collapsible when it
             gets in the way during a session. State deliberately does not
             persist across page loads. --}}
        <div x-data="{ open: true }" class="mb-6">

            {{-- Collapsed chip — only renders once the banner is dismissed.
                 Keeps the expired/expiring headline visible and offers a
                 one-click way to bring the full list back. --}}
            <div x-show="!open" x-cloak
                 class="flex items-center justify-between gap-3 rounded-xl border {{ $attentionExpiredCount > 0 ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-slate-50' }} px-4 py-2">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="h-1.5 w-1.5 rounded-full {{ $attentionExpiredCount > 0 ? 'bg-amber-500 node-pulse' : 'bg-slate-400' }}"></span>
                    <span class="text-[10px] font-semibold uppercase tracking-[0.25em] {{ $attentionExpiredCount > 0 ? 'text-amber-700' : 'text-slate-600' }}">Action required</span>
                    @if($attnSummary !== '')
                        <span class="text-[11px] text-slate-600 tabular-nums truncate">{!! $attnSummary !!}</span>
                    @endif
                </div>
                <button type="button" @click="open = true"
                    class="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors shrink-0">
                    Show
                    <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            </div>

            <div x-show="open" x-transition.opacity
                 class="rounded-2xl border {{ $attentionExpiredCount > 0 ? 'border-amber-200' : 'border-slate-200' }} bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 {{ $attentionExpiredCount > 0 ? 'bg-amber-50/60' : 'bg-slate-50/60' }} px-6 py-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.25em] {{ $attentionExpiredCount > 0 ? 'text-amber-700' : 'text-slate-600' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $attentionExpiredCount > 0 ? 'bg-amber-500 node-pulse' : 'bg-slate-400' }}"></span>
                            Action required
                        </span>
                        <span class="text-[11px] text-slate-500 tabular-nums truncate">
                            @if($attentionExpiredCount > 0){{ $attentionExpiredCount }} expired @endif
                            @if($attentionExpiredCount > 0 && $attentionExpiringCount > 0) · @endif
                            @if($attentionExpiringCount > 0){{ $attentionExpiringCount }} expiring within {{ $expiryWarnDays }}d @endif
                        </span>
                    </div>
                    <div class="flex items-center gap-3 text-[11px] text-slate-500 tabular-nums">
                        <span class="hidden sm:inline">{{ $totalActiveDrivers }} active {{ \Illuminate\Support\Str::plural('driver', $totalActiveDrivers) }}</span>
                        <button type="button" @click="open = false"
                            title="Hide compliance banner"
                            aria-label="Hide compliance banner"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>
                </div>
            @if(count($attentionVisible) > 0)
            <ul class="divide-y divide-slate-100">
                @foreach($attentionVisible as $item)
                    @php
                        $expired = $item['expired'];
                        $days    = $item['days_left'];
                        if ($expired) {
                            $pillClass = 'bg-red-100 text-red-700 border-red-200';
                            $pillText  = $days === -1 ? 'Expired yesterday' : 'Expired ' . abs($days) . 'd ago';
                            $dotClass  = 'bg-red-500';
                        } elseif ($days <= 14) {
                            $pillClass = 'bg-amber-100 text-amber-800 border-amber-200';
                            $pillText  = $days === 0 ? 'Expires today' : ($days === 1 ? '1 day left' : $days . ' days left');
                            $dotClass  = 'bg-amber-500';
                        } else {
                            $pillClass = 'bg-slate-100 text-slate-700 border-slate-200';
                            $pillText  = $days . ' days left';
                            $dotClass  = 'bg-slate-400';
                        }
                    @endphp
                    <li>
                        <a href="{{ route('admin.drivers.edit', $item['user_id']) }}"
                           class="flex items-center gap-4 px-6 py-2.5 hover:bg-slate-50/70 transition-colors">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotClass }}"></span>
                            <div class="min-w-0 flex-1 flex items-center gap-3">
                                <span class="text-sm font-medium text-slate-900 truncate">{{ $item['driver_name'] }}</span>
                                <span class="inline-flex items-center rounded-md bg-slate-50 border border-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                                    {{ $item['label'] }}
                                </span>
                            </div>
                            <span class="hidden sm:inline text-[11px] text-slate-400 tabular-nums shrink-0">
                                {{ $item['date']->format('d M Y') }}
                            </span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold tabular-nums shrink-0 {{ $pillClass }}">
                                {{ $pillText }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if($attentionOverflow > 0)
                <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-2.5 text-center">
                    <button type="button" wire:click="$set('showExpiring', true)"
                        class="text-[11px] font-semibold text-slate-600 hover:text-slate-900 inline-flex items-center gap-1">
                        View {{ $attentionOverflow }} more in roster
                        <svg viewBox="0 0 24 24" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            @endif
            @endif
            </div> {{-- /banner card (x-show="open") --}}
        </div> {{-- /x-data dismiss wrapper --}}
    @endif

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
        <div class="flex items-center gap-2">
            {{-- View toggle: table (dense) vs cards (briefing). Segmented
                 control mirrors the pattern on /admin/vehicles so ops pick
                 up the pattern once. --}}
            <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 bg-white text-sm">
                <button type="button" wire:click="setView('table')"
                    class="flex items-center gap-1.5 px-3 py-2 font-medium transition-colors {{ $view === 'table' ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-50' }}"
                    title="Table view">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
                    Table
                </button>
                <button type="button" wire:click="setView('cards')"
                    class="flex items-center gap-1.5 px-3 py-2 font-medium transition-colors {{ $view === 'cards' ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-50' }}"
                    title="Card view">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/></svg>
                    Cards
                </button>
            </div>
            <a href="{{ route('admin.drivers.create') }}" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition-colors">
                + Add Driver
            </a>
        </div>
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

    @if($view === 'table')
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
                    $rawId = $profile?->id_number ?? '';
                    $idDigits = strlen(preg_replace('/\D/', '', $rawId));
                    $idTypeRaw = $profile?->id_type ?? 'sa_id';
                    $idAnomaly = $idTypeRaw === 'sa_id' && $rawId !== '' && $idDigits !== 13;
                    $idTypeChip = match ($idTypeRaw) {
                        'passport' => ['label' => 'Passport', 'class' => 'bg-blue-50 text-blue-700 border border-blue-200'],
                        'other'    => ['label' => 'Other',    'class' => 'bg-slate-50 text-slate-700 border border-slate-200'],
                        default    => ['label' => 'SA ID',    'class' => 'bg-slate-50 text-slate-600 border border-slate-200'],
                    };
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
                                class="{{ $driver->is_active ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }} font-medium">
                                {{ $driver->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                            <button wire:click="deleteDriver({{ $driver->id }})"
                                wire:confirm="Delete {{ $driver->name }}?\n\nThis hides them from every list, picker and board. Historical jobs still reference them. Use Deactivate instead if they're just on leave."
                                class="text-red-600 hover:text-red-800 font-medium">
                                Delete
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
    @else
    {{-- Card view. Same badge logic as the table, laid out to scan one
         driver at a time rather than compare a column across rows. Good
         for onboarding / daily briefings / big monitors. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse($drivers as $driver)
            @php
                $profile = $driver->driverProfile;
                $licenseBadge = $this->expiryBadge($profile?->license_expiry?->toDateString(), $licenseWarnMonths);
                $pdpBadge = $this->expiryBadge($profile?->prdp_expiry?->toDateString(), $pdpWarnMonths);
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
                $rawId = $profile?->id_number ?? '';
                $idDigits = strlen(preg_replace('/\D/', '', $rawId));
                $idTypeRaw = $profile?->id_type ?? 'sa_id';
                $idAnomaly = $idTypeRaw === 'sa_id' && $rawId !== '' && $idDigits !== 13;
                $idTypeChip = match ($idTypeRaw) {
                    'passport' => ['label' => 'Passport', 'class' => 'bg-blue-50 text-blue-700 border border-blue-200'],
                    'other'    => ['label' => 'Other',    'class' => 'bg-slate-50 text-slate-700 border border-slate-200'],
                    default    => ['label' => 'SA ID',    'class' => 'bg-slate-50 text-slate-600 border border-slate-200'],
                };
                $hasTracker  = filled($profile?->tracker_id);
                $hasCamera   = filled($profile?->camera_id);
                $hasTollCard = filled($profile?->toll_card_number);
                $initials = collect(explode(' ', trim($driver->name)))
                    ->filter()
                    ->take(2)
                    ->map(fn($p) => strtoupper(substr($p, 0, 1)))
                    ->implode('');
            @endphp
            <div class="group relative flex flex-col rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                {{-- Top stripe reflects the worst compliance status for this driver --}}
                @php
                    $worst = in_array('red', [$licenseBadge, $pdpBadge, $tpBadge], true) ? 'red'
                           : (in_array('amber', [$licenseBadge, $pdpBadge, $tpBadge], true) ? 'amber'
                           : (in_array('green', [$licenseBadge, $pdpBadge, $tpBadge], true) ? 'green' : 'gray'));
                    $stripe = [
                        'red'   => 'bg-gradient-to-r from-red-500 to-rose-500',
                        'amber' => 'bg-gradient-to-r from-amber-400 to-orange-400',
                        'green' => 'bg-gradient-to-r from-emerald-500 to-teal-500',
                        'gray'  => 'bg-gradient-to-r from-slate-300 to-slate-200',
                    ][$worst];
                @endphp
                <div class="h-1.5 w-full rounded-t-2xl {{ $stripe }}"></div>

                <div class="flex flex-1 flex-col gap-4 p-5">
                    {{-- Header: avatar + name + status --}}
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 flex-none items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">
                            {{ $initials ?: '??' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.drivers.edit', $driver) }}" class="block truncate text-sm font-semibold text-slate-900 hover:text-blue-600">
                                {{ $driver->name }}
                            </a>
                            <div class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                                <span>{{ $profile?->cellphone ?? $driver->phone ?? '—' }}</span>
                                @if($profile?->base_location)
                                    <span class="text-slate-300">·</span>
                                    <span class="truncate">{{ $profile->base_location }}</span>
                                @endif
                            </div>
                        </div>
                        <x-badge :color="$driver->is_active ? 'green' : 'red'">
                            {{ $driver->is_active ? 'Active' : 'Inactive' }}
                        </x-badge>
                    </div>

                    {{-- ID line --}}
                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-600">
                        <span class="font-mono">{{ $profile?->id_number ?: '—' }}</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $idTypeChip['class'] }}">{{ $idTypeChip['label'] }}</span>
                        @if($idAnomaly)
                            <span title="SA ID is not 13 digits — verify" class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-amber-100 text-amber-800 border border-amber-200">verify</span>
                        @endif
                    </div>

                    {{-- Compliance grid (licence / PDP / trade plate) --}}
                    <div class="grid grid-cols-3 gap-2 rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Licence</div>
                            @if($profile?->license_expiry)
                                <div class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badgeClasses[$licenseBadge] }}">
                                    {{ $profile->license_expiry->format('d M Y') }}
                                </div>
                                @if($profile?->license_code)
                                    <div class="mt-0.5 text-[10px] uppercase tracking-wide text-slate-400">Code {{ $profile->license_code }}</div>
                                @endif
                            @else
                                <div class="mt-1 text-xs text-slate-400">—</div>
                            @endif
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">PDP</div>
                            @if($profile?->prdp_expiry)
                                <div class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badgeClasses[$pdpBadge] }}">
                                    {{ $profile->prdp_expiry->format('d M Y') }}
                                </div>
                            @else
                                <div class="mt-1 text-xs text-slate-400">—</div>
                            @endif
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Trade Plate</div>
                            @if($profile?->trade_plate)
                                <div class="mt-1 font-mono text-[11px] font-semibold text-slate-700">{{ strtoupper($profile->trade_plate) }}</div>
                                @if($profile->trade_plate_expiry)
                                    <div class="mt-0.5 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $badgeClasses[$tpBadge] }}">
                                        exp {{ $profile->trade_plate_expiry->format('d M Y') }}
                                    </div>
                                @else
                                    <div class="mt-0.5 text-[10px] font-medium text-amber-700">no expiry</div>
                                @endif
                            @else
                                <div class="mt-1 text-xs text-slate-400">—</div>
                            @endif
                        </div>
                    </div>

                    {{-- Equipment row --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span title="Tracker {{ $hasTracker ? ': ' . $profile->tracker_id : 'missing' }}"
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold
                                {{ $hasTracker ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">T</span>
                            <span title="Camera {{ $hasCamera ? ': ' . $profile->camera_id : 'missing' }}"
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold
                                {{ $hasCamera ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">C</span>
                            <span title="Toll card {{ $hasTollCard ? ': ' . $profile->toll_card_number : 'missing' }}"
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold
                                {{ $hasTollCard ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400 border border-dashed border-slate-300' }}">$</span>
                        </div>
                        @if($driver->active_jobs_count > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700 border border-blue-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                {{ $driver->active_jobs_count }} active
                            </span>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="mt-auto flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                        <a href="{{ route('admin.drivers.edit', $driver) }}"
                           class="inline-flex items-center rounded-md bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100">
                            Edit
                        </a>
                        <button wire:click="toggleActive({{ $driver->id }})" wire:confirm="Are you sure?"
                            class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-semibold {{ $driver->is_active ? 'bg-amber-50 text-amber-700 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}">
                            {{ $driver->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                        <button wire:click="deleteDriver({{ $driver->id }})"
                            wire:confirm="Delete {{ $driver->name }}?\n\nThis hides them from every list, picker and board. Historical jobs still reference them. Use Deactivate instead if they're just on leave."
                            class="inline-flex items-center rounded-md bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">
                No drivers found.
            </div>
        @endforelse
    </div>
    @endif

    <div class="mt-4">{{ $drivers->links() }}</div>
</div>
