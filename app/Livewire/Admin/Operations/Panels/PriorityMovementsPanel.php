<?php

namespace App\Livewire\Admin\Operations\Panels;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\PriorityMovementsQuery;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Top-12 stuck jobs, grouped by lane (origin_province → destination_province).
 *
 * A lane heading turns five separate "EC → GP" rows into one dispatch
 * decision, which is the single biggest usability change on the page.
 */
#[Lazy]
class PriorityMovementsPanel extends Component
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
        return view('livewire.admin.operations.panels.skeleton', ['rows' => 4, 'tiles' => 1]);
    }

    public function render()
    {
        $filters = new OperationsFilters(
            companyId: $this->companyId,
            transporterId: $this->transporterId,
            brandId: $this->brandId,
            region: $this->region,
        );

        $data = Cache::remember(
            $filters->cacheKey('priority_movements'),
            (int) config('operations.cache.live_ttl', 30),
            fn () => (new PriorityMovementsQuery())->get($filters),
        );

        // Total overdue = rows where hours_in_stage > stage threshold.
        // Same predicate the At-risk hero tile uses so the two numbers
        // can never disagree.
        $overdueTotal = $data['jobs']->where('is_overdue', true)->count();

        return view('livewire.admin.operations.panels.priority-movements', [
            'lanes'        => $data['lanes'],
            'jobs'         => $data['jobs'],
            'overdueTotal' => $overdueTotal,
            'updatedAt'    => now(),
        ]);
    }
}
