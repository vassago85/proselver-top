<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_documents', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('file_hash');
            $table->timestamp('captured_at')->nullable()->after('client_uuid');
            $table->decimal('latitude', 10, 7)->nullable()->after('captured_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('notes', 500)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('job_documents', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'captured_at', 'latitude', 'longitude', 'notes']);
        });
    }
};
