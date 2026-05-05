<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = [
        'uuid',
        'name',
        'normalized_name',
        'type',
        'workflow_type',
        'is_platform_owner',
        'address',
        'vat_number',
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

    public function requiresExternalConfirmation(): bool
    {
        return $this->workflow_type !== 'standard';
    }

    public function isPlatformOwner(): bool
    {
        return (bool) $this->is_platform_owner;
    }
}
