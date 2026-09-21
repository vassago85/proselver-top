<?php

namespace App\Livewire\Admin\Operations\Panels;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\PriorityMovementsQuery;
use App\Models\Job;
use App\Models\User;
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

    /**
     * Per-row driver picker state for the inline assign action on
     * planned-but-unassigned queue rows.  Keyed by job id so a
     * dispatcher can queue several selections without the pickers
     * jumping between rows.  Mirrors /admin/dispatch and the
     * "Awaiting Driver" section on /admin/planning so the same
     * pattern feels familiar wherever a driver-less planned job
     * shows up.
     *
     * @var array<int,string>
     */
    public array $driverSelections = [];

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

    /**
     * Attach a driver to a planned-but-unassigned queue row inline,
     * so the ops controller can clear the "planned · N hours over"
     * backlog without opening each order.  Mirrors
     * /admin/dispatch::assignDriver() and planning::assignDriverInline
     * so all three surfaces share the same status guard, audit trail
     * and flash text.
     */
    public function assignDriverInline(int $jobId): void
    {
        $driverId = $this->driverSelections[$jobId] ?? null;

        if (!$driverId) {
            session()->flash('error', 'Pick a driver first.');
            return;
        }

        $job = Job::findOrFail($jobId);

        if (!$job->canTransitionTo(Job::STATUS_DRIVER_ASSIGNED)) {
            session()->flash('error', "Order {$job->job_number} can't move to driver-assigned from its current status.");
            return;
        }

        $driver = User::findOrFail($driverId);
        $job->driver_user_id = $driver->id;
        $job->transitionTo(Job::STATUS_DRIVER_ASSIGNED);

        unset($this->driverSelections[$jobId]);

        // Drop the cached query result so the row disappears from
        // the queue on the next poll; without this the reader still
        // sees the same job as unassigned for up to `live_ttl` seconds.
        $filters = new OperationsFilters(
            companyId: $this->companyId,
            transporterId: $this->transporterId,
            brandId: $this->brandId,
            region: $this->region,
        );
        Cache::forget($filters->cacheKey('priority_movements'));

        session()->flash('success', "{$driver->name} assigned to {$job->job_number}.");
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

        // Driver pool for the inline picker.  Only fetched when at
        // least one row on the queue is planned-without-driver (the
        // only rows that show the picker), so the query is skipped
        // entirely on a queue full of dispatched / on-road rows.
        $driverOptions = [];
        $hasAssignable = $data['jobs']->contains(
            fn ($j) => $j->status === Job::STATUS_PLANNED && $j->driver_user_id === null
        );
        if ($hasAssignable) {
            $driverOptions = User::whereHas('roles', fn ($q) => $q->where('slug', 'driver'))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($d) => [
                    'value' => (string) $d->id,
                    'label' => $d->name,
                ])
                ->values()
                ->all();
        }

        return view('livewire.admin.operations.panels.priority-movements', [
            'lanes'         => $data['lanes'],
            'jobs'          => $data['jobs'],
            'overdueTotal'  => $overdueTotal,
            'updatedAt'     => now(),
            'driverOptions' => $driverOptions,
        ]);
    }
}
