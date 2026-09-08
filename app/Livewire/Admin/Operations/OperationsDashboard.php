<?php

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\OperationsFilters;
use App\Domain\Operations\Queries\ThroughputQuery;
use App\Models\Brand;
use App\Models\Company;
use Illuminate\Support\Carbon;
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

    public function mount(): void
    {
        $this->applyPresetIfNoRange();
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

    public function render()
    {
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
        ]);
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
