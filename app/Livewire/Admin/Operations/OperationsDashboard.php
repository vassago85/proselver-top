<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\StaleActionJobsQuery;
use App\Domain\Operations\Queries\ThroughputQuery;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations command centre — shell component.
 *
 * Owns filter state and layout. Zero aggregate queries — every KPI
 * lives inside a nested Livewire panel that polls independently (for
 * live tiles) or reacts to filter changes (for range-bound tiles).
 *
 * Filter dispatches:
 *   - `filters-updated` fires whenever any entity filter or the date
 *     range changes. Live panels ignore date changes (see their
 *     `#[On]` handlers); range panels re-render on any change.
 */
#[Layout('components.layouts.app')]
class OperationsDashboard extends Component
{
    // ── Live-panel filters (also apply to performance panels) ─────
    #[Url] public ?int    $companyId     = null;   // booking customer / OEM
    #[Url] public ?int    $transporterId = null;   // executing company
    #[Url] public ?int    $brandId       = null;
    #[Url] public ?string $region        = null;   // pickup or delivery province

    // ── Performance-panel-only filters ────────────────────────────
    // These NEVER touch live panels. The wire:model reactivity is
    // debounced so a text-typing customer doesn't fire twenty queries.
    #[Url] public ?string $dateFrom = null;
    #[Url] public ?string $dateTo   = null;
    #[Url] public string  $preset   = '7d';

    // ── Stale-action login gate ─────────────────────────────────────
    //
    // The modal blocks landing when the acting user has jobs they
    // created that have sat in the same stage for >=
    // `trident.stale_action_days`. Rows they did NOT create are shown
    // for context but never block dismiss.
    //
    // `showStaleGate` is set once on mount so a user who's just hit
    // "Wait longer" on their last owned row can close the modal and
    // reach the dashboard without a race against the query. The gate
    // re-arms on next page load.
    public bool $showStaleGate = false;

    // Per-row comment inputs, keyed by job id. Each owned row needs its
    // own textarea so ops can queue up several snoozes on one visit
    // without the comment field jumping between rows.
    /** @var array<int,string> */
    public array $staleComments = [];

    public function mount(): void
    {
        $this->applyPresetIfNoRange();

        // Only ops-landing internal roles get the gate. Owner and
        // accounts land on their own dashboards; drivers/customers
        // never reach this component. This check is intentionally
        // broad — every user who can reach the ops dashboard should
        // see the modal when their own rows go stale.
        $actor = auth()->user();
        if ($actor && method_exists($actor, 'isInternal') && $actor->isInternal()) {
            $data = (new StaleActionJobsQuery())->forUser($actor);
            $this->showStaleGate = $data['total'] > 0;
        }
    }

    /**
     * Dispatched every time a filter changes, so panels re-render
     * with a fresh OperationsFilters DTO. Kept in one place so the
     * cache key stays consistent across renders.
     *
     * NOTE: We wrap the payload in a single `filters:` param instead
     * of spreading it. Livewire's `Event` constructor treats keys
     * `ref` / `component` / `el` / `self` / `to` as event routing
     * hints — spreading a payload that contains a `to` key (e.g. the
     * date-to string) makes Livewire try to route the event to a
     * component literally named "2026-09-08" and 500s.
     */
    public function updated($property): void
    {
        if (in_array($property, ['companyId', 'transporterId', 'brandId', 'region', 'dateFrom', 'dateTo'])) {
            $this->dispatch('ops-filters-updated', filters: $this->filtersPayload());
        }
    }

    /**
     * Applies a named preset (Today / 7d / 30d / MTD) and re-emits.
     * Custom is handled by writing dateFrom / dateTo directly.
     */
    public function applyPreset(string $preset): void
    {
        $this->preset = $preset;
        [$from, $to] = $this->presetRange($preset);
        $this->dateFrom = $from?->toDateString();
        $this->dateTo   = $to?->toDateString();
        $this->dispatch('ops-filters-updated', filters: $this->filtersPayload());
    }

    public function resetFilters(): void
    {
        $this->companyId = null;
        $this->transporterId = null;
        $this->brandId = null;
        $this->region = null;
        $this->preset = '7d';
        [$from, $to] = $this->presetRange('7d');
        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
        $this->dispatch('ops-filters-updated', filters: $this->filtersPayload());
    }

    /**
     * Snapshot the filter values so nested panels can reconstruct
     * an OperationsFilters DTO on their side. Keys are camelCase to
     * match the DTO constructor parameters.
     *
     * @return array<string,mixed>
     */
    public function filtersPayload(): array
    {
        return [
            'from'          => $this->dateFrom,
            'to'            => $this->dateTo,
            'companyId'     => $this->companyId,
            'transporterId' => $this->transporterId,
            'brandId'       => $this->brandId,
            'region'        => $this->region,
        ];
    }

    /**
     * "Wait longer" on a single stale row. Requires a non-empty comment
     * on that row (per-job key in `$staleComments`) — an empty snooze
     * would strip the whole point of the gate, which is a written
     * record of WHY ops is happy to sit on this for another week.
     *
     * The actual write goes through `Job::snoozeStaleAction()` so the
     * audit trail + timeline note fire from one place, and so this
     * component stays a thin controller for the modal state.
     */
    public function snoozeStale(int $jobId): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        $comment = trim((string) ($this->staleComments[$jobId] ?? ''));
        if ($comment === '') {
            $this->addError('staleComment.' . $jobId, 'Add a short reason before waiting longer.');
            return;
        }

        $job = Job::query()
            ->whereKey($jobId)
            ->where('created_by_user_id', $actor->id) // creators only, per plan
            ->first();

        if (! $job) {
            // Silently drop: either the job was moved/cancelled by
            // someone else while the modal was open, or the actor is
            // not the creator. Either way, the next render pulls a
            // fresh list and the row disappears.
            unset($this->staleComments[$jobId]);
            return;
        }

        $job->snoozeStaleAction($actor, $comment);
        unset($this->staleComments[$jobId]);
        $this->resetErrorBag('staleComment.' . $jobId);
    }

    /**
     * Close the modal. Only permitted when the actor has no OWN stale
     * rows still in play — otherwise we drop back into the modal on
     * next render anyway, so the button pretends not to exist. The
     * server-side guard here also stops a crafted request from
     * bypassing the disabled attribute in the client.
     */
    public function dismissStaleGate(): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        $data = (new StaleActionJobsQuery())->forUser($actor);
        if ($data['owned']->isNotEmpty()) {
            return;
        }

        $this->showStaleGate = false;
    }

    public function render()
    {
        $stale = $this->currentStaleData();

        return view('livewire.admin.operations.operations-dashboard', [
            'companies'    => Company::query()
                ->whereIn('type', [Company::TYPE_OEM, Company::TYPE_DEALER, Company::TYPE_CUSTOMER])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->prepend((object) ['id' => null, 'name' => 'All customers']),
            'transporters' => Company::query()
                ->where('type', Company::TYPE_TRANSPORTER)
                ->orderBy('name')
                ->get(['id', 'name']),
            'brands'       => Brand::orderBy('name')->get(['id', 'name']),
            'provinces'    => [
                'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal',
                'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
            ],
            'presets'      => [
                'today' => 'Today',
                '7d'    => 'Last 7 days',
                '30d'   => 'Last 30 days',
                'mtd'   => 'Month to date',
            ],
            'windowLabel'  => $this->windowLabel(),
            'filters'      => OperationsFilters::fromArray($this->filtersPayload()),
            'stale'        => $stale,
        ]);
    }

    /**
     * Pulled into its own method so the render + close-guard paths
     * cannot disagree on what "the current stale set" means.
     *
     * @return array{owned: Collection, others: Collection, total: int, worst_days: int}
     */
    private function currentStaleData(): array
    {
        $actor = auth()->user();
        if (! $actor || ! method_exists($actor, 'isInternal') || ! $actor->isInternal()) {
            return [
                'owned'      => collect(),
                'others'     => collect(),
                'total'      => 0,
                'worst_days' => 0,
            ];
        }

        return (new StaleActionJobsQuery())->forUser($actor);
    }

    /**
     * @return array{0:Carbon, 1:Carbon}
     */
    private function presetRange(string $preset): array
    {
        $now = now();
        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '30d'   => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'mtd'   => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            default => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()], // 7d
        };
    }

    private function applyPresetIfNoRange(): void
    {
        if ($this->dateFrom && $this->dateTo) {
            return;
        }

        [$from, $to] = $this->presetRange($this->preset);
        $this->dateFrom = $from->toDateString();
        $this->dateTo   = $to->toDateString();
    }

    private function windowLabel(): string
    {
        if (! $this->dateFrom || ! $this->dateTo) {
            return '';
        }

        $from = Carbon::parse($this->dateFrom);
        $to   = Carbon::parse($this->dateTo);
        return $from->format('d M') . ' – ' . $to->format('d M Y');
    }
}
