<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Location;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Direct Google Directions API smoke-test, by location ids or job_number.
 *
 * Shows the raw HTTP status, the Google "status" field (OK,
 * REQUEST_DENIED, OVER_QUERY_LIMIT, ZERO_RESULTS, ...), distance,
 * duration, the number of polyline points in overview_polyline vs
 * the steps[] concatenation, and any error_message.  Used to isolate
 * "calculate() returns null" issues without shell-quoting curl by hand.
 *
 * Usage:
 *   php artisan route:test 115 117                  # by location ids
 *   php artisan route:test --job=26050289           # by job_number
 */
class RouteTest extends Command
{
    protected $signature = 'route:test
        {pickup? : Pickup location id}
        {delivery? : Delivery location id}
        {--job= : Job number or job id (alternative to passing locations)}';

    protected $description = 'Smoke-test the Google Directions API for a pickup/delivery pair, with full response detail';

    public function handle(): int
    {
        $pickup = null;
        $delivery = null;

        if ($this->option('job')) {
            $arg = (string) $this->option('job');
            $job = ctype_digit($arg)
                ? Job::find((int) $arg) ?? Job::where('job_number', $arg)->first()
                : Job::where('job_number', $arg)->first();
            if (!$job) {
                $this->error("Job '{$arg}' not found.");
                return self::FAILURE;
            }
            $pickup = $job->pickupLocation;
            $delivery = $job->deliveryLocation;
            $this->info("Using locations from job {$job->job_number}");
        } else {
            $pid = $this->argument('pickup');
            $did = $this->argument('delivery');
            if (!$pid || !$did) {
                $this->error('Provide --job=NNN OR positional pickup_id delivery_id.');
                return self::FAILURE;
            }
            $pickup = Location::find((int) $pid);
            $delivery = Location::find((int) $did);
        }

        if (!$pickup || !$delivery) {
            $this->error('Pickup or delivery location not found.');
            return self::FAILURE;
        }

        $this->line("Pickup   #{$pickup->id} {$pickup->company_name}   ({$pickup->latitude}, {$pickup->longitude})");
        $this->line("Delivery #{$delivery->id} {$delivery->company_name} ({$delivery->latitude}, {$delivery->longitude})");

        if (!$pickup->latitude || !$pickup->longitude || !$delivery->latitude || !$delivery->longitude) {
            $this->error('One or both locations have no coordinates -- run locations:geocode first.');
            return self::FAILURE;
        }

        $apiKey = SystemSetting::get('google_maps_api_key', config('services.google_maps.api_key'));
        if (!$apiKey) {
            $this->error('No google_maps_api_key configured (system_settings or env).');
            return self::FAILURE;
        }
        $this->line("API key  length " . strlen($apiKey));
        $this->newLine();

        $coordOrigin = "{$pickup->latitude},{$pickup->longitude}";
        $coordDest = "{$delivery->latitude},{$delivery->longitude}";

        $response = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => $coordOrigin,
            'destination' => $coordDest,
            'region' => 'za',
            'key' => $apiKey,
        ]);

        $this->line("Attempt 1 — lat/lng");
        $this->line("  origin:      {$coordOrigin}");
        $this->line("  destination: {$coordDest}");
        $this->line("HTTP status: " . $response->status());
        $data = $response->json();
        $this->line("Google status: " . ($data['status'] ?? 'NO_STATUS_FIELD'));
        if (!empty($data['error_message'])) {
            $this->warn("error_message: " . $data['error_message']);
        }

        // Mirror RouteCalculationService: off-road industrial pins often
        // ZERO_RESULT on lat/lng but route fine on the street address.
        if (($data['status'] ?? null) === 'ZERO_RESULTS') {
            $addrOrigin = \App\Services\RouteCalculationService::addressQuery($pickup);
            $addrDest = \App\Services\RouteCalculationService::addressQuery($delivery);
            if ($addrOrigin && $addrDest) {
                $this->newLine();
                $this->line("Attempt 2 — address fallback (same as production now)");
                $this->line("  origin:      {$addrOrigin}");
                $this->line("  destination: {$addrDest}");
                $response = Http::get('https://maps.googleapis.com/maps/api/directions/json', [
                    'origin' => $addrOrigin,
                    'destination' => $addrDest,
                    'region' => 'za',
                    'key' => $apiKey,
                ]);
                $this->line("HTTP status: " . $response->status());
                $data = $response->json();
                $this->line("Google status: " . ($data['status'] ?? 'NO_STATUS_FIELD'));
                if (!empty($data['error_message'])) {
                    $this->warn("error_message: " . $data['error_message']);
                }
            }
        }

        if (empty($data['routes'][0])) {
            $this->newLine();
            $this->error('Google returned no routes. See messages above for the reason.');
            $this->line('Raw response head:');
            $this->line(substr(json_encode($data, JSON_PRETTY_PRINT), 0, 1200));
            return self::FAILURE;
        }

        $route = $data['routes'][0];
        $leg = $route['legs'][0];
        $overviewLen = strlen($route['overview_polyline']['points'] ?? '');

        $stepPoints = 0;
        foreach ($leg['steps'] ?? [] as $step) {
            if (!empty($step['polyline']['points'])) {
                $stepPoints += strlen($step['polyline']['points']);
            }
        }

        $this->newLine();
        $this->info('Route OK:');
        $this->line(sprintf('  Distance: %s km', round($leg['distance']['value'] / 1000, 2)));
        $this->line(sprintf('  Duration: %d minutes', (int) ceil($leg['duration']['value'] / 60)));
        $this->line(sprintf('  overview_polyline: %d chars (rough proxy for point count)', $overviewLen));
        $this->line(sprintf('  steps[] polyline:  %d chars total across %d steps', $stepPoints, count($leg['steps'] ?? [])));

        return self::SUCCESS;
    }
}
