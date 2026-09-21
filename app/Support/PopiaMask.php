<?php

namespace App\Support;

/**
 * POPIA-friendly masks for PII we still need to hint at on operational
 * screens but must not display in full.  Full values live in the source
 * of truth (`driver_profiles`, `users`); every use-site that renders a
 * personal identifier for context — order detail, ops queue, dispatch
 * cards — should route through here.
 *
 * The masks preserve enough of the identifier for a human to recognise
 * a specific record they already know (last four SA-ID digits, last
 * three of a cellphone number) while dropping the digits an attacker
 * would need to derive age, gender, citizenship, or place a call.
 */
class PopiaMask
{
    /**
     * Mask a South African ID number.  Real IDs are 13 digits (YYMMDD
     * SSSS CAZ) but we tolerate any length so historical rows with
     * padding, spaces or a stray hyphen still render safely.
     *
     * Blank / null / anything shorter than the tail we're keeping is
     * collapsed to an em dash so the UI doesn't leak the raw value
     * via the fallback path.
     */
    public static function saId(?string $id, int $keepTail = 4): string
    {
        $digits = preg_replace('/\D+/', '', (string) $id);
        if ($digits === '' || strlen($digits) <= $keepTail) {
            return '—';
        }

        $tail = substr($digits, -$keepTail);
        $mask = str_repeat('•', strlen($digits) - $keepTail);

        return $mask.$tail;
    }

    /**
     * Mask a cellphone number down to the last three digits.  Same
     * digit-only normalisation as saId() so `+27 82 123 4567`,
     * `0821234567` and `082-123-4567` all render as `•••••••567`.
     *
     * Ops still needs the last-few-digits to disambiguate two drivers
     * with the same first name in a fleet, without publishing the
     * full number every time the order page renders.
     */
    public static function cellphone(?string $phone, int $keepTail = 3): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '' || strlen($digits) <= $keepTail) {
            return '—';
        }

        $tail = substr($digits, -$keepTail);
        $mask = str_repeat('•', strlen($digits) - $keepTail);

        return $mask.$tail;
    }

    /**
     * Reduce two possibly-different phone numbers to a single display
     * string.  Prefers the first non-blank; the "duplicate" flag says
     * whether the caller can safely render ONE row instead of two
     * labelled "Phone" / "Cellphone" showing the same number.
     *
     * Duplicates are declared when:
     *   - only one of the two inputs is filled (nothing to duplicate); OR
     *   - both inputs normalise to the same canonical SA cellphone
     *     digits, so `0821234567` and `+27 82 123 4567` are one number.
     *
     * @return array{0: string, 1: bool}  [$masked, $isDuplicate]
     */
    public static function preferredCellphone(?string $a, ?string $b): array
    {
        $aFilled = $a !== null && trim($a) !== '';
        $bFilled = $b !== null && trim($b) !== '';

        if (!$aFilled && !$bFilled) {
            return ['—', true];
        }
        if (!$aFilled || !$bFilled) {
            return [self::cellphone($aFilled ? $a : $b), true];
        }

        $canonA = self::canonicalSaCell($a);
        $canonB = self::canonicalSaCell($b);
        $isDup = $canonA !== '' && $canonA === $canonB;

        return [self::cellphone($a), $isDup];
    }

    /**
     * Collapse SA cellphone formats to a single canonical digit
     * sequence so `+27 82 123 4567`, `0821234567`, `27821234567` and
     * `082-123-4567` all compare equal.
     *
     * The 27 -> 0 rewrite is deliberately conservative: only applied
     * when the digits start with `27` and produce a 10-digit result
     * beginning with 0.  Everything else is returned as-is (masking
     * still works on the raw digits; only the equality check needs
     * canonicalisation).
     */
    private static function canonicalSaCell(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '27') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }
        return $digits;
    }
}
