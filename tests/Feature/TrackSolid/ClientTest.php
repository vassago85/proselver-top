<?php

use App\Models\SystemSetting;
use App\Services\TrackSolid\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Lock-in tests against the TrackSolid Pro v2.7.14 spec. Each "spec
 * reference" comment below points at the section in the PDF / docx
 * the assertion is encoding so any future spec drift is easy to find.
 */

beforeEach(function () {
    Cache::flush();
});

/**
 * Plant a complete, enabled credential set so isConfigured() is true
 * and the client is willing to make calls. Returns the raw values so
 * tests can assert on them.
 */
function configureTrackSolid(array $overrides = []): array
{
    $values = array_merge([
        Client::SETTING_ENABLED => '1',
        Client::SETTING_BASE_URL => 'https://hk-open.tracksolidpro.com',
        Client::SETTING_APP_KEY => 'TESTKEY123',
        Client::SETTING_APP_SECRET => 'TESTSECRET456',
        Client::SETTING_ACCOUNT => 'ops@example.com',
        Client::SETTING_USER_PWD_MD5 => md5('s3cret'),
    ], $overrides);

    foreach ($values as $key => $val) {
        SystemSetting::set(
            $key,
            $val,
            $key === Client::SETTING_ENABLED ? 'boolean' : 'string'
        );
    }
    return $values;
}

describe('buildSignature', function () {
    it('matches the algorithm worked example from §6.4 of the spec', function () {
        // Spec example, line 1004-1005:
        //   md5(app_secret + bar2foo1foo_bar3foobar4 + app_secret)
        $appSecret = 'h9lri085eachcz4sn7gwnkh6j0jt0yz4';
        $params = ['bar' => 2, 'foo' => 1, 'foo_bar' => 3, 'foobar' => 4];

        $expectedBody = $appSecret . 'bar2foo1foo_bar3foobar4' . $appSecret;
        $expected = strtoupper(md5($expectedBody));

        expect(Client::buildSignature($params, $appSecret))->toBe($expected);
    });

    it('matches the real TrackSolid sandbox worked example (jimi.oauth.token.get)', function () {
        // From the TrackSolid Open API onboarding email — the published
        // "expected sign" `4EE067D88EA65FF2AFD4890955E042CB` corresponds
        // to timestamp 2025-05-19 10:23:00 (the prose says 08:10:00 but
        // their inline raw string and the resulting hash both align on
        // 10:23:00 — hashes don't lie, deterministic md5).
        $params = [
            'app_key'      => '8FB345B8693CCD00CE073CAB5F094009339A22A4105B6558',
            'expires_in'   => '7200',
            'format'       => 'json',
            'method'       => 'jimi.oauth.token.get',
            'sign_method'  => 'md5',
            'timestamp'    => '2025-05-19 10:23:00',
            'user_id'      => 'JMTEST123',
            'user_pwd_md5' => '21218cca77804d2ba1922c33e0151105',
            'v'            => '1.0',
        ];

        $sig = Client::buildSignature($params, 'c0aa0226fddc4365a3c67fef45427f8a');

        expect($sig)->toBe('4EE067D88EA65FF2AFD4890955E042CB');
    });

    it('sorts params alphabetically regardless of input order', function () {
        $secret = 'sek';
        $orderA = ['z' => 1, 'a' => 2, 'm' => 3];
        $orderB = ['a' => 2, 'z' => 1, 'm' => 3];

        expect(Client::buildSignature($orderA, $secret))
            ->toBe(Client::buildSignature($orderB, $secret));
    });

    it('returns an UPPERCASE 32-char hex string per the spec', function () {
        $sig = Client::buildSignature(['method' => 'jimi.oauth.token.get'], 'sek');
        expect($sig)
            ->toMatch('/^[A-F0-9]{32}$/')
            ->toBe(strtoupper($sig));
    });

    it('excludes the sign field if present (it is what we are computing)', function () {
        $secret = 'sek';
        $withSign = ['a' => 1, 'b' => 2, 'sign' => 'STALE'];
        $without = ['a' => 1, 'b' => 2];

        expect(Client::buildSignature($withSign, $secret))
            ->toBe(Client::buildSignature($without, $secret));
    });

    it('excludes empty / null values from the signature body', function () {
        $secret = 'sek';
        $withEmpty = ['a' => 1, 'b' => '', 'c' => null, 'd' => 2];
        $withoutEmpty = ['a' => 1, 'd' => 2];

        expect(Client::buildSignature($withEmpty, $secret))
            ->toBe(Client::buildSignature($withoutEmpty, $secret));
    });
});

describe('authenticate', function () {
    it('POSTs to /route/rest with method=jimi.oauth.token.get and a v=1.0 sign', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::response([
                'code' => 0,
                'message' => 'success',
                'result' => [
                    'accessToken' => 'TOKEN-XYZ',
                    'expiresIn' => 7200,
                    'account' => 'ops@example.com',
                    'appKey' => 'TESTKEY123',
                ],
            ]),
        ]);

        $token = app(Client::class)->authenticate();

        expect($token)->toBe('TOKEN-XYZ');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $request->url() === 'https://hk-open.tracksolidpro.com/route/rest'
                && $request->method() === 'POST'
                && ($body['method'] ?? null) === 'jimi.oauth.token.get'
                && ($body['app_key'] ?? null) === 'TESTKEY123'
                && ($body['user_id'] ?? null) === 'ops@example.com'
                && ($body['user_pwd_md5'] ?? null) === md5('s3cret')
                && ($body['v'] ?? null) === '1.0'
                && ($body['format'] ?? null) === 'json'
                && ($body['sign_method'] ?? null) === 'md5'
                && preg_match('/^[A-F0-9]{32}$/', $body['sign'] ?? '');
        });
    });

    it('caches the token so the second call does NOT re-authenticate', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::response([
                'code' => 0, 'message' => 'success',
                'result' => ['accessToken' => 'TOKEN', 'expiresIn' => 7200],
            ]),
        ]);

        $client = app(Client::class);
        $client->authenticate();
        $client->authenticate();
        $client->authenticate();

        Http::assertSentCount(1);
    });

    it('throws when the API returns a non-zero code', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::response([
                'code' => 1001,
                'message' => 'Incorrect user name or password',
            ]),
        ]);

        expect(fn () => app(Client::class)->authenticate())
            ->toThrow(\RuntimeException::class, 'Incorrect user name or password');
    });

    it('strips a trailing /route/rest from a pasted base URL', function () {
        configureTrackSolid([
            Client::SETTING_BASE_URL => 'https://hk-open.tracksolidpro.com/route/rest',
        ]);

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::response([
                'code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200],
            ]),
        ]);

        app(Client::class)->authenticate();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hk-open.tracksolidpro.com/route/rest';
        });
    });
});

describe('listDevices', function () {
    it('parses the spec response shape (result is the array, not result.list)', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push([
                    'code' => 0,
                    'message' => 'success',
                    // Per spec §7.11 the result is a top-level array of devices
                    'result' => [[
                        'imei' => '868120145233604',
                        'deviceName' => 'Truck 1',
                        'mcType' => 'GT300L',
                        'vehicleNumber' => 'CA123GP',
                        'driverName' => 'driver',
                        'carFrame' => 'VIN-2235',
                        'enabledFlag' => 1,
                    ]],
                ]),
        ]);

        $devices = app(Client::class)->listDevices();

        expect($devices)->toHaveCount(1);
        expect($devices[0])->toMatchArray([
            'imei' => '868120145233604',
            'name' => 'Truck 1',
            'vehicle_number' => 'CA123GP',
            'driver_name' => 'driver',
            'vin' => 'VIN-2235',
        ]);
    });

    it('passes target=<account> per spec §7.11', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push(['code' => 0, 'result' => []]),
        ]);

        app(Client::class)->listDevices();

        Http::assertSent(function ($request) {
            $body = $request->data();
            if (($body['method'] ?? null) !== 'jimi.user.device.list') {
                return false;
            }
            return ($body['target'] ?? null) === 'ops@example.com'
                && ($body['access_token'] ?? null) === 'T';
        });
    });
});

describe('getLatestPositions', function () {
    it('calls jimi.device.location.get with imeis as a comma-separated string', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push(['code' => 0, 'result' => []]),
        ]);

        app(Client::class)->getLatestPositions(['IMEI-1', 'IMEI-2', 'IMEI-3']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            if (($body['method'] ?? null) !== 'jimi.device.location.get') {
                return false;
            }
            return ($body['imeis'] ?? null) === 'IMEI-1,IMEI-2,IMEI-3';
        });
    });

    it('batches IMEIs into groups of 100 (spec maximum)', function () {
        configureTrackSolid();

        $imeis = collect(range(1, 250))->map(fn ($i) => 'IMEI-' . str_pad($i, 4, '0', STR_PAD_LEFT))->all();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push(['code' => 0, 'result' => []])
                ->push(['code' => 0, 'result' => []])
                ->push(['code' => 0, 'result' => []]),
        ]);

        app(Client::class)->getLatestPositions($imeis);

        // 1 auth + 3 batches of 100/100/50 = 4 total
        Http::assertSentCount(4);
    });

    it('normalises spec response fields (lat, lng, gpsTime, speed, direction)', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push([
                    'code' => 0,
                    'message' => 'success',
                    'result' => [[
                        'imei' => '868120145233604',
                        'deviceName' => 'Truck 1',
                        'lat' => -26.2041,
                        'lng' => 28.0473,
                        'speed' => '60.5',
                        'direction' => '180',
                        'gpsTime' => '2026-05-05 12:00:00',
                        'status' => '1',
                        'accStatus' => '1',
                    ]],
                ]),
        ]);

        $positions = app(Client::class)->getLatestPositions(['868120145233604']);

        expect($positions)->toHaveCount(1);
        expect($positions[0])->toMatchArray([
            'tracker_id' => '868120145233604',
            'latitude' => -26.2041,
            'longitude' => 28.0473,
            'speed_kmh' => 60.5,
            'heading_deg' => 180.0,
        ]);
        expect($positions[0]['reported_at']->format('Y-m-d H:i:s'))->toBe('2026-05-05 12:00:00');
    });

    it('drops rows where lat/lng are both 0 (spec: "if the device expires, the value is 0")', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push([
                    'code' => 0,
                    'result' => [
                        ['imei' => 'EXPIRED', 'lat' => 0, 'lng' => 0, 'gpsTime' => '2026-05-05 12:00:00'],
                        ['imei' => 'VALID', 'lat' => -26.2, 'lng' => 28.0, 'gpsTime' => '2026-05-05 12:00:00'],
                    ],
                ]),
        ]);

        $positions = app(Client::class)->getLatestPositions(['EXPIRED', 'VALID']);

        expect($positions)->toHaveCount(1);
        expect($positions[0]['tracker_id'])->toBe('VALID');
    });

    it('treats direction=-1 as unknown (null heading)', function () {
        configureTrackSolid();

        Http::fake([
            'hk-open.tracksolidpro.com/*' => Http::sequence()
                ->push(['code' => 0, 'result' => ['accessToken' => 'T', 'expiresIn' => 7200]])
                ->push([
                    'code' => 0,
                    'result' => [[
                        'imei' => 'IMEI-1', 'lat' => -26.2, 'lng' => 28.0,
                        'direction' => '-1', 'gpsTime' => '2026-05-05 12:00:00',
                    ]],
                ]),
        ]);

        $positions = app(Client::class)->getLatestPositions(['IMEI-1']);
        expect($positions[0]['heading_deg'])->toBeNull();
    });

    it('returns [] when not configured rather than crashing', function () {
        // Deliberately do NOT configure
        expect(app(Client::class)->getLatestPositions(['IMEI-1']))->toBe([]);
        Http::assertNothingSent();
    });
});
