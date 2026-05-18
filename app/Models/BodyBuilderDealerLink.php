<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Approval edge between a dealer company and a body-builder company.
 *
 * Existence of an `is_active=true` row means the BB is allowed to
 * confirm receipts and raise movement requests against the dealer's
 * inventory. Deactivating the link pauses the BB's authority without
 * losing historical request audit (we don't soft-delete it because we
 * want to *show* deactivated links in the dealer's "Linked Body
 * Builders" page with a "reactivate" affordance).
 */
class BodyBuilderDealerLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'dealer_company_id',
        'body_builder_company_id',
        'linked_by_user_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'dealer_company_id');
    }

    public function bodyBuilder(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'body_builder_company_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
