<?php

namespace App\Domain\Operations;

use App\Models\Job;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Carbon;

/**
 * Frozen filter set for a single render of the operations dashboard.
 *
 * The shell component holds the raw #[Url] state; when it dispatches
 * `filters-updated` it constructs one of these DTOs and each panel
 * consumes it. Panels never read shell state directly — the DTO is
 * the single source of truth and is cacheable via cacheKey().
 *
 * All predicates default to a ProSelver-executed scope so the ops
 * dashboard answers "how is OUR dispatch operation doing"; dealer-
 * internal / third-party / self-collect work is opt-in via
 * `allExecutors`.
 */
final class OperationsFilters
{
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $companyId = null,
        public readonly ?int $transporterId = null,
        public readonly ?int $brandId = null,
        public readonly ?string $region = null,
        public readonly ?string $statusFilter = null,
        public readonly bool $allExecutors = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            from: isset($data['from']) && $data['from'] !== null && $data['from'] !== ''
                ? Carbon::parse($data['from'])->startOfDay()
                : null,
            to: isset($data['to']) && $data['to'] !== null && $data['to'] !== ''
                ? Carbon::parse($data['to'])->endOfDay()
                : null,
            companyId: self::intOrNull($data['companyId'] ?? null),
            transporterId: self::intOrNull($data['transporterId'] ?? null),
            brandId: self::intOrNull($data['brandId'] ?? null),
            region: self::stringOrNull($data['region'] ?? null),
            statusFilter: self::stringOrNull($data['statusFilter'] ?? null),
            allExecutors: (bool) ($data['allExecutors'] ?? false),
        );
    }

    /**
     * Apply the entity filters (company / transporter / brand / region)
     * to a Job query. Date and status filters are NOT applied here —
     * those are per-metric decisions made in the query classes.
     */
    public function applyEntityScope(EloquentBuilder $query): EloquentBuilder
    {
        // Every filter column is fully qualified because callers
        // (LaneSummaryQuery etc.) join `locations` twice — an
        // unqualified `company_id` / `executor_type` blows up with
        // "ambiguous column" the moment those joins are present.
        if (! $this->allExecutors) {
            $query->where('transport_jobs.executor_type', Job::EXECUTOR_PROSELVER);
        }

        if ($this->companyId)     { $query->where('transport_jobs.company_id', $this->companyId); }
        if ($this->transporterId) { $query->where('transport_jobs.executing_company_id', $this->transporterId); }
        if ($this->brandId)       { $query->where('transport_jobs.brand_id', $this->brandId); }
        if ($this->region) {
            $region = $this->region;
            $query->where(function ($w) use ($region) {
                $w->whereHas('pickupLocation',    fn ($l) => $l->where('province', $region))
                  ->orWhereHas('deliveryLocation', fn ($l) => $l->where('province', $region));
            });
        }

        return $query;
    }

    /**
     * Stable cache key that changes whenever any predicate does.
     * Includes a coarse 30-second bucket so cached data doesn't
     * outlive its useful window.
     */
    public function cacheKey(string $panel, int $bucketSeconds = 30): string
    {
        $bucket = intdiv(now()->timestamp, max(1, $bucketSeconds));

        $payload = [
            'panel'         => $panel,
            'from'          => $this->from?->toIso8601String(),
            'to'            => $this->to?->toIso8601String(),
            'companyId'     => $this->companyId,
            'transporterId' => $this->transporterId,
            'brandId'       => $this->brandId,
            'region'        => $this->region,
            'statusFilter'  => $this->statusFilter,
            'allExecutors'  => $this->allExecutors,
            'bucket'        => $bucket,
        ];

        return 'ops:' . md5(serialize($payload));
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }
}
