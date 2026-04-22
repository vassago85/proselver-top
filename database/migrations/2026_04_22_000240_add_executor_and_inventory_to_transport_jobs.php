<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('executing_company_id')
                ->nullable()
                ->after('company_id')
                ->constrained('companies')
                ->nullOnDelete();

            $table->string('destination_type', 20)->nullable()->after('delivery_location_id');

            $table->foreignId('inventory_id')
                ->nullable()
                ->after('vin')
                ->constrained('inventory')
                ->nullOnDelete();

            $table->index(['executing_company_id', 'status']);
            $table->index('destination_type');
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropIndex(['destination_type']);
            $table->dropIndex(['executing_company_id', 'status']);
            $table->dropConstrainedForeignId('inventory_id');
            $table->dropColumn('destination_type');
            $table->dropConstrainedForeignId('executing_company_id');
        });
    }
};
