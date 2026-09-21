<?php

use App\Support\PopiaMask;

test('saId masks all but the last four digits', function () {
    expect(PopiaMask::saId('9001015800089'))->toBe('•••••••••0089');
});

test('saId normalises punctuation and whitespace before masking', function () {
    // "9001 01 5800089" and "90-01-01 58000 89" both collapse to the
    // same 13 canonical digits, so both must yield the same mask.
    expect(PopiaMask::saId('9001 01 5800089'))->toBe('•••••••••0089');
    expect(PopiaMask::saId('90-01-01 58000 89'))->toBe('•••••••••0089');
});

test('saId returns em dash for null, empty or too-short input', function () {
    expect(PopiaMask::saId(null))->toBe('—');
    expect(PopiaMask::saId(''))->toBe('—');
    // A four-digit "ID" carries only the last-four tail we would keep;
    // returning it verbatim would defeat the mask, so we treat it as
    // unrenderable rather than pretend to redact.
    expect(PopiaMask::saId('1234'))->toBe('—');
});

test('cellphone keeps the last three digits', function () {
    expect(PopiaMask::cellphone('0821234567'))->toBe('•••••••567');
});

test('cellphone tolerates international and punctuated formats', function () {
    expect(PopiaMask::cellphone('+27 82 123 4567'))->toBe('••••••••567');
    expect(PopiaMask::cellphone('082-123-4567'))->toBe('•••••••567');
});

test('preferredCellphone flags exact duplicates so the UI can drop the second row', function () {
    // Same digits, same formatting -> duplicate flag lets the order
    // page render one line instead of Phone + Cellphone showing the
    // same number twice.  The +27 vs 0 case is covered in its own
    // test so this one stays about the trivial equality.
    [$masked, $isDup] = PopiaMask::preferredCellphone('0821234567', '0821234567');
    expect($masked)->toBe('•••••••567');
    expect($isDup)->toBeTrue();
});

test('preferredCellphone reports different numbers as distinct', function () {
    [$masked, $isDup] = PopiaMask::preferredCellphone('0821234567', '0839999999');
    expect($masked)->toBe('•••••••567');
    expect($isDup)->toBeFalse();
});

test('preferredCellphone treats a single filled input as a duplicate (nothing to render twice)', function () {
    // Only one row has data — there's nothing for the second row to
    // duplicate, so the caller should render one line.  Same
    // semantics for the (null, x) and (x, null) cases.
    [$masked, $isDup] = PopiaMask::preferredCellphone(null, '0821234567');
    expect($masked)->toBe('•••••••567');
    expect($isDup)->toBeTrue();

    [$masked2, $isDup2] = PopiaMask::preferredCellphone('0821234567', null);
    expect($masked2)->toBe('•••••••567');
    expect($isDup2)->toBeTrue();
});

test('preferredCellphone canonicalises +27 vs 0-prefix SA numbers', function () {
    // Users.phone stored as +27..., driver_profiles.cellphone stored
    // as 0... is the common shape.  Both must collapse to a single
    // row on the order page.
    [, $isDup] = PopiaMask::preferredCellphone('+27821234567', '0821234567');
    expect($isDup)->toBeTrue();
});

test('preferredCellphone returns an em dash when both inputs are empty', function () {
    [$masked, $isDup] = PopiaMask::preferredCellphone(null, '');
    expect($masked)->toBe('—');
    expect($isDup)->toBeTrue();
});
