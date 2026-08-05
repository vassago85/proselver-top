<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "don't show me again" state for in-app hints and educational
 * modals.
 *
 * A row here means: user X has permanently dismissed hint Y. Reads are
 * hot-path (mount() on order-show has to check the post-cancel modal
 * hint every page load), so this stays as a wide-and-shallow table with
 * a composite unique index rather than a JSON blob on the users row --
 * a JSON blob would need parsing on every check and would break the
 * moment two hints are dismissed in the same request.
 *
 * The hint_key is a plain namespaced string, deliberately not a foreign
 * key. Retiring a hint (or renaming its key) is then a code-only
 * change; the historical dismissal rows simply become orphans and can
 * be swept out by a periodic cleanup command if we ever add one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_dismissed_hints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hint_key', 128);
            $table->timestamp('dismissed_at')->useCurrent();
            $table->timestamps();

            // One dismissal per (user, hint). Attempting to dismiss the
            // same hint twice becomes an upsert-and-move-on rather than
            // an integrity error, so the caller doesn't need to
            // remember whether the user already clicked "don't show".
            $table->unique(['user_id', 'hint_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_dismissed_hints');
    }
};
