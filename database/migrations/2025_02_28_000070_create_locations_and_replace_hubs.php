<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name');
            $table->boolean('is_private')->default(false);
            $table->text('address');
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_contact')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('is_active');
        });

        // Migrate existing hubs into locations
        $hubs = DB::table('hubs')->whereNull('deleted_at')->get();
        foreach ($hubs as $hub) {
            DB::table('locations')->insert([
                'id' => $hub->id,
                'uuid' => $hub->uuid ?? (string) Str::uuid(),
                'company_id' => null,
                'company_name' => $hub->name,
                'is_private' => false,
                'address' => $hub->address ?? $hub->name,
                'city' => $hub->city,
                'province' => $hub->province,
                'latitude' => $hub->latitude,
                'longitude' => $hub->longitude,
                'is_active' => $hub->is_active,
                'created_at' => $hub->created_at,
                'updated_at' => $hub->updated_at,
            ]);
        }

        // Reset sequence after explicit ID inserts
        if ($hubs->isNotEmpty()) {
            $maxId = $hubs->max('id');
            DB::statement("SELECT setval(pg_get_serial_sequence('locations', 'id'), ?)", [$maxId]);
        }

        // Add location FK columns to transport_jobs
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('pickup_location_id')->nullable()->after('transport_route_id')->constrained('locations')->nullOnDelete();
            $table->foreignId('delivery_location_id')->nullable()->after('pickup_location_id')->constrained('locations')->nullOnDelete();
            $table->foreignId('yard_location_id')->nullable()->after('delivery_location_id')->constrained('locations')->nullOnDelete();
        });

        // Copy hub data to location columns
        DB::statement('UPDATE transport_jobs SET pickup_location_id = from_hub_id WHERE from_hub_id IS NOT NULL');
        DB::statement('UPDATE transport_jobs SET delivery_location_id = to_hub_id WHERE to_hub_id IS NOT NULL');
        DB::statement('UPDATE transport_jobs SET yard_location_id = yard_hub_id WHERE yard_hub_id IS NOT NULL');

        // Drop old hub FK columns from transport_jobs
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_hub_id');
            $table->dropConstrainedForeignId('to_hub_id');
            $table->dropConstrainedForeignId('yard_hub_id');
        });

        // Add location FK columns to transport_routes
        Schema::table('transport_routes', function (Blueprint $table) {
            $table->foreignId('origin_location_id')->nullable()->after('id')->constrained('locations')->nullOnDelete();
            $table->foreignId('destination_location_id')->nullable()->after('origin_location_id')->constrained('locations')->nullOnDelete();
        });

        // Copy hub data to location columns
        DB::statement('UPDATE transport_routes SET origin_location_id = origin_hub_id');
        DB::statement('UPDATE transport_routes SET destination_location_id = destination_hub_id');

        // Drop old unique constraint and hub FK columns from transport_routes
        Schema::table('transport_routes', function (Blueprint $table) {
            $table->dropUnique('route_unique');
            $table->dropConstrainedForeignId('origin_hub_id');
            $table->dropConstrainedForeignId('destination_hub_id');
            $table->unique(['origin_location_id', 'destination_location_id', 'vehicle_class_id'], 'route_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transport_routes', function (Blueprint $table) {
            $table->dropUnique('route_unique');
            $table->foreignId('origin_hub_id')->nullable()->constrained('hubs');
            $table->foreignId('destination_hub_id')->nullable()->constrained('hubs');
            $table->dropConstrainedForeignId('origin_location_id');
            $table->dropConstrainedForeignId('destination_location_id');
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('from_hub_id')->nullable()->constrained('hubs');
            $table->foreignId('to_hub_id')->nullable()->constrained('hubs');
            $table->foreignId('yard_hub_id')->nullable()->constrained('hubs');
            $table->dropConstrainedForeignId('pickup_location_id');
            $table->dropConstrainedForeignId('delivery_location_id');
            $table->dropConstrainedForeignId('yard_location_id');
        });

        Schema::dropIfExists('locations');
    }
};
