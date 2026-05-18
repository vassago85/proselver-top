<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase-6 trip planning schema.
 *
 * A `trip` is one driver's day plan: they leave a depot, work through a
 * sequence of `trip_stops` (job pickups, job dropoffs, positioning legs,
 * waypoints like COF / weighbridge), and end at a depot. Each transport
 * job that belongs to the trip gets `trip_id` set; the FK is nullable so
 * standalone (untripped) jobs continue to work exactly as before.
 *
 * No data backfill — every existing job stays untripped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();

            // Owning company. A dealer plans trips for their own drivers;
            // ProSelver plans trips for the platform-owner company's
            // drivers. Either way the trip scopes by company for the
            // list views.
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('driver_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('trip_date');

            // planned -> in_progress -> completed (cancelled is a side
            // exit at any point pre-completion). Kept as VARCHAR for the
            // same forwards-compatibility reason as Job.executor_type.
            $table->string('status', 32)->default('planned');

            $table->foreignId('start_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();
            $table->foreignId('end_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            // Default operational lookups: "what's on a given driver for
            // today?" and "what trips are happening for this dealer this
            // week?". A driver shouldn't typically have two trips for
            // the same date — enforced as a separate partial-unique
            // (Postgres) index below so the planner can override by
            // soft-deleting first if a re-plan is needed.
            $table->index(['company_id', 'trip_date']);
            $table->index(['driver_user_id', 'trip_date']);
            $table->index(['status']);
        });

        // One active (non-soft-deleted) trip per driver per date.
        // Postgres-only partial unique index; SQLite testing falls back
        // to a plain composite unique without the deleted_at predicate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX trips_driver_date_active_unique ' .
                'ON trips (driver_user_id, trip_date) ' .
                'WHERE deleted_at IS NULL'
            );
        }

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();

            // Render order inside the trip. Re-numbered by the planner
            // on every reorder so we don't need to do clever
            // fractional-index trickery; with ~20 stops max per trip a
            // full renumber is cheap.
            $table->unsignedInteger('sequence');

            // job_pickup / job_dropoff link to a transport job. The
            // other types are operational stops with no job: positioning
            // (driver repositions empty / as passenger), waypoint_cof
            // (annual roadworthy check), waypoint_weighbridge, waypoint_fuel,
            // waypoint_other.
            $table->string('stop_type', 32);

            $table->foreignId('transport_job_id')
                ->nullable()
                ->constrained('transport_jobs')
                ->nullOnDelete();

            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            $table->timestamp('expected_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('departed_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['trip_id', 'sequence']);
            $table->index(['transport_job_id']);
            $table->index(['stop_type']);
        });

        // Hook trips onto transport_jobs. Nullable + index so the
        // unassigned-jobs side panel in the planner is a single index
        // lookup (`WHERE trip_id IS NULL AND company_id = ?`).
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('trip_id')
                ->nullable()
                ->after('driver_user_id')
                ->constrained('trips')
                ->nullOnDelete();

            $table->index(['company_id', 'trip_id']);
        });

        // Default waypoint catalogue (COF / Weighbridge / Fuel). Stored
        // in system_settings so ops can edit without a code change. The
        // planner reads this list to populate the "add waypoint" menu.
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'trip_waypoint_catalogue'],
                [
                    'value' => json_encode([
                        ['code' => 'waypoint_cof',        'label' => 'COF check',          'icon' => 'shield-check'],
                        ['code' => 'waypoint_weighbridge', 'label' => 'Weighbridge',       'icon' => 'scale'],
                        ['code' => 'waypoint_fuel',       'label' => 'Fuel stop',          'icon' => 'fuel'],
                        ['code' => 'waypoint_other',      'label' => 'Other waypoint',     'icon' => 'map-pin'],
                        ['code' => 'positioning',         'label' => 'Driver positioning', 'icon' => 'route'],
                    ]),
                    'type' => 'json',
                    'description' => 'Catalogue of non-job stop types the trip planner can insert (COF, weighbridge, etc.).',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('transport_jobs', 'trip_id')) {
                $table->dropForeign(['trip_id']);
                $table->dropIndex(['company_id', 'trip_id']);
                $table->dropColumn('trip_id');
            }
        });

        Schema::dropIfExists('trip_stops');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS trips_driver_date_active_unique');
        }

        Schema::dropIfExists('trips');

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'trip_waypoint_catalogue')->delete();
        }
    }
};
