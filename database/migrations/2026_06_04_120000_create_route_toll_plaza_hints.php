<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lane memory of which toll plazas a trip actually passes.
 *
 * Why we need this: detectTolls() can only match plazas that lie close
 * to the Google route polyline.  For some lanes (e.g. JHB -> Richards
 * Bay) Google only offers an inland bypass alternative that skips the
 * easternmost N17 plazas (Trichardt, Ermelo) by 30-80km, so no amount
 * of geometry tweaking will recover them.  When ops manually adds the
 * missing gate on one trip, remember it for the lane so every future
 * trip on the same (pickup, delivery) pair auto-applies the same set.
 *
 * Same keying as route_estimates -- one lane per location pair -- and
 * the same cascade-on-delete behaviour: if either endpoint is removed
 * the hint becomes meaningless, and likewise if the plaza is deleted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('route_toll_plaza_hints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pickup_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('delivery_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('toll_plaza_id')->constrained('toll_plazas')->cascadeOnDelete();

            // Audit trail mirroring model_toll_class_hints: who first
            // added the gate, when, when it was last applied, and how
            // many times -- helpful when curating which corrections to
            // promote into a permanent route_estimates polyline tweak.
            $table->foreignId('learned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('learned_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(1);

            $table->timestamps();

            // Multiple plazas per lane allowed (e.g. Ermelo + Trichardt
            // on the same JHB->RB lane), but the same plaza on the same
            // lane is idempotent.
            $table->unique(
                ['pickup_location_id', 'delivery_location_id', 'toll_plaza_id'],
                'route_toll_plaza_hints_unique',
            );

            // Hot path: estimator looks up "all plaza ids for this
            // pickup/delivery pair" on every advance estimate.  Lookups
            // by destination alone (or single location) aren't a use
            // case yet, so one composite index is enough.
            $table->index(['pickup_location_id', 'delivery_location_id'], 'route_toll_plaza_hints_lane_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_toll_plaza_hints');
    }
};
