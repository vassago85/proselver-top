<?php

namespace App\Domain\Operations;

use App\Models\SystemSetting;

/**
 * One-stop resolver for the exception thresholds the ops dashboard
 * uses. Reads config/operations.php as the shipped default and lets
 * SystemSetting override each value at runtime.
 *
 * The legacy SystemSetting keys (`ops.alert.awaiting_confirm_days`
 * etc.) stored days for some fields and hours for others. This
 * resolver normalises the whole set to hours and layers legacy
 * day-based keys on top for backwards compatibility.
 */
class OperationsThresholds
{
    /**
     * @return array<string,int>  keys match config('operations.thresholds')
     */
    public static function forExceptions(): array
    {
        $baseline = config('operations.thresholds', []);

        // Layer legacy day-based ops.alert.* keys on top so an ops
        // manager who tuned "awaiting_confirm_days = 3" a year ago
        // still sees that value honoured after the rebuild.
        $legacyOverrides = array_filter([
            'awaiting_confirmation_hours'    => self::hoursFromLegacy('ops.alert.awaiting_confirm_days', 24),
            'ready_no_driver_hours'          => self::intSetting('ops.alert.to_dispatch_hours'),
            'dispatched_not_collected_hours' => self::hoursFromLegacy('ops.alert.dispatched_days', 24),
            'long_in_transit_hours'          => self::hoursFromLegacy('ops.alert.in_transit_days', 24),
        ], fn ($v) => $v !== null);

        return array_replace(
            array_map(fn ($v) => (int) $v, $baseline),
            $legacyOverrides,
        );
    }

    public static function atRiskLevels(): array
    {
        return config('operations.at_risk', ['warning_count' => 5, 'critical_count' => 20]);
    }

    private static function hoursFromLegacy(string $key, int $multiplier): ?int
    {
        $raw = SystemSetting::get($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        return ((int) $raw) * $multiplier;
    }

    private static function intSetting(string $key): ?int
    {
        $raw = SystemSetting::get($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
