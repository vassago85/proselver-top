<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global memory of model_name -> SANRAL toll class corrections.
 *
 * Why we need this: a Powerstar VX4035 is an 8x4 (4 heavy axles), so
 * SANRAL Class 3.  The Trident vehicle_classes default for "HCV" or
 * even "Extra Heavy" is a coarse band that won't always match -- ops
 * is forced to set the per-trip override every single time they see
 * one.  Once they've corrected it on any trip we should remember it
 * globally so the next trip for the same model defaults to the right
 * class out of the gate.
 *
 * Keyed by NORMALISED model string (uppercase, trimmed) to match the
 * existing per-company model_class_hints pattern used by the bulk
 * importer.  Global rather than per-company on purpose -- physics is
 * physics: a Powerstar VX4035 has the same axle count regardless of
 * which dealer booked the trip.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('model_toll_class_hints', function (Blueprint $table) {
            $table->id();

            // Uppercase + trimmed model name; matches the rule the
            // estimator applies before lookup.  Unique so a re-correction
            // overwrites the old value cleanly.
            $table->string('model_key', 191)->unique();

            $table->unsignedTinyInteger('toll_class');

            // Audit trail: who first taught us, who last reinforced.
            // Reinforce on every save where the override matches the
            // existing hint -- that "this is still right" signal is
            // worth keeping for the eventual data-cleanup pass.
            $table->foreignId('learned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('learned_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_toll_class_hints');
    }
};
