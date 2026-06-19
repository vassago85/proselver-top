<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1B — dealer-branded delivery notes.
 *
 * Adds the three printable-branding columns the PDF pipeline needs
 * to render a dealer's own letterhead.  address / vat_number /
 * billing_email / phone already exist on companies; they just
 * weren't being read by the PDF.  These three are net-new:
 *
 *   - logo_path           stored on config('filesystems.default')
 *                         (local in dev, R2/S3 in prod, same as
 *                         job_documents).
 *   - registration_number SA company registration (2003/012345/07).
 *   - branding_footer      optional legal/banking/sign-off copy
 *                          printed at the bottom of the note; when
 *                          null we fall back to the executor-typed
 *                          default footer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->string('registration_number', 30)->nullable()->after('vat_number');
            $table->text('branding_footer')->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'registration_number', 'branding_footer']);
        });
    }
};
