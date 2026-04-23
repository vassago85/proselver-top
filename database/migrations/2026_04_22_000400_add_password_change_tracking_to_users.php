<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when each user last rotated their password and flags accounts that
 * must change their password before doing anything else.
 *
 * Context: every fleet driver was seeded with the same shared password
 * (Trident@2026). Without a forced rotation on first sign-in, one leaked
 * password compromises the entire fleet PWA. The middleware that reads
 * `must_change_password` sits in ForceChangePassword, and the driver seeder
 * now sets this flag by default for freshly seeded drivers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('password');
            }
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
