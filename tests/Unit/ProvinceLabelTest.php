<?php

/**
 * ProvinceLabel::canonicalise() collapses casing / spelling variants
 * of the same province onto a single canonical label. The ops
 * dashboard relies on this to keep "Gauteng" and "GAUTENG" from
 * showing up as two separate lanes on Top waiting lanes.
 */

use App\Domain\Operations\ProvinceLabel;

it('collapses casing variants of the same province', function () {
    expect(ProvinceLabel::canonicalise('Gauteng'))->toBe('Gauteng');
    expect(ProvinceLabel::canonicalise('GAUTENG'))->toBe('Gauteng');
    expect(ProvinceLabel::canonicalise('gauteng'))->toBe('Gauteng');
    expect(ProvinceLabel::canonicalise('  Gauteng  '))->toBe('Gauteng');
});

it('normalises KwaZulu-Natal spellings', function () {
    expect(ProvinceLabel::canonicalise('KwaZulu-Natal'))->toBe('KwaZulu-Natal');
    expect(ProvinceLabel::canonicalise('KWAZULU-NATAL'))->toBe('KwaZulu-Natal');
    expect(ProvinceLabel::canonicalise('kwazulu natal'))->toBe('KwaZulu-Natal');
    expect(ProvinceLabel::canonicalise('KZN'))->toBe('KwaZulu-Natal');
});

it('normalises North West spellings', function () {
    expect(ProvinceLabel::canonicalise('North West'))->toBe('North West');
    expect(ProvinceLabel::canonicalise('NORTH WEST'))->toBe('North West');
    expect(ProvinceLabel::canonicalise('North-West'))->toBe('North West');
});

it('returns null for null / blank input', function () {
    expect(ProvinceLabel::canonicalise(null))->toBeNull();
    expect(ProvinceLabel::canonicalise(''))->toBeNull();
    expect(ProvinceLabel::canonicalise('   '))->toBeNull();
});

it('title-cases unknown provinces as a fallback rather than leaking raw casing', function () {
    // Something we don't have a canonical mapping for — better to
    // still normalise so the UI doesn't show "cape town" alongside
    // "Cape Town".
    expect(ProvinceLabel::canonicalise('CAPE TOWN'))->toBe('Cape Town');
    expect(ProvinceLabel::canonicalise('cape town'))->toBe('Cape Town');
});
