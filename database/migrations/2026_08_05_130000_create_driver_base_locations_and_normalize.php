<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Controlled reference list for driver base locations.
 *
 * driver_profiles.base_location used to be a free-text column, so the
 * same city landed as "JOHANNESBURG", "Johannesburg", "JOHANNESBUG" and
 * even "GAUTENG" depending on who typed it in. The Driver Operations
 * filter dropdown exposed every one of those as a separate option, and
 * because none matched, filtering silently missed drivers.
 *
 * This migration:
 *   1. Creates a small reference table of canonical base locations.
 *   2. Seeds the SA cities ops actually operates out of.
 *   3. Reads every non-empty value already in driver_profiles.base_location,
 *      canonicalises it (case-insensitive collapse + a handful of known
 *      misspellings), and either updates the profile row to point at a
 *      seeded row OR upserts the observed value as its own row so no
 *      historical data is silently lost.
 *
 * We deliberately keep driver_profiles.base_location as a plain string
 * column rather than adding an FK. The dropdown on create/edit is
 * populated from the reference table, so new writes are already
 * constrained; old writes are canonicalised in step (3). Keeping a
 * string means every downstream surface (audit log JSON, exports,
 * search) works without joins.
 */
return new class extends Migration {
    /**
     * The canonical list of SA base cities the platform operates out of
     * on go-live. Extending this later is just an insert into the table
     * -- admins do NOT need to edit code to add a new depot city.
     */
    private const CANONICAL_CITIES = [
        'Johannesburg',
        'Pretoria',
        'Cape Town',
        'Durban',
        'Port Elizabeth',
        'East London',
        'Bloemfontein',
        'Polokwane',
        'Nelspruit',
        'Kimberley',
        'Rustenburg',
    ];

    /**
     * Known non-canonical typings we can auto-map onto the canonical
     * list. Everything else is preserved as-is (title-cased) so admins
     * see it in the picker and can decide whether to merge or rename.
     */
    private const KNOWN_ALIASES = [
        'JOHANNESBUG' => 'Johannesburg',
        'JHB'         => 'Johannesburg',
        'JOZI'        => 'Johannesburg',
        'PTA'         => 'Pretoria',
        'CPT'         => 'Cape Town',
        'CAPETOWN'    => 'Cape Town',
        'DBN'         => 'Durban',
        'PE'          => 'Port Elizabeth',
        'GQEBERHA'    => 'Port Elizabeth',
        'BLOEM'       => 'Bloemfontein',
    ];

    public function up(): void
    {
        Schema::create('driver_base_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            // Sort order lets admins pin frequently-used depots to the
            // top of the dropdown without renaming. Default 100 so a
            // manual "10" for the head office floats above the seeded
            // cities without an explicit reorder.
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        // ── Seed the canonical list ───────────────────────────────────
        $now = now();
        $seed = [];
        foreach (self::CANONICAL_CITIES as $index => $name) {
            $seed[] = [
                'name' => $name,
                'is_active' => true,
                // Preserve the source order so the picker isn't
                // alphabetical -- Johannesburg / Pretoria float to the
                // top because that's where most drivers are based.
                'sort_order' => 10 + $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('driver_base_locations')->insert($seed);

        // ── Canonicalise existing profile strings ─────────────────────
        // Skip if the driver_profiles table doesn't exist yet (fresh
        // install without the phase 1 restructure). The reference table
        // still gets seeded so create/edit forms have their options.
        if (!Schema::hasTable('driver_profiles')) {
            return;
        }

        $distinct = DB::table('driver_profiles')
            ->whereNotNull('base_location')
            ->where('base_location', '!=', '')
            ->distinct()
            ->pluck('base_location');

        // Build the canonical -> raw-value map for a single bulk update
        // per canonical form. Anything that doesn't resolve to a canonical
        // city gets its own reference row (title-cased) so ops isn't
        // surprised by data disappearing.
        $rawToCanonical = [];
        $extraRows = [];

        foreach ($distinct as $raw) {
            $canonical = $this->canonicalise($raw);
            if ($canonical === null) {
                continue;
            }
            $rawToCanonical[$raw] = $canonical;

            // If it's not in the seeded list, add it as its own row so
            // the dropdown reflects the live data. Admins can merge or
            // hide it later via a settings page.
            $isSeeded = in_array($canonical, self::CANONICAL_CITIES, true);
            if (!$isSeeded && !isset($extraRows[$canonical])) {
                $extraRows[$canonical] = [
                    'name' => $canonical,
                    'is_active' => true,
                    // Sort observed-but-not-seeded values below the
                    // canonical list.
                    'sort_order' => 500,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($extraRows)) {
            DB::table('driver_base_locations')->insert(array_values($extraRows));
        }

        // Group by canonical form so we issue one UPDATE per city, not
        // one per historical variant.
        $byCanonical = [];
        foreach ($rawToCanonical as $raw => $canonical) {
            $byCanonical[$canonical][] = $raw;
        }
        foreach ($byCanonical as $canonical => $rawValues) {
            DB::table('driver_profiles')
                ->whereIn('base_location', $rawValues)
                ->update(['base_location' => $canonical]);
        }
    }

    public function down(): void
    {
        // Data-loss on rollback is fine: the reference table is a new
        // artefact and driver_profiles.base_location still holds the
        // canonicalised strings, which is a strict improvement even
        // without the reference table.
        Schema::dropIfExists('driver_base_locations');
    }

    /**
     * Turn a raw historical value into a canonical city name, or null
     * if the value should be dropped (empty / pure whitespace).
     *
     * Rules:
     *   - trim + collapse whitespace
     *   - upper-case for the alias / seeded-list lookup
     *   - if uppercase matches an alias, use the alias's canonical form
     *   - if uppercase matches a seeded city, use the seeded casing
     *   - otherwise title-case the trimmed input so casing at least
     *     matches across surfaces ("PRETORIA" -> "Pretoria")
     */
    private function canonicalise(string $raw): ?string
    {
        $trimmed = preg_replace('/\s+/', ' ', trim($raw));
        if ($trimmed === '') {
            return null;
        }

        $upper = Str::upper($trimmed);

        if (isset(self::KNOWN_ALIASES[$upper])) {
            return self::KNOWN_ALIASES[$upper];
        }

        foreach (self::CANONICAL_CITIES as $city) {
            if (Str::upper($city) === $upper) {
                return $city;
            }
        }

        // Preserve unknown values with tidy casing so ops sees a clean
        // picker. "GAUTENG" survives as "Gauteng" -- still available as
        // a filter option, still merge-able through the admin UI.
        return Str::title($trimmed);
    }
};
