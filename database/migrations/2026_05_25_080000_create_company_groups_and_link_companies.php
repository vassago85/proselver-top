<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce a "company group" parent (e.g. MCCARTHY, CFAO) above the
 * existing dealership Company rows, plus the one foot-in-the-door flag
 * we need on Inventory so a dealership can choose, per stock item,
 * whether their sibling dealerships in the same group can see it.
 *
 * Stock always BELONGS to a single dealership — the group is purely a
 * visibility / overview construct, not an ownership transfer.  See
 * Inventory::scopeVisibleTo() for the read-side enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('company_group_id')
                ->nullable()
                ->after('is_platform_owner')
                ->constrained('company_groups')
                ->nullOnDelete();
            $table->index('company_group_id');
        });

        Schema::table('inventory', function (Blueprint $table) {
            // Dealer chooses, per stock item, whether sibling
            // dealerships in the same group can see it on the
            // group-overview boards. Default off — explicit opt-in.
            $table->boolean('share_with_group')
                ->default(false)
                ->after('status');
            $table->index(['share_with_group', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropIndex(['share_with_group', 'status']);
            $table->dropColumn('share_with_group');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_group_id');
        });

        Schema::dropIfExists('company_groups');
    }
};
