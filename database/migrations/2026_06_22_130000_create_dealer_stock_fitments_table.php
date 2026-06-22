<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-leg fitment / body-builder chain for a dealer stock unit.
 *
 * A single VIN often passes through several body builders in
 * sequence: chassis -> dropside supplier -> crane supplier, or
 * chassis -> fridge body supplier -> fridge unit supplier.  The
 * previous single-BB block on dealer_stock (bb_share_*, bb_build_notes,
 * bb_internal_job_number) couldn't represent that -- moving the
 * vehicle to the next BB overwrote the first BB's notes.
 *
 * Each row here is one stop in the build chain.  Per-leg fields:
 *
 *   - fitment_type           (free text, e.g. "Dropside", "Crane")
 *   - status                 (planned / in_progress / completed / cancelled)
 *   - started_at             (set when the unit physically arrives)
 *   - completed_at           (set when it ships out of this BB)
 *   - internal_job_number    (written by the BB)
 *   - share_with_bb          (master toggle for sharing dealer info)
 *   - share_salesperson      (text shown to the BB)
 *   - share_end_customer     (text shown to the BB)
 *   - notes                  (build spec / instructions)
 *
 * The `up()` migration also backfills any dealer_stock currently
 * sitting at a body builder into a single fitment row, so no data
 * is lost on the cut-over.  The legacy bb_* columns on dealer_stock
 * are left in place for one release as a fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_stock_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_stock_id')->constrained('dealer_stock')->cascadeOnDelete();
            $table->foreignId('body_builder_company_id')->constrained('companies');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('fitment_type', 80)->nullable();
            $table->string('status', 32)->default('planned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('internal_job_number', 80)->nullable();
            $table->boolean('share_with_bb')->default(false);
            $table->string('share_salesperson', 120)->nullable();
            $table->string('share_end_customer', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['dealer_stock_id', 'sequence']);
            $table->index(['body_builder_company_id', 'status']);
            $table->index('internal_job_number');
        });

        // Backfill: any vehicle currently at a body builder gets a
        // single in-progress fitment row carrying its existing bb_*
        // payload, so the BB still sees their notes / share toggles
        // after the cut-over.
        DB::table('dealer_stock')
            ->where('current_location_type', 'body_builder')
            ->whereNotNull('current_location_id')
            ->select([
                'id',
                'current_location_id',
                'bb_share_with_body_builder',
                'bb_share_salesperson',
                'bb_share_end_customer',
                'bb_build_notes',
                'bb_internal_job_number',
                'updated_at',
                'created_at',
            ])
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                $now = now();
                $insert = [];
                foreach ($rows as $r) {
                    $insert[] = [
                        'dealer_stock_id'         => $r->id,
                        'body_builder_company_id' => $r->current_location_id,
                        'sequence'                => 1,
                        'fitment_type'            => null,
                        'status'                  => 'in_progress',
                        'started_at'              => $r->updated_at ?? $r->created_at ?? $now,
                        'completed_at'            => null,
                        'internal_job_number'     => $r->bb_internal_job_number,
                        'share_with_bb'           => (bool) $r->bb_share_with_body_builder,
                        'share_salesperson'       => $r->bb_share_salesperson,
                        'share_end_customer'      => $r->bb_share_end_customer,
                        'notes'                   => $r->bb_build_notes,
                        'created_at'              => $now,
                        'updated_at'              => $now,
                    ];
                }
                if (!empty($insert)) {
                    DB::table('dealer_stock_fitments')->insert($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_stock_fitments');
    }
};
