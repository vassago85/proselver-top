<?php

namespace App\Domain\Operations;

/**
 * Canonicalise province labels for grouping and display.
 *
 * The `locations.province` column has drifted over the years — some
 * rows carry `Gauteng` (title case, from the current UI), some carry
 * `GAUTENG` (uppercase, from a legacy import), some carry `gauteng`
 * (from a JSON export). Grouping by raw value splits the same
 * dispatch corridor into two or three lanes on the ops dashboard
 * and misleads planners into thinking there's less traffic on each
 * lane than there actually is.
 *
 * `canonicalise()` normalises any of those to the ZA-standard label
 * (`Gauteng`, `KwaZulu-Natal`, etc.). Unknown provinces are returned
 * in title case as a best-effort fallback.
 *
 * Used by LaneSummaryQuery + PriorityMovementsQuery so the two
 * always agree on which rows belong to the same corridor.
 */
final class ProvinceLabel
{
    /**
     * lower-case slug -> canonical display label.
     */
    private const CANONICAL = [
        'eastern cape'   => 'Eastern Cape',
        'free state'     => 'Free State',
        'gauteng'        => 'Gauteng',
        'kwazulu-natal'  => 'KwaZulu-Natal',
        'kwazulu natal'  => 'KwaZulu-Natal',   // legacy no-hyphen variant
        'kzn'            => 'KwaZulu-Natal',   // abbreviated
        'limpopo'        => 'Limpopo',
        'mpumalanga'     => 'Mpumalanga',
        'north west'     => 'North West',
        'north-west'     => 'North West',
        'northern cape'  => 'Northern Cape',
        'western cape'   => 'Western Cape',
    ];

    /**
     * Return the canonical form of a province string, or null when
     * the input is null / blank. Unknown labels are title-cased as a
     * safe fallback so they still render sensibly.
     */
    public static function canonicalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }

        if (isset(self::CANONICAL[$key])) {
            return self::CANONICAL[$key];
        }

        // Unknown province — return best-effort title case rather
        // than the raw string, so weird casings don't leak to the UI.
        return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
    }
}
