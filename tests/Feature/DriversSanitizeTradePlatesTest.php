<?php

/**
 * Covers the one-off backfill for driver_profiles.trade_plate.  The
 * command's contract:
 *
 *   1. rows already in canonical form are left untouched
 *   2. rows with fixable raw values ("TP JHB 11") are rewritten to
 *      canonical form ("TPJHB011") -- one AuditLog entry per write
 *   3. rows whose raw value has no alphanumerics ("---", "   ") are
 *      nulled out -- also audited
 *   4. rows whose canonical form would COLLIDE with another driver
 *      are refused; the run exits FAILURE so ops has to resolve
 *      before re-running
 *   5. --dry-run performs the scan but writes nothing (and audits
 *      nothing) so ops can preview a change window safely
 */

use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a driver_profile row bypassing the model's Attribute cast
 * so the historical "raw" value we want to sanitise sits in the DB
 * verbatim.  The cast is exactly what the sanitiser is meant to
 * enforce -- letting it run on write here would defeat the test.
 */
function seedRawTradePlate(?string $raw): DriverProfile
{
    $user = User::factory()->create();
    $now = now()->toDateTimeString();
    DB::table('driver_profiles')->insert([
        'user_id'    => $user->id,
        'trade_plate' => $raw,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return DriverProfile::where('user_id', $user->id)->firstOrFail();
}

test('canonical rows are left untouched', function () {
    $profile = seedRawTradePlate('TPJHB011');
    $originalUpdatedAt = DB::table('driver_profiles')->where('id', $profile->id)->value('updated_at');

    $this->artisan('drivers:sanitize-trade-plates')
        ->expectsOutputToContain('unchanged   1')
        ->expectsOutputToContain('normalised  0')
        ->assertSuccessful();

    // The raw DB value hasn't changed -- confirms no write happened.
    expect(DB::table('driver_profiles')->where('id', $profile->id)->value('trade_plate'))
        ->toBe('TPJHB011');
    expect(DB::table('driver_profiles')->where('id', $profile->id)->value('updated_at'))
        ->toBe($originalUpdatedAt);

    expect(AuditLog::count())->toBe(0);
});

test('rows with fixable raw values are normalised and audited', function () {
    $profile = seedRawTradePlate('tp jhb 11');

    $this->artisan('drivers:sanitize-trade-plates')
        ->expectsOutputToContain('normalised  1')
        ->assertSuccessful();

    expect(DB::table('driver_profiles')->where('id', $profile->id)->value('trade_plate'))
        ->toBe('TPJHB11');

    $audit = AuditLog::where('entity_type', 'driver_profile')
        ->where('entity_id', $profile->id)
        ->firstOrFail();
    expect($audit->action_type)->toBe('trade_plate_sanitised');
    expect($audit->before_json)->toBe(['trade_plate' => 'tp jhb 11']);
    expect($audit->after_json)->toBe(['trade_plate' => 'TPJHB11']);
});

test('rows whose raw value has no alphanumerics are nulled out', function () {
    $profile = seedRawTradePlate('---');

    $this->artisan('drivers:sanitize-trade-plates')
        ->expectsOutputToContain('nulled      1')
        ->assertSuccessful();

    expect(DB::table('driver_profiles')->where('id', $profile->id)->value('trade_plate'))
        ->toBeNull();

    $audit = AuditLog::where('entity_type', 'driver_profile')
        ->where('entity_id', $profile->id)
        ->firstOrFail();
    expect($audit->before_json)->toBe(['trade_plate' => '---']);
    expect($audit->after_json)->toBe(['trade_plate' => null]);
});

test('collisions are refused and the run exits FAILURE', function () {
    // Driver A already holds the canonical form.
    $a = seedRawTradePlate('TPJHB011');
    // Driver B's raw value normalises to the SAME string -- the
    // command must refuse to write over A and must surface the
    // driver ids so ops can resolve.
    $b = seedRawTradePlate('tp-jhb-011');

    $this->artisan('drivers:sanitize-trade-plates')
        ->expectsOutputToContain('unchanged   1')
        ->expectsOutputToContain('conflict    1')
        ->expectsOutputToContain('would collide with driver_profile id=' . $a->id)
        ->assertFailed();

    // Driver B's row is unchanged (still raw form).
    expect(DB::table('driver_profiles')->where('id', $b->id)->value('trade_plate'))
        ->toBe('tp-jhb-011');
    // No audit was written for the skipped row.
    expect(AuditLog::where('entity_id', $b->id)->count())->toBe(0);
});

test('dry-run previews without writing or auditing', function () {
    $canonical = seedRawTradePlate('TPJHB011');
    $needsFix  = seedRawTradePlate('tp jhb 11');
    $needsNull = seedRawTradePlate('---');

    $this->artisan('drivers:sanitize-trade-plates', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('unchanged   1')
        ->expectsOutputToContain('normalised  1')
        ->expectsOutputToContain('nulled      1')
        ->assertSuccessful();

    // Nothing on the DB moved -- read raw values directly.
    expect(DB::table('driver_profiles')->where('id', $canonical->id)->value('trade_plate'))
        ->toBe('TPJHB011');
    expect(DB::table('driver_profiles')->where('id', $needsFix->id)->value('trade_plate'))
        ->toBe('tp jhb 11');
    expect(DB::table('driver_profiles')->where('id', $needsNull->id)->value('trade_plate'))
        ->toBe('---');

    expect(AuditLog::count())->toBe(0);
});

test('the command is idempotent: re-running after a live run is a no-op', function () {
    seedRawTradePlate('tp jhb 11');

    $this->artisan('drivers:sanitize-trade-plates')->assertSuccessful();
    $auditsAfterFirst = AuditLog::count();
    expect($auditsAfterFirst)->toBe(1);

    // Second run should scan the same row, find it canonical, write
    // nothing, audit nothing.
    $this->artisan('drivers:sanitize-trade-plates')
        ->expectsOutputToContain('unchanged   1')
        ->expectsOutputToContain('normalised  0')
        ->assertSuccessful();

    expect(AuditLog::count())->toBe($auditsAfterFirst);
});
