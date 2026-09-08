<?php

/**
 * Location::displayLabel() is the single-line fallback used by the
 * /vehicles, driver-workload and planning views. It replaced a
 * broken chain that referenced non-existent `transport_jobs.pickup_address`
 * / `.delivery_address` columns, which caused every location without
 * a `company_name` to render as "—".
 *
 * The fallback order is: company_name -> address -> "city, province"
 * -> em-dash. Locking every rung of that ladder here so somebody
 * "simplifying" the helper can't quietly reintroduce the bug.
 */

use App\Models\Location;

it('prefers company_name when it is set', function () {
    $l = new Location([
        'company_name' => 'Acme Motors',
        'address'      => '12 Sample Rd',
        'city'         => 'Randburg',
        'province'     => 'Gauteng',
    ]);

    expect($l->displayLabel())->toBe('Acme Motors');
});

it('falls back to the street address when company_name is blank', function () {
    $l = new Location([
        'company_name' => '',
        'address'      => '12 Sample Rd',
        'city'         => 'Randburg',
        'province'     => 'Gauteng',
    ]);

    expect($l->displayLabel())->toBe('12 Sample Rd');
});

it('falls back to city + province when only geo hints are available', function () {
    $l = new Location([
        'company_name' => null,
        'address'      => null,
        'city'         => 'Randburg',
        'province'     => 'Gauteng',
    ]);

    expect($l->displayLabel())->toBe('Randburg, Gauteng');
});

it('drops missing pieces of the city, province fallback', function () {
    $cityOnly = new Location([
        'company_name' => null,
        'address'      => null,
        'city'         => 'Randburg',
        'province'     => null,
    ]);
    expect($cityOnly->displayLabel())->toBe('Randburg');

    $provOnly = new Location([
        'company_name' => null,
        'address'      => null,
        'city'         => null,
        'province'     => 'Gauteng',
    ]);
    expect($provOnly->displayLabel())->toBe('Gauteng');
});

it('returns an em-dash when every field is blank', function () {
    $l = new Location([
        'company_name' => null,
        'address'      => null,
        'city'         => null,
        'province'     => null,
    ]);

    expect($l->displayLabel())->toBe('—');
});

it('treats whitespace-only fields as blank so they do not win the fallback', function () {
    $l = new Location([
        'company_name' => '   ',
        'address'      => '12 Sample Rd',
        'city'         => null,
        'province'     => null,
    ]);

    // If displayLabel returned trim($company_name) === "" then it must
    // fall through to `address`, NOT return an empty string.
    expect($l->displayLabel())->toBe('12 Sample Rd');
});
