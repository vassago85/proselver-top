<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty cash entries — the financial side of slip captures.
 *
 * Phase 1 just stored a photo + category in `job_documents`. Phase 2
 * adds the structured rand amount, an approval workflow, and a hook
 * for client-side OCR (the driver's PWA runs Tesseract.js on the
 * receipt and pre-fills the amount; we keep the OCR text + suggested
 * amount on the row so ops can audit what the driver actually saw).
 *
 * Each row points at the underlying JobDocument (the photo) so the
 * existing storage / retention / R2 plumbing is reused as-is.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('petty_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Both nullable: a slip can be against a specific job (most
            // common) OR an "in-between jobs" expense (driver fills up
            // for the next leg). For accommodation this is essential —
            // a hotel night isn't always tied to a single job.
            $table->foreignId('job_id')->nullable()->constrained('transport_jobs')->nullOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();

            // The receipt photo lives in job_documents. Keeping the FK
            // means delete-cascade and retention rules are unified.
            $table->foreignId('document_id')->nullable()->constrained('job_documents')->nullOnDelete();

            $table->string('category', 30); // fuel_slip / food_slip / toll_slip / parking_slip / accommodation_slip / other
            $table->unsignedInteger('amount_cents'); // ZAR cents, validated > 0
            $table->string('currency', 3)->default('ZAR');
            $table->string('merchant_name')->nullable();
            $table->date('spent_at')->nullable(); // date on the slip itself
            $table->text('description')->nullable();

            // Approval workflow
            $table->string('status', 20)->default('submitted'); // submitted / approved / rejected / reimbursed
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('reimbursed_at')->nullable();
            $table->string('reimbursement_reference')->nullable(); // EFT ref, pay-run ID etc.

            // OCR best-effort capture. Non-authoritative — the driver
            // confirms `amount_cents` themselves. We persist what the
            // OCR saw so a discrepancy investigation has the evidence.
            $table->unsignedInteger('ocr_amount_cents')->nullable();
            $table->text('ocr_text')->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable(); // 0–100

            $table->uuid('client_uuid')->nullable()->unique(); // PWA idempotency
            $table->timestamps();

            $table->index(['driver_user_id', 'status']);
            $table->index(['job_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('spent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_entries');
    }
};
