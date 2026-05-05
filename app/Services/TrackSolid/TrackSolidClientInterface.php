<?php

namespace App\Services\TrackSolid;

/**
 * Vendor-agnostic shape of the GPS-tracker client. Pinning this
 * separately from the concrete `Client` lets the poller depend on the
 * interface and lets unit tests inject a fake without spinning up an
 * HTTP fake or a Mockery double.
 *
 * Methods returning structured arrays follow a consistent normalised
 * shape so a second tracker vendor (Wialon, Teltonika cloud, etc.) can
 * replace `Client` without changing the poller. See {@link normalisePosition()}
 * in the concrete client for the exact shape.
 */
interface TrackSolidClientInterface
{
    /**
     * Return whether credentials are configured AND the integration is
     * toggled on. Callers (poller / settings test page / wallboard) use
     * this to no-op gracefully when ops hasn't filled in the keys yet.
     */
    public function isConfigured(): bool;

    /**
     * Authenticate against the upstream API and return a usable access
     * token. Implementations are expected to cache the token for its
     * declared TTL and refresh transparently.
     *
     * @throws \RuntimeException when the credentials are missing or the
     *                           upstream rejects them.
     */
    public function authenticate(): string;

    /**
     * Return every device tied to the configured account, normalised to:
     *   [
     *     ['imei' => string, 'name' => ?string, 'status' => ?string],
     *     ...
     *   ]
     */
    public function listDevices(): array;

    /**
     * Return the latest position for each of the supplied IMEIs.
     * Normalised shape per row:
     *   [
     *     'tracker_id'  => string,            // imei
     *     'latitude'    => float,
     *     'longitude'   => float,
     *     'speed_kmh'   => ?float,
     *     'heading_deg' => ?float,
     *     'reported_at' => \DateTimeImmutable, // UTC
     *     'raw'         => array,             // verbatim upstream payload
     *   ]
     *
     * Implementations should use a single batch endpoint when the API
     * supports it; otherwise they may iterate `getDevicePosition()` in a
     * loop with light backoff.
     *
     * @param string[] $imeis
     */
    public function getLatestPositions(array $imeis): array;

    /**
     * Single-device fallback. Same shape as one row of getLatestPositions,
     * or null if the device hasn't reported yet.
     */
    public function getDevicePosition(string $imei): ?array;
}
