<?php

namespace App\Livewire\Admin\Operations\Panels;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\LivePipelineQuery;
use App\Domain\Operations\Queries\PriorityMovementsQuery;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The four live-pipeline tiles + the At-risk hero.
 *
 * LIVE MEANING: ignores the date filter entirely. Numbers describe
 * state right now, not what happened in a window.
 *
 * Refreshes every 30 seconds while the tab is open, so an operator
 * can leave the dashboard visible and act on drift as it happens.
 */
#[Lazy]
class LivePipelinePanel extends Component
{
    public ?int    $companyId     = null;
    public ?int    $transporterId = null;
    public ?int    $brandId       = null;
    public ?string $region        = null;

    /**
     * Fires the very first time the panel resolves. Parent already
     * passed initial filter values via #[Reactive]-style props so we
     * only need to seed nothing here.
     */
    public function mount(?int $companyId = null, ?int $transporterId = null, ?int $brandId = null, ?string $region = null): void
    {
        $this->companyId = $companyId;
        $this->transporterId = $transporterId;
        $this->brandId = $brandId;
        $this->region = $region;
    }

    /**
     * The shell dispatches this on any filter change. Live panels
     * IGNORE the date range entirely — a date-range change must not
     * force a live re-query.
     *
     * Payload is a single `filters` array (not spread) because
     * Livewire's `Event` class reserves the top-level keys
     * `ref` / `component` / `el` / `self` / `to`. See the shell
     * component's `updated()` docblock.
     *
     * @param  array<string,mixed>  $filters
     */
    #[On('ops-filters-updated')]
    public function onFilters(array $filters = []): void
    {
        $companyId     = isset($filters['companyId'])     ? (int) $filters['companyId']     : null;
        $transporterId = isset($filters['transporterId']) ? (int) $filters['transporterId'] : null;
        $brandId       = isset($filters['brandId'])       ? (int) $filters['brandId']       : null;
        $region        = $filters['region'] ?? null;

        // Livewire hydrates the (int) cast of a null-ish value to 0.
        // Guard so we don't lock the panel to "company 0" when the
        // user is filtering on all customers.
        if ($companyId === 0)     { $companyId = null; }
        if ($transporterId === 0) { $transporterId = null; }
        if ($brandId === 0)       { $brandId = null; }

        // If only the date range moved, skip the render.
        if (
            $companyId === $this->companyId
            && $transporterId === $this->transporterId
            && $brandId === $this->brandId
            && $region === $this->region
        ) {
            return;
        }

        $this->companyId = $companyId;
        $this->transporterId = $transporterId;
        $this->brandId = $brandId;
        $this->region = $region;
    }

    public function placeholder()
    {
        return view('livewire.admin.operations.panels.skeleton', ['rows' => 1, 'tiles' => 5]);
    }

    public function render()
    {
        $filters = new OperationsFilters(
            companyId: $this->companyId,
            transporterId: $this->transporterId,
            brandId: $this->brandId,
            region: $this->region,
        );

        // Two queries max, cached for 30s so two operators looking
        // at the same page pay for one round-trip.
        $pipeline = Cache::remember(
            $filters->cacheKey('live_pipeline'),
            (int) config('operations.cache.live_ttl', 30),
            fn () => (new LivePipelineQuery())->get($filters),
        );

        // At-risk = jobs past their stage threshold on the ops queue.
        // Same predicate the queue uses for badges, so this tile and
        // the queue can never disagree by construction.
        $atRiskCount = Cache::remember(
            $filters->cacheKey('at_risk_count'),
            (int) config('operations.cache.live_ttl', 30),
            fn () => (new PriorityMovementsQuery())->overdueCount($filters),
        );

        $atRiskLevels = config('operations.at_risk', ['warning_count' => 5, 'critical_count' => 20]);
        $atRiskSeverity = match (true) {
            $atRiskCount === 0                             => 'neutral',
            $atRiskCount >= $atRiskLevels['critical_count'] => 'critical',
            $atRiskCount >= $atRiskLevels['warning_count']  => 'warning',
            default                                        => 'warning',
        };

        return view('livewire.admin.operations.panels.live-pipeline', [
            'pipeline'       => $pipeline,
            'atRiskCount'    => $atRiskCount,
            'atRiskSeverity' => $atRiskSeverity,
            'updatedAt'      => now(),
        ]);
    }
}
