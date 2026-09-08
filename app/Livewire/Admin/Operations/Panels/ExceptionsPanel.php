<?php

namespace App\Livewire\Admin\Operations\Panels;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\OperationsThresholds;
use App\Domain\Operations\Queries\ExceptionCountsQuery;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The six exception buckets, each rendered as a severity-dot row
 * linking into /admin/orders?exception=<bucket>.
 *
 * Live: no date filter. Polls every 60 seconds.
 */
#[Lazy]
class ExceptionsPanel extends Component
{
    public ?int    $companyId     = null;
    public ?int    $transporterId = null;
    public ?int    $brandId       = null;
    public ?string $region        = null;

    public function mount(?int $companyId = null, ?int $transporterId = null, ?int $brandId = null, ?string $region = null): void
    {
        $this->companyId = $companyId;
        $this->transporterId = $transporterId;
        $this->brandId = $brandId;
        $this->region = $region;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    #[On('ops-filters-updated')]
    public function onFilters(array $filters = []): void
    {
        $companyId     = isset($filters['companyId'])     ? (int) $filters['companyId']     : null;
        $transporterId = isset($filters['transporterId']) ? (int) $filters['transporterId'] : null;
        $brandId       = isset($filters['brandId'])       ? (int) $filters['brandId']       : null;
        $region        = $filters['region'] ?? null;

        if ($companyId === 0)     { $companyId = null; }
        if ($transporterId === 0) { $transporterId = null; }
        if ($brandId === 0)       { $brandId = null; }

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
        return view('livewire.admin.operations.panels.skeleton', ['rows' => 6, 'tiles' => 1]);
    }

    public function render()
    {
        $filters = new OperationsFilters(
            companyId: $this->companyId,
            transporterId: $this->transporterId,
            brandId: $this->brandId,
            region: $this->region,
        );

        $thresholds = OperationsThresholds::forExceptions();
        $counts = Cache::remember(
            $filters->cacheKey('exceptions'),
            (int) config('operations.cache.live_ttl', 30),
            fn () => (new ExceptionCountsQuery())->get($filters, $thresholds),
        );

        // Presentation model: label + sublabel + severity + link.
        // Sublabel carries the threshold so an ops manager who tuned
        // it via SystemSetting sees their choice reflected in the UI.
        $buckets = [
            [
                'key'       => 'awaiting_confirmation',
                'label'     => 'Awaiting customer confirmation',
                'sublabel'  => "> {$thresholds['awaiting_confirmation_hours']}h without a reply",
                'severity'  => 'warning',
                'count'     => $counts['awaiting_confirmation'],
            ],
            [
                'key'       => 'confirmation_issue',
                'label'     => 'Confirmation issue unresolved',
                'sublabel'  => 'customer flagged a problem',
                'severity'  => 'critical',
                'count'     => $counts['confirmation_issue'],
            ],
            [
                'key'       => 'ready_no_driver',
                'label'     => 'Ready · no driver',
                'sublabel'  => "> {$thresholds['ready_no_driver_hours']}h since confirmation",
                'severity'  => 'warning',
                'count'     => $counts['ready_no_driver'],
            ],
            [
                'key'       => 'dispatched_not_collected',
                'label'     => 'Dispatched · not collected',
                'sublabel'  => "> {$thresholds['dispatched_not_collected_hours']}h since driver assigned",
                'severity'  => 'warning',
                'count'     => $counts['dispatched_not_collected'],
            ],
            [
                'key'       => 'long_in_transit',
                'label'     => 'Long in transit',
                'sublabel'  => "> {$thresholds['long_in_transit_hours']}h since collection",
                'severity'  => 'critical',
                'count'     => $counts['long_in_transit'],
            ],
            [
                'key'       => 'pod_pending',
                'label'     => 'POD pending',
                'sublabel'  => "> {$thresholds['pod_pending_hours']}h since delivery",
                'severity'  => 'warning',
                'count'     => $counts['pod_pending'],
            ],
        ];

        return view('livewire.admin.operations.panels.exceptions', [
            'buckets'   => $buckets,
            'total'     => $counts['at_risk'],
            'updatedAt' => now(),
        ]);
    }
}
