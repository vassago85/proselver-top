<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * tracker_positions: every GPS sample we've seen for a device.
 *
 * Why a separate table (rather than just last-known cols on driver_profiles)
 *   1. We want a breadcrumb trail. Even though the wallboard only renders
 *      the latest row per tracker, dispatch will eventually want a 24-hour
 *      replay or a "where was driver X at 14:32" lookup. Keeping every
 *      sample makes that a query, not a separate ingest pipeline.
 *   2. The match between a TrackSolid IMEI and an internal driver is via
 *      DriverProfile.tracker_id. That mapping can change (vehicles move
 *      between drivers, trackers get re-bound). Storing positions keyed
 *      on tracker_id means historical breadcrumbs stay correct even if
 *      the driver-tracker linkage changes later.
 *
 * Idempotency: the upstream API will re-deliver the same (tracker_id,
 * reported_at) sample if we poll faster than the device reports. Unique
 * constraint stops duplicates without needing app-level dedup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_positions', function (Blueprint $table) {
            $table->id();

            // External device identifier (IMEI for TrackSolid). Indexed
            // because every wallboard query and every poller upsert filters
            // on this column.
            $table->string('tracker_id')->index();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->decimal('heading_deg', 5, 2)->nullable();

            // When the device sampled the position. NOT when we ingested
            // it — see received_at for that. This is the field the UI
            // shows as "last seen" and the field freshness scopes filter
            // against.
            $table->timestamp('reported_at');
            $table->timestamp('received_at');

            // Verbatim upstream payload, so debugging "why did this sample
            // come out wrong?" doesn't need a re-poll.
            $table->json('raw')->nullable();

            $table->timestamps();

            // Idempotent upsert key: same device + same device-reported
            // timestamp → same row. The poller's upsert relies on this.
            $table->unique(['tracker_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_positions');
    }
};
