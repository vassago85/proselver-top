<?php

use App\Models\SystemSetting;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * `suggest()` is the single funnel into Google's Geocoding API from the
 * bulk-import preview, address-book "look up" button and cleanup UI --
 * we exercise the multi-result parse here so all three call sites share
 * the same guarantees about candidate shape and empty-array fallback.
 */
function fakeGeocodeMultiResponse(): array
{
    return [
        'status' => 'OK',
        'results' => [
            [
                'formatted_address' => '12 Sample Road, Randburg, 2194, South Africa',
                'place_id' => 'ChIJ_place_1',
                'geometry' => [
                    'location' => ['lat' => -26.0930, 'lng' => 28.0050],
                ],
                'address_components' => [
                    ['long_name' => 'Randburg',   'short_name' => 'Randburg',   'types' => ['locality']],
                    ['long_name' => 'Gauteng',    'short_name' => 'GP',         'types' => ['administrative_area_level_1']],
                    ['long_name' => 'South Africa','short_name' => 'ZA',        'types' => ['country']],
                ],
            ],
            [
                'formatted_address' => '55 Sample Street, Sandton, 2196, South Africa',
                'place_id' => 'ChIJ_place_2',
                'geometry' => [
                    'location' => ['lat' => -26.1080, 'lng' => 28.0561],
                ],
                'address_components' => [
                    ['long_name' => 'Sandton', 'short_name' => 'Sandton', 'types' => ['sublocality_level_1']],
                    ['long_name' => 'Gauteng', 'short_name' => 'GP',      'types' => ['administrative_area_level_1']],
                ],
            ],
        ],
    ];
}

test('suggest returns normalised candidates from a multi-result geocoder payload', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response(fakeGeocodeMultiResponse(), 200),
    ]);

    $out = GeocodingService::suggest('Sample Road', 5);

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'formatted_address' => '12 Sample Road, Randburg, 2194, South Africa',
        'city' => 'Randburg',
        'province' => 'Gauteng',
        'lat' => -26.0930,
        'lng' => 28.0050,
        'place_id' => 'ChIJ_place_1',
    ]);
    // Second candidate reads city from sublocality_level_1 as fallback.
    expect($out[1]['city'])->toBe('Sandton');
    expect($out[1]['province'])->toBe('Gauteng');
});

test('suggest honours the limit parameter', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response(fakeGeocodeMultiResponse(), 200),
    ]);

    $out = GeocodingService::suggest('Sample', 1);

    expect($out)->toHaveCount(1);
});

test('suggest returns empty when the API key is missing', function () {
    // No SystemSetting, no env config -- suggest must gracefully return
    // [] rather than throwing so callers can fall back to keep-as-typed.
    config(['services.google_maps.api_key' => null]);
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => fn () => throw new RuntimeException('should not be called'),
    ]);

    expect(GeocodingService::suggest('Sample'))->toBe([]);
});

test('suggest returns empty on ZERO_RESULTS', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
            'status' => 'ZERO_RESULTS',
            'results' => [],
        ], 200),
    ]);

    expect(GeocodingService::suggest('random gibberish'))->toBe([]);
});

test('suggest ignores results that have no coordinates', function () {
    // Postal-code-only results sometimes come back without a
    // geometry.location -- we drop them because a suggestion the user
    // can pick that then breaks route/toll estimation is worse than
    // "no suggestion".
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => 'Broken Result',
                    'geometry' => [],
                    'address_components' => [],
                ],
                [
                    'formatted_address' => '12 Sample Road, Randburg',
                    'geometry' => ['location' => ['lat' => -26.09, 'lng' => 28.00]],
                    'address_components' => [
                        ['long_name' => 'Randburg', 'types' => ['locality']],
                    ],
                ],
            ],
        ], 200),
    ]);

    $out = GeocodingService::suggest('Sample');
    expect($out)->toHaveCount(1);
    expect($out[0]['formatted_address'])->toBe('12 Sample Road, Randburg');
});

test('geocodeDetailed still returns the top hit for legacy callers', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response(fakeGeocodeMultiResponse(), 200),
    ]);

    $top = GeocodingService::geocodeDetailed('Sample Road');

    expect($top)->not->toBeNull();
    expect($top['formatted_address'])->toBe('12 Sample Road, Randburg, 2194, South Africa');
    expect($top['city'])->toBe('Randburg');
    expect($top['lat'])->toBe(-26.0930);
});
