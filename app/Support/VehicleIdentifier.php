<?php

namespace App\Support;

/**
 * VIN vs South-African registration classifier.
 *
 * Operators book against either a chassis / VIN or a licence plate,
 * depending on what the customer told them.  The two live in separate
 * columns (`vin` and `registration`) on `transport_jobs`,
 * `dealer_stock` and `movement_requests`, and the whole matching /
 * dedup / owner-approval stack keys off `vin`.  A registration in the
 * `vin` column silently breaks stock lookup, the movement linker and
 * duplicate detection, so we need to route the typed value into the
 * right column at capture time.
 *
 * Heuristic (SA context):
 *  - normalise: uppercase, drop everything non-alphanumeric.
 *  - 12+ chars  -> VIN         (real VINs are 17; SA truck chassis
 *                              numbers regularly run 13-15).
 *  - <=9 chars  -> registration (typical SA plates are 6-9 chars once
 *                              spaces / dashes are stripped, e.g.
 *                              "KTR284EC", "CA123456", "ND 12345").
 *  - 10-11 chars -> grey zone, resolved by tiebreakers:
 *      * contains I / O / Q  -> registration  (VIN standard bans
 *                              those letters to avoid confusion with
 *                              1 / 0);
 *      * ends in a SA province code -> registration;
 *      * otherwise VIN, with `isAmbiguous()` = true so the UI asks
 *        the operator to confirm.
 */
class VehicleIdentifier
{
    public const TYPE_VIN = 'vin';

    public const TYPE_REGISTRATION = 'registration';

    /**
     * Two-letter (and one three-letter, KZN) province suffixes that
     * appear on the end of SA plates.  L is Limpopo's single-letter
     * form used on some older plates.  Longest-match first so KZN is
     * detected before ZN / N.
     */
    private const PROVINCE_SUFFIXES = [
        'KZN', 'GP', 'WP', 'EC', 'ZN', 'FS', 'NC', 'NW', 'MP', 'L',
    ];

    /** Letters the VIN standard (ISO 3779) never uses. */
    private const VIN_FORBIDDEN_LETTERS = ['I', 'O', 'Q'];

    /** Uppercase, strip anything that isn't a letter or digit. */
    public static function normalise(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($raw)) ?? '';
    }

    /**
     * Classify the input into one of the two identifier types.  Never
     * returns an ambiguous / unknown value -- when the input is empty
     * or in the grey zone we still pick the more likely of the two so
     * callers can act on it; `isAmbiguous()` tells the UI whether it
     * should ask the user to confirm.
     */
    public static function classify(?string $raw): string
    {
        $value = self::normalise($raw);
        $len = strlen($value);

        if ($len === 0) {
            // Nothing to work with; default to registration so a
            // stray keystroke doesn't leave a fresh form claiming
            // "Detected: VIN" before anything is typed.
            return self::TYPE_REGISTRATION;
        }

        if ($len >= 12) {
            return self::TYPE_VIN;
        }

        if ($len <= 9) {
            return self::TYPE_REGISTRATION;
        }

        if (self::containsVinForbiddenLetters($value)) {
            return self::TYPE_REGISTRATION;
        }

        if (self::endsWithProvinceSuffix($value)) {
            return self::TYPE_REGISTRATION;
        }

        return self::TYPE_VIN;
    }

    /**
     * True when the input landed in the 10-11 char grey zone AND
     * neither tiebreaker triggered -- i.e. `classify()` had to pick
     * a side without much confidence.  The UI shows a "confirm?"
     * badge in that case.
     */
    public static function isAmbiguous(?string $raw): bool
    {
        $value = self::normalise($raw);
        $len = strlen($value);

        if ($len < 10 || $len > 11) {
            return false;
        }

        if (self::containsVinForbiddenLetters($value)) {
            return false;
        }

        if (self::endsWithProvinceSuffix($value)) {
            return false;
        }

        return true;
    }

    /** Convenience helper -- true iff `classify()` returns VIN. */
    public static function isVin(?string $raw): bool
    {
        return self::classify($raw) === self::TYPE_VIN;
    }

    /** Convenience helper -- true iff `classify()` returns registration. */
    public static function isRegistration(?string $raw): bool
    {
        return self::classify($raw) === self::TYPE_REGISTRATION;
    }

    private static function containsVinForbiddenLetters(string $value): bool
    {
        foreach (self::VIN_FORBIDDEN_LETTERS as $letter) {
            if (str_contains($value, $letter)) {
                return true;
            }
        }
        return false;
    }

    private static function endsWithProvinceSuffix(string $value): bool
    {
        foreach (self::PROVINCE_SUFFIXES as $suffix) {
            if (str_ends_with($value, $suffix)) {
                return true;
            }
        }
        return false;
    }
}
