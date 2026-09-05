<?php

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  LOGIN HISTORY                                                       ║
 * ╠══════════════════════════════════════════════════════════════════════╣
 * ║  Who signed in (or tried to) and from where.                         ║
 * ║                                                                      ║
 * ║  Populated by App\Listeners\LogLoginActivity, which listens on       ║
 * ║  Laravel's Login / Failed / Logout auth events (Fortify's Failed     ║
 * ║  event is fired explicitly from FortifyServiceProvider because a     ║
 * ║  custom authenticateUsing() closure short-circuits Guard::attempt(). ║
 * ║                                                                      ║
 * ║  Same viewer gate as /admin/audit-log: developer / super admin /     ║
 * ║  owner / operations controller.  Everyone else 403s in mount().      ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    /** Free text across identity, IP, user name/email, session id. */
    #[Url] public string $search = '';

    /** '' | 'login' | 'failed' | 'logout'. */
    #[Url] public string $event = '';

    #[Url] public ?int $userId = null;
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public int $perPage = 50;

    /** 'desc' = newest first (what an auditor almost always wants). */
    #[Url] public string $sort = 'desc';

    private const PER_PAGE_OPTIONS = [25, 50, 100, 200];

    public function mount(): void
    {
        if (!$this->canView()) {
            abort(403, 'Login history is restricted to management.');
        }

        if (!$this->dateFrom) {
            $this->dateFrom = now()->subDays(29)->toDateString();
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->toDateString();
        }

        $this->clampPerPage();
    }

    public function canView(): bool
    {
        $u = auth()->user();

        return (bool) $u && (
            $u->isDeveloper()
            || $u->isSuperAdmin()
            || $u->isOwner()
            || $u->isOperationsController()
        );
    }

    public function updated($property, $value = null): void
    {
        if (in_array($property, ['search', 'event', 'userId', 'dateFrom', 'dateTo', 'perPage', 'sort'], true)) {
            $this->clampPerPage();
            $this->resetPage();
        }
    }

    private function clampPerPage(): void
    {
        if (!in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 50;
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'event', 'userId', 'sort']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
        $this->resetPage();
    }

    public function applyRange(string $range): void
    {
        $now = now();

        if ($range === 'all') {
            $this->dateFrom = '';
            $this->dateTo = '';
            $this->resetPage();

            return;
        }

        [$from, $to] = match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_7' => [$now->copy()->subDays(6), $now->copy()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [$now->copy()->subDays(29), $now->copy()],
        };

        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
        $this->resetPage();
    }

    /** Postgres → ilike; SQLite → like (case-insensitive for ASCII already). */
    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function baseQuery(): Builder
    {
        $like = $this->searchOperator();

        return LoginHistory::query()
            ->with('user:id,name,email')
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->search !== '', function ($q) use ($like) {
                $wild = '%' . trim($this->search) . '%';
                $q->where(function ($w) use ($wild, $like) {
                    $w->where('identity', $like, $wild)
                        ->orWhere('ip_address', $like, $wild)
                        ->orWhere('session_id', $like, $wild)
                        ->orWhereHas('user', fn ($u) => $u
                            ->where('name', $like, $wild)
                            ->orWhere('email', $like, $wild));
                });
            });
    }

    public function eventLabel(string $event): string
    {
        return match ($event) {
            'login'  => 'Signed in',
            'failed' => 'Failed',
            'logout' => 'Signed out',
            default  => ucfirst($event),
        };
    }

    public function eventColor(string $event): string
    {
        return match ($event) {
            'login'  => 'emerald',
            'failed' => 'rose',
            'logout' => 'slate',
            default  => 'blue',
        };
    }

    /** Best-effort short browser/OS label for the User-Agent column. */
    public function shortUserAgent(?string $ua): string
    {
        if (!$ua) {
            return '—';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                          => 'Edge',
            str_contains($ua, 'Chrome/') && !str_contains($ua, 'Chromium') => 'Chrome',
            str_contains($ua, 'Firefox/')                       => 'Firefox',
            str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome') => 'Safari',
            str_contains($ua, 'curl/')                          => 'curl',
            str_contains($ua, 'PostmanRuntime')                 => 'Postman',
            default                                             => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows')  => 'Windows',
            str_contains($ua, 'Android')  => 'Android',
            str_contains($ua, 'iPhone')   => 'iOS',
            str_contains($ua, 'iPad')     => 'iPadOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux')    => 'Linux',
            default                       => null,
        };

        return $os ? "$browser · $os" : $browser;
    }

    public function with(): array
    {
        $rows = $this->baseQuery()
            ->orderBy('created_at', $this->sort === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $this->sort === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage);

        return [
            'rows' => $rows,
            'stats' => $this->stats(),
            'userOptions' => $this->userOptions(),
        ];
    }

    private function stats(): array
    {
        $events = $this->baseQuery()->count();
        $success = (clone $this->baseQuery())->where('event', 'login')->count();
        $failed = (clone $this->baseQuery())->where('event', 'failed')->count();
        $distinctUsers = $this->baseQuery()->distinct()->count('user_id');
        $latest = $this->baseQuery()->max('created_at');

        return [
            'events' => $events,
            'success' => $success,
            'failed' => $failed,
            'users' => $distinctUsers,
            'latest' => $latest ? Carbon::parse($latest) : null,
        ];
    }

    /** Only users who actually appear in the trail, keeps the dropdown tight. */
    private function userOptions()
    {
        return User::query()
            ->select('id', 'name')
            ->whereIn('id', LoginHistory::query()->select('user_id')->whereNotNull('user_id')->distinct())
            ->orderBy('name')
            ->get();
    }
}; ?>

<div>
    <x-slot:header>Login History</x-slot:header>

    <x-page-header
        eyebrow="Governance"
        title="Login History"
        subtitle="Every sign-in, failed attempt and sign-out. Entries are immutable — they can be read, never edited." />

    {{-- KPIs, scoped to the active filters --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-dash.kpi label="Events" :value="number_format($stats['events'])" color="blue"
            :helper="$dateFrom && $dateTo ? 'Between ' . $dateFrom . ' and ' . $dateTo : 'All recorded history'">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/><line x1="8" x2="16" y1="17" y2="17"/></svg></x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi label="Signed in" :value="number_format($stats['success'])" color="emerald"
            helper="Successful logins">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg></x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi label="Failed" :value="number_format($stats['failed'])"
            :color="$stats['failed'] > 0 ? 'rose' : 'slate'"
            helper="Wrong password or unknown identity">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi label="Last event"
            :value="$stats['latest'] ? $stats['latest']->diffForHumans(['parts' => 1, 'short' => true]) : '—'"
            color="indigo"
            :helper="$stats['latest'] ? $stats['latest']->format('D d M Y · H:i') : 'Nothing matches these filters'">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
        </x-dash.kpi>
    </div>

    {{-- Range presets --}}
    <div class="mb-3 flex flex-wrap items-center gap-1.5">
        @php
            $ranges = [
                'today' => 'Today',
                'yesterday' => 'Yesterday',
                'this_week' => 'This week',
                'last_7' => 'Last 7 days',
                'last_30' => 'Last 30 days',
                'this_month' => 'This month',
                'last_month' => 'Last month',
                'all' => 'All time',
            ];
        @endphp
        @foreach($ranges as $key => $label)
            <button type="button" wire:click="applyRange('{{ $key }}')"
                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:border-slate-300 hover:text-slate-900">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Filter row --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
         x-data="{ open: window.matchMedia('(min-width: 1024px)').matches }"
         x-init="window.addEventListener('resize', () => { if (window.matchMedia('(min-width: 1024px)').matches) open = true })">

        <div class="flex items-end gap-3">
            <x-dash.filter-field label="Search" minWidth="0">
                <input type="search" wire:model.live.debounce.400ms="search"
                    placeholder="User, email, identity, IP or session"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
            </x-dash.filter-field>

            <button type="button" @click="open = !open"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-900 lg:hidden">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/></svg>
                <span x-text="open ? 'Less' : 'Filters'">Filters</span>
            </button>
        </div>

        <div x-show="open" x-cloak class="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3 lg:border-t-0 lg:pt-0">
            <x-dash.filter-select label="Event" wire:model.live="event">
                <option value="">All events</option>
                <option value="login">Signed in</option>
                <option value="failed">Failed</option>
                <option value="logout">Signed out</option>
            </x-dash.filter-select>

            <x-dash.filter-select label="User" wire:model.live="userId">
                <option value="">Anyone</option>
                @foreach($userOptions as $u)
                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                @endforeach
            </x-dash.filter-select>

            <x-dash.filter-date label="From" wire:model.live="dateFrom" />
            <x-dash.filter-date label="To" wire:model.live="dateTo" />

            <x-dash.filter-select label="Order" wire:model.live="sort" minWidth="140px">
                <option value="desc">Newest first</option>
                <option value="asc">Oldest first</option>
            </x-dash.filter-select>

            <x-dash.filter-select label="Per page" wire:model.live="perPage" minWidth="110px">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
            </x-dash.filter-select>

            <x-dash.filter-reset wire:click="resetFilters" />
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if($rows->isEmpty())
            <x-empty-state
                title="No matching sign-in activity"
                description="Nothing was recorded for these filters. Widen the date range or clear the filters to see more.">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </x-slot:icon>
                <x-slot:actions>
                    <x-dash.filter-reset wire:click="resetFilters" label="Clear filters" />
                </x-slot:actions>
            </x-empty-state>
        @else
            {{-- ── Desktop ─────────────────────────────────────────────── --}}
            <div class="hidden lg:block">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50/70">
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Event</th>
                            <th class="px-4 py-3">Identity typed</th>
                            <th class="px-4 py-3">IP</th>
                            <th class="px-4 py-3">Client</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($rows as $row)
                            <tr class="align-top transition-colors hover:bg-slate-50/60">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="text-sm font-medium text-slate-900 tabular-nums">{{ optional($row->created_at)->format('d M Y') }}</p>
                                    <p class="text-xs text-slate-500 tabular-nums">{{ optional($row->created_at)->format('H:i:s') }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    @if($row->user)
                                        <p class="text-sm font-medium text-slate-900">{{ $row->user->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $row->user->email }}</p>
                                    @else
                                        <p class="text-sm italic text-slate-500">Unknown</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-badge :color="$this->eventColor($row->event)" dot>
                                        {{ $this->eventLabel($row->event) }}
                                    </x-badge>
                                </td>
                                <td class="max-w-xs px-4 py-3">
                                    <p class="truncate font-mono text-xs text-slate-600" title="{{ $row->identity }}">
                                        {{ $row->identity ?: '—' }}
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="font-mono text-xs text-slate-500">{{ $row->ip_address ?: '—' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="text-xs text-slate-500" title="{{ $row->user_agent }}">{{ $this->shortUserAgent($row->user_agent) }}</p>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ── Mobile ──────────────────────────────────────────────── --}}
            <ul role="list" class="divide-y divide-slate-100 lg:hidden">
                @foreach($rows as $row)
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <x-badge :color="$this->eventColor($row->event)" dot>
                                    {{ $this->eventLabel($row->event) }}
                                </x-badge>
                                <p class="mt-2 truncate text-sm font-semibold text-slate-900">
                                    {{ $row->user?->name ?? 'Unknown user' }}
                                </p>
                                @if($row->user?->email)
                                    <p class="truncate text-xs text-slate-500">{{ $row->user->email }}</p>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-medium text-slate-900 tabular-nums">{{ optional($row->created_at)->format('d M') }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">{{ optional($row->created_at)->format('H:i') }}</p>
                            </div>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                            <div class="col-span-2 min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Identity typed</dt>
                                <dd class="truncate font-mono text-slate-600">{{ $row->identity ?: '—' }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">IP</dt>
                                <dd class="truncate font-mono text-slate-500">{{ $row->ip_address ?: '—' }}</dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Client</dt>
                                <dd class="truncate text-slate-500">{{ $this->shortUserAgent($row->user_agent) }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 p-3">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
