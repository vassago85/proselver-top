<?php

use App\Support\VehicleIdentifier;

it('normalises by uppercasing and stripping non-alphanumerics', function () {
    expect(VehicleIdentifier::normalise('ktr 284-ec'))->toBe('KTR284EC');
    expect(VehicleIdentifier::normalise(' jn1tbnt32u0000001 '))->toBe('JN1TBNT32U0000001');
    expect(VehicleIdentifier::normalise(null))->toBe('');
    expect(VehicleIdentifier::normalise(''))->toBe('');
});

it('classifies real 17-char VINs as VIN', function () {
    expect(VehicleIdentifier::classify('JN1TBNT32U0000001'))->toBe(VehicleIdentifier::TYPE_VIN);
    expect(VehicleIdentifier::classify('1HGCM82633A004352'))->toBe(VehicleIdentifier::TYPE_VIN);
});

it('classifies 12+ char inputs as VIN', function () {
    // SA truck chassis numbers commonly run 13-15 chars.
    expect(VehicleIdentifier::classify('ABC123456789A'))->toBe(VehicleIdentifier::TYPE_VIN);
    expect(VehicleIdentifier::classify('WDB9066571S123456'))->toBe(VehicleIdentifier::TYPE_VIN);
});

it('classifies short inputs as registration', function () {
    // The one that started this whole thing -- order 26070589.
    expect(VehicleIdentifier::classify('KTR284EC'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    expect(VehicleIdentifier::classify('CA123456'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    expect(VehicleIdentifier::classify('ND12345'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    expect(VehicleIdentifier::classify('BB123GP'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
});

it('uses province-suffix tiebreaker in the 10-11 char grey zone', function () {
    // 10 chars ending in EC -> plate.
    expect(VehicleIdentifier::classify('LONGREG7ZEC'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    // 10 chars ending in GP -> plate.
    expect(VehicleIdentifier::classify('AB12345 GP'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    // KZN is checked before ZN so this doesn't misfire on unrelated Z-ending VINs.
    expect(VehicleIdentifier::classify('AB1234 KZN'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
});

it('uses forbidden-letter tiebreaker in the grey zone', function () {
    // 11 chars but contains 'O' -> can't be a real VIN.
    expect(VehicleIdentifier::classify('AB1234O78XY'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    // 10 chars with 'I' -> plate.
    expect(VehicleIdentifier::classify('AB1I34567X'))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
});

it('picks VIN in the grey zone when no tiebreaker fires but flags ambiguity', function () {
    $value = 'ABCD12345X'; // 10 chars, no I/O/Q, no province suffix.
    expect(VehicleIdentifier::classify($value))->toBe(VehicleIdentifier::TYPE_VIN);
    expect(VehicleIdentifier::isAmbiguous($value))->toBeTrue();
});

it('is not ambiguous when a tiebreaker resolved the grey zone', function () {
    expect(VehicleIdentifier::isAmbiguous('AB1234 KZN'))->toBeFalse();
    expect(VehicleIdentifier::isAmbiguous('AB1I34567X'))->toBeFalse();
});

it('is not ambiguous outside the grey zone', function () {
    expect(VehicleIdentifier::isAmbiguous('KTR284EC'))->toBeFalse();
    expect(VehicleIdentifier::isAmbiguous('JN1TBNT32U0000001'))->toBeFalse();
    expect(VehicleIdentifier::isAmbiguous(''))->toBeFalse();
});

it('defaults empty input to registration and is not ambiguous', function () {
    expect(VehicleIdentifier::classify(''))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
    expect(VehicleIdentifier::classify(null))->toBe(VehicleIdentifier::TYPE_REGISTRATION);
});

it('exposes handy boolean helpers', function () {
    expect(VehicleIdentifier::isVin('JN1TBNT32U0000001'))->toBeTrue();
    expect(VehicleIdentifier::isVin('KTR284EC'))->toBeFalse();
    expect(VehicleIdentifier::isRegistration('KTR284EC'))->toBeTrue();
    expect(VehicleIdentifier::isRegistration('JN1TBNT32U0000001'))->toBeFalse();
});
