<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bulk-upload supporting columns.
 *
 * companies.movement_csv_mapping
 *   When ops uploads an OEM's monthly movement spreadsheet (FAW, Isuzu,
 *   etc.) the column layout is per-OEM and never changes between months.
 *   We persist the mapping the first time ops confirms it on the preview
 *   screen so subsequent uploads for the same company drop straight into
 *   "preview" without re-mapping. Stored as JSON because the shape can
 *   evolve (new optional columns) without another migration.
 *
 * brands.needs_review
 *   The importer can opportunistically create new brand rows when it
 *   encounters a chassis prefix it doesn't recognise. Flagging those for
 *   review lets a curator clean up the catalogue without blocking the
 *   day-to-day load. Defaults to FALSE for every existing row so nothing
 *   pre-curated gets surfaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('movement_csv_mapping')->nullable()->after('phone');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('movement_csv_mapping');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};
