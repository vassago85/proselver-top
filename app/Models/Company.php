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

    protected $fillable = [
        'uuid',
        'name',
        'normalized_name',
        'type',
        'address',
        'vat_number',
        'billing_email',
        'phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')->withTimestamps();
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
}
