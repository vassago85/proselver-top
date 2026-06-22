<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_OEM = 'oem';
    public const TYPE_DEALER = 'dealer';
    public const TYPE_TRANSPORTER = 'transporter';
    public const TYPE_BODY_BUILDER = 'body_builder';
    public const TYPE_YARD = 'yard';
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_CUSTOMER = 'customer';

    public const TYPES = [
        self::TYPE_OEM,
        self::TYPE_DEALER,
        self::TYPE_TRANSPORTER,
        self::TYPE_BODY_BUILDER,
        self::TYPE_YARD,
        self::TYPE_INTERNAL,
        self::TYPE_CUSTOMER,
    ];

    // Type predicates — single source of truth for the "what kind of
    // company is this?" question, so every view / policy / service
    // asks the same way and a future re-shuffle of the type column
    // (e.g. adding OEM-subsidiary) only has to be taught here.
    public function isOem(): bool { return $this->type === self::TYPE_OEM; }
    public function isDealer(): bool { return $this->type === self::TYPE_DEALER; }
    public function isInternal(): bool { return $this->type === self::TYPE_INTERNAL; }
    public function isBodyBuilder(): bool { return $this->type === self::TYPE_BODY_BUILDER; }

    protected $fillable = [
        'uuid',
        'name',
        'logo_path',
        'normalized_name',
        'type',
        'workflow_type',
        'is_platform_owner',
        'company_group_id',
        'collection_sla_days',
        'address',
        'vat_number',
        'registration_number',
        'branding_footer',
        'billing_email',
        'phone',
        'is_active',
        'movement_csv_mapping',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_platform_owner' => 'boolean',
            'collection_sla_days' => 'integer',
            'movement_csv_mapping' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->uuid)) {
                $company->uuid = (string) Str::uuid();
            }
            $company->normalized_name = Str::lower(Str::ascii($company->name));
        });

        static::updating(function (Company $company) {
            if ($company->isDirty('name')) {
                $company->normalized_name = Str::lower(Str::ascii($company->name));
            }
        });

        // Single-platform-owner invariant: flipping a row's flag to true must
        // clear it on every other row. Cheap enough to run on every save that
        // touches the flag and keeps the "one per instance" rule honest even
        // if someone sets it via tinker or a direct SQL insert-then-resave.
        static::saving(function (Company $company) {
            if ($company->is_platform_owner && $company->isDirty('is_platform_owner')) {
                static::query()
                    ->where('is_platform_owner', true)
                    ->when($company->exists, fn ($q) => $q->where('id', '!=', $company->id))
                    ->update(['is_platform_owner' => false]);
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')
            ->withPivot('location_id')
            ->withTimestamps();
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_company')->withTimestamps();
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class, 'owner_company_id');
    }

    public function executedJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'executing_company_id');
    }

    /**
     * Body-builder companies this dealer has authorised to confirm
     * receipts and raise movement requests against their inventory.
     * Pivot carries the link's active/notes/audit metadata so the
     * dealer "Linked Body Builders" page can show pause / reactivate
     * affordances without joining the link model explicitly.
     */
    public function linkedBodyBuilders(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'body_builder_dealer_links',
            'dealer_company_id',
            'body_builder_company_id',
        )
            ->withPivot(['id', 'is_active', 'linked_by_user_id', 'notes', 'created_at', 'updated_at'])
            ->withTimestamps();
    }

    /**
     * Inverse: dealer companies that have authorised THIS body builder.
     * Used by the BB portal's "My Linked Dealers" list and to scope the
     * BB's job-visibility query (only jobs from companies that have an
     * active link to us).
     */
    public function linkedDealers(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'body_builder_dealer_links',
            'body_builder_company_id',
            'dealer_company_id',
        )
            ->withPivot(['id', 'is_active', 'linked_by_user_id', 'notes', 'created_at', 'updated_at'])
            ->withTimestamps();
    }

    public function movementRequestsSent(): HasMany
    {
        return $this->hasMany(MovementRequest::class, 'requesting_company_id');
    }

    public function movementRequestsReceived(): HasMany
    {
        return $this->hasMany(MovementRequest::class, 'target_company_id');
    }

    public function requiresExternalConfirmation(): bool
    {
        return $this->workflow_type !== 'standard';
    }

    public function isPlatformOwner(): bool
    {
        return (bool) $this->is_platform_owner;
    }

    /**
     * The dealer-group umbrella this company sits under, if any.
     * Holding companies like MCCARTHY / CFAO own multiple member
     * dealerships; the group is for overview / cross-visibility only,
     * never for ownership transfer of jobs or stock.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    /**
     * Sibling companies in the same group (excluding this one).
     * Used by visibility scopes so a group-level user / a stock item
     * with share_with_group=true appears on sister dealerships'
     * boards. Returns an empty collection when this company has no
     * group set, never the entire companies table.
     */
    public function siblingCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'company_group_id', 'company_group_id')
            ->where('id', '!=', $this->id);
    }
}
