<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend companies with workflow_type
        Schema::table('companies', function (Blueprint $table) {
            $table->string('workflow_type', 20)->default('standard')->after('type');
        });

        // 2. Extend driver_profiles with operational fields + document uploads
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('id_number', 20)->nullable()->after('user_id');
            $table->string('cellphone', 20)->nullable()->after('id_number');
            $table->string('base_location')->nullable()->after('cellphone');

            $table->string('license_document_disk')->nullable()->after('prdp_expiry');
            $table->string('license_document_path')->nullable()->after('license_document_disk');
            $table->string('license_document_filename')->nullable()->after('license_document_path');
            $table->string('pdp_document_disk')->nullable()->after('license_document_filename');
            $table->string('pdp_document_path')->nullable()->after('pdp_document_disk');
            $table->string('pdp_document_filename')->nullable()->after('pdp_document_path');
        });

        // 3. Add Phase 1 status timestamps to transport_jobs
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->timestamp('customer_confirmed_at')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('customer_confirmed_by')->nullable()->after('customer_confirmed_at');
            $table->timestamp('planned_at')->nullable()->after('customer_confirmed_by');
            $table->timestamp('ready_for_collection_at')->nullable()->after('planned_at');
            $table->timestamp('collected_at')->nullable()->after('ready_for_collection_at');
            $table->timestamp('in_transit_at')->nullable()->after('collected_at');
            $table->timestamp('delivered_at')->nullable()->after('in_transit_at');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'customer_confirmed_at',
                'customer_confirmed_by',
                'planned_at',
                'ready_for_collection_at',
                'collected_at',
                'in_transit_at',
                'delivered_at',
            ]);
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'id_number',
                'cellphone',
                'base_location',
                'license_document_disk',
                'license_document_path',
                'license_document_filename',
                'pdp_document_disk',
                'pdp_document_path',
                'pdp_document_filename',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('workflow_type');
        });
    }
};
