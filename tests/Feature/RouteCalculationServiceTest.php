<?php

use App\Models\Location;
use App\Models\SystemSetting;
use App\Services\RouteCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeOkRouteResponse(): array
{
    return [
        'status' => 'OK',
        'routes' => [[
            'legs' => [[
                'distance' => ['value' => 1_085_300],
                'duration' => ['value' => 44_580],
                'steps' => [[
                    'html_instructions' => 'Head north on N2',
                    'polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                ]],
            ]],
            'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
        ]],
    ];
}

test('addressQuery prefers a street address and appends South Africa', function () {
    $location = new Location([
        'company_name' => 'PE Plant',
        'address' => '1 FAW Drive, Coega IDZ',
        'city' => 'Gqeberha',
        'province' => 'Eastern Cape',
    ]);

    expect(RouteCalculationService::addressQuery($location))
        ->toBe('1 FAW Drive, Coega IDZ, Gqeberha, Eastern Cape, South Africa');
});

test('calculate retries with address strings when lat/lng returns ZERO_RESULTS', function () {
    // Coega / Riverside industrial pins are often off-road for Google's
    // snap-to-road — consumer Maps shows "Driving not available" + flights.
    // Production must fall back to the street address before giving up.
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');

    $pickup = Location::create([
        'company_name' => 'PE Plant',
        'address' => '1 FAW Drive, Coega IDZ',
        'city' => 'Gqeberha',
        'province' => 'Eastern Cape',
        'latitude' => -33.79980000,
        'longitude' => 25.80250000,
        'type' => Location::TYPE_PLANT,
        'is_active' => true,
    ]);
    $delivery = Location::create([
        'company_name' => 'FAW Mbombela',
        'address' => '9 Laminar Flow, Riverside Industrial Park',
        'city' => 'Mbombela',
        'province' => 'Mpumalanga',
        'latitude' => -25.44907300,
        'longitude' => 30.95756010,
        'type' => Location::TYPE_DEALER,
        'is_active' => true,
    ]);

    Http::fake([
        'maps.googleapis.com/maps/api/directions/json*' => Http::sequence()
            ->push(['status' => 'ZERO_RESULTS', 'routes' => [], 'geocoded_waypoints' => [[], []]])
            ->push(fakeOkRouteResponse()),
    ]);

    $result = RouteCalculationService::calculate($pickup, $delivery);

    expect($result)->not->toBeNull()
        ->and($result['distance_km'])->toBe(1085.3)
        ->and($result['duration_minutes'])->toBe(743);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['origin'] === '-33.79980000,25.80250000'
        || str_contains((string) $request->url(), '-33.79980000'));
    Http::assertSent(fn ($request) => str_contains((string) ($request['origin'] ?? ''), '1 FAW Drive')
        || str_contains(urldecode((string) $request->url()), '1 FAW Drive'));
});
