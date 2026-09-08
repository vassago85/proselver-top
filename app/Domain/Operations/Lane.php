<?php

namespace App\Domain\Operations;

/**
 * A dispatch corridor: origin province → destination province.
 *
 * Ops thinks in lanes ("EC → GP has five waiting, consolidate"), not
 * in individual rows. This value object encapsulates the pair so the
 * queue can group rows without every caller re-implementing the same
 * "canonicalise both ends and glue with an arrow" logic.
 *
 * A lane is INCOMPLETE when either endpoint is missing (e.g. the
 * pickup location was deleted, or the customer typed an address but
 * no province). Incomplete lanes get a single "Lane not set" heading
 * pinned at the top of the queue — they are the ones ops most needs
 * to fix, not hide behind a "—".
 */
final class Lane
{
    /**
     * Sentinel label for lanes that cannot be grouped by province.
     */
    public const NOT_SET_LABEL = 'Lane not set';

    /**
     * @param  string|null  $origin       canonicalised origin province
     * @param  string|null  $destination  canonicalised destination province
     */
    public function __construct(
        public readonly ?string $origin,
        public readonly ?string $destination,
    ) {
    }

    /**
     * Build from raw location province strings, canonicalising both
     * ends so mixed casing collapses to one lane.
     */
    public static function from(?string $originRaw, ?string $destinationRaw): self
    {
        return new self(
            ProvinceLabel::canonicalise($originRaw),
            ProvinceLabel::canonicalise($destinationRaw),
        );
    }

    /**
     * True when both origin and destination are known. False as soon
     * as either endpoint is missing — that lane heads to the
     * "Lane not set" bucket.
     */
    public function isComplete(): bool
    {
        return $this->origin !== null && $this->destination !== null;
    }

    /**
     * The heading string to show above the row group. Incomplete
     * lanes collapse into a single bucket so ops sees "12 jobs need a
     * pickup province" instead of a dozen "— → GP" fragments.
     */
    public function label(): string
    {
        if (! $this->isComplete()) {
            return self::NOT_SET_LABEL;
        }

        return $this->origin . ' → ' . $this->destination;
    }

    /**
     * Stable grouping key: `label()` is fine for grouping AND for
     * display, but callers sometimes want a distinct key that never
     * changes across two `Lane::from()` calls with the same inputs.
     */
    public function key(): string
    {
        return $this->label();
    }
}
