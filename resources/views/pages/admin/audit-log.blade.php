<?php

use App\Models\AuditLog;
use App\Models\Job;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  AUDIT LOG                                                           ║
 * ╠══════════════════════════════════════════════════════════════════════╣
 * ║  Who did what, to which record, when, and from where.                ║
 * ║                                                                      ║
 * ║  Three writers feed the audit_logs table and they do NOT agree on    ║
 * ║  entity_type, so this page normalises rather than trusting it:        ║
 * ║                                                                      ║
 * ║    App\Traits\Auditable    entity_type = getMorphClass(), and with   ║
 * ║                            no morph map registered that's the FQCN    ║
 * ║                            ("App\Models\Job").                       ║
 * ║    App\Services\AuditService  entity_type is whatever string the      ║
 * ║                            call site passed -- "job", "transport_job",║
 * ║                            "petty_cash_entry", "system_setting"...    ║
 * ║    DriverOffboardingService   same shape as Auditable.               ║
 * ║                                                                      ║
 * ║  So "job", "transport_job" and "App\Models\Job" are all the same      ║
 * ║  thing. canonicalEntity() collapses them; that's what drives both     ║
 * ║  the deep-link and the human label.                                  ║
 * ║                                                                      ║
 * ║  before_json / after_json come straight from model attributes, which  ║
 * ║  means a User row carries its password hash and remember_token. Those ║
 * ║  are redacted on the way out -- see REDACTED_KEY_FRAGMENTS. An audit  ║
 * ║  reader has no business seeing credentials.                          ║
 * ║                                                                      ║
 * ║  SQL stays portable (no Postgres-only ILIKE / FILTER / ::date) so the ║
 * ║  page is coverable by the SQLite test suite; searchOperator() picks   ║
 * ║  ILIKE on Postgres purely so search is case-insensitive there, since  ║
 * ║  SQLite's LIKE already is.                                           ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    /** Free text across action, entity, reason, IP, actor name/email and entity id. */
    #[Url] public string $search = '';

    #[Url] public string $actionType = '';
    #[Url] public string $entityType = '';
    #[Url] public ?int $actorId = null;
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public int $perPage = 50;

    /** 'desc' = newest first (the default an auditor wants). */
    #[Url] public string $sort = 'desc';

    /** id of the row whose before/after detail is expanded, if any. */
    public ?int $openId = null;

    /** Per-request memo for entityTypeGroups(); private, so never hydrated. */
    private ?array $entityGroupCache = null;

    /**
     * Any before/after key containing one of these fragments is replaced with
     * a placeholder. Substring matching on purpose: it catches password,
     * password_hash, remember_token, two_factor_secret, api_token and
     * anything similarly named added later without this list needing an edit.
     */
    private const REDACTED_KEY_FRAGMENTS = ['password', 'token', 'secret', 'api_key', 'signature'];

    /**
     * Keys that are pure noise in a diff -- timestamps Eloquent maintains and
     * the primary key, which is already shown as the entity id.
     */
    private const IGNORED_DIFF_KEYS = ['id', 'created_at', 'updated_at'];

    public function mount(): void
    {
        // Mirrors the sidebar's Audit Log gate exactly. Previously this page
        // had no gate at all, so any internal role could read the whole
        // trail; it's now management + ops controller only.
        if (!$this->canView()) {
            abort(403, 'The audit log is restricted to management.');
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

    /** Guards against a hand-edited ?perPage= in the URL becoming an OOM. */
    private const PER_PAGE_OPTIONS = [25, 50, 100, 200];

    /*
     * Any filter change invalidates both the page number and the expanded
     * row -- openId would otherwise point at a record no longer on screen.
     */
    public function updated($property, $value = null): void
    {
        if (in_array($property, ['search', 'actionType', 'entityType', 'actorId', 'dateFrom', 'dateTo', 'perPage', 'sort'], true)) {
            $this->clampPerPage();
            $this->resetPage();
            $this->openId = null;
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
        $this->reset(['search', 'actionType', 'entityType', 'actorId', 'sort']);
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
        $this->openId = null;
        $this->resetPage();
    }

    /** Expand / collapse a row's field-level diff. */
    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    /*
     * Quick ranges. 'all' clears the window entirely -- an auditor chasing a
     * specific incident shouldn't have to guess dates, and the table is
     * paginated so an unbounded query still only fetches one page.
     */
    public function applyRange(string $range): void
    {
        $now = now();

        if ($range === 'all') {
            $this->dateFrom = '';
            $this->dateTo = '';
            $this->resetPage();
            $this->openId = null;

            return;
        }

        [$from, $to] = match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()],
            // Yesterday and this-week exist because that's how the owner asks
            // the question -- "what changed while I was away", "what have we
            // done this week" -- and the Owner dashboard links straight in.
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
        $this->openId = null;
    }

    /**
     * Postgres LIKE is case-sensitive and SQLite has no ILIKE, so neither
     * operator is portable on its own. SQLite's LIKE is already
     * case-insensitive for ASCII, giving the same behaviour on both.
     */
    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function baseQuery(): Builder
    {
        $like = $this->searchOperator();

        return AuditLog::query()
            ->with('actor:id,name,email')
            ->when($this->actionType !== '', fn ($q) => $q->where('action_type', $this->actionType))
            // Filtering is by canonical entity, not by the raw string, so
            // picking "Job" catches App\Models\Job, job and transport_job in
            // one go rather than a third of the job history.
            ->when($this->entityType !== '', fn ($q) => $q->whereIn('entity_type', $this->rawEntityTypesFor($this->entityType)))
            ->when($this->actorId, fn ($q) => $q->where('actor_user_id', $this->actorId))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->search !== '', function ($q) use ($like) {
                $term = trim($this->search);
                $wild = '%' . $term . '%';

                $q->where(function ($w) use ($term, $wild, $like) {
                    $w->where('action_type', $like, $wild)
                        ->orWhere('entity_type', $like, $wild)
                        ->orWhere('reason', $like, $wild)
                        ->orWhere('ip_address', $like, $wild)
                        ->orWhere('actor_roles_snapshot', $like, $wild)
                        ->orWhereHas('actor', fn ($a) => $a
                            ->where('name', $like, $wild)
                            ->orWhere('email', $like, $wild));

                    // A bare number is almost always someone pasting a record
                    // id, so match it exactly rather than burying it in the
                    // text search.
                    if (ctype_digit($term)) {
                        $w->orWhere('entity_id', (int) $term);
                    }
                });
            });
    }

    /**
     * Collapse the three entity_type conventions onto one key per real thing.
     * Returns null when we don't recognise it, which just means "no deep
     * link" -- the raw label is still shown.
     */
    public function canonicalEntity(?string $entityType): ?string
    {
        if (!$entityType) {
            return null;
        }

        $short = Str::of(class_basename($entityType))->snake()->toString();

        return match ($short) {
            'job', 'transport_job' => 'job',
            'user' => 'user',
            default => $short,
        };
    }

    /** "App\Models\Job" -> "Job", "petty_cash_entry" -> "Petty cash entry". */
    public function entityLabel(?string $entityType): string
    {
        if (!$entityType) {
            return '—';
        }

        return Str::of(class_basename($entityType))
            ->snake()
            ->replace('_', ' ')
            ->ucfirst()
            ->toString();
    }

    /** Humanised action, e.g. petty_cash_approved -> "Petty cash approved". */
    public function actionLabel(string $action): string
    {
        return Str::of($action)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * Semantic colour for the action badge, matched on fragments because the
     * action vocabulary is open -- every service invents its own verbs and a
     * fixed map would silently fall through to grey for anything new.
     */
    public function actionColor(string $action): string
    {
        return match (true) {
            Str::contains($action, ['deleted', 'reject', 'cancel', 'removed', 'offboard']) => 'rose',
            Str::contains($action, ['approve', 'verified', 'confirm', 'created', 'payout', 'reimbursed']) => 'emerald',
            Str::contains($action, ['export']) => 'purple',
            Str::contains($action, ['updated', 'changed', 'saved', 'set', 'assigned', 'merged']) => 'blue',
            default => 'slate',
        };
    }

    private function isRedactedKey(string $key): bool
    {
        $lower = Str::lower($key);

        foreach (self::REDACTED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function presentValue($value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
        }

        $string = (string) $value;

        return $string === '' ? '—' : $string;
    }

    /**
     * Field-level diff for one entry: the union of before/after keys, each
     * with its old and new value and whether it actually moved. Beats showing
     * two blobs of JSON and making the reader spot the difference.
     */
    public function diffRows(AuditLog $log): array
    {
        $before = is_array($log->before_json) ? $log->before_json : [];
        $after = is_array($log->after_json) ? $log->after_json : [];

        $keys = collect(array_keys($before))
            ->merge(array_keys($after))
            ->unique()
            ->reject(fn ($k) => in_array($k, self::IGNORED_DIFF_KEYS, true))
            ->sort()
            ->values();

        return $keys->map(function ($key) use ($before, $after) {
            $redacted = $this->isRedactedKey($key);
            $hadBefore = array_key_exists($key, $before);
            $hadAfter = array_key_exists($key, $after);

            return [
                'key' => $key,
                'before' => $redacted ? '••••••••' : $this->presentValue($before[$key] ?? null),
                'after' => $redacted ? '••••••••' : $this->presentValue($after[$key] ?? null),
                'redacted' => $redacted,
                // Only claim a change when both sides were present; a create
                // has no "before" to have moved away from.
                'changed' => $hadBefore && $hadAfter && ($before[$key] ?? null) !== ($after[$key] ?? null),
            ];
        })->all();
    }

    /*
     * Reading the audit trail in bulk is itself an auditable act, so an
     * export writes its own entry -- including the filters used, so a later
     * reader can tell exactly what left the building.
     */
    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->canView(), 403);

        AuditService::log('audit_log_exported', 'audit_log', null, null, [
            'search' => $this->search ?: null,
            'action_type' => $this->actionType ?: null,
            'entity_type' => $this->entityType ?: null,
            'actor_user_id' => $this->actorId,
            'date_from' => $this->dateFrom ?: null,
            'date_to' => $this->dateTo ?: null,
        ]);

        // Fresh query without the display ordering: chunkById imposes its own
        // ordering on the primary key and would fight a created_at sort.
        $query = $this->baseQuery();

        $filename = sprintf(
            'audit-log_%s_to_%s.csv',
            $this->dateFrom ?: 'start',
            $this->dateTo ?: 'now',
        );

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 instead of mangling names.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'When', 'Actor', 'Actor email', 'Roles at the time', 'Action',
                'Entity', 'Entity ID', 'Reason', 'IP address', 'Changes',
            ]);

            $query->chunkById(300, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    $changes = collect($this->diffRows($log))
                        ->map(fn ($r) => $r['key'] . ': ' . $r['before'] . ' -> ' . $r['after'])
                        ->implode('; ');

                    fputcsv($out, [
                        optional($log->created_at)->format('Y-m-d H:i:s'),
                        $log->actor?->name ?? 'System',
                        $log->actor?->email ?? '',
                        $log->actor_roles_snapshot ?? '',
                        $this->actionLabel($log->action_type),
                        $this->entityLabel($log->entity_type),
                        $log->entity_id ?? '',
                        $log->reason ?? '',
                        $log->ip_address ?? '',
                        $changes,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $rows = $this->baseQuery()
            ->orderBy('created_at', $this->sort === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', $this->sort === 'asc' ? 'asc' : 'desc')
            ->paginate($this->perPage);

        return [
            'rows' => $rows,
            'entityLinks' => $this->resolveEntityLinks($rows),
            'stats' => $this->stats(),
            'actionTypeOptions' => $this->actionTypeOptions(),
            'entityTypeOptions' => $this->entityTypeOptions(),
            'actorOptions' => $this->actorOptions(),
        ];
    }

    /**
     * Deep links for the current page only, and only to records that still
     * exist -- two bulk lookups rather than a route() guess per row, so we
     * never render a link straight into a 404. Soft-deleted jobs are excluded
     * because implicit route binding won't resolve them either.
     */
    private function resolveEntityLinks(LengthAwarePaginator $rows): array
    {
        $items = collect($rows->items());

        $idsFor = fn (string $canonical) => $items
            ->filter(fn ($log) => $this->canonicalEntity($log->entity_type) === $canonical)
            ->pluck('entity_id')
            ->filter()
            ->unique()
            ->values();

        $jobIds = $idsFor('job');
        $userIds = $idsFor('user');

        $liveJobIds = $jobIds->isEmpty() ? [] : Job::whereIn('id', $jobIds)->pluck('id')->all();
        $liveUserIds = $userIds->isEmpty() ? [] : User::whereIn('id', $userIds)->pluck('id')->all();

        $links = [];

        foreach ($items as $log) {
            if (!$log->entity_id) {
                continue;
            }

            $canonical = $this->canonicalEntity($log->entity_type);

            if ($canonical === 'job' && in_array($log->entity_id, $liveJobIds)) {
                $links[$log->id] = route('admin.orders.show', $log->entity_id);
            } elseif ($canonical === 'user' && in_array($log->entity_id, $liveUserIds)) {
                $links[$log->id] = route('admin.users.edit', $log->entity_id);
            }
        }

        return $links;
    }

    /** Headline counts for the active window (filters included). */
    private function stats(): array
    {
        $like = $this->searchOperator();

        $events = $this->baseQuery()->count();
        $actors = $this->baseQuery()->distinct()->count('actor_user_id');
        $destructive = $this->baseQuery()
            ->where(function ($q) use ($like) {
                $q->where('action_type', 'deleted')
                    ->orWhere('action_type', $like, '%reject%')
                    ->orWhere('action_type', $like, '%cancel%');
            })
            ->count();
        $latest = $this->baseQuery()->max('created_at');

        return [
            'events' => $events,
            'actors' => $actors,
            'destructive' => $destructive,
            'latest' => $latest ? Carbon::parse($latest) : null,
        ];
    }

    private function actionTypeOptions(): array
    {
        return AuditLog::query()
            ->select('action_type')
            ->distinct()
            ->orderBy('action_type')
            ->pluck('action_type')
            ->all();
    }

    /**
     * canonical key => every raw entity_type string in the table that means it.
     * Memoised because baseQuery() is rebuilt once per figure on the page and
     * this would otherwise repeat the same DISTINCT five times a render.
     */
    private function entityTypeGroups(): array
    {
        if ($this->entityGroupCache !== null) {
            return $this->entityGroupCache;
        }

        $groups = [];

        foreach (AuditLog::query()->select('entity_type')->distinct()->pluck('entity_type') as $raw) {
            $groups[$this->canonicalEntity($raw)][] = $raw;
        }

        return $this->entityGroupCache = $groups;
    }

    private function rawEntityTypesFor(string $canonical): array
    {
        // Fall back to the value itself so a hand-written ?entityType= still
        // does something sensible.
        return $this->entityTypeGroups()[$canonical] ?? [$canonical];
    }

    /** canonical key => human label, one entry per real entity, sorted by label. */
    private function entityTypeOptions(): array
    {
        $options = [];

        foreach (array_keys($this->entityTypeGroups()) as $canonical) {
            $options[$canonical] = $this->entityLabel($canonical);
        }

        asort($options);

        return $options;
    }

    /** Only users who actually appear in the trail -- one subquery. */
    private function actorOptions()
    {
        return User::query()
            ->select('id', 'name')
            ->whereIn('id', AuditLog::query()->select('actor_user_id')->whereNotNull('actor_user_id')->distinct())
            ->orderBy('name')
            ->get();
    }
}; ?>

<div>
    <x-slot:header>Audit Log</x-slot:header>

    <x-page-header
        eyebrow="Governance"
        title="Audit Log"
        subtitle="Every recorded change, who made it and what moved. Entries are immutable — they can be read and exported, never edited.">
        <x-slot:actions>
            <button type="button" wire:click="exportCsv"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Export CSV
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Headline counts, scoped to whatever filters are active --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-dash.kpi label="Events" :value="number_format($stats['events'])" color="blue"
            :helper="$dateFrom && $dateTo ? 'Between ' . $dateFrom . ' and ' . $dateTo : 'All recorded history'">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" x2="16" y1="13" y2="13"/><line x1="8" x2="16" y1="17" y2="17"/></svg></x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi label="People" :value="number_format($stats['actors'])" color="teal"
            helper="Distinct users who acted">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg></x-slot:icon>
        </x-dash.kpi>

        <x-dash.kpi label="Reversals" :value="number_format($stats['destructive'])"
            :color="$stats['destructive'] > 0 ? 'rose' : 'slate'"
            helper="Deletions, rejections and cancellations">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></x-slot:icon>
        </x-dash.kpi>

        {{-- Short relative form ("4m ago", "3d ago") on purpose: the long form
             wrapped to three lines in the two-column mobile KPI grid. The exact
             timestamp is right underneath for anyone who needs it. --}}
        <x-dash.kpi label="Last event"
            :value="$stats['latest'] ? $stats['latest']->diffForHumans(['parts' => 1, 'short' => true]) : '—'"
            color="indigo"
            :helper="$stats['latest'] ? $stats['latest']->format('D d M Y · H:i') : 'Nothing matches these filters'">
            <x-slot:icon><svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></x-slot:icon>
        </x-dash.kpi>
    </div>

    {{-- Range presets: an auditor usually knows "yesterday" or "last month",
         not two ISO dates. "All time" is here because chasing an old incident
         shouldn't require guessing when it happened. --}}
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

    {{-- Search stays visible; the other seven controls fold away below lg.
         Stacked full-width they pushed the actual log most of a screen down on
         a phone, and search plus the presets above cover the common case.
         One copy of the markup, with `open` seeded from the viewport so desktop
         never sees the collapsed state. --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
         x-data="{ open: window.matchMedia('(min-width: 1024px)').matches }"
         x-init="window.addEventListener('resize', () => { if (window.matchMedia('(min-width: 1024px)').matches) open = true })">

        <div class="flex items-end gap-3">
            <x-dash.filter-field label="Search" minWidth="0">
                <input type="search" wire:model.live.debounce.400ms="search"
                    placeholder="Actor, action, entity, reason, IP or record id"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
            </x-dash.filter-field>

            <button type="button" @click="open = !open"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-900 lg:hidden">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/></svg>
                <span x-text="open ? 'Less' : 'Filters'">Filters</span>
            </button>
        </div>

        <div x-show="open" x-cloak class="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-3 lg:border-t-0 lg:pt-0">
        <x-dash.filter-select label="Action" wire:model.live="actionType">
            <option value="">All actions</option>
            @foreach($actionTypeOptions as $option)
                <option value="{{ $option }}">{{ $this->actionLabel($option) }}</option>
            @endforeach
        </x-dash.filter-select>

        <x-dash.filter-select label="Entity" wire:model.live="entityType">
            <option value="">All entities</option>
            @foreach($entityTypeOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-dash.filter-select>

        <x-dash.filter-select label="Actor" wire:model.live="actorId">
            <option value="">Anyone</option>
            @foreach($actorOptions as $actor)
                <option value="{{ $actor->id }}">{{ $actor->name }}</option>
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
                title="No matching activity"
                description="Nothing was recorded for these filters. Widen the date range or clear the filters to see more.">
                <x-slot:icon>
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </x-slot:icon>
                <x-slot:actions>
                    <x-dash.filter-reset wire:click="resetFilters" label="Clear filters" />
                </x-slot:actions>
            </x-empty-state>
        @else
            {{-- ── Desktop: full table ───────────────────────────────────── --}}
            <div class="hidden lg:block">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50/70">
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Actor</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Record</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($rows as $log)
                            @php $diff = $this->diffRows($log); @endphp
                            <tr class="align-top transition-colors hover:bg-slate-50/60">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="text-sm font-medium text-slate-900 tabular-nums">{{ optional($log->created_at)->format('d M Y') }}</p>
                                    <p class="text-xs text-slate-500 tabular-nums">{{ optional($log->created_at)->format('H:i:s') }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-sm font-medium text-slate-900">{{ $log->actor?->name ?? 'System' }}</p>
                                    @if($log->actor_roles_snapshot)
                                        <p class="text-xs text-slate-500">{{ $log->actor_roles_snapshot }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-badge :color="$this->actionColor($log->action_type)" dot>
                                        {{ $this->actionLabel($log->action_type) }}
                                    </x-badge>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-sm text-slate-900">{{ $this->entityLabel($log->entity_type) }}</p>
                                    @if($log->entity_id)
                                        @if(isset($entityLinks[$log->id]))
                                            <a href="{{ $entityLinks[$log->id] }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                                                #{{ $log->entity_id }} &rarr;
                                            </a>
                                        @else
                                            <p class="text-xs text-slate-500 tabular-nums">#{{ $log->entity_id }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-3">
                                    <p class="text-sm text-slate-600">{{ $log->reason ?: '—' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="font-mono text-xs text-slate-500">{{ $log->ip_address ?: '—' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if(count($diff) > 0)
                                        <button type="button" wire:click="toggle({{ $log->id }})"
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900">
                                            {{ $openId === $log->id ? 'Hide' : 'Changes' }}
                                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 transition-transform {{ $openId === $log->id ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if($openId === $log->id && count($diff) > 0)
                                <tr class="bg-slate-50/80">
                                    <td colspan="7" class="px-4 py-4">
                                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                                            <table class="min-w-full divide-y divide-slate-100 text-sm">
                                                <thead class="bg-slate-50">
                                                    <tr class="text-left text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                                                        <th class="px-3 py-2">Field</th>
                                                        <th class="px-3 py-2">Before</th>
                                                        <th class="px-3 py-2">After</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach($diff as $row)
                                                        <tr class="{{ $row['changed'] ? 'bg-amber-50/40' : '' }}">
                                                            <td class="px-3 py-2 font-mono text-xs text-slate-700">
                                                                {{ $row['key'] }}
                                                                @if($row['redacted'])
                                                                    <span class="ml-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">redacted</span>
                                                                @endif
                                                            </td>
                                                            <td class="px-3 py-2 break-words text-xs text-slate-500">{{ $row['before'] }}</td>
                                                            <td class="px-3 py-2 break-words text-xs {{ $row['changed'] ? 'font-semibold text-slate-900' : 'text-slate-600' }}">{{ $row['after'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ── Mobile / tablet: stacked cards ────────────────────────── --}}
            {{-- A seven-column table is unreadable on a phone, so below lg the
                 same rows render as cards instead of forcing a sideways scroll. --}}
            <ul role="list" class="divide-y divide-slate-100 lg:hidden">
                @foreach($rows as $log)
                    @php $diff = $this->diffRows($log); @endphp
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <x-badge :color="$this->actionColor($log->action_type)" dot>
                                    {{ $this->actionLabel($log->action_type) }}
                                </x-badge>
                                <p class="mt-2 truncate text-sm font-semibold text-slate-900">{{ $log->actor?->name ?? 'System' }}</p>
                                @if($log->actor_roles_snapshot)
                                    <p class="truncate text-xs text-slate-500">{{ $log->actor_roles_snapshot }}</p>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-medium text-slate-900 tabular-nums">{{ optional($log->created_at)->format('d M') }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">{{ optional($log->created_at)->format('H:i') }}</p>
                            </div>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Record</dt>
                                <dd class="truncate text-slate-700">
                                    {{ $this->entityLabel($log->entity_type) }}
                                    @if($log->entity_id)
                                        @if(isset($entityLinks[$log->id]))
                                            <a href="{{ $entityLinks[$log->id] }}" class="font-semibold text-blue-600">#{{ $log->entity_id }}</a>
                                        @else
                                            <span class="text-slate-500">#{{ $log->entity_id }}</span>
                                        @endif
                                    @endif
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Source</dt>
                                <dd class="truncate font-mono text-slate-500">{{ $log->ip_address ?: '—' }}</dd>
                            </div>
                            @if($log->reason)
                                <div class="col-span-2">
                                    <dt class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">Reason</dt>
                                    <dd class="text-slate-700">{{ $log->reason }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if(count($diff) > 0)
                            <button type="button" wire:click="toggle({{ $log->id }})"
                                class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900">
                                {{ $openId === $log->id ? 'Hide changes' : 'Show changes' }}
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 transition-transform {{ $openId === $log->id ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </button>

                            @if($openId === $log->id)
                                <div class="mt-3 space-y-2">
                                    @foreach($diff as $row)
                                        <div class="rounded-lg border border-slate-200 p-2.5 {{ $row['changed'] ? 'bg-amber-50/40' : 'bg-slate-50/60' }}">
                                            <p class="font-mono text-[11px] font-semibold text-slate-700">
                                                {{ $row['key'] }}
                                                @if($row['redacted'])
                                                    <span class="ml-1 font-sans text-[10px] uppercase tracking-wider text-slate-400">redacted</span>
                                                @endif
                                            </p>
                                            <p class="mt-1 break-words text-[11px] text-slate-500">{{ $row['before'] }}</p>
                                            <p class="break-words text-[11px] {{ $row['changed'] ? 'font-semibold text-slate-900' : 'text-slate-600' }}">&rarr; {{ $row['after'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 p-3">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
