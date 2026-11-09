<?php

use App\Models\Location;
use App\Models\SystemSetting;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function isuzuEstablishmentPayload(): array
{
    return [
        'status' => 'OK',
        'results' => [[
            'formatted_address' => '12 Heidelberg Rd, City Deep, Johannesburg, 2001, South Africa',
            'place_id' => 'ChIJ_isuzu',
            'types' => ['establishment', 'point_of_interest'],
            'geometry' => [
                'location' => ['lat' => -26.217, 'lng' => 28.078],
                'location_type' => 'ROOFTOP',
            ],
            'address_components' => [
                ['long_name' => 'Johannesburg', 'types' => ['locality']],
                ['long_name' => 'City Deep', 'types' => ['sublocality_level_1']],
                ['long_name' => 'Gauteng', 'types' => ['administrative_area_level_1']],
                ['long_name' => 'South Africa', 'short_name' => 'ZA', 'types' => ['country']],
            ],
        ]],
    ];
}

test('a dealer-name stub takes the Places street and province in one save', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'findplacefromtext')) {
            return Http::response([
                'status' => 'OK',
                'candidates' => [['place_id' => 'ChIJ_isuzu']],
            ], 200);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (($query['place_id'] ?? null) === 'ChIJ_isuzu') {
            return Http::response(isuzuEstablishmentPayload(), 200);
        }

        return Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200);
    });

    $location = Location::create([
        'company_name' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'address' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'is_active' => true,
    ]);

    $location->refresh();

    expect($location->address)->toBe('12 Heidelberg Rd, City Deep, Johannesburg, 2001, South Africa')
        ->and($location->province)->toBe('Gauteng')
        ->and($location->city)->toBe('Johannesburg')
        ->and((float) $location->latitude)->toBe(-26.217)
        ->and((float) $location->longitude)->toBe(28.078);
});

test('a name stub with a rough pin is replaced by the street-level hit', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');

    $location = Location::withoutEvents(fn () => Location::create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'address' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'latitude' => -26.0,
        'longitude' => 28.0,
        'is_active' => true,
    ]));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'findplacefromtext')) {
            return Http::response([
                'status' => 'OK',
                'candidates' => [['place_id' => 'ChIJ_isuzu']],
            ], 200);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (($query['place_id'] ?? null) === 'ChIJ_isuzu') {
            return Http::response(isuzuEstablishmentPayload(), 200);
        }

        return Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200);
    });

    $location->save();
    $location->refresh();

    expect($location->address)->toBe('12 Heidelberg Rd, City Deep, Johannesburg, 2001, South Africa')
        ->and($location->province)->toBe('Gauteng')
        ->and((float) $location->latitude)->toBe(-26.217)
        ->and((float) $location->longitude)->toBe(28.078);
});

test('a suburb-only hit fills the province and does not invent a street pin', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'findplacefromtext')) {
            return Http::response(['status' => 'ZERO_RESULTS', 'candidates' => []], 200);
        }

        return Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'City Deep, Johannesburg, South Africa',
                'types' => ['locality', 'political'],
                'geometry' => [
                    'location' => ['lat' => -26.22, 'lng' => 28.07],
                    'location_type' => 'APPROXIMATE',
                ],
                'address_components' => [
                    ['long_name' => 'Johannesburg', 'types' => ['locality']],
                    ['long_name' => 'Gauteng', 'types' => ['administrative_area_level_1']],
                    ['long_name' => 'South Africa', 'short_name' => 'ZA', 'types' => ['country']],
                ],
            ]],
        ], 200);
    });

    $location = Location::create([
        'company_name' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'address' => 'ISUZU TRUCK CENTRE CITY DEEP',
        'is_active' => true,
    ]);

    $location->refresh();

    expect($location->province)->toBe('Gauteng')
        ->and($location->city)->toBe('Johannesburg')
        ->and($location->address)->toBe('ISUZU TRUCK CENTRE CITY DEEP')
        ->and($location->latitude)->toBeNull()
        ->and($location->longitude)->toBeNull();
});

test('an already confirmed address is not sent to Google again', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        '*' => function () {
            throw new RuntimeException('geocoder must not be called');
        },
    ]);

    $location = Location::create([
        'company_name' => 'Isuzu Truck Centre',
        'address' => '12 Heidelberg Rd, City Deep, Johannesburg',
        'city' => 'Johannesburg',
        'province' => 'Gauteng',
        'latitude' => -26.217,
        'longitude' => 28.078,
        'is_active' => true,
    ]);

    expect($location->address)->toBe('12 Heidelberg Rd, City Deep, Johannesburg');
    Http::assertNothingSent();
});

test('resolve prefers a precise geocode hit and does not call Places', function () {
    SystemSetting::set('google_maps_api_key', 'test-key', 'string', 'test');
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/json*' => Http::response(isuzuEstablishmentPayload(), 200),
        'maps.googleapis.com/maps/api/place/*' => function () {
            throw new RuntimeException('places must not be called for a precise geocode');
        },
    ]);

    $hit = GeocodingService::resolve('12 Heidelberg Rd, City Deep');

    expect($hit)->not->toBeNull()
        ->and($hit['precise'])->toBeTrue()
        ->and($hit['province'])->toBe('Gauteng');
});
